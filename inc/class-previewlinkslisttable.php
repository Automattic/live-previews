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
	private LinkToggle $toggle;

	public function __construct( PreviewLinkService $service, int $now, ?LinkToggle $toggle = null ) {
		parent::__construct(
			[
				'singular' => 'preview-link',
				'plural'   => self::PLURAL,
				'ajax'     => false,
			]
		);

		$this->service = $service;
		$this->now     = $now;
		$this->toggle  = $toggle ?? new LinkToggle();
	}

	/**
	 * The site-wide enable/disable slider, on the same line as the bulk
	 * actions. The checkbox belongs to the small `lp-toggle` form the admin
	 * page renders *outside* this table's own form (forms cannot nest), wired
	 * up via the HTML `form` attribute.
	 *
	 * @param string $which 'top' or 'bottom'.
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$enabled = ! $this->toggle->is_disabled();

		echo '<div class="alignleft actions">';
		echo '<label class="lp-switch">';
		printf(
			'<input type="checkbox" name="lp_enabled" value="1" form="lp-toggle" %s onchange="document.getElementById(\'lp-toggle\').submit()" />',
			checked( $enabled, true, false )
		);
		echo '<span class="lp-track" aria-hidden="true"></span>';
		printf(
			'<span>%s</span>',
			$enabled
				? esc_html__( 'Preview links are enabled', 'live-previews' )
				: esc_html__( 'Preview links are disabled', 'live-previews' )
		);
		echo '</label>';
		printf(
			'<noscript><button type="submit" class="button" form="lp-toggle">%s</button></noscript>',
			esc_html__( 'Apply', 'live-previews' )
		);
		echo '</div>';
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
			'ip_ranges'  => esc_html__( 'IP ranges', 'live-previews' ),
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
		$creator  = PreviewLinksAdminPage::requested_creator();

		$this->items = $this->service->page_of_links( $offset, $per_page, $creator );

		$total = $this->service->count_links( $creator );

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

		/* translators: %d: user ID */
		$name = false !== $user ? $user->display_name : sprintf( __( 'User #%d', 'live-previews' ), $user_id );

		// The name links to the creator-filtered view of this table, which is
		// where the "revoke everything this user created" action lives.
		$url = add_query_arg(
			[
				'page'    => PreviewLinksAdminPage::SLUG,
				'creator' => $user_id,
			],
			admin_url( 'admin.php' )
		);

		return sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html( $name ) );
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

	/**
	 * The link's own IP restriction. Central Dashboard ranges apply to every
	 * link and are not repeated per row. Read-only, like the rest of the table:
	 * a link is immutable once shared, so to change its ranges you revoke it and
	 * mint a fresh one — the same rule as every other property of a link.
	 */
	public function column_ip_ranges( PreviewLink $item ): string {
		$ranges = $item->allowed_ips();

		if ( [] === $ranges ) {
			return esc_html( '—' );
		}

		return implode(
			'<br />',
			array_map(
				static fn ( string $range ): string => sprintf( '<code>%s</code>', esc_html( $range ) ),
				$ranges
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
