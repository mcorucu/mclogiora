<?php
/**
 * Multilingual SEO integration tests.
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
use McLogiora\Seo\SeoContext;
use McLogiora\Seo\SeoModule;
use McLogiora\Workflows\TranslationWorkflowService;
use WP_UnitTestCase;

/**
 * Exercises the SEO head output against real WordPress requests.
 *
 * The values that matter here cannot be proven with doubles. Whether a
 * canonical tag is self-referential depends on WP_Rewrite, the permalink
 * structure, the queried object, and Phase 12's permalink filters all agreeing
 * with each other, and the only way to know they agree is to make a request
 * and read the head.
 */
final class MultilingualSeoIntegrationTest extends WP_UnitTestCase {
	/**
	 * Service container.
	 *
	 * @var \McLogiora\Core\Container
	 */
	private $container;

	/**
	 * Sets up an installed, two-language site with pretty permalinks.
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
	 * Clears per-request SEO memoisation.
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
	 * Registers routing and persists the language rewrite rules.
	 *
	 * @return RoutingModule
	 */
	private function activate_routing() {
		$module = new RoutingModule();
		$module->register( $this->container );
		$module->register_rewrite_rules();
		$module->maybe_flush_rewrite_rules();

		return $module;
	}

	/**
	 * Creates a translated page pair and returns both identifiers.
	 *
	 * @param string $source_slug Source slug.
	 * @param string $target_slug Translated slug.
	 * @return array{source:int,target:int}
	 */
	private function translated_page( $source_slug = 'about-us', $target_slug = 'hakkimizda' ) {
		$source = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => 'About us',
				'post_name'   => $source_slug,
				'post_status' => 'publish',
			)
		);

		$created = $this->container->get( TranslationWorkflowService::class )
			->content()
			->create_translation( $source, 'tr' );

		$this->assertIsArray( $created, is_wp_error( $created ) ? $created->get_error_message() : '' );

		wp_update_post(
			array(
				'ID'          => $created['post_id'],
				'post_name'   => $target_slug,
				'post_status' => 'publish',
			)
		);

		$this->container->get( AlternateUrlService::class )->reset();

		return array(
			'source' => (int) $source,
			'target' => (int) $created['post_id'],
		);
	}

	/**
	 * Returns the rendered `wp_head` output for the current request.
	 *
	 * @return string
	 */
	private function head() {
		$this->container->get( SeoContext::class )->reset();
		$this->container->get( AlternateUrlService::class )->reset();

		ob_start();
		do_action( 'wp_head' );

		return (string) ob_get_clean();
	}

	/**
	 * Returns the rendered document language attributes.
	 *
	 * @return string
	 */
	private function language_attributes() {
		ob_start();
		language_attributes();

		return (string) ob_get_clean();
	}

	/**
	 * Asserts a translated page declares the translated language.
	 *
	 * @return void
	 */
	public function test_translated_page_declares_its_own_language() {
		$pages = $this->translated_page();

		$this->activate_routing();
		$this->go_to( home_url( '/tr/hakkimizda/' ) );

		$attributes = $this->language_attributes();

		$this->assertStringContainsString( 'lang="tr-TR"', $attributes );
		$this->assertStringNotContainsString( 'lang="en-US"', $attributes );
		$this->assertSame( $pages['target'], get_queried_object_id() );
	}

	/**
	 * Asserts the default language keeps the site language.
	 *
	 * @return void
	 */
	public function test_default_language_page_declares_the_default_language() {
		$this->translated_page();

		$this->activate_routing();
		$this->go_to( home_url( '/about-us/' ) );

		$this->assertStringContainsString( 'lang="en-US"', $this->language_attributes() );
	}

	/**
	 * Asserts a right-to-left language sets the direction.
	 *
	 * @return void
	 */
	public function test_right_to_left_language_sets_the_direction() {
		$languages = $this->container->get( LanguageRepositoryInterface::class );

		if ( ! $languages->find_by_code( 'ar' ) instanceof Language ) {
			$languages->create( new Language( 'ar', 'ar', 'Arabic', 'Arabic', 'rtl', LanguageStatus::ACTIVE, 2, false ) );
		}

		$context = $this->container->get( LanguageContextInterface::class );
		$context->reset();
		$context->set_requested_code( 'ar' );

		$attributes = $this->language_attributes();

		$this->assertStringContainsString( 'lang="ar"', $attributes );
		$this->assertStringContainsString( 'dir="rtl"', $attributes );
	}

	/**
	 * Asserts existing attributes survive the language rewrite.
	 *
	 * @return void
	 */
	public function test_other_language_attributes_are_preserved() {
		$this->translated_page();
		$this->activate_routing();
		$this->go_to( home_url( '/tr/hakkimizda/' ) );

		$decorated = static function ( $output ) {
			return $output . ' data-theme="example"';
		};

		add_filter( 'language_attributes', $decorated, 5 );
		$attributes = $this->language_attributes();
		remove_filter( 'language_attributes', $decorated, 5 );

		$this->assertStringContainsString( 'data-theme="example"', $attributes );
		$this->assertStringContainsString( 'lang="tr-TR"', $attributes );
	}

	/**
	 * Asserts the request locale follows the current language.
	 *
	 * @return void
	 */
	public function test_request_locale_follows_the_current_language() {
		$this->translated_page();
		$this->activate_routing();
		$this->go_to( home_url( '/tr/hakkimizda/' ) );

		$this->assertSame( 'tr_TR', get_locale() );
	}

	/**
	 * Asserts switching the locale does not re-enter the gettext filter.
	 *
	 * @return void
	 */
	public function test_locale_lifecycle_does_not_recurse_through_gettext() {
		$this->translated_page();
		$this->activate_routing();

		$depth   = 0;
		$maximum = 0;

		$enter = static function ( $translation ) use ( &$depth, &$maximum ) {
			++$depth;
			$maximum = max( $maximum, $depth );

			return $translation;
		};
		$leave = static function ( $translation ) use ( &$depth ) {
			--$depth;

			return $translation;
		};

		add_filter( 'gettext', $enter, -PHP_INT_MAX );
		add_filter( 'gettext', $leave, PHP_INT_MAX );

		$this->go_to( home_url( '/tr/hakkimizda/' ) );
		$this->language_attributes();
		get_locale();
		__( 'Anything at all', 'mclogiora' );

		remove_filter( 'gettext', $enter, -PHP_INT_MAX );
		remove_filter( 'gettext', $leave, PHP_INT_MAX );

		$this->assertLessThanOrEqual( 1, $maximum, 'The gettext filter must never nest inside itself.' );
	}

	/**
	 * Asserts a translated page canonicalizes to itself, not to the source.
	 *
	 * @return void
	 */
	public function test_translated_page_canonicalizes_to_itself() {
		$pages = $this->translated_page();

		$this->activate_routing();
		$this->go_to( home_url( '/tr/hakkimizda/' ) );

		$canonical = wp_get_canonical_url( $pages['target'] );

		$this->assertIsString( $canonical );
		$this->assertSame( '/tr/hakkimizda/', wp_parse_url( $canonical, PHP_URL_PATH ) );
		$this->assertStringNotContainsString( 'about-us', $canonical, 'A translation must never canonicalize to its source.' );
	}

	/**
	 * Asserts the default-language page canonicalizes to itself.
	 *
	 * @return void
	 */
	public function test_default_language_page_canonicalizes_to_itself() {
		$pages = $this->translated_page();

		$this->activate_routing();
		$this->go_to( home_url( '/about-us/' ) );

		$this->assertSame( '/about-us/', wp_parse_url( wp_get_canonical_url( $pages['source'] ), PHP_URL_PATH ) );
	}

	/**
	 * Asserts the canonical URL is one of the page's own hreflang alternates.
	 *
	 * A page that names one URL as itself and a different URL as its own
	 * language's version has made two contradictory claims, and a search
	 * engine has no way to tell which to believe. Phase 13 stated the
	 * invariant; nothing asserted it, and a translated object reachable at the
	 * unprefixed default route broke it in exactly that way.
	 *
	 * @param string $route Route to request.
	 * @return void
	 *
	 * @dataProvider provide_multilingual_singular_routes
	 */
	public function test_canonical_url_belongs_to_the_hreflang_set( $route ) {
		$this->translated_page();

		$this->activate_routing();
		$this->go_to( home_url( $route ) );

		$head = $this->head();

		preg_match( '/<link rel="canonical" href="([^"]+)"/', $head, $canonical );
		preg_match_all( '/<link rel="alternate" hreflang="[^"]+" href="([^"]+)"/', $head, $alternates );

		$this->assertNotEmpty( $canonical, "No canonical was printed for {$route}:\n{$head}" );
		$this->assertNotEmpty( $alternates[1], "No alternates were printed for {$route}:\n{$head}" );

		$this->assertContains(
			$canonical[1],
			$alternates[1],
			"The canonical URL for {$route} is not in its own hreflang set:\n{$head}"
		);
	}

	/**
	 * Supplies the multilingual singular routes the invariant must hold on.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function provide_multilingual_singular_routes() {
		return array(
			'translated route' => array( '/tr/hakkimizda/' ),
			'source route'     => array( '/about-us/' ),
		);
	}

	/**
	 * Asserts exactly one canonical tag is printed on a singular request.
	 *
	 * @return void
	 */
	public function test_singular_request_prints_exactly_one_canonical() {
		$this->translated_page();

		$this->activate_routing();
		$this->go_to( home_url( '/tr/hakkimizda/' ) );

		$head = $this->head();

		$this->assertSame( 1, substr_count( $head, 'rel="canonical"' ), "Duplicate canonical in head:\n{$head}" );
		$this->assertStringContainsString( '/tr/hakkimizda/', $head );
	}

	/**
	 * Asserts the head carries the full alternate set with x-default.
	 *
	 * @return void
	 */
	public function test_translated_page_emits_hreflang_alternates() {
		$this->translated_page();

		$this->activate_routing();
		$this->go_to( home_url( '/tr/hakkimizda/' ) );

		$head = $this->head();

		$this->assertStringContainsString( 'hreflang="en-US"', $head );
		$this->assertStringContainsString( 'hreflang="tr-TR"', $head, 'The current language must reference itself.' );
		$this->assertStringContainsString( 'hreflang="x-default"', $head );
		$this->assertStringNotContainsString( 'hreflang="en_US"', $head, 'An underscore makes the annotation invalid.' );

		$this->assertSame( 1, substr_count( $head, 'hreflang="tr-TR"' ) );
		$this->assertSame( 1, substr_count( $head, 'hreflang="en-US"' ) );
	}

	/**
	 * Asserts x-default points at the default language equivalent.
	 *
	 * @return void
	 */
	public function test_x_default_points_at_the_default_language() {
		$this->translated_page();

		$this->activate_routing();
		$this->go_to( home_url( '/tr/hakkimizda/' ) );

		$head = $this->head();

		$this->assertMatchesRegularExpression(
			'#hreflang="x-default" href="[^"]*/about-us/"#',
			$head
		);
	}

	/**
	 * Asserts an untranslated page emits no alternates at all.
	 *
	 * A lone self-referential annotation tells a search engine nothing, and a
	 * fabricated one would tell it something false.
	 *
	 * @return void
	 */
	public function test_untranslated_page_emits_no_alternates() {
		self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => 'lonely',
				'post_status' => 'publish',
			)
		);

		$this->activate_routing();
		$this->go_to( home_url( '/lonely/' ) );

		$head = $this->head();

		$this->assertStringNotContainsString( 'hreflang=', $head );
	}

	/**
	 * Asserts a translated term archive gets a canonical and alternates.
	 *
	 * @return void
	 */
	public function test_translated_term_archive_has_canonical_and_alternates() {
		$source = self::factory()->term->create_and_get( array( 'taxonomy' => 'category', 'name' => 'News' ) );

		$created = $this->container->get( TranslationWorkflowService::class )
			->taxonomy()
			->create_translation( $source->term_id, 'category', 'tr', 'Haberler' );

		$this->assertIsArray( $created, is_wp_error( $created ) ? $created->get_error_message() : '' );

		wp_update_term( $created['term_id'], 'category', array( 'slug' => 'haberler' ) );

		$this->activate_routing();
		$this->go_to( get_term_link( (int) $created['term_id'], 'category' ) );

		$head = $this->head();

		$this->assertSame( 1, substr_count( $head, 'rel="canonical"' ), "Term archives get exactly one canonical:\n{$head}" );
		$this->assertStringContainsString( 'haberler', $head );
		$this->assertStringContainsString( 'hreflang="tr-TR"', $head );
		$this->assertStringContainsString( 'hreflang="en-US"', $head );
	}

	/**
	 * Asserts the front page emits a canonical and alternates.
	 *
	 * @return void
	 */
	public function test_front_page_has_canonical_and_alternates() {
		$this->activate_routing();
		$this->go_to( home_url( '/' ) );

		$head = $this->head();

		$this->assertSame( 1, substr_count( $head, 'rel="canonical"' ) );
		$this->assertStringContainsString( 'hreflang="en-US"', $head );
		$this->assertStringContainsString( 'hreflang="tr-TR"', $head );
		$this->assertStringContainsString( 'hreflang="x-default"', $head );
	}

	/**
	 * Asserts a translated posts page resolves through its translation.
	 *
	 * @return void
	 */
	public function test_posts_page_uses_its_translated_permalink() {
		$pages = $this->translated_page( 'journal', 'gunluk' );

		update_option( 'show_on_front', 'page' );
		update_option(
			'page_on_front',
			self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) )
		);
		update_option( 'page_for_posts', $pages['source'] );

		$this->activate_routing();
		$this->go_to( home_url( '/tr/gunluk/' ) );

		$head = $this->head();

		$this->assertStringContainsString( 'hreflang="tr-TR"', $head );
		$this->assertStringContainsString( '/tr/gunluk/', $head );

		update_option( 'show_on_front', 'posts' );
		delete_option( 'page_on_front' );
		delete_option( 'page_for_posts' );
	}

	/**
	 * Asserts OpenGraph locale metadata is emitted once.
	 *
	 * @return void
	 */
	public function test_open_graph_locale_is_emitted_once() {
		$this->translated_page();

		$this->activate_routing();
		$this->go_to( home_url( '/tr/hakkimizda/' ) );

		$head = $this->head();

		$this->assertSame( 1, substr_count( $head, 'property="og:locale"' ) );
		$this->assertStringContainsString( 'content="tr_TR"', $head );
		$this->assertStringContainsString( 'property="og:locale:alternate"', $head );
		$this->assertStringContainsString( 'content="en_US"', $head );
	}

	/**
	 * Asserts nothing is emitted in the admin.
	 *
	 * @return void
	 */
	public function test_no_seo_output_in_the_admin() {
		$this->translated_page();
		$this->activate_routing();
		$this->go_to( home_url( '/tr/hakkimizda/' ) );

		/*
		 * The module is asked directly rather than through wp_head. A theme
		 * never runs wp_head in the admin, and doing so here would only
		 * measure how much of core is willing to render on the wrong screen.
		 */
		$module = new SeoModule();
		$module->register( $this->container );

		set_current_screen( 'dashboard' );
		$this->container->get( SeoContext::class )->reset();

		ob_start();
		$module->render();
		$output = (string) ob_get_clean();

		$applies = $this->container->get( SeoContext::class )->applies();

		set_current_screen( 'front' );
		$this->container->get( SeoContext::class )->reset();

		$this->assertFalse( $applies, 'SEO output must not apply in the admin.' );
		$this->assertSame( '', $output );
	}

	/**
	 * Asserts nothing is emitted while WordPress is installing.
	 *
	 * @return void
	 */
	public function test_no_seo_output_while_installing() {
		$this->translated_page();
		$this->activate_routing();
		$this->go_to( home_url( '/tr/hakkimizda/' ) );

		wp_installing( true );
		$this->container->get( RuntimeReadiness::class )->reset();

		$head = $this->head();

		wp_installing( false );
		$this->container->get( RuntimeReadiness::class )->reset();

		$this->assertStringNotContainsString( 'hreflang=', $head );
	}

	/**
	 * Asserts a 404 under a language prefix emits no annotations.
	 *
	 * @return void
	 */
	public function test_no_seo_output_on_a_missing_translation() {
		$this->activate_routing();
		$this->go_to( home_url( '/tr/bulunamayan-sayfa/' ) );

		$this->assertTrue( is_404() );
		$this->assertStringNotContainsString( 'hreflang=', $this->head() );
	}

	/**
	 * Asserts every emitted URL actually resolves.
	 *
	 * An alternate pointing at a URL that 404s is worse than no alternate: it
	 * asserts a translation exists and then fails to produce it.
	 *
	 * @return void
	 */
	public function test_every_alternate_url_resolves() {
		$this->translated_page();

		$this->activate_routing();
		$this->go_to( home_url( '/tr/hakkimizda/' ) );

		preg_match_all( '#rel="alternate" hreflang="[^"]+" href="([^"]+)"#', $this->head(), $matches );

		$this->assertNotEmpty( $matches[1] );

		foreach ( array_unique( $matches[1] ) as $url ) {
			$this->go_to( html_entity_decode( $url ) );

			$this->assertFalse( is_404(), "Alternate URL does not resolve: {$url}" );
		}
	}
}
