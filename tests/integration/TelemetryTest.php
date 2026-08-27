<?php
declare(strict_types = 1);

namespace Automattic\LivePreviews;

use Automattic\VIP\Telemetry\Telemetry as VIP_Telemetry;
use WP_UnitTestCase;

/**
 * @covers \Automattic\LivePreviews\Telemetry
 */
class TelemetryTest extends WP_UnitTestCase {
	public function test_record_event_forwards_to_the_vip_client(): void {
		VIP_Telemetry::$events = [];

		Telemetry::get_instance()->record_event( 'unit_test_event', [ 'foo' => 'bar' ] );

		static::assertCount( 1, VIP_Telemetry::$events );
		static::assertSame( Telemetry::EVENT_PREFIX, VIP_Telemetry::$events[0]['prefix'] );
		static::assertSame( 'unit_test_event', VIP_Telemetry::$events[0]['event'] );
		static::assertSame( [ 'foo' => 'bar' ], VIP_Telemetry::$events[0]['properties'] );
	}

	public function test_the_source_prefix_is_a_whitelisted_tracks_source(): void {
		// The Tracks source (the token before the first underscore) must be a
		// single lowercase word whitelisted in Automattic/nosara, or events are
		// diverted to `prod_rejects`. Guard the exact value against regressions.
		static::assertSame( 'livepreviews_', Telemetry::EVENT_PREFIX );

		$source = strtok( Telemetry::EVENT_PREFIX, '_' );
		static::assertSame( 'livepreviews', $source );
		static::assertMatchesRegularExpression( '/^[a-z]+$/', (string) $source, 'The Tracks source must be a single lowercase word with no underscores.' );
	}

	public function test_singleton(): void {
		static::assertSame( Telemetry::get_instance(), Telemetry::get_instance() );
	}

	public function test_global_properties_always_carry_the_plugin_version(): void {
		$properties = self::global_properties();

		static::assertSame( VIP_LIVE_PREVIEWS_VERSION, $properties['plugin_version'] );

		// Off-platform (no VIP_GO_APP_ID) the app id is simply omitted rather
		// than sent as a null or zero.
		if ( ! defined( 'VIP_GO_APP_ID' ) ) {
			static::assertArrayNotHasKey( 'vip_app_id', $properties );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_global_properties_include_the_vip_app_id_on_platform(): void {
		if ( ! defined( 'VIP_GO_APP_ID' ) ) {
			define( 'VIP_GO_APP_ID', 1234 );
		}

		$properties = self::global_properties();

		// The environment id is an integer, matching how the platform defines it.
		static::assertSame( VIP_GO_APP_ID, $properties['vip_app_id'] );
	}

	/**
	 * Invoke the private factory that builds the client's global properties.
	 *
	 * @return array<string, mixed>
	 */
	private static function global_properties(): array {
		$method = new \ReflectionMethod( Telemetry::class, 'global_properties' );
		$method->setAccessible( true );

		/** @var array<string, mixed> $properties */
		$properties = $method->invoke( null );

		return $properties;
	}
}
