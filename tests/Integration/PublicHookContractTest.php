<?php
/**
 * Public hook contract tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Integration;

use McLogiora\Capabilities\CapabilityRegistry;
use McLogiora\Core\Activation;
use McLogiora\Core\Application;
use McLogiora\Core\Deactivation;
use McLogiora\Core\InstallationFailure;
use McLogiora\Core\RuntimeReadiness;
use McLogiora\Database\Installer;
use McLogiora\Database\SchemaBuilder;
use McLogiora\Database\TableNames;
use McLogiora\Editors\Payload\PayloadAdapterRegistry;
use McLogiora\Editors\Payload\TranslationPayloadAdapterInterface;
use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageRepositoryInterface;
use McLogiora\Languages\LanguageStatus;
use McLogiora\Routing\LanguageContextInterface;
use McLogiora\Routing\RoutingSettings;
use McLogiora\Seo\AlternateUrlService;
use McLogiora\Seo\SeoConcern;
use McLogiora\Seo\SeoContext;
use McLogiora\Seo\SeoModule;
use McLogiora\Seo\SeoSubject;
use McLogiora\Switcher\SwitcherModule;
use McLogiora\Widgets\WidgetAdapterInterface;
use McLogiora\Widgets\WidgetAdapterRegistry;
use WP_UnitTestCase;

/**
 * Qualifies the hooks promoted to the public developer API.
 *
 * A hook is only a contract if its lifecycle position, its arguments, and what
 * happens to its return value are all pinned down. These tests are that pin.
 * Each promoted hook is proven three ways: it receives what the documentation
 * says it receives, its return is used the way the documentation says it is,
 * and the behaviour with no consumer attached is unchanged.
 *
 * The unfiltered capability default is asserted here too. That hook is
 * deliberately *not* public, and the test protects the decision rather than the
 * hook: it fails if the baseline every admin screen checks is ever weakened.
 */
final class PublicHookContractTest extends WP_UnitTestCase {
	/**
	 * Service container.
	 *
	 * @var \McLogiora\Core\Container
	 */
	private $container;

	/**
	 * Callbacks attached by the running test, as hook => callback pairs.
	 *
	 * @var array<int,array{0:string,1:callable}>
	 */
	private $attached = array();

	/**
	 * Sets up an installed, two-language site with pretty permalinks.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->container = Application::instance( dirname( __DIR__, 2 ) . '/mclogiora.php' )->container();

		InstallationFailure::clear();

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

		delete_option( RoutingSettings::OPTION_NAME );

		if ( '/%postname%/' !== get_option( 'permalink_structure' ) ) {
			$this->set_permalink_structure( '/%postname%/' );
		}

		create_initial_taxonomies();

		$context = $this->container->get( LanguageContextInterface::class );
		$context->reset();
		$context->set_requested_code( '' );

		$this->container->get( AlternateUrlService::class )->reset();
		$this->container->get( SeoContext::class )->reset();
	}

	/**
	 * Detaches every callback the test attached.
	 *
	 * A consumer left attached would silently become part of the next test's
	 * "no consumer" baseline, which is the one thing these tests must measure
	 * honestly.
	 *
	 * @return void
	 */
	public function tear_down() {
		foreach ( $this->attached as $pair ) {
			remove_filter( $pair[0], $pair[1], 10 );
		}

		$this->attached = array();

		$this->container->get( LanguageContextInterface::class )->set_requested_code( '' );
		$this->container->get( AlternateUrlService::class )->reset();
		$this->container->get( SeoContext::class )->reset();

		parent::tear_down();
	}

	/**
	 * Attaches a callback and records it for removal.
	 *
	 * @param string   $hook Hook name.
	 * @param callable $callback Callback.
	 * @param int      $accepted_args Accepted argument count.
	 * @return void
	 */
	private function on( $hook, callable $callback, $accepted_args = 1 ) {
		add_filter( $hook, $callback, 10, $accepted_args );

		$this->attached[] = array( $hook, $callback );
	}

	/* --------------------------------------------------------------------
	 * mclogiora_activated / mclogiora_deactivated
	 * ----------------------------------------------------------------- */

	/**
	 * Asserts activation fires once, last, with the install result.
	 *
	 * @return void
	 */
	public function test_activated_action_fires_once_with_the_install_result() {
		$calls = array();

		$this->on(
			'mclogiora_activated',
			static function ( $installed ) use ( &$calls ) {
				$calls[] = $installed;
			}
		);

		Activation::activate();

		$this->assertCount( 1, $calls, 'The activation action fires exactly once per activation.' );
		$this->assertTrue( $calls[0], 'A successful schema install passes true.' );
	}

	/**
	 * Asserts the schema exists by the time the activation action fires.
	 *
	 * The documented promise is that this runs last: a consumer seeding data
	 * must find the tables already there.
	 *
	 * @return void
	 */
	public function test_activated_action_fires_after_the_schema_is_installed() {
		delete_option( 'mclogiora_db_version' );

		$observed = null;
		$tables   = $this->container->get( TableNames::class );
		$schema   = $this->container->get( SchemaBuilder::class );

		$this->on(
			'mclogiora_activated',
			static function () use ( &$observed, $tables, $schema ) {
				$observed = $schema->table_exists( $tables->languages() );
			}
		);

		Activation::activate();

		$this->assertTrue( $observed, 'The languages table must exist when the action fires.' );
	}

	/**
	 * Asserts deactivation fires once and deletes nothing.
	 *
	 * @return void
	 */
	public function test_deactivated_action_fires_once_and_removes_no_data() {
		$languages = $this->container->get( LanguageRepositoryInterface::class );
		$before    = count( $languages->all() );
		$calls     = 0;

		$this->on(
			'mclogiora_deactivated',
			static function () use ( &$calls ) {
				++$calls;
			}
		);

		Deactivation::deactivate();

		$this->assertSame( 1, $calls );
		$this->assertSame( $before, count( $languages->all() ), 'Deactivation must not remove data.' );
		$this->assertNotFalse( get_option( 'mclogiora_db_version' ), 'Deactivation must not drop the schema version.' );
	}

	/* --------------------------------------------------------------------
	 * mclogiora_widget_adapters
	 * ----------------------------------------------------------------- */

	/**
	 * Asserts the filter receives the core adapters.
	 *
	 * @return void
	 */
	public function test_widget_adapter_filter_receives_the_core_adapters() {
		$received = null;

		$this->on(
			'mclogiora_widget_adapters',
			static function ( $adapters ) use ( &$received ) {
				$received = $adapters;

				return $adapters;
			}
		);

		WidgetAdapterRegistry::with_core_adapters();

		$this->assertIsArray( $received );
		$this->assertNotEmpty( $received );

		foreach ( $received as $adapter ) {
			$this->assertInstanceOf( WidgetAdapterInterface::class, $adapter );
		}
	}

	/**
	 * Asserts a third-party adapter is registered and used.
	 *
	 * @return void
	 */
	public function test_widget_adapter_filter_registers_a_third_party_adapter() {
		$this->on(
			'mclogiora_widget_adapters',
			function ( $adapters ) {
				$adapters[] = $this->widget_adapter();

				return $adapters;
			}
		);

		$registry = WidgetAdapterRegistry::with_core_adapters();

		$this->assertTrue( $registry->supports( 'mclogiora_test_widget' ) );
		$this->assertSame( 'mclogiora_test', $registry->for_type( 'mclogiora_test_widget' )->id() );
	}

	/**
	 * Asserts entries that are not adapters are ignored rather than fatal.
	 *
	 * @return void
	 */
	public function test_widget_adapter_filter_ignores_entries_that_are_not_adapters() {
		$baseline = count( WidgetAdapterRegistry::with_core_adapters()->all() );

		$this->on(
			'mclogiora_widget_adapters',
			static function ( $adapters ) {
				$adapters[] = new \stdClass();
				$adapters[] = 'not an adapter';

				return $adapters;
			}
		);

		$this->assertCount( $baseline, WidgetAdapterRegistry::with_core_adapters()->all() );
	}

	/**
	 * Asserts a non-array return leaves the core adapters in place.
	 *
	 * @return void
	 */
	public function test_widget_adapter_filter_falls_back_when_a_non_array_is_returned() {
		$baseline = count( WidgetAdapterRegistry::with_core_adapters()->all() );

		$this->on(
			'mclogiora_widget_adapters',
			static function () {
				return 'nonsense';
			}
		);

		$this->assertCount( $baseline, WidgetAdapterRegistry::with_core_adapters()->all() );
	}

	/* --------------------------------------------------------------------
	 * mclogiora_register_payload_adapters
	 * ----------------------------------------------------------------- */

	/**
	 * Asserts the filter receives an empty array and the registry.
	 *
	 * @return void
	 */
	public function test_payload_adapter_filter_receives_an_empty_array_and_the_registry() {
		$value    = 'unset';
		$registry = null;

		$this->on(
			'mclogiora_register_payload_adapters',
			static function ( $extra, $passed ) use ( &$value, &$registry ) {
				$value    = $extra;
				$registry = $passed;

				return $extra;
			},
			2
		);

		PayloadAdapterRegistry::with_core_adapters();

		$this->assertSame( array(), $value, 'The filter is additive: it always starts empty.' );
		$this->assertInstanceOf( PayloadAdapterRegistry::class, $registry );
	}

	/**
	 * Asserts a third-party payload adapter is registered.
	 *
	 * @return void
	 */
	public function test_payload_adapter_filter_registers_a_third_party_adapter() {
		$this->on(
			'mclogiora_register_payload_adapters',
			function ( $extra ) {
				$extra[] = $this->payload_adapter();

				return $extra;
			}
		);

		$ids = array();

		foreach ( PayloadAdapterRegistry::with_core_adapters()->all() as $adapter ) {
			$ids[] = $adapter->id();
		}

		$this->assertContains( 'mclogiora_test_payload', $ids );
	}

	/**
	 * Asserts the core adapters cannot be removed through the filter.
	 *
	 * @return void
	 */
	public function test_payload_adapter_filter_cannot_remove_the_core_adapters() {
		$baseline = count( PayloadAdapterRegistry::with_core_adapters()->all() );

		$this->on(
			'mclogiora_register_payload_adapters',
			static function () {
				return array();
			}
		);

		$this->assertCount( $baseline, PayloadAdapterRegistry::with_core_adapters()->all() );
		$this->assertGreaterThan( 0, $baseline );
	}

	/**
	 * Asserts junk entries and non-array returns are ignored.
	 *
	 * @return void
	 */
	public function test_payload_adapter_filter_ignores_invalid_returns() {
		$baseline = count( PayloadAdapterRegistry::with_core_adapters()->all() );

		$this->on(
			'mclogiora_register_payload_adapters',
			static function () {
				return array( new \stdClass(), 42 );
			}
		);

		$this->assertCount( $baseline, PayloadAdapterRegistry::with_core_adapters()->all() );

		$this->on(
			'mclogiora_register_payload_adapters',
			static function () {
				return null;
			}
		);

		$this->assertCount( $baseline, PayloadAdapterRegistry::with_core_adapters()->all() );
	}

	/* --------------------------------------------------------------------
	 * mclogiora_switcher_flag
	 * ----------------------------------------------------------------- */

	/**
	 * Asserts no flag is shown by default.
	 *
	 * @return void
	 */
	public function test_switcher_shows_no_flag_without_a_consumer() {
		$with_flags = $this->switcher_html();
		$no_flags   = $this->switcher_html( array( 'show_flag' => false ) );

		$this->assertStringContainsString( 'English', $with_flags );
		$this->assertSame(
			$no_flags,
			$with_flags,
			'With no consumer, asking for flags must produce exactly the markup of asking for none.'
		);
	}

	/**
	 * Asserts the filter receives the language code and its return is shown.
	 *
	 * @return void
	 */
	public function test_switcher_flag_filter_receives_the_language_code() {
		$codes = array();

		$this->on(
			'mclogiora_switcher_flag',
			static function ( $flag, $code ) use ( &$codes ) {
				$codes[] = $code;

				return 'FLAG-' . $code;
			},
			2
		);

		$html = $this->switcher_html();

		$this->assertContains( 'en', $codes );
		$this->assertContains( 'tr', $codes );
		$this->assertStringContainsString( 'FLAG-en', $html );
		$this->assertStringContainsString( 'FLAG-tr', $html );
	}

	/**
	 * Asserts the flag filter is not an HTML injection point.
	 *
	 * The returned value is escaped with esc_html() before it reaches the
	 * page, so markup is displayed literally. Escaping is mcLogiora's
	 * responsibility, and this is the assertion that keeps it so.
	 *
	 * @return void
	 */
	public function test_switcher_flag_filter_output_is_escaped_by_mclogiora() {
		$this->on(
			'mclogiora_switcher_flag',
			static function () {
				return '<script>alert(1)</script>';
			},
			2
		);

		$html = $this->switcher_html();

		$this->assertStringNotContainsString( '<script>', $html, 'A filtered flag must never render as markup.' );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	/**
	 * Asserts an empty return shows no flag.
	 *
	 * @return void
	 */
	public function test_switcher_flag_filter_can_opt_out_per_language() {
		$this->on(
			'mclogiora_switcher_flag',
			static function ( $flag, $code ) {
				return 'tr' === $code ? 'TRFLAG' : '';
			},
			2
		);

		$html = $this->switcher_html();

		$this->assertStringContainsString( 'TRFLAG', $html );
		$this->assertSame( 1, substr_count( $html, 'FLAG' ) );
	}

	/* --------------------------------------------------------------------
	 * SEO hooks
	 * ----------------------------------------------------------------- */

	/**
	 * Asserts the ownership filter receives every concern mcLogiora emits.
	 *
	 * @return void
	 */
	public function test_seo_owns_concern_filter_receives_known_concerns() {
		$seen = array();

		$this->on(
			'mclogiora_seo_owns_concern',
			static function ( $owns, $concern ) use ( &$seen ) {
				$seen[] = $concern;

				return $owns;
			},
			2
		);

		$this->head_for_translated_page();

		$this->assertContains( SeoConcern::HREFLANG, $seen );

		foreach ( $seen as $concern ) {
			$this->assertContains( $concern, SeoConcern::all(), 'Only known concern identifiers are passed.' );
		}
	}

	/**
	 * Asserts returning false for hreflang removes the annotations.
	 *
	 * @return void
	 */
	public function test_seo_owns_concern_filter_can_suppress_hreflang() {
		$this->assertStringContainsString( 'hreflang', $this->head_for_translated_page() );

		$this->on(
			'mclogiora_seo_owns_concern',
			static function ( $owns, $concern ) {
				return SeoConcern::HREFLANG === $concern ? false : $owns;
			},
			2
		);

		$this->assertStringNotContainsString( 'hreflang', $this->head_for_translated_page() );
	}

	/**
	 * Asserts the OpenGraph locale filter switches the tag off.
	 *
	 * @return void
	 */
	public function test_open_graph_locale_filter_switches_the_tag_off() {
		$this->assertStringContainsString( 'og:locale', $this->head_for_translated_page() );

		$this->on(
			'mclogiora_seo_output_open_graph_locale',
			static function () {
				return false;
			}
		);

		$this->assertStringNotContainsString( 'og:locale', $this->head_for_translated_page() );
	}

	/**
	 * Asserts the x-default filter receives a URL and a usable subject.
	 *
	 * Only the four documented accessors are exercised, because only those four
	 * are contract.
	 *
	 * @return void
	 */
	public function test_x_default_filter_receives_a_url_and_the_subject() {
		$url     = 'unset';
		$subject = null;

		$this->on(
			'mclogiora_seo_x_default_url',
			static function ( $value, $passed ) use ( &$url, &$subject ) {
				$url     = $value;
				$subject = $passed;

				return $value;
			},
			2
		);

		$head = $this->head_for_translated_page();

		$this->assertIsString( $url );
		$this->assertInstanceOf( SeoSubject::class, $subject );
		$this->assertContains( $subject->kind(), array( 'post', 'term', SeoSubject::HOME ) );
		$this->assertIsInt( $subject->object_id() );
		$this->assertIsString( $subject->taxonomy() );
		$this->assertIsBool( $subject->is_home() );
		$this->assertStringContainsString( 'x-default', $head );
	}

	/**
	 * Asserts an empty x-default return omits the annotation.
	 *
	 * @return void
	 */
	public function test_x_default_filter_can_omit_the_annotation() {
		$this->on(
			'mclogiora_seo_x_default_url',
			static function () {
				return '';
			},
			2
		);

		$this->assertStringNotContainsString( 'x-default', $this->head_for_translated_page() );
	}

	/**
	 * Asserts a non-string x-default return is treated as empty, not printed.
	 *
	 * @return void
	 */
	public function test_x_default_filter_treats_a_non_string_as_empty() {
		$this->on(
			'mclogiora_seo_x_default_url',
			static function () {
				return array( 'not', 'a', 'url' );
			},
			2
		);

		$this->assertStringNotContainsString( 'x-default', $this->head_for_translated_page() );
	}

	/**
	 * Asserts the canonical filter is consulted for non-singular requests.
	 *
	 * Singular requests never reach it: WordPress core prints their canonical.
	 *
	 * @return void
	 */
	public function test_canonical_filter_covers_non_singular_requests_only() {
		$received = array();

		$this->on(
			'mclogiora_seo_canonical_url',
			static function ( $url, $subject, $language ) use ( &$received ) {
				$received[] = array( $url, $language );

				return $url;
			},
			3
		);

		$this->head_for_translated_page();

		$this->assertSame( array(), $received, 'A singular request leaves the canonical to WordPress core.' );

		$this->activate_routing();
		$this->go_to( home_url( '/tr/' ) );
		$this->head();

		$this->assertNotEmpty( $received, 'A non-singular request consults the filter.' );
		$this->assertIsString( $received[0][0] );
		$this->assertSame( 'tr', $received[0][1], 'The current language code is passed.' );
	}

	/**
	 * Asserts an empty canonical return suppresses the tag.
	 *
	 * @return void
	 */
	public function test_canonical_filter_can_suppress_the_tag() {
		$this->activate_routing();
		$this->go_to( home_url( '/tr/' ) );

		$this->assertStringContainsString( 'rel="canonical"', $this->head() );

		$this->on(
			'mclogiora_seo_canonical_url',
			static function () {
				return '';
			},
			3
		);

		$this->go_to( home_url( '/tr/' ) );

		$this->assertStringNotContainsString( 'rel="canonical"', $this->head() );
	}

	/* --------------------------------------------------------------------
	 * Deliberately not public
	 * ----------------------------------------------------------------- */

	/**
	 * Asserts the unfiltered capability baseline stays `manage_options`.
	 *
	 * This protects a decision rather than a hook. `mclogiora_resolved_capability`
	 * is not a supported extension point precisely because everything the plugin
	 * guards checks whatever it returns. The value that matters is therefore the
	 * one with no consumer attached, and it must not weaken.
	 *
	 * @return void
	 */
	public function test_the_unfiltered_capability_baseline_is_manage_options() {
		$registry = new CapabilityRegistry();

		$this->assertNotEmpty( $registry->all() );

		foreach ( $registry->all() as $planned ) {
			$this->assertSame( 'manage_options', $registry->resolve( $planned ) );
		}
	}

	/* --------------------------------------------------------------------
	 * Helpers
	 * ----------------------------------------------------------------- */

	/**
	 * Returns a widget adapter double.
	 *
	 * @return WidgetAdapterInterface
	 */
	private function widget_adapter() {
		return new class() implements WidgetAdapterInterface {
			/**
			 * {@inheritDoc}
			 *
			 * @return string
			 */
			public function id() {
				return 'mclogiora_test';
			}

			/**
			 * {@inheritDoc}
			 *
			 * @return string
			 */
			public function label() {
				return 'Test widget';
			}

			/**
			 * {@inheritDoc}
			 *
			 * @param string $widget_type Widget base identifier.
			 * @return bool
			 */
			public function supports( $widget_type ) {
				return 'mclogiora_test_widget' === $widget_type;
			}

			/**
			 * {@inheritDoc}
			 *
			 * @return array<string,string>
			 */
			public function translatable_fields() {
				return array( 'title' => 'Title' );
			}

			/**
			 * {@inheritDoc}
			 *
			 * @param array<string,mixed> $instance Widget instance options.
			 * @return array<string,string>
			 */
			public function extract( array $instance ) {
				return isset( $instance['title'] ) ? array( 'title' => (string) $instance['title'] ) : array();
			}

			/**
			 * {@inheritDoc}
			 *
			 * @param array<string,mixed>  $instance Widget instance options.
			 * @param array<string,string> $translated Translated field values.
			 * @return array<string,mixed>
			 */
			public function apply( array $instance, array $translated ) {
				return array_merge( $instance, $translated );
			}
		};
	}

	/**
	 * Returns a payload adapter double that never acts.
	 *
	 * @return TranslationPayloadAdapterInterface
	 */
	private function payload_adapter() {
		return new class() implements TranslationPayloadAdapterInterface {
			/**
			 * {@inheritDoc}
			 *
			 * @return string
			 */
			public function id() {
				return 'mclogiora_test_payload';
			}

			/**
			 * {@inheritDoc}
			 *
			 * @return bool
			 */
			public function is_available() {
				return false;
			}

			/**
			 * {@inheritDoc}
			 *
			 * @param int $source_id Source post identifier.
			 * @return bool
			 */
			public function applies_to( $source_id ) {
				unset( $source_id );

				return false;
			}

			/**
			 * {@inheritDoc}
			 *
			 * @param int $source_id Source post identifier.
			 * @param int $target_id Newly created translation identifier.
			 * @return true|\WP_Error
			 */
			public function copy( $source_id, $target_id ) {
				unset( $source_id, $target_id );

				return true;
			}
		};
	}

	/**
	 * Renders a switcher with flags switched on.
	 *
	 * @param array<string,mixed> $overrides Instance overrides.
	 * @return string
	 */
	private function switcher_html( array $overrides = array() ) {
		$module = $this->container->get( SwitcherModule::class );
		$module->register( $this->container );

		return $module->render(
			array_merge(
				array(
					'show_flag'    => true,
					'show_name'    => true,
					'show_current' => true,
					'missing'      => 'home',
				),
				$overrides
			)
		);
	}

	/**
	 * Registers routing and persists the language rewrite rules.
	 *
	 * @return void
	 */
	private function activate_routing() {
		$module = new \McLogiora\Routing\RoutingModule();
		$module->register( $this->container );
		$module->register_rewrite_rules();
		$module->maybe_flush_rewrite_rules();
	}

	/**
	 * Returns the head output for a translated page request.
	 *
	 * @return string
	 */
	private function head_for_translated_page() {
		$source = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => 'About us',
				'post_name'   => 'about-us-' . wp_rand( 1000, 99999 ),
				'post_status' => 'publish',
			)
		);

		$created = $this->container->get( \McLogiora\Workflows\TranslationWorkflowService::class )
			->content()
			->create_translation( $source, 'tr' );

		$this->assertIsArray( $created, is_wp_error( $created ) ? $created->get_error_message() : '' );

		wp_update_post(
			array(
				'ID'          => $created['post_id'],
				'post_status' => 'publish',
			)
		);

		$this->activate_routing();
		$this->go_to( get_permalink( $created['post_id'] ) );

		return $this->head();
	}

	/**
	 * Returns the SEO head block for the current request.
	 *
	 * @return string
	 */
	private function head() {
		$this->container->get( SeoContext::class )->reset();
		$this->container->get( AlternateUrlService::class )->reset();

		$module = new SeoModule();
		$module->register( $this->container );

		ob_start();
		$module->render();

		return (string) ob_get_clean();
	}
}
