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

	/** Per-user "links per page" screen option; the `_page` suffix is core convention. */
	public const PER_PAGE_OPTION = 'live_previews_links_per_page';

	/** Rows per page until the screen option overrides it. */
	public const DEFAULT_PER_PAGE = 20;

	private const CAPABILITY = 'edit_others_posts';

	private PreviewLinkService $service;
	private Clock $clock;

	/** Built lazily on the screen load, then reused when rendering the page. */
	private ?PreviewLinksListTable $table = null;

	public function __construct( PreviewLinkService $service, Clock $clock ) {
		$this->service = $service;
		$this->clock   = $clock;
	}

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );

		// Registered on init, not the page load: core saves screen options in
		// wp-admin/admin.php before the load-{hook} action fires, and a custom
		// per-page option is discarded unless this filter returns its value.
		add_filter(
			'set_screen_option_' . self::PER_PAGE_OPTION,
			[ $this, 'save_per_page' ],
			10,
			3
		);
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
			// Handle revoke actions before any output, so we can redirect cleanly,
			// then wire up the screen options and contextual help.
			add_action( "load-{$hook}", [ $this, 'handle_actions' ] );
			add_action( "load-{$hook}", [ $this, 'configure_screen' ] );
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

		$table = $this->table();
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

	/**
	 * Register the per-page screen option, the hideable columns, and the help tabs
	 * once the screen exists. Runs on the page load, after any revoke.
	 */
	public function configure_screen(): void {
		$screen = get_current_screen();

		if ( ! $screen instanceof \WP_Screen ) {
			return;
		}

		add_screen_option(
			'per_page',
			[
				'label'   => __( 'Links per page', 'live-previews' ),
				'default' => self::DEFAULT_PER_PAGE,
				'option'  => self::PER_PAGE_OPTION,
			]
		);

		// Let WordPress render the column show/hide checkboxes in Screen Options.
		add_filter( "manage_{$screen->id}_columns", [ $this, 'screen_columns' ] );

		$this->add_help( $screen );
	}

	/**
	 * The columns offered as show/hide checkboxes in Screen Options. WordPress
	 * drops the checkbox column itself.
	 *
	 * @return array<string, string>
	 */
	public function screen_columns(): array {
		return $this->table()->get_columns();
	}

	/**
	 * Persist the "links per page" screen option. Core discards a custom per-page
	 * option unless a filter returns its value.
	 *
	 * @param mixed  $_screen_option Incoming value; unused.
	 * @param string $_option        Option name; unused.
	 * @param mixed  $value          The submitted value.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature is dictated by the set_screen_option filter.
	public function save_per_page( mixed $_screen_option, string $_option, mixed $value ): int {
		return is_scalar( $value ) ? min( 999, max( 1, (int) $value ) ) : self::DEFAULT_PER_PAGE;
	}

	private function add_help( \WP_Screen $screen ): void {
		$screen->add_help_tab(
			[
				'id'      => 'live-previews-overview',
				'title'   => __( 'Overview', 'live-previews' ),
				'content' => '<p>' . esc_html__( 'This screen lists every preview link across the site, so you can see at a glance which drafts are shared, how far each link has been used, and when it expires. It is read-only apart from revoking, and is shown to editors because they can already view the drafts these links point at.', 'live-previews' ) . '</p>',
			]
		);

		$reading  = '<p>' . esc_html__( 'The table identifies a link by the last four characters of its token and can revoke it, but it never shows or re-copies the shareable URL: only a hash of the token is stored, never the token itself. If a link is lost, revoke it and generate a fresh one from the post editor.', 'live-previews' ) . '</p>';
		$reading .= '<p><strong>' . esc_html__( 'Status', 'live-previews' ) . '</strong></p><ul>';
		$reading .= '<li>' . esc_html__( 'Active: the link works.', 'live-previews' ) . '</li>';
		$reading .= '<li>' . esc_html__( 'Expired: past its expiry time.', 'live-previews' ) . '</li>';
		$reading .= '<li>' . esc_html__( 'Exhausted: reached its limit on distinct viewers.', 'live-previews' ) . '</li>';
		$reading .= '<li>' . esc_html__( 'Revoked: switched off by hand.', 'live-previews' ) . '</li>';
		$reading .= '</ul><p>' . esc_html__( 'Uses counts distinct viewers against the cap; an infinity sign means no cap.', 'live-previews' ) . '</p>';

		$screen->add_help_tab(
			[
				'id'      => 'live-previews-reading',
				'title'   => __( 'Reading a row', 'live-previews' ),
				'content' => $reading,
			]
		);

		$screen->add_help_tab(
			[
				'id'      => 'live-previews-revoking',
				'title'   => __( 'Revoking', 'live-previews' ),
				'content' => '<p>' . esc_html__( 'Revoking a link stops it working immediately. For a short period the visitor sees a "no longer available" notice, and after that a plain "not found" page. Revoking cannot be undone: generate a new link to restore access. Use the row action to revoke one link, or tick several and choose the Revoke bulk action.', 'live-previews' ) . '</p>',
			]
		);

		$screen->set_help_sidebar(
			'<p><strong>' . esc_html__( 'For more information', 'live-previews' ) . '</strong></p>' .
			'<p><a href="' . esc_url( 'https://docs.wpvip.com/' ) . '">' . esc_html__( 'WordPress VIP documentation', 'live-previews' ) . '</a></p>' .
			'<p><a href="' . esc_url( 'mailto:support@wpvip.com' ) . '">' . esc_html__( 'Contact VIP support', 'live-previews' ) . '</a></p>'
		);
	}

	private function table(): PreviewLinksListTable {
		if ( null === $this->table ) {
			if ( ! class_exists( 'WP_List_Table' ) ) {
				/** @psalm-suppress MissingFile */
				require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
			}

			$this->table = new PreviewLinksListTable( $this->service, $this->clock->now() );
		}

		return $this->table;
	}

	private function page_url(): string {
		return add_query_arg( 'page', self::SLUG, admin_url( 'admin.php' ) );
	}
}
