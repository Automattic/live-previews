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

	/** Screen ID core derives from the slug, so other classes can spot this screen. */
	public const SCREEN_ID = 'toplevel_page_' . self::SLUG;

	/** Per-user "links per page" screen option; the `_page` suffix is core convention. */
	public const PER_PAGE_OPTION = 'live_previews_links_per_page';

	/** Rows per page until the screen option overrides it. */
	public const DEFAULT_PER_PAGE = 20;

	private const CAPABILITY = 'edit_others_posts';

	/**
	 * The break-glass "revoke everything" switch has a far larger blast radius
	 * than per-row revoke, so it is gated above the table's own capability.
	 */
	private const REVOKE_ALL_CAPABILITY = 'manage_options';

	private PreviewLinkService $service;
	private Clock $clock;
	private BulkLinkRevoker $revoker;
	private LinkToggle $toggle;

	/**
	 * @var list<string> Central CIDR ranges from the VIP Dashboard config. Shown
	 *                   above the table so an auditor can see the baseline every
	 *                   link accepts, which no per-row column repeats.
	 */
	private array $central_ip_ranges;

	/** Built lazily on the screen load, then reused when rendering the page. */
	private ?PreviewLinksListTable $table = null;

	/**
	 * @param list<string> $central_ip_ranges Central CIDR ranges applying to
	 *                                        every link, or empty for none.
	 */
	public function __construct( PreviewLinkService $service, Clock $clock, BulkLinkRevoker $revoker, ?LinkToggle $toggle = null, array $central_ip_ranges = [] ) {
		$this->service           = $service;
		$this->clock             = $clock;
		$this->revoker           = $revoker;
		$this->toggle            = $toggle ?? new LinkToggle();
		$this->central_ip_ranges = $central_ip_ranges;
	}

	/**
	 * The creator the table is filtered to, or null when showing everyone.
	 * Read-only display state, carried in the URL so pagination keeps it.
	 */
	public static function requested_creator(): ?int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display filter; every action derived from it is separately nonce-checked.
		$creator = isset( $_GET['creator'] ) && is_scalar( $_GET['creator'] ) ? (int) $_GET['creator'] : 0;

		return $creator > 0 ? $creator : null;
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

		$toggled = $this->process_toggle();

		if ( null !== $toggled ) {
			wp_safe_redirect( add_query_arg( 'lp_toggled', $toggled, $this->page_url() ) );
			exit;
		}

		$revoked = $this->process_request();

		if ( null === $revoked ) {
			return;
		}

		$args = [ 'lp_revoked' => $revoked ];

		if ( $this->revoker->has_pending_work() ) {
			// A sweep overflowed this run and continues on cron; say so rather
			// than implying the count above was everything.
			$args['lp_pending'] = 1;
		}

		wp_safe_redirect( add_query_arg( $args, $this->page_url() ) );
		exit;
	}

	/**
	 * Flip the site-wide switch if the request asks for it. Submitted by the
	 * toggle slider at the top of the screen: the new state is simply whether
	 * the checkbox arrived, so replaying a submission is idempotent.
	 *
	 * @return string|null 'disabled' or 'enabled' when the switch was flipped,
	 *                     null when the request carried no toggle action.
	 */
	public function process_toggle(): ?string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified below via check_admin_referer() before anything changes.
		$action = isset( $_POST['action'] ) && is_string( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';

		if ( 'toggle_links' !== $action ) {
			return null;
		}

		check_admin_referer( 'live_previews_toggle_links' );

		if ( ! current_user_can( self::REVOKE_ALL_CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to change whether preview links work on this site.', 'live-previews' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked above; the checkbox's presence is the requested state.
		if ( isset( $_POST['lp_enabled'] ) ) {
			$this->toggle->enable();

			return 'enabled';
		}

		$this->toggle->disable();

		return 'disabled';
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

		if ( 'revoke_creator' === $get_action ) {
			$creator = self::requested_creator();

			check_admin_referer( 'live_previews_revoke_creator_' . (string) $creator );

			return null === $creator ? 0 : $this->revoker->revoke_by_creator( $creator );
		}

		if ( 'revoke_all' === $get_action ) {
			check_admin_referer( 'live_previews_revoke_all' );

			if ( ! current_user_can( self::REVOKE_ALL_CAPABILITY ) ) {
				wp_die( esc_html__( 'You are not allowed to revoke all preview links.', 'live-previews' ) );
			}

			return $this->revoker->revoke_all();
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

		$this->render_toggle();
		$this->maybe_render_disabled_banner();
		$this->maybe_render_central_ranges();
		$this->maybe_render_notice();
		$this->maybe_render_creator_filter();

		echo '<form method="post">';
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( self::SLUG ) );
		$table->display();
		echo '</form>';

		$this->maybe_render_revoke_all();
		echo '</div>';
	}

	/**
	 * The site-wide enable/disable switch, as a toggle slider at the top of the
	 * screen. Deliberately undramatic — disabling is a reversible pause, so it
	 * gets a settings-style control rather than a warning-laden button; the
	 * banner below carries the loudness while links are off.
	 *
	 * The control is a real checkbox (so it is keyboard- and screen-reader
	 * accessible) dressed as a slider; wp-admin ships no toggle component, so
	 * the few styles it needs are scoped here. It submits on change, with a
	 * plain Apply button for the no-JavaScript case.
	 */
	private function render_toggle(): void {
		if ( ! current_user_can( self::REVOKE_ALL_CAPABILITY ) ) {
			return;
		}

		$enabled = ! $this->toggle->is_disabled();

		echo '<style>
			.lp-toggle { margin: 8px 0 4px; }
			.lp-toggle .lp-switch { display: inline-flex; align-items: center; gap: 8px; cursor: pointer; }
			.lp-toggle input[type="checkbox"] { position: absolute; opacity: 0; width: 36px; height: 20px; margin: 0; cursor: pointer; }
			.lp-toggle .lp-track { box-sizing: border-box; width: 36px; height: 20px; border-radius: 10px; background: #8c8f94; position: relative; transition: background 0.15s ease; }
			.lp-toggle .lp-track::before { content: ""; position: absolute; top: 2px; left: 2px; width: 16px; height: 16px; border-radius: 50%; background: #fff; transition: transform 0.15s ease; }
			.lp-toggle input:checked ~ .lp-track { background: #2271b1; }
			.lp-toggle input:checked ~ .lp-track::before { transform: translateX(16px); }
			.lp-toggle input:focus-visible ~ .lp-track { outline: 2px solid #2271b1; outline-offset: 2px; }
		</style>';

		echo '<form method="post" class="lp-toggle" action="' . esc_url( $this->page_url() ) . '">';
		wp_nonce_field( 'live_previews_toggle_links' );
		echo '<input type="hidden" name="action" value="toggle_links" />';
		echo '<label class="lp-switch">';
		printf( '<input type="checkbox" name="lp_enabled" value="1" %s onchange="this.form.submit()" />', checked( $enabled, true, false ) );
		echo '<span class="lp-track" aria-hidden="true"></span>';
		printf(
			'<span>%s</span>',
			$enabled
				? esc_html__( 'Preview links are enabled', 'live-previews' )
				: esc_html__( 'Preview links are disabled', 'live-previews' )
		);
		echo '</label>';
		printf( '<noscript><button type="submit" class="button">%s</button></noscript>', esc_html__( 'Apply', 'live-previews' ) );
		echo '</form>';
	}

	/**
	 * A loud, undismissable banner while the site-wide switch is off. This is
	 * deliberately the one piece of out-of-band state in the plugin, so every
	 * editor on this screen must see it — a table of "Active" links that quietly
	 * do not work would generate exactly the support tickets it exists to avoid.
	 */
	private function maybe_render_disabled_banner(): void {
		if ( ! $this->toggle->is_disabled() ) {
			return;
		}

		$since = $this->toggle->disabled_at();
		$actor = $this->toggle->disabled_by();

		$who = null;
		if ( null !== $actor && 0 !== $actor ) {
			$user = get_userdata( $actor );
			$who  = false !== $user ? $user->display_name : null;
		}

		$format = (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' );
		$when   = null !== $since ? wp_date( $format, $since ) : false;

		if ( null !== $who && false !== $when ) {
			/* translators: 1: user display name, 2: date and time */
			$detail = sprintf( __( 'Disabled by %1$s on %2$s.', 'live-previews' ), $who, $when );
		} elseif ( false !== $when ) {
			/* translators: %s: date and time */
			$detail = sprintf( __( 'Disabled on %s.', 'live-previews' ), $when );
		} else {
			$detail = '';
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s %s</p></div>',
			esc_html__( 'Preview links are disabled site-wide.', 'live-previews' ),
			esc_html__( 'No preview link works while this is off — including newly generated ones. Each link keeps its own expiry and usage, and links that are still valid start working again when re-enabled.', 'live-previews' ),
			esc_html( $detail )
		);
	}


	/**
	 * When the table is filtered to one creator, say so and offer the sweep that
	 * revokes everything they created — the whole set, not just this page.
	 */
	private function maybe_render_creator_filter(): void {
		$creator = self::requested_creator();

		if ( null === $creator ) {
			return;
		}

		$user = get_userdata( $creator );
		/* translators: %d: user ID */
		$name = false !== $user ? $user->display_name : sprintf( __( 'User #%d', 'live-previews' ), $creator );

		$revoke_url = wp_nonce_url(
			add_query_arg(
				[
					'page'    => self::SLUG,
					'action'  => 'revoke_creator',
					'creator' => $creator,
				],
				admin_url( 'admin.php' )
			),
			'live_previews_revoke_creator_' . $creator
		);

		printf(
			'<p>%s <a href="%s">%s</a> | <a href="%s" class="button-link button-link-delete" onclick="return confirm(%s);">%s</a></p>',
			esc_html(
				/* translators: %s: user display name */
				sprintf( __( 'Showing links created by %s.', 'live-previews' ), $name )
			),
			esc_url( $this->page_url() ),
			esc_html__( 'Show all creators', 'live-previews' ),
			esc_url( $revoke_url ),
			esc_attr( (string) wp_json_encode( __( 'Revoke every preview link this user created, across the whole site? This cannot be undone.', 'live-previews' ) ) ),
			esc_html(
				/* translators: %s: user display name */
				sprintf( __( 'Revoke all links by %s', 'live-previews' ), $name )
			)
		);
	}

	/**
	 * The break-glass switch: revoke every live link on the site. Shown only to
	 * administrators, behind its own confirmation, because its blast radius far
	 * exceeds the table's per-row and bulk revokes.
	 */
	private function maybe_render_revoke_all(): void {
		if ( ! current_user_can( self::REVOKE_ALL_CAPABILITY ) ) {
			return;
		}

		$url = wp_nonce_url(
			add_query_arg(
				[
					'page'   => self::SLUG,
					'action' => 'revoke_all',
				],
				admin_url( 'admin.php' )
			),
			'live_previews_revoke_all'
		);

		printf(
			'<p><a href="%s" class="button-link button-link-delete" onclick="return confirm(%s);">%s</a> — %s</p>',
			esc_url( $url ),
			esc_attr( (string) wp_json_encode( __( 'Revoke EVERY preview link on this site? Every shared draft immediately stops being viewable. This cannot be undone.', 'live-previews' ) ) ),
			esc_html__( 'Revoke all preview links', 'live-previews' ),
			esc_html__( 'Permanently revokes every link on the site, for a confirmed leak. To pause links reversibly instead, use the toggle at the top of this screen.', 'live-previews' )
		);
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

	/**
	 * State the central baseline once, above the table. The IP ranges column
	 * shows only each link's own ranges, so without this line a table full of
	 * dashes would read as "no IP restrictions" on a site where the Dashboard
	 * restricts every link.
	 */
	private function maybe_render_central_ranges(): void {
		if ( [] === $this->central_ip_ranges ) {
			return;
		}

		$ranges = implode(
			', ',
			array_map(
				static fn ( string $range ): string => sprintf( '<code>%s</code>', esc_html( $range ) ),
				$this->central_ip_ranges
			)
		);

		printf(
			'<p>%s %s</p>',
			esc_html__( 'IP ranges set in the VIP Dashboard apply to every link, in addition to any ranges shown per link below:', 'live-previews' ),
			$ranges // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each range is passed through esc_html() above; the only markup is static <code> tags.
		);
	}

	private function maybe_render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read only to render a result notice after our own post-toggle redirect; no action is taken here.
		$toggled = isset( $_GET['lp_toggled'] ) && is_string( $_GET['lp_toggled'] ) ? sanitize_key( wp_unslash( $_GET['lp_toggled'] ) ) : '';

		if ( 'disabled' === $toggled || 'enabled' === $toggled ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				'disabled' === $toggled
					? esc_html__( 'Preview links disabled. No link will work until they are enabled again.', 'live-previews' )
					: esc_html__( 'Preview links enabled. Links that are still valid work again.', 'live-previews' )
			);

			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read only to render a result notice after our own post-revoke redirect; no action is taken here.
		if ( ! isset( $_GET['lp_revoked'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See above.
		$count = is_scalar( $_GET['lp_revoked'] ) ? (int) $_GET['lp_revoked'] : 0;

		$message = sprintf(
			/* translators: %d: number of preview links revoked */
			_n( '%d preview link revoked.', '%d preview links revoked.', $count, 'live-previews' ),
			$count
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Set by our own post-revoke redirect; read only to phrase the notice.
		if ( isset( $_GET['lp_pending'] ) ) {
			$message .= ' ' . __( 'The remaining links are being revoked in the background.', 'live-previews' );
		}

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html( $message )
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
		$reading .= '<p>' . esc_html__( 'IP ranges shows the addresses a link is restricted to, on top of any ranges configured centrally in the VIP Dashboard. A dash means the link adds no restriction of its own. A link cannot be edited once shared: to change its ranges, revoke it and generate a new one.', 'live-previews' ) . '</p>';

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
				'content' => '<p>' . esc_html__( 'Revoking a link stops it working immediately. For a short period the visitor sees a "no longer available" notice, and after that a plain "not found" page. Revoking cannot be undone: generate a new link to restore access. Use the row action to revoke one link, or tick several and choose the Revoke bulk action.', 'live-previews' ) . '</p>'
					. '<p>' . esc_html__( 'To revoke everything one person created — when someone leaves, for example — click their name in the Created by column, then use "Revoke all links by" that user; it covers every link of theirs on the site, not just the rows shown. Administrators also see a "Revoke all preview links" switch below the table that revokes every link on the site at once. When a user account is deleted, their links are revoked automatically.', 'live-previews' ) . '</p>'
				. '<p>' . esc_html__( 'If you suspect links are being misused but are not yet sure, administrators can instead switch preview links off with the toggle at the top of this screen. That is a reversible pause, not a revocation: no link works while disabled, and links that are still valid resume working when re-enabled.', 'live-previews' ) . '</p>',
			]
		);

		$screen->set_help_sidebar( $this->help_sidebar() );
	}

	/**
	 * Where to send someone who needs more help.
	 *
	 * VIP support can only help VIP customers, so pointing every install at it
	 * would send most people to a desk that cannot answer them. On VIP the links
	 * go to the platform's documentation and support; everywhere else, to the
	 * plugin's own support forum.
	 */
	private function help_sidebar(): string {
		$links = Platform::is_vip()
			? [
				'https://docs.wpvip.com/'  => __( 'WordPress VIP documentation', 'live-previews' ),
				'mailto:support@wpvip.com' => __( 'Contact VIP support', 'live-previews' ),
			]
			: [
				'https://wordpress.org/support/plugin/live-previews/' => __( 'Support forum', 'live-previews' ),
			];

		$sidebar = '<p><strong>' . esc_html__( 'For more information', 'live-previews' ) . '</strong></p>';

		foreach ( $links as $url => $label ) {
			$sidebar .= '<p><a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></p>';
		}

		return $sidebar;
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
