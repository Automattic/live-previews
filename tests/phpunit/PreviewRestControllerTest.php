<?php
declare(strict_types = 1);

namespace Automattic\LivePreviews;

use Spy_REST_Server;
use WP_REST_Request;
use WP_REST_Server;
use WP_Test_REST_TestCase;

/**
 * @covers \Automattic\LivePreviews\PreviewRestController
 */
class PreviewRestControllerTest extends WP_Test_REST_TestCase {
	private const ROUTE = '/' . PreviewRestController::NAMESPACE . PreviewRestController::ROUTE;

	/**
	 * @global WP_REST_Server|null $wp_rest_server
	 */
	public function setUp(): void {
		/** @var WP_REST_Server $wp_rest_server */
		global $wp_rest_server;

		parent::setUp();

		$wp_rest_server = new Spy_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$service = new PreviewLinkService(
			new PostMetaTokenRepository(),
			new AccessPolicy(),
			new SystemClock()
		);
		( new PreviewRestController( $service ) )->register_routes();
	}

	/**
	 * @global WP_REST_Server|null $wp_rest_server
	 */
	public function tearDown(): void {
		/** @var WP_REST_Server $wp_rest_server */
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tearDown();
	}

	public function test_the_route_is_registered(): void {
		/** @var WP_REST_Server $wp_rest_server */
		global $wp_rest_server;

		static::assertArrayHasKey( self::ROUTE, $wp_rest_server->get_routes() );
	}

	public function test_an_editor_mints_a_link_carrying_a_token(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$response = $this->create_link( $post_id, 8 * HOUR_IN_SECONDS );

		static::assertSame( 200, $response->get_status() );
		$data = (array) $response->get_data();
		static::assertStringContainsString( 'preview=true', (string) $data['url'] );
		static::assertStringContainsString( PreviewGate::TOKEN_QUERY_VAR . '=', (string) $data['url'] );
		static::assertGreaterThan( time(), (int) $data['expires_at'] );
	}

	public function test_a_user_without_edit_rights_is_forbidden(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		static::assertSame( 403, $this->create_link( $post_id, HOUR_IN_SECONDS )->get_status() );
	}

	public function test_an_unlisted_expiration_is_rejected(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		// 42 seconds is not one of the offered options.
		static::assertSame( 400, $this->create_link( $post_id, 42 )->get_status() );
	}

	public function test_a_capped_link_is_accepted(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		static::assertSame( 200, $this->create_link( $post_id, HOUR_IN_SECONDS, 5 )->get_status() );
	}

	public function test_a_zero_use_cap_is_rejected(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		static::assertSame( 400, $this->create_link( $post_id, HOUR_IN_SECONDS, 0 )->get_status() );
	}

	private function create_link( int $post_id, int $expiration, ?int $max_uses = null ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', self::ROUTE );
		$request->set_body_params(
			[
				'post_id'    => $post_id,
				'expiration' => $expiration,
				'max_uses'   => $max_uses,
			]
		);

		return rest_do_request( $request );
	}
}
