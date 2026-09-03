<?php
declare(strict_types = 1);

namespace Automattic\LivePreviews;

use Automattic\LivePreviews\Config;
use Automattic\LivePreviews\Plugin;
use ReflectionProperty;
use WP_UnitTestCase;

/**
 * @covers \Automattic\LivePreviews\Plugin
*/
class PluginTest extends WP_UnitTestCase {
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		// get_current_screen() and set_current_screen() live in wp-admin, which
		// the test bootstrap does not load.
		require_once ABSPATH . 'wp-admin/includes/admin.php';
	}

	public function set_up(): void {
		parent::set_up();

		set_current_screen( PreviewLinksAdminPage::SCREEN_ID );
	}

	public function tear_down(): void {
		// Drop any Config singleton swapped in for a test so the next test
		// re-reads the constant bootstrap.php defines.
		$this->set_config_singleton( null );
		remove_all_filters( 'live_previews_is_vip_platform' );
		set_current_screen( 'front' );
		parent::tear_down();
	}

	public function test_register_wires_hooks(): void {
		$plugin = Plugin::get_instance();

		static::assertEquals( 10, has_action( 'init', [ $plugin, 'init' ] ) );

		// The composition root wires these with injected instances, so assert a
		// callback is present rather than a specific static callable.
		static::assertNotFalse( has_action( 'rest_api_init' ) );
		static::assertNotFalse( has_action( 'posts_results' ) );
		static::assertNotFalse( has_action( 'enqueue_block_editor_assets' ) );
		static::assertNotFalse( has_filter( 'site_status_tests' ) );
	}

	/**
	 * Nothing of ours belongs in the front end of somebody's site.
	 */
	public function test_it_renders_nothing_on_the_front_end(): void {
		static::assertFalse( has_action( 'wp_footer', [ Plugin::get_instance(), 'wp_footer' ] ) );
		static::assertFalse( method_exists( Plugin::class, 'wp_footer' ) );
	}

	public function test_config_notice_lists_missing_fields(): void {
		$this->on_vip();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->set_config_singleton( new Config( [ 'api_base_url' => 'https://api.vendor.example' ] ) );

		$actual = $this->render_config_notice();

		static::assertStringContainsString( 'notice notice-warning', $actual );
		static::assertStringContainsString( 'missing required fields: api_token', $actual );
	}

	public function test_config_notice_reports_undefined_constant(): void {
		$this->on_vip();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->set_config_singleton( new Config( null ) );

		$actual = $this->render_config_notice();

		static::assertStringContainsString( 'the ' . Config::CONSTANT_NAME . ' constant is not defined', $actual );
	}

	public function test_config_notice_hidden_from_non_admins(): void {
		$this->on_vip();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$this->set_config_singleton( new Config( null ) );

		static::assertSame( '', $this->render_config_notice() );
	}

	/**
	 * The config constant is injected by the VIP Dashboard. A self-hosted site
	 * has no dashboard to complete and does not need the config, so it must not
	 * be warned about it.
	 */
	public function test_config_notice_hidden_off_vip(): void {
		$this->on_vip( false );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->set_config_singleton( new Config( null ) );

		static::assertSame( '', $this->render_config_notice() );
	}

	/**
	 * The notice belongs on the Live Previews screen, not on every admin page
	 * the site's editors happen to open.
	 *
	 * @dataProvider provide_other_screens
	 */
	public function test_config_notice_hidden_on_other_admin_screens( string $screen_id ): void {
		$this->on_vip();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->set_config_singleton( new Config( null ) );

		set_current_screen( $screen_id );

		static::assertSame( '', $this->render_config_notice() );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function provide_other_screens(): array {
		return [
			'dashboard'   => [ 'dashboard' ],
			'post editor' => [ 'post' ],
			'plugins'     => [ 'plugins' ],
			'front end'   => [ 'front' ],
		];
	}

	private function on_vip( bool $is_vip = true ): void {
		add_filter( 'live_previews_is_vip_platform', static fn (): bool => $is_vip );
	}

	private function render_config_notice(): string {
		ob_start();
		Plugin::get_instance()->render_config_notice();
		return (string) ob_get_clean();
	}

	/**
	 * @param Config|null $config
	 */
	private function set_config_singleton( ?Config $config ): void {
		$property = new ReflectionProperty( Config::class, 'instance' );
		$property->setValue( null, $config );
	}
}
