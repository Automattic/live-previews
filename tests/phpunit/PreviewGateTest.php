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

		// Same browser both times: the cookie is kept, so the return visit is fine
		// and does not count again.
		static::assertSame( 'publish', $this->visit( $post_id, $token, false ) );
		static::assertSame( 'publish', $this->visit( $post_id, $token, false ) );
		static::assertSame( 1, $this->repository->all_for_post( $post_id )[0]->use_count() );
	}

	public function test_a_bot_views_without_spending_a_use(): void {
		$post_id                    = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token                      = $this->service->mint( $post_id, HOUR_IN_SECONDS, 1, 1 );
		$_SERVER['HTTP_USER_AGENT'] = 'Slackbot-LinkExpanding 1.0';

		// The unfurler sees a preview card but must not burn the single use.
		static::assertSame( 'publish', $this->visit( $post_id, $token, true ) );
		static::assertSame( 0, $this->repository->all_for_post( $post_id )[0]->use_count() );
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
