<?php

namespace Automattic\LivePreviews;

use WP_List_Table;

/**
 * The site-wide audit table of every issued preview link.
 *
 * Read-only but for one action. Because only the token *hash* is stored, the
 * table can identify a link by a four-character hint and revoke it, but can
 * never show or re-copy its shareable URL — that keeps the "no re-copy by
 * design" hardening intact. Rows come from {@see PreviewLinkService} one page at
 * a time, so a large site is never loaded whole.
 *
 * @psalm-suppress PropertyNotSetInConstructor Parent WP_List_Table initialises $items, $screen and $_args in the constructor we call.
 */
final class PreviewLinksListTable extends WP_List_Table {
	/** Ties the bulk-action nonce emitted here to the check in {@see PreviewLinksAdminPage}. */
	public const PLURAL = 'preview-links';

	private PreviewLinkService $service;
	private int $now;

	public function __construct( PreviewLinkService $service, int $now ) {
		parent::__construct(
			[
				'singular' => 'preview-link',
				'plural'   => self::PLURAL,
				'ajax'     => false,
			]
		);

		$this->service = $service;
		$this->now     = $now;
	}

	/**
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return [
			'cb'         => '<input type="checkbox" />',
			'post'       => esc_html__( 'Post', 'live-previews' ),
			'created_by' => esc_html__( 'Created by', 'live-previews' ),
			'usage'      => esc_html__( 'Uses', 'live-previews' ),
			'expiry'     => esc_html__( 'Expires', 'live-previews' ),
			'status'     => esc_html__( 'Status', 'live-previews' ),
			'token'      => esc_html__( 'Link', 'live-previews' ),
		];
	}

	/**
	 * @return array<string, string>
	 */
	protected function get_bulk_actions(): array {
		return [ 'revoke' => esc_html__( 'Revoke', 'live-previews' ) ];
	}

	protected function get_default_primary_column_name(): string {
		return 'post';
	}

	public function no_items(): void {
		esc_html_e( 'No preview links have been created yet.', 'live-previews' );
	}

	public function prepare_items(): void {
		$per_page = $this->get_items_per_page( PreviewLinksAdminPage::PER_PAGE_OPTION, PreviewLinksAdminPage::DEFAULT_PER_PAGE );
		$offset   = ( $this->get_pagenum() - 1 ) * $per_page;

		$this->items = $this->service->page_of_links( $offset, $per_page );

		$total = $this->service->count_links();

		$this->set_pagination_args(
			[
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / $per_page ),
			]
		);

		$this->_column_headers = [ $this->get_columns(), get_hidden_columns( $this->screen ), [] ];
	}

	public function column_cb( $item ): string {
		/** @var PreviewLink $item */
		return sprintf(
			'<input type="checkbox" name="links[]" value="%s" />',
			esc_attr( $item->post_id() . ':' . $item->token_hash() )
		);
	}

	public function column_post( PreviewLink $item ): string {
		$post_id = $item->post_id();
		$title   = get_the_title( $post_id );

		if ( '' === $title ) {
			/* translators: %d: post ID */
			$title = sprintf( __( '(post #%d)', 'live-previews' ), $post_id );
		}

		$edit_link = get_edit_post_link( $post_id );
		$label     = is_string( $edit_link ) && '' !== $edit_link
			? sprintf( '<a href="%s">%s</a>', esc_url( $edit_link ), esc_html( $title ) )
			: esc_html( $title );

		return $label . $this->row_actions( $this->row_action_links( $item ) );
	}

	public function column_created_by( PreviewLink $item ): string {
		$user_id = $item->created_by();

		if ( 0 === $user_id ) {
			return esc_html( '—' );
		}

		$user = get_userdata( $user_id );

		if ( false !== $user ) {
			return esc_html( $user->display_name );
		}

		/* translators: %d: user ID */
		return esc_html( sprintf( __( 'User #%d', 'live-previews' ), $user_id ) );
	}

	public function column_usage( PreviewLink $item ): string {
		$max = $item->max_uses();

		return esc_html(
			sprintf(
				'%d / %s',
				$item->use_count(),
				null === $max ? '∞' : (string) $max
			)
		);
	}

	public function column_expiry( PreviewLink $item ): string {
		$expires  = $item->expires_at();
		$format   = (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' );
		$absolute = wp_date( $format, $expires );

		if ( $item->is_expired( $this->now ) ) {
			/* translators: %s: human-readable duration, e.g. "2 hours" */
			$relative = sprintf( __( '%s ago', 'live-previews' ), human_time_diff( $expires, $this->now ) );
		} else {
			/* translators: %s: human-readable duration, e.g. "2 hours" */
			$relative = sprintf( __( 'in %s', 'live-previews' ), human_time_diff( $this->now, $expires ) );
		}

		return sprintf(
			'%s<br /><small>%s</small>',
			esc_html( false === $absolute ? '' : $absolute ),
			esc_html( $relative )
		);
	}

	public function column_status( PreviewLink $item ): string {
		if ( $item->is_revoked() ) {
			$label = __( 'Revoked', 'live-previews' );
		} elseif ( $item->is_expired( $this->now ) ) {
			$label = __( 'Expired', 'live-previews' );
		} elseif ( $item->is_exhausted() ) {
			$label = __( 'Exhausted', 'live-previews' );
		} else {
			$label = __( 'Active', 'live-previews' );
		}

		return esc_html( $label );
	}

	public function column_token( PreviewLink $item ): string {
		return sprintf( '<code>%s</code>', esc_html( '····' . $item->token_hint() ) );
	}

	/**
	 * Row actions for a link: a Revoke link, but only while the link is still
	 * live. Revoking an already-dead link would be a no-op.
	 *
	 * @return array<string, string>
	 */
	private function row_action_links( PreviewLink $item ): array {
		if ( $item->is_revoked() || $item->is_expired( $this->now ) ) {
			return [];
		}

		$url = wp_nonce_url(
			add_query_arg(
				[
					'page'   => PreviewLinksAdminPage::SLUG,
					'action' => 'revoke',
					'post'   => $item->post_id(),
					'token'  => $item->token_hash(),
				],
				admin_url( 'admin.php' )
			),
			'live_previews_revoke_' . $item->post_id() . '_' . $item->token_hash()
		);

		return [
			'revoke' => sprintf(
				'<a href="%s" class="submitdelete">%s</a>',
				esc_url( $url ),
				esc_html__( 'Revoke', 'live-previews' )
			),
		];
	}
}
