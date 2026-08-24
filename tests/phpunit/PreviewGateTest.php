<?php
declare(strict_types = 1);

namespace Automattic\LivePreviews;

use WP_Query;
use WP_UnitTestCase;

/**
 * End-to-end enforcement: mint a real token, then prove the gate unlocks the
 * draft for a request carrying it and leaves it locked otherwise.
 *
 * @covers \Automattic\LivePreviews\PreviewGate
 */
class PreviewGateTest extends WP_UnitTestCase {
	private PreviewLinkService $service;
	private PreviewGate $gate;

	public function set_up(): void {
		parent::set_up();

		$this->service = new PreviewLinkService(
			new PostMetaTokenRepository(),
			new AccessPolicy(),
			new SystemClock()
		);
		$this->gate    = new PreviewGate( $this->service );
	}

	public function tear_down(): void {
		unset( $_GET[ PreviewGate::TOKEN_QUERY_VAR ] );
		parent::tear_down();
	}

	public function test_a_valid_token_unlocks_the_draft(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token   = $this->service->mint( $post_id, HOUR_IN_SECONDS, false, 1 );

		$_GET[ PreviewGate::TOKEN_QUERY_VAR ] = $token->value();

		$posts = $this->gate->unlock_valid_previews( [ get_post( $post_id ) ], $this->preview_query() );

		static::assertSame( 'publish', $posts[0]->post_status );
	}

	public function test_a_wrong_token_leaves_the_draft_locked(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->service->mint( $post_id, HOUR_IN_SECONDS, false, 1 );

		$_GET[ PreviewGate::TOKEN_QUERY_VAR ] = 'not-the-token';

		$posts = $this->gate->unlock_valid_previews( [ get_post( $post_id ) ], $this->preview_query() );

		static::assertSame( 'draft', $posts[0]->post_status );
	}

	public function test_no_token_leaves_the_draft_locked(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->service->mint( $post_id, HOUR_IN_SECONDS, false, 1 );

		$posts = $this->gate->unlock_valid_previews( [ get_post( $post_id ) ], $this->preview_query() );

		static::assertSame( 'draft', $posts[0]->post_status );
	}

	public function test_non_preview_requests_are_untouched(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token   = $this->service->mint( $post_id, HOUR_IN_SECONDS, false, 1 );

		$_GET[ PreviewGate::TOKEN_QUERY_VAR ] = $token->value();

		$query             = new WP_Query();
		$query->is_preview = false;
		$posts             = $this->gate->unlock_valid_previews( [ get_post( $post_id ) ], $query );

		static::assertSame( 'draft', $posts[0]->post_status );
	}

	private function preview_query(): WP_Query {
		$query             = new WP_Query();
		$query->is_preview = true;

		return $query;
	}
}
