<?php
/**
 * Installation and boot safety tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Integration;

use McLogiora\Core\Application;
use McLogiora\Core\RuntimeReadiness;
use McLogiora\Database\Installer;
use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageRepositoryInterface;
use McLogiora\Languages\LanguageStatus;
use McLogiora\Routing\FrontendTranslationModule;
use McLogiora\Routing\LanguageContextInterface;
use McLogiora\Strings\StringTranslationService;
use McLogiora\Switcher\SwitcherModule;
use McLogiora\Tests\Support\ArrayCache;
use McLogiora\Tests\Support\FakeStringRepository;
use WP_UnitTestCase;

/**
 * Proves mcLogiora can be present while WordPress builds itself.
 *
 * Phase 12 shipped a `gettext` filter whose guard called `is_preview()`.
 * Before WordPress creates the main query that emits a `_doing_it_wrong()`
 * notice, whose message is built with `__()`, which re-enters the filter. The
 * recursion consumed the machine and killed the test runner before PHPUnit
 * printed a single character. Every test here exists to make that class of
 * failure loud and immediate instead of fatal and mute.
 */
final class InstallationSafetyTest extends WP_UnitTestCase {
	/**
	 * Service container.
	 *
	 * @var \McLogiora\Core\Container
	 */
	private $container;

	/**
	 * Sets up a fully installed, two-language site.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->container = Application::instance( dirname( __DIR__, 2 ) . '/mclogiora.php' )->container();

		delete_option( 'mclogiora_db_version' );
		$this->container->get( Installer::class )->install();
		$this->container->get( RuntimeReadiness::class )->reset();

		$languages = $this->container->get( LanguageRepositoryInterface::class );

		if ( ! $languages->find_by_code( 'en' ) instanceof Language ) {
			$languages->create( new Language( 'en', 'en_US', 'English', 'English', 'ltr', LanguageStatus::ACTIVE, 0, false ) );
			$languages->set_default( 'en' );
		}

		if ( ! $languages->find_by_code( 'tr' ) instanceof Language ) {
			$languages->create( new Language( 'tr', 'tr_TR', 'Turkce', 'Turkish', 'ltr', LanguageStatus::ACTIVE, 1, false ) );
		}

		$context = $this->container->get( LanguageContextInterface::class );
		$context->reset();
		$context->set_requested_code( '' );
	}

	/**
	 * Restores anything a test deliberately broke.
	 *
	 * @return void
	 */
	public function tear_down() {
		wp_installing( false );
		$this->container->get( RuntimeReadiness::class )->reset();

		parent::tear_down();
	}

	/**
	 * Asserts WordPress installed and reached PHPUnit with the plugin loaded.
	 *
	 * The integration suite installs WordPress from scratch on every run with
	 * mcLogiora active. That installation *is* the regression test; this makes
	 * the proof explicit so a future failure names itself instead of appearing
	 * as a silent runner death.
	 *
	 * @return void
	 */
	public function test_wordpress_installed_with_the_plugin_present() {
		global $wpdb;

		$this->assertTrue( class_exists( Application::class ), 'The plugin must have loaded.' );
		$this->assertGreaterThan( 0, did_action( 'plugins_loaded' ), 'Plugin bootstrap must have run.' );
		$this->assertGreaterThan( 0, did_action( 'init' ), 'WordPress must have reached init.' );
		$this->assertGreaterThan( 0, did_action( 'wp_loaded' ), 'WordPress must have finished loading.' );

		foreach ( array( 'posts', 'options', 'users', 'term_taxonomy' ) as $table ) {
			$name = $wpdb->{$table};

			$this->assertSame(
				$name,
				$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ), // phpcs:ignore WordPress.DB -- schema assertion.
				"Core table {$name} must exist."
			);
		}

		$this->assertNotEmpty( get_option( 'siteurl' ), 'A completed installation stores a site URL.' );
	}

	/**
	 * Asserts translating a string cannot recurse before the query exists.
	 *
	 * This is the original blocker, reduced to an assertion. Against the
	 * unfixed code the depth guard trips on the first call; against the fixed
	 * code the filter declines immediately because the main query is absent.
	 *
	 * @return void
	 */
	public function test_translation_does_not_recurse_before_the_main_query_exists() {
		$depth   = 0;
		$maximum = 0;

		$enter = static function ( $translation ) use ( &$depth, &$maximum ) {
			++$depth;
			$maximum = max( $maximum, $depth );

			if ( $depth > 5 ) {
				throw new \RuntimeException( 'The gettext filter re-entered itself.' );
			}

			return $translation;
		};

		$leave = static function ( $translation ) use ( &$depth ) {
			--$depth;

			return $translation;
		};

		add_filter( 'gettext', $enter, -PHP_INT_MAX );
		add_filter( 'gettext', $leave, PHP_INT_MAX );

		$saved = $GLOBALS['wp_query'];
		unset( $GLOBALS['wp_query'] );

		try {
			$result = __( 'A string translated before the query exists.', 'mclogiora' );
			$url    = home_url( '/somewhere/' );
		} finally {
			$GLOBALS['wp_query'] = $saved;
			remove_filter( 'gettext', $enter, -PHP_INT_MAX );
			remove_filter( 'gettext', $leave, PHP_INT_MAX );
		}

		$this->assertSame( 'A string translated before the query exists.', $result );
		$this->assertStringContainsString( 'somewhere', $url );
		$this->assertLessThanOrEqual( 1, $maximum, 'The gettext filter must never nest inside itself.' );
	}

	/**
	 * Asserts the runtime refuses to work while WordPress is installing.
	 *
	 * @return void
	 */
	public function test_runtime_is_disabled_while_wordpress_is_installing() {
		$readiness = $this->container->get( RuntimeReadiness::class );
		$readiness->reset();

		wp_installing( true );

		$this->assertTrue( $readiness->is_installing() );
		$this->assertFalse( $readiness->is_schema_ready(), 'Installation must not report a usable schema.' );
		$this->assertFalse( $readiness->is_frontend_runtime() );
	}

	/**
	 * Asserts installation never reaches the string repository.
	 *
	 * A spy proves absence of work, which is what "install-safe" means. A test
	 * that only checked the returned string would pass even if every lookup
	 * still ran and simply returned the original.
	 *
	 * @return void
	 */
	public function test_installation_performs_no_string_lookups() {
		$spy = $this->install_string_repository_spy();

		wp_installing( true );
		$this->container->get( RuntimeReadiness::class )->reset();

		try {
			$translated = __( 'Some interface string', 'mclogiora' );
		} finally {
			wp_installing( false );
			$this->restore_string_service();
		}

		$this->assertSame( 'Some interface string', $translated, 'Installation must return the original string.' );
		$this->assertSame( 0, $spy->lookup_count(), 'Installation must not query the string store at all.' );
	}

	/**
	 * Asserts front-end translation modules register nothing during install.
	 *
	 * @return void
	 */
	public function test_frontend_modules_register_no_hooks_during_installation() {
		wp_installing( true );
		$this->container->get( RuntimeReadiness::class )->reset();

		$before_gettext   = $this->count_callbacks( 'gettext' );
		$before_shortcode = shortcode_exists( SwitcherModule::SHORTCODE );

		$translation = new FrontendTranslationModule();
		$translation->register( $this->container );

		$switcher = new SwitcherModule();
		$switcher->register( $this->container );

		wp_installing( false );

		$this->assertSame(
			$before_gettext,
			$this->count_callbacks( 'gettext' ),
			'No gettext callback may be added while WordPress is installing.'
		);
		$this->assertSame(
			$before_shortcode,
			shortcode_exists( SwitcherModule::SHORTCODE ),
			'The switcher shortcode must not be registered during installation.'
		);
	}

	/**
	 * Asserts a missing schema falls back to the original string.
	 *
	 * @return void
	 */
	public function test_missing_schema_falls_back_to_the_original_string() {
		$spy = $this->install_string_repository_spy();

		delete_option( 'mclogiora_db_version' );
		$this->container->get( RuntimeReadiness::class )->reset();

		try {
			$translated = __( 'Another interface string', 'mclogiora' );
		} finally {
			$this->restore_string_service();
		}

		$this->assertSame( 'Another interface string', $translated );
		$this->assertSame( 0, $spy->lookup_count(), 'A missing schema must not be queried.' );
	}

	/**
	 * Asserts plural translation is deliberately left to WordPress.
	 *
	 * Phase 12 translates singular strings only. Plural forms need per-locale
	 * plural rules that mcLogiora's string store does not model, and guessing
	 * them would produce confidently wrong grammar.
	 *
	 * @return void
	 */
	public function test_plural_translation_is_left_untouched() {
		$this->assertFalse( has_filter( 'ngettext' ), 'mcLogiora must not filter plural forms.' );
		$this->assertFalse( has_filter( 'ngettext_with_context' ) );
		$this->assertSame( 'many items', _n( 'one item', 'many items', 2, 'mclogiora' ) );
	}

	/**
	 * Asserts switcher registration resolves nothing and queries nothing.
	 *
	 * Registration happens on every request, including ones that never render
	 * a switcher. It must therefore cost nothing: no language lookup, no
	 * relation lookup, and no URL generation.
	 *
	 * @return void
	 */
	public function test_switcher_registration_touches_no_data() {
		global $wpdb;

		/*
		 * Registering the block asks WordPress to translate its title, which
		 * legitimately travels through the site's own gettext pipeline. That
		 * pipeline is a separate subsystem with its own tests, so it is lifted
		 * out here to leave only the switcher's own cost being measured.
		 */
		remove_all_filters( 'gettext' );
		remove_all_filters( 'gettext_with_context' );

		$registry = \WP_Block_Type_Registry::get_instance();

		if ( $registry->is_registered( 'mclogiora/language-switcher' ) ) {
			unregister_block_type( 'mclogiora/language-switcher' );
		}

		$module = new SwitcherModule();

		$before = $wpdb->num_queries;

		$module->register( $this->container );
		$module->register_block();
		$module->register_widget();

		$this->assertSame(
			$before,
			$wpdb->num_queries,
			'Registering switcher surfaces must resolve no language, relation, or URL.'
		);

		$this->assertTrue( shortcode_exists( SwitcherModule::SHORTCODE ) );
		$this->assertTrue( $registry->is_registered( 'mclogiora/language-switcher' ) );
	}

	/**
	 * Asserts the readiness gate itself performs no query per call.
	 *
	 * The lifecycle fix sits in front of every `gettext` call on the site. If
	 * it asked the database whether the schema exists each time, the cure
	 * would be worse than the disease.
	 *
	 * @return void
	 */
	public function test_readiness_gate_costs_no_queries_once_warm() {
		global $wpdb;

		$readiness = $this->container->get( RuntimeReadiness::class );

		$this->assertTrue( $readiness->is_frontend_runtime(), 'The fixture is an ordinary ready front-end request.' );

		$before = $wpdb->num_queries;

		for ( $i = 0; $i < 50; $i++ ) {
			$readiness->is_frontend_runtime();
		}

		$this->assertSame( $before, $wpdb->num_queries, 'The readiness gate must be a pure in-memory check.' );
	}

	/**
	 * Replaces the container's string service with a spy-backed one.
	 *
	 * @return FakeStringRepository
	 */
	private function install_string_repository_spy() {
		$spy = new FakeStringRepository();

		$this->container->set(
			StringTranslationService::class,
			new StringTranslationService( $spy, new ArrayCache() )
		);

		$module = new FrontendTranslationModule();
		$module->register( $this->container );

		return $spy;
	}

	/**
	 * Restores the real string translation service.
	 *
	 * @return void
	 */
	private function restore_string_service() {
		$container = $this->container;

		$this->container->set(
			StringTranslationService::class,
			static function () use ( $container ) {
				return new StringTranslationService(
					$container->get( \McLogiora\Strings\StringRepositoryInterface::class ),
					$container->get( \McLogiora\Cache\CacheInterface::class )
				);
			}
		);
	}

	/**
	 * Counts the callbacks registered on a hook.
	 *
	 * @param string $hook Hook name.
	 * @return int
	 */
	private function count_callbacks( $hook ) {
		global $wp_filter;

		if ( ! isset( $wp_filter[ $hook ] ) ) {
			return 0;
		}

		$total = 0;

		foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
			$total += count( $callbacks );
		}

		return $total;
	}
}
