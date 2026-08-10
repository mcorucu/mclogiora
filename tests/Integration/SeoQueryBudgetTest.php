<?php
/**
 * SEO query budget tests.
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
use McLogiora\Routing\LanguageContextInterface;
use McLogiora\Routing\RoutingModule;
use McLogiora\Routing\RoutingSettings;
use McLogiora\Seo\AlternateUrlService;
use McLogiora\Seo\CanonicalService;
use McLogiora\Seo\SeoContext;
use McLogiora\Switcher\LanguageSwitcher;
use McLogiora\Workflows\TranslationWorkflowService;
use WP_UnitTestCase;

/**
 * Keeps the SEO layer from re-asking questions it has already answered.
 *
 * Canonical, hreflang, and the switcher all need the same translation group.
 * Resolved separately that is one lookup each, per page view, forever. The
 * relation group is memoised inside `TranslatedUrlGenerator`, and these tests
 * exist so that memoisation cannot be removed without something failing.
 *
 * The numbers are deliberately expressed as ceilings rather than exact counts.
 * An exact count would fail the first time WordPress changed how it caches
 * terms, which would say nothing about this plugin.
 */
final class SeoQueryBudgetTest extends WP_UnitTestCase {
	/**
	 * Service container.
	 *
	 * @var \McLogiora\Core\Container
	 */
	private $container;

	/**
	 * Sets up an installed, three-language site with a translated page.
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

		foreach ( array(
			array( 'en', 'en_US', 'English', 0 ),
			array( 'tr', 'tr_TR', 'Turkce', 1 ),
			array( 'de', 'de_DE', 'Deutsch', 2 ),
		) as $definition ) {
			if ( ! $languages->find_by_code( $definition[0] ) instanceof Language ) {
				$languages->create(
					new Language( $definition[0], $definition[1], $definition[2], $definition[2], 'ltr', LanguageStatus::ACTIVE, $definition[3], false )
				);
			}
		}

		$languages->set_default( 'en' );

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
	 * Restores the language context.
	 *
	 * @return void
	 */
	public function tear_down() {
		$this->container->get( LanguageContextInterface::class )->set_requested_code( '' );
		$this->container->get( AlternateUrlService::class )->reset();
		$this->container->get( SeoContext::class )->reset();

		parent::tear_down();
	}

	/**
	 * Creates a page translated into Turkish and German.
	 *
	 * @return int Turkish translation identifier.
	 */
	private function fully_translated_page() {
		$source = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => 'About us',
				'post_name'   => 'about-us',
				'post_status' => 'publish',
			)
		);

		$workflow = $this->container->get( TranslationWorkflowService::class )->content();
		$turkish  = $workflow->create_translation( $source, 'tr' );
		$german   = $workflow->create_translation( $source, 'de' );

		$this->assertIsArray( $turkish, is_wp_error( $turkish ) ? $turkish->get_error_message() : '' );
		$this->assertIsArray( $german, is_wp_error( $german ) ? $german->get_error_message() : '' );

		wp_update_post( array( 'ID' => $turkish['post_id'], 'post_name' => 'hakkimizda', 'post_status' => 'publish' ) );
		wp_update_post( array( 'ID' => $german['post_id'], 'post_name' => 'ueber-uns', 'post_status' => 'publish' ) );

		$module = new RoutingModule();
		$module->register( $this->container );
		$module->register_rewrite_rules();
		$module->maybe_flush_rewrite_rules();

		$this->container->get( AlternateUrlService::class )->reset();

		return (int) $turkish['post_id'];
	}

	/**
	 * Asserts the whole language group is resolved in one lookup.
	 *
	 * A three-language switcher that asked per language would be an N+1 on
	 * every page of the site.
	 *
	 * @return void
	 */
	public function test_alternates_resolve_the_group_once() {
		global $wpdb;

		$this->fully_translated_page();
		$this->go_to( home_url( '/tr/hakkimizda/' ) );

		$this->container->get( AlternateUrlService::class )->reset();
		$this->container->get( SeoContext::class )->reset();

		$subject = $this->container->get( SeoContext::class )->subject();

		$this->assertNotNull( $subject );

		$service = $this->container->get( AlternateUrlService::class );

		$before     = $wpdb->num_queries;
		$alternates = $service->alternates( $subject );
		$first_cost = $wpdb->num_queries - $before;

		$this->assertCount( 3, $alternates, 'All three languages have a translation.' );

		$before = $wpdb->num_queries;
		$service->alternates( $subject );

		$this->assertSame( $before, $wpdb->num_queries, 'A repeated request must be free.' );
		$this->assertLessThanOrEqual( 12, $first_cost, "Building three alternates cost {$first_cost} queries." );
	}

	/**
	 * Asserts canonical, alternates, and the switcher share one group lookup.
	 *
	 * @return void
	 */
	public function test_canonical_and_switcher_reuse_the_alternate_lookup() {
		global $wpdb;

		$this->fully_translated_page();
		$this->go_to( home_url( '/tr/hakkimizda/' ) );

		$this->container->get( AlternateUrlService::class )->reset();
		$this->container->get( SeoContext::class )->reset();

		$subject = $this->container->get( SeoContext::class )->subject();
		$this->container->get( AlternateUrlService::class )->alternates( $subject );

		$before = $wpdb->num_queries;

		$this->container->get( CanonicalService::class )->resolved_url();
		$this->container->get( AlternateUrlService::class )->x_default_url( $subject );
		$this->container->get( LanguageSwitcher::class )->items();

		$extra = $wpdb->num_queries - $before;

		$this->assertLessThanOrEqual( 8, $extra, "Reusing the resolved group cost {$extra} extra queries." );
	}

	/**
	 * Asserts a repeated head render costs nothing new.
	 *
	 * @return void
	 */
	public function test_repeated_head_render_costs_nothing_new() {
		global $wpdb;

		$this->fully_translated_page();
		$this->go_to( home_url( '/tr/hakkimizda/' ) );

		ob_start();
		do_action( 'wp_head' );
		ob_get_clean();

		$before = $wpdb->num_queries;

		ob_start();
		do_action( 'wp_head' );
		$second = (string) ob_get_clean();

		$cost = $wpdb->num_queries - $before;

		$this->assertStringContainsString( 'hreflang=', $second );
		$this->assertLessThanOrEqual( 4, $cost, "A second head render cost {$cost} queries." );
	}

	/**
	 * Asserts the readiness gate never queries once it is warm.
	 *
	 * It sits in front of every gettext call on the site.
	 *
	 * @return void
	 */
	public function test_readiness_gate_is_free_once_warm() {
		global $wpdb;

		$this->fully_translated_page();
		$this->go_to( home_url( '/tr/hakkimizda/' ) );

		$readiness = $this->container->get( RuntimeReadiness::class );
		$readiness->is_frontend_runtime();

		$before = $wpdb->num_queries;

		for ( $i = 0; $i < 100; $i++ ) {
			$readiness->is_frontend_runtime();
		}

		$this->assertSame( $before, $wpdb->num_queries );
	}

	/**
	 * Asserts repeated identical string lookups do not re-query.
	 *
	 * @return void
	 */
	public function test_repeated_string_lookups_do_not_requery() {
		global $wpdb;

		$this->fully_translated_page();
		$this->go_to( home_url( '/tr/hakkimizda/' ) );

		__( 'A string with no translation', 'mclogiora' );

		$before = $wpdb->num_queries;

		for ( $i = 0; $i < 50; $i++ ) {
			__( 'A string with no translation', 'mclogiora' );
		}

		$this->assertSame( $before, $wpdb->num_queries, 'A known-missing string must be looked up once per request.' );
	}
}
