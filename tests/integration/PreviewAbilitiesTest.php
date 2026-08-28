<?php
declare(strict_types = 1);

namespace Automattic\LivePreviews;

use Automattic\VIP\Telemetry\Telemetry as VIP_Telemetry;
use WP_Ability;
use WP_Error;
use WP_UnitTestCase;

/**
 * Exercises the abilities the plugin registers through its real composition
 * root: retrieving them from the registry runs the plugin's own registration
 * hooks, so these tests prove the wired ability, not a stand-in.
 *
 * @covers \Automattic\LivePreviews\PreviewAbilities
 */
class PreviewAbilitiesTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		if ( ! function_exists( 'wp_register_ability' ) ) {
			static::markTestSkipped( 'The Abilities API is unavailable on this WordPress version.' );
		}
	}

	public function test_the_category_is_registered(): void {
		// Touch the registry first so the plugin's init hooks run.
		wp_get_ability( PreviewAbilities::CREATE_LINK );

		static::assertTrue( wp_has_ability_category( PreviewAbilities::CATEGORY ) );
	}

	public function test_the_create_link_ability_is_registered_and_public(): void {
		$ability = wp_get_ability( PreviewAbilities::CREATE_LINK );

		static::assertInstanceOf( WP_Ability::class, $ability );
		static::assertSame( PreviewAbilities::CATEGORY, $ability->get_category() );

		// The single public flag is what exposes the ability to REST, MCP, and
		// the AI Client; it must also seed show_in_rest.
		static::assertTrue( $ability->get_meta_item( 'public' ) );
		$meta = $ability->get_meta();
		static::assertTrue( $meta['show_in_rest'] );
	}

	public function test_the_input_schema_advertises_the_accepted_expirations(): void {
		$ability = wp_get_ability( PreviewAbilities::CREATE_LINK );
		static::assertInstanceOf( WP_Ability::class, $ability );

		$schema     = $ability->get_input_schema();
		$properties = is_array( $schema['properties'] ?? null ) ? $schema['properties'] : [];
		$expiration = is_array( $properties['expiration'] ?? null ) ? $properties['expiration'] : [];

		// The ability offers exactly the lifetimes the REST endpoint accepts, so
		// a client cannot mint a link the REST path would reject.
		static::assertSame(
			PreviewRestController::allowed_expirations(),
			$expiration['enum'] ?? null
		);
	}

	public function test_an_editor_can_execute_the_ability_to_mint_a_link(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$ability = wp_get_ability( PreviewAbilities::CREATE_LINK );
		static::assertInstanceOf( WP_Ability::class, $ability );

		$result = $ability->execute(
			[
				'post_id'    => $post_id,
				'expiration' => 8 * HOUR_IN_SECONDS,
			]
		);

		static::assertIsArray( $result );
		static::assertStringContainsString( 'preview=true', (string) $result['url'] );
		static::assertStringContainsString( PreviewGate::TOKEN_QUERY_VAR . '=', (string) $result['url'] );
		static::assertGreaterThan( time(), (int) $result['expires_at'] );
	}

	public function test_executing_the_ability_tags_telemetry_with_the_ability_channel(): void {
		VIP_Telemetry::$events = [];

		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$ability = wp_get_ability( PreviewAbilities::CREATE_LINK );
		static::assertInstanceOf( WP_Ability::class, $ability );
		$ability->execute(
			[
				'post_id'    => $post_id,
				'expiration' => HOUR_IN_SECONDS,
			]
		);

		static::assertCount( 1, VIP_Telemetry::$events );
		static::assertSame( 'ability', VIP_Telemetry::$events[0]['properties']['channel'] );
	}

	public function test_a_user_without_edit_rights_is_denied(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$ability = wp_get_ability( PreviewAbilities::CREATE_LINK );
		static::assertInstanceOf( WP_Ability::class, $ability );

		$result = $ability->execute(
			[
				'post_id'    => $post_id,
				'expiration' => HOUR_IN_SECONDS,
			]
		);

		static::assertInstanceOf( WP_Error::class, $result );
		static::assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
