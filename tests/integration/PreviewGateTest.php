<?php
declare(strict_types = 1);

namespace Automattic\LivePreviews;

use WP_Query;
use WP_UnitTestCase;

/**
 * End-to-end enforcement: mint a real token, then prove the gate unlocks the
 * draft for a request carrying it, counts distinct human viewers against a cap,
 * and leaves it locked otherwise.
 *
 * @covers \Automattic\LivePreviews\PreviewGate
 */
class PreviewGateTest extends WP_UnitTestCase {
	private PreviewLinkService $service;
	private PostMetaTokenRepository $repository;

	public function set_up(): void {
		parent::set_up();

		$this->repository = new PostMetaTokenRepository();
		$this->service    = new PreviewLinkService(
			$this->repository,
			new AccessPolicy(),
			new SystemClock()
		);

		// A human, not a crawler, so visits count.
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Test Human)';
	}

	public function tear_down(): void {
		unset( $_GET[ PreviewGate::TOKEN_QUERY_VAR ], $_SERVER['HTTP_USER_AGENT'] );
		$_COOKIE = [];
		parent::tear_down();
	}

	public function test_a_valid_token_unlocks_the_draft(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token   = $this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1 );

		static::assertSame( 'publish', $this->visit( $post_id, $token ) );
	}

	public function test_a_wrong_token_leaves_the_draft_locked(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1 );

		static::assertSame( 'draft', $this->visit( $post_id, Token::from_string( 'not-the-token' ) ) );
	}

	public function test_no_token_leaves_the_draft_locked(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1 );

		$posts = ( new PreviewGate( $this->service ) )
			->unlock_valid_previews( [ get_post( $post_id ) ], $this->preview_query() );

		static::assertSame( 'draft', $posts[0]->post_status );
	}

	public function test_non_preview_requests_are_untouched(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token   = $this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1 );

		$_GET[ PreviewGate::TOKEN_QUERY_VAR ] = $token->value();

		$query             = new WP_Query();
		$query->is_preview = false;
		$posts             = ( new PreviewGate( $this->service ) )
			->unlock_valid_previews( [ get_post( $post_id ) ], $query );

		static::assertSame( 'draft', $posts[0]->post_status );
	}

	public function test_a_capped_link_exhausts_after_distinct_viewers(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token   = $this->service->mint( $post_id, HOUR_IN_SECONDS, 1, 1 );

		// First human: allowed and counted.
		static::assertSame( 'publish', $this->visit( $post_id, $token, true ) );
		static::assertSame( 1, $this->repository->all_for_post( $post_id )[0]->use_count() );

		// A second, distinct human (fresh cookie jar): the cap is spent.
		static::assertSame( 'draft', $this->visit( $post_id, $token, true ) );
	}

	public function test_the_same_viewer_may_revisit_a_one_use_link(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token   = $this->service->mint( $post_id, HOUR_IN_SECONDS, 1, 1 );

		// Same browser both times: the server-issued slot ID is kept, so the
		// return visit is fine and does not claim a second slot.
		static::assertSame( 'publish', $this->visit( $post_id, $token, false ) );
		static::assertSame( 'publish', $this->visit( $post_id, $token, false ) );
		static::assertSame( 1, $this->repository->all_for_post( $post_id )[0]->use_count() );
	}

	public function test_an_array_shaped_cookie_is_ignored_rather_than_fatal(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token   = $this->service->mint( $post_id, HOUR_IN_SECONDS, 5, 1 );

		// A visitor controls their own cookie names, and one ending in `[]` makes
		// PHP hand back an array where a string is expected.
		$_COOKIE = [ 'lp_viewer_' . substr( $token->hash(), 0, 20 ) => [ 'not', 'a', 'string' ] ];

		static::assertSame( 'publish', $this->visit( $post_id, $token ) );
		static::assertSame( 1, $this->repository->all_for_post( $post_id )[0]->use_count() );
	}

	public function test_the_slot_cookie_value_is_not_derivable_from_the_link(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token   = $this->service->mint( $post_id, HOUR_IN_SECONDS, 2, 1 );

		$this->visit( $post_id, $token, true );

		$cookie = 'lp_viewer_' . substr( $token->hash(), 0, 20 );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Reading back the value the gate itself just set, in a test.
		$issued = isset( $_COOKIE[ $cookie ] ) ? (string) $_COOKIE[ $cookie ] : '';

		static::assertNotSame( '', $issued, 'A slot ID should have been issued.' );
		static::assertNotSame( '1', $issued );
		static::assertStringNotContainsString( $issued, $token->value() );
		static::assertStringNotContainsString( $issued, $token->hash() );
		static::assertSame(
			[ $issued ],
			$this->repository->all_for_post( $post_id )[0]->viewers(),
			'The issued ID is the one the server recorded.'
		);
	}

	public function test_a_bot_gets_no_content_and_spends_no_slot(): void {
		$post_id                    = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token                      = $this->service->mint( $post_id, HOUR_IN_SECONDS, 1, 1 );
		$_SERVER['HTTP_USER_AGENT'] = 'Slackbot-LinkExpanding 1.0';

		// Exempting unfurlers from the cap by user agent alone would hand the
		// bypass to anyone who can set a header. Instead they get a stub: the
		// draft stays locked, and no slot is spent.
		static::assertSame( 'draft', $this->visit( $post_id, $token, true ) );
		static::assertSame( 0, $this->repository->all_for_post( $post_id )[0]->use_count() );
	}

	public function test_spoofing_a_bot_user_agent_wins_nothing(): void {
		$post_id                    = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token                      = $this->service->mint( $post_id, HOUR_IN_SECONDS, 1, 1 );
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (definitely-a-crawler-bot)';

		// The point of the stub: claiming to be a crawler costs you the content
		// instead of buying you an uncounted view.
		static::assertSame( 'draft', $this->visit( $post_id, $token, true ) );
	}

	public function test_a_missing_user_agent_is_treated_as_automated(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token   = $this->service->mint( $post_id, HOUR_IN_SECONDS, 1, 1 );
		unset( $_SERVER['HTTP_USER_AGENT'] );

		static::assertSame( 'draft', $this->visit( $post_id, $token, true ) );
		static::assertSame( 0, $this->repository->all_for_post( $post_id )[0]->use_count() );
	}

	public function test_a_bot_is_shown_a_stub_with_no_draft_details(): void {
		$post_id                    = self::factory()->post->create( [
			'post_status' => 'draft',
			'post_title'  => 'Confidential Launch Plan',
		] );
		$token                      = $this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1 );
		$_SERVER['HTTP_USER_AGENT'] = 'Slackbot-LinkExpanding 1.0';

		$gate = $this->denied_main_query( $post_id, $token );

		$this->expectException( \WPDieException::class );
		$this->expectExceptionMessageMatches( '/private preview link/i' );
		$gate->maybe_render_notice();
	}

	public function test_a_forged_viewer_cookie_does_not_bypass_a_spent_cap(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token   = $this->service->mint( $post_id, HOUR_IN_SECONDS, 1, 1 );

		// One genuine viewer spends the only slot.
		static::assertSame( 'publish', $this->visit( $post_id, $token, true ) );

		// A second viewer forges the marker. Everything here is derivable from
		// the shared URL, which is exactly what the old scheme got wrong.
		$_COOKIE = [
			'lp_viewer_' . substr( $token->hash(), 0, 20 ) => substr( hash( 'sha256', $token->value() ), 0, 32 ),
		];

		static::assertSame( 'draft', $this->visit( $post_id, $token ) );
		static::assertSame(
			1,
			$this->repository->all_for_post( $post_id )[0]->use_count(),
			'A forged cookie must not silently suppress the count either.'
		);
	}

	public function test_an_unissued_but_well_formed_cookie_still_claims_a_slot(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token   = $this->service->mint( $post_id, HOUR_IN_SECONDS, 5, 1 );

		$_COOKIE = [
			'lp_viewer_' . substr( $token->hash(), 0, 20 ) => str_repeat( 'a', 32 ),
		];

		// A cookie the link never issued makes this a new viewer, not a free one.
		static::assertSame( 'publish', $this->visit( $post_id, $token ) );
		static::assertSame( 1, $this->repository->all_for_post( $post_id )[0]->use_count() );
	}

	public function test_an_expired_link_shows_a_friendly_notice(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token   = Token::generate();
		// Save a link that expired an hour ago, without waiting for the clock.
		$this->repository->save(
			new PreviewLink( $post_id, $token->hash(), time() - HOUR_IN_SECONDS, null, 1, time() - 2 * HOUR_IN_SECONDS )
		);

		$gate = $this->denied_main_query( $post_id, $token );

		$this->expectException( \WPDieException::class );
		$this->expectExceptionMessageMatches( '/expired/i' );
		$gate->maybe_render_notice();
	}

	public function test_a_revoked_link_shows_a_friendly_notice(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token   = $this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1 );
		$this->service->revoke( $post_id, $this->repository->all_for_post( $post_id )[0]->token_hash() );

		$gate = $this->denied_main_query( $post_id, $token );

		$this->expectException( \WPDieException::class );
		$this->expectExceptionMessageMatches( '/revoked/i' );
		$gate->maybe_render_notice();
	}

	public function test_a_filter_can_collapse_the_reason_to_a_generic_notice(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token   = Token::generate();
		$this->repository->save(
			new PreviewLink( $post_id, $token->hash(), time() - HOUR_IN_SECONDS, null, 1, time() - 2 * HOUR_IN_SECONDS )
		);

		// An operator who prefers not to name the reason turns disclosure off.
		add_filter( 'live_previews_disclose_denial_reason', '__return_false' );

		$gate = $this->denied_main_query( $post_id, $token );

		// The specific "expired" wording is withheld in favour of the generic one.
		$this->expectException( \WPDieException::class );
		$this->expectExceptionMessageMatches( '/no longer available/i' );
		$gate->maybe_render_notice();
	}

	public function test_the_filter_receives_the_reason_so_it_can_hide_only_some(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token   = $this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1 );
		$this->service->revoke( $post_id, $this->repository->all_for_post( $post_id )[0]->token_hash() );

		// Reveal every reason except revocation, which the callback singles out
		// using the reason passed alongside the default.
		add_filter(
			'live_previews_disclose_denial_reason',
			static fn ( bool $disclose, string $reason ): bool => AccessDecision::REASON_REVOKED !== $reason,
			10,
			2
		);

		$gate = $this->denied_main_query( $post_id, $token );

		$this->expectException( \WPDieException::class );
		$this->expectExceptionMessageMatches( '/no longer available/i' );
		$gate->maybe_render_notice();
	}

	public function test_an_unknown_token_shows_no_notice(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1 );

		// A garbage token must 404 like a missing post, not reveal the draft exists.
		$gate = $this->denied_main_query( $post_id, Token::from_string( 'not-a-real-token' ) );

		$gate->maybe_render_notice();
		static::assertTrue( true, 'No wp_die was triggered for an unknown token.' );
	}

	public function test_an_editor_is_not_blocked_by_a_dead_link(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token   = Token::generate();
		$this->repository->save(
			new PreviewLink( $post_id, $token->hash(), time() - HOUR_IN_SECONDS, null, 1, time() - 2 * HOUR_IN_SECONDS )
		);
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		// An editor can view the draft directly, so the expired link must not
		// intercept them with the notice.
		$gate = $this->denied_main_query( $post_id, $token );

		$gate->maybe_render_notice();
		static::assertTrue( true, 'No wp_die was triggered for a user who can edit the post.' );
	}

	/**
	 * Run the gate over a main-query preview and hand back the gate so the caller
	 * can assert on the notice it would render.
	 */
	private function denied_main_query( int $post_id, Token $token ): PreviewGate {
		$_GET[ PreviewGate::TOKEN_QUERY_VAR ] = $token->value();
		clean_post_cache( $post_id );

		$gate = new PreviewGate( $this->service );
		$gate->unlock_valid_previews( [ get_post( $post_id ) ], $this->preview_query() );

		return $gate;
	}

	/**
	 * Simulate one request against the gate and return the post's resulting status.
	 *
	 * @param bool $fresh_browser Clear the cookie jar first, i.e. a new visitor.
	 */
	private function visit( int $post_id, Token $token, bool $fresh_browser = false ): string {
		if ( $fresh_browser ) {
			$_COOKIE = [];
		}

		$_GET[ PreviewGate::TOKEN_QUERY_VAR ] = $token->value();

		// The gate mutates the in-memory WP_Post; reload from cache-cleared state
		// so each simulated request starts from the real (draft) status.
		clean_post_cache( $post_id );

		$posts = ( new PreviewGate( $this->service ) )
			->unlock_valid_previews( [ get_post( $post_id ) ], $this->preview_query() );

		return (string) $posts[0]->post_status;
	}

	private function preview_query(): WP_Query {
		$query             = new WP_Query();
		$query->is_preview = true;

		return $query;
	}
}
