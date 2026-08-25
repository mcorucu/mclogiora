<?php
/**
 * Registered admin screen smoke coverage.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Integration;

use McLogiora\Admin\AdminScreenRegistry;
use McLogiora\Admin\AdminMenu;
use McLogiora\Core\Application;
use McLogiora\Core\RuntimeReadiness;
use McLogiora\Database\Installer;
use WP_UnitTestCase;

/**
 * Confirms every registered mcLogiora page renders its primary heading.
 */
final class AdminScreenSmokeTest extends WP_UnitTestCase {
	/**
	 * Registered screen inventory and expected headings.
	 *
	 * @var array<string,string>
	 */
	private $screens = array(
		'mclogiora-languages'          => 'Languages',
		'mclogiora-setup'              => 'Set up mcLogiora',
		'mclogiora-translation-manager'=> 'Translation Manager',
		'mclogiora-string-translation' => 'String Translation',
		'mclogiora-menus-widgets'      => 'Menus & Widgets',
		'mclogiora-routing'            => 'Languages & URLs',
		'mclogiora-suggestions'        => 'Translation Suggestions',
		'mclogiora-compatibility'      => 'Editors and compatibility',
		'mclogiora-system-status'      => 'System Status',
	);

	/**
	 * Renders each registered screen under an administrator user.
	 *
	 * @return void
	 */
	public function test_every_registered_admin_screen_renders_without_runtime_error() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$application = Application::instance( dirname( __DIR__, 2 ) . '/mclogiora.php' );
		$container   = $application->container();
		$container->get( Installer::class )->install();
		$container->get( RuntimeReadiness::class )->reset();
		$registry = $container->get( AdminScreenRegistry::class );
		$registered = array();

		foreach ( $registry->all() as $screen ) {
			$registered[ $screen->slug() ] = $screen;
		}

		$this->assertSame( array_keys( $this->screens ), array_keys( $registered ) );

		foreach ( $this->screens as $slug => $heading ) {
			ob_start();
			call_user_func( $registered[ $slug ]->callback() );
			$html = ob_get_clean();
			$html = html_entity_decode( $html, ENT_QUOTES, 'UTF-8' );

			$this->assertStringContainsString( $heading, $html, $slug . ' did not render its expected heading.' );
			$this->assertStringNotContainsString( 'Fatal error', $html, $slug . ' rendered a fatal error.' );
		}

		$menu = new AdminMenu();
		$menu->register( $container );

		foreach ( array( 'render_dashboard' => 'mcLogiora', 'render_settings' => 'Settings' ) as $callback => $heading ) {
			ob_start();
			call_user_func( array( $menu, $callback ) );
			$html = ob_get_clean();

			$this->assertStringContainsString( $heading, $html, $callback . ' did not render its expected heading.' );
			$this->assertStringNotContainsString( 'Fatal error', $html, $callback . ' rendered a fatal error.' );
		}
	}
}
