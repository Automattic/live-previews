<?php
declare(strict_types = 1);

namespace Automattic\LivePreviews;

use WP_UnitTestCase;

/**
 * @covers \Automattic\LivePreviews\Config
 */
class ConfigTest extends WP_UnitTestCase {
	private const FIXTURES_DIR = __DIR__ . '/../../fixtures';

	/**
	 * The platform defines the constant to say "this integration is on", and
	 * carries no data in it. An empty array is a complete configuration, not a
	 * half-finished one.
	 */
	public function test_the_empty_constant_the_platform_defines_is_ready(): void {
		$config = new Config( require self::FIXTURES_DIR . '/config-valid.php' );

		static::assertTrue( $config->is_available() );
		static::assertTrue( $config->is_ready() );
		static::assertSame( [], $config->missing_fields() );
	}

	public function test_unset_keys_fall_back_to_the_given_default(): void {
		$config = new Config( require self::FIXTURES_DIR . '/config-valid.php' );

		static::assertSame( 'fallback', $config->get( 'not_in_the_fixture', 'fallback' ) );
		static::assertNull( $config->get( 'not_in_the_fixture' ) );
	}

	public function test_invalid_fixture_is_not_available(): void {
		$config = new Config( require self::FIXTURES_DIR . '/config-invalid.php' );

		static::assertFalse( $config->is_available() );
		static::assertFalse( $config->is_ready() );
		static::assertSame( 'default', $config->get( 'anything', 'default' ) );
	}

	public function test_undefined_constant_is_not_available(): void {
		$config = new Config( null );

		static::assertFalse( $config->is_available() );
		static::assertFalse( $config->is_ready() );
	}

	/**
	 * Values the platform may add later must survive the round trip, even though
	 * nothing declares or requires them yet.
	 */
	public function test_it_reads_values_it_does_not_declare(): void {
		$config = new Config( [ 'ip_allowlist' => [ '203.0.113.4' ] ] );

		static::assertTrue( $config->is_ready() );
		static::assertSame( [ '203.0.113.4' ], $config->get( 'ip_allowlist' ) );
	}

	public function test_singleton_reads_the_constant(): void {
		// bootstrap.php defines the constant from the valid fixture.
		static::assertTrue( Config::get_instance()->is_ready() );
		static::assertSame( Config::get_instance(), Config::get_instance() );
	}

	public function test_for_display_stringifies_values(): void {
		$config = new Config( [
			'endpoint' => 'https://api.vendor.example',
			'retries'  => 3,
			'flags'    => [ 'a', 'b' ],
		] );

		$display = $config->for_display();

		static::assertSame( 'https://api.vendor.example', $display['endpoint'] );
		static::assertSame( '3', $display['retries'] );
		static::assertSame( '["a","b"]', $display['flags'] );
	}
}
