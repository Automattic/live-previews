<?php

namespace Automattic\LivePreviews;

/**
 * The site-wide "Preview Links" admin screen: registers the menu, renders the
 * {@see PreviewLinksListTable}, and handles revoke actions.
 *
 * Gated at `edit_others_posts` (an editor), matching the audience that can
 * already view other authors' drafts, so the screen exposes nothing new. Revokes
 * run through a post-redirect-get flow — process the action, then redirect to a
 * clean URL — so a refresh cannot replay them, and every revoke is nonce-checked.
 */
final class PreviewLinksAdminPage {
	/** Menu slug; also the `page` query var. Referenced by the list table's row actions. */
	public const SLUG = 'live-previews';

	private const CAPABILITY = 'edit_others_posts';

	private const PER_PAGE = 20;

	private PreviewLinkService $service;
	private Clock $clock;

	public function __construct( PreviewLinkService $service, Clock $clock ) {
		$this->service = $service;
		$this->clock   = $clock;
	}

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
	}

	public function add_menu(): void {
		$hook = add_menu_page(
			esc_html__( 'Preview Links', 'live-previews' ),
			esc_html__( 'Preview Links', 'live-previews' ),
			self::CAPABILITY,
			self::SLUG,
			[ $this, 'render' ],
			'dashicons-share'
		);

		if ( '' !== $hook ) {
			// Handle revoke actions before any output, so we can redirect cleanly.
			add_action( "load-{$hook}", [ $this, 'handle_actions' ] );
		}
	}

	public function handle_actions(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$revoked = $this->process_request();

		if ( null === $revoked ) {
			return;
		}

		wp_safe_redirect( add_query_arg( 'lp_revoked', $revoked, $this->page_url() ) );
		exit;
	}

	/**
	 * Carry out whichever revoke the request asks for.
	 *
	 * @return int|null Number of links revoked, or null if the request carried no
	 *                  revoke action (an ordinary page view).
	 */
	public function process_request(): ?int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified below via check_admin_referer(); inputs are read only to build the per-link nonce action.
		$get_action = isset( $_GET['action'] ) && is_string( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';

		if ( 'revoke' === $get_action ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See above.
			$post_id = isset( $_GET['post'] ) && is_scalar( $_GET['post'] ) ? (int) $_GET['post'] : 0;
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See above.
			$token = isset( $_GET['token'] ) && is_string( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';

			check_admin_referer( 'live_previews_revoke_' . $post_id . '_' . $token );

			return $this->service->revoke( $post_id, $token ) ? 1 : 0;
		}

		if ( 'revoke' === $this->requested_bulk_action() ) {
			check_admin_referer( 'bulk-' . PreviewLinksListTable::PLURAL );

			return $this->revoke_selected();
		}

		return null;
	}

	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage preview links.', 'live-previews' ) );
		}

		if ( ! class_exists( 'WP_List_Table' ) ) {
			/** @psalm-suppress MissingFile */
			require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		}

		$table = new PreviewLinksListTable( $this->service, $this->clock->now(), self::PER_PAGE );
		$table->prepare_items();

		echo '<div class="wrap">';
		printf( '<h1>%s</h1>', esc_html__( 'Preview Links', 'live-previews' ) );

		$this->maybe_render_notice();

		echo '<form method="post">';
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( self::SLUG ) );
		$table->display();
		echo '</form>';
		echo '</div>';
	}

	/**
	 * The revoke bulk action requested, if any. Reads the list table's own
	 * `action`/`action2` fields; the selection is only acted on after the caller
	 * has verified the bulk nonce.
	 */
	private function requested_bulk_action(): string {
		foreach ( [ 'action', 'action2' ] as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Acted on only after check_admin_referer() in process_request().
			if ( isset( $_REQUEST[ $key ] ) && is_string( $_REQUEST[ $key ] ) && '-1' !== $_REQUEST[ $key ] ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See above.
				return sanitize_key( wp_unslash( $_REQUEST[ $key ] ) );
			}
		}

		return '';
	}

	/**
	 * Revoke every link ticked in the table, returning how many were revoked. Each
	 * value is a `post_id:token_hash` pair emitted by the checkbox column.
	 */
	private function revoke_selected(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in process_request(); each pair is sanitised with sanitize_text_field() in the loop below.
		$selected = isset( $_POST['links'] ) ? wp_unslash( $_POST['links'] ) : [];

		if ( ! is_array( $selected ) ) {
			return 0;
		}

		$count = 0;

		foreach ( $selected as $pair ) {
			if ( ! is_string( $pair ) ) {
				continue;
			}

			$parts = explode( ':', sanitize_text_field( $pair ), 2 );

			if ( 2 === count( $parts ) && $this->service->revoke( (int) $parts[0], $parts[1] ) ) {
				++$count;
			}
		}

		return $count;
	}

	private function maybe_render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read only to render a result notice after our own post-revoke redirect; no action is taken here.
		if ( ! isset( $_GET['lp_revoked'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See above.
		$count = is_scalar( $_GET['lp_revoked'] ) ? (int) $_GET['lp_revoked'] : 0;

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %d: number of preview links revoked */
					_n( '%d preview link revoked.', '%d preview links revoked.', $count, 'live-previews' ),
					$count
				)
			)
		);
	}

	private function page_url(): string {
		return add_query_arg( 'page', self::SLUG, admin_url( 'admin.php' ) );
	}
}
