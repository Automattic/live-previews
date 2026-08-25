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

	public function test_listing_returns_live_links_only(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$this->create_link( $post_id, HOUR_IN_SECONDS, 5 );

		$request = new WP_REST_Request( 'GET', self::ROUTE );
		$request->set_query_params( [ 'post_id' => $post_id ] );
		$response = rest_do_request( $request );

		static::assertSame( 200, $response->get_status() );
		$data = (array) $response->get_data();
		static::assertCount( 1, $data );
		static::assertSame( 5, $data[0]['max_uses'] );
		static::assertSame( 0, $data[0]['use_count'] );
		static::assertArrayHasKey( 'id', $data[0] );
		static::assertSame( 4, strlen( (string) $data[0]['token_hint'] ), 'A 4-char token hint identifies the link.' );
	}

	public function test_expiration_options_are_filterable(): void {
		$callback = static fn (): array => [
			[
				'seconds' => 123,
				'label'   => 'Custom',
			],
		];
		add_filter( 'live_previews_expiration_options', $callback );
		$options  = PreviewRestController::expiration_options();
		remove_filter( 'live_previews_expiration_options', $callback );

		static::assertSame( 123, $options[0]['seconds'] );
	}

	public function test_default_expiration_is_filterable(): void {
		$callback = static fn (): int => 42;
		add_filter( 'live_previews_default_expiration', $callback );
		$default  = PreviewRestController::default_expiration();
		remove_filter( 'live_previews_default_expiration', $callback );

		static::assertSame( 42, $default );
	}

	public function test_a_link_can_be_revoked_and_then_denied(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->create_link( $post_id, HOUR_IN_SECONDS );

		$id = ( new PostMetaTokenRepository() )->all_for_post( $post_id )[0]->token_hash();

		$revoke = new WP_REST_Request( 'DELETE', self::ROUTE . '/' . $id );
		$revoke->set_query_params( [ 'post_id' => $post_id ] );
		static::assertSame( 200, rest_do_request( $revoke )->get_status() );

		// It no longer appears in the live list.
		$list = new WP_REST_Request( 'GET', self::ROUTE );
		$list->set_query_params( [ 'post_id' => $post_id ] );
		static::assertCount( 0, (array) rest_do_request( $list )->get_data() );
	}

	public function test_revoking_an_unknown_link_is_a_404(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$request = new WP_REST_Request( 'DELETE', self::ROUTE . '/' . str_repeat( 'a', 64 ) );
		$request->set_query_params( [ 'post_id' => $post_id ] );

		static::assertSame( 404, rest_do_request( $request )->get_status() );
	}

	public function test_listing_is_forbidden_without_edit_rights(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$request = new WP_REST_Request( 'GET', self::ROUTE );
		$request->set_query_params( [ 'post_id' => $post_id ] );

		static::assertSame( 403, rest_do_request( $request )->get_status() );
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
