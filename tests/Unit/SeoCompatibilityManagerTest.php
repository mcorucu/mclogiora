<?php
/**
 * SEO plugin ownership tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Unit;

use McLogiora\Compatibility\PluginDetector;
use McLogiora\Seo\SeoCompatibilityManager;
use McLogiora\Seo\SeoConcern;
use PHPUnit\Framework\TestCase;

/**
 * Pins who writes which tag when an SEO plugin is present.
 *
 * The failure this prevents is two plugins writing the same tag. A page with
 * two canonical URLs may have neither respected, which is worse than either
 * plugin acting alone. The opposite failure matters just as much: standing
 * down from `hreflang` because an SEO plugin exists would delete the one piece
 * of output only mcLogiora can produce.
 */
final class SeoCompatibilityManagerTest extends TestCase {
	/**
	 * Clears the active plugin list.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		update_option( 'active_plugins', array() );
	}

	/**
	 * Clears the active plugin list.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		delete_option( 'active_plugins' );

		parent::tearDown();
	}

	/**
	 * Returns a manager seeing the given active plugins.
	 *
	 * @param string[] $plugins Active plugin basenames.
	 * @return SeoCompatibilityManager
	 */
	private function manager( array $plugins = array() ) {
		update_option( 'active_plugins', $plugins );

		return new SeoCompatibilityManager( new PluginDetector() );
	}

	/**
	 * Asserts mcLogiora owns everything when no SEO plugin is present.
	 *
	 * @return void
	 */
	public function test_owns_every_concern_without_an_seo_plugin() {
		$manager = $this->manager();

		$this->assertSame( array(), $manager->active_adapters() );

		foreach ( SeoConcern::all() as $concern ) {
			$this->assertTrue( $manager->owns( $concern ), "Should own {$concern}." );
		}
	}

	/**
	 * Asserts Yoast takes canonical, OpenGraph, and sitemap but not hreflang.
	 *
	 * @return void
	 */
	public function test_yoast_takes_its_concerns_but_leaves_hreflang() {
		$manager = $this->manager( array( 'wordpress-seo/wp-seo.php' ) );

		$this->assertCount( 1, $manager->active_adapters() );
		$this->assertSame( 'yoast', $manager->active_adapters()[0]->id() );

		$this->assertFalse( $manager->owns( SeoConcern::CANONICAL ) );
		$this->assertFalse( $manager->owns( SeoConcern::OG_LOCALE ) );
		$this->assertFalse( $manager->owns( SeoConcern::SITEMAP ) );
		$this->assertTrue( $manager->owns( SeoConcern::HREFLANG ), 'No SEO plugin here emits hreflang.' );
	}

	/**
	 * Asserts Slim SEO leaves the core sitemap alone.
	 *
	 * @return void
	 */
	public function test_slim_seo_leaves_the_sitemap_to_mclogiora() {
		$manager = $this->manager( array( 'slim-seo/slim-seo.php' ) );

		$this->assertFalse( $manager->owns( SeoConcern::CANONICAL ) );
		$this->assertTrue( $manager->owns( SeoConcern::SITEMAP ), 'Slim SEO keeps the WordPress core sitemap.' );
		$this->assertTrue( $manager->owns( SeoConcern::HREFLANG ) );
	}

	/**
	 * Asserts every supported plugin is detected by its basename.
	 *
	 * @dataProvider adapter_provider
	 * @param string $basename Plugin basename.
	 * @param string $expected Adapter identifier.
	 * @return void
	 */
	public function test_supported_plugins_are_detected( $basename, $expected ) {
		$adapters = $this->manager( array( $basename ) )->active_adapters();

		$this->assertCount( 1, $adapters );
		$this->assertSame( $expected, $adapters[0]->id() );
	}

	/**
	 * Supplies supported plugin basenames.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function adapter_provider() {
		return array(
			'yoast'      => array( 'wordpress-seo/wp-seo.php', 'yoast' ),
			'rank math'  => array( 'seo-by-rank-math/rank-math.php', 'rank-math' ),
			'aioseo'     => array( 'all-in-one-seo-pack/all_in_one_seo_pack.php', 'aioseo' ),
			'tsf'        => array( 'autodescription/autodescription.php', 'the-seo-framework' ),
			'slim seo'   => array( 'slim-seo/slim-seo.php', 'slim-seo' ),
		);
	}

	/**
	 * Asserts an unknown SEO plugin is reported but never obeyed.
	 *
	 * Standing down for a plugin nobody has verified would break working sites
	 * to protect a hypothetical one.
	 *
	 * @return void
	 */
	public function test_unknown_seo_plugin_is_reported_not_obeyed() {
		$manager = $this->manager( array( 'some-other-seo-plugin/plugin.php' ) );

		$this->assertSame( array(), $manager->active_adapters() );
		$this->assertSame( array( 'some-other-seo-plugin/plugin.php' ), $manager->unrecognised_seo_plugins() );

		foreach ( SeoConcern::all() as $concern ) {
			$this->assertTrue( $manager->owns( $concern ) );
		}
	}

	/**
	 * Asserts an ordinary plugin is not mistaken for an SEO plugin.
	 *
	 * @return void
	 */
	public function test_ordinary_plugins_are_not_flagged() {
		$manager = $this->manager( array( 'akismet/akismet.php', 'contact-form-7/wp-contact-form-7.php' ) );

		$this->assertSame( array(), $manager->unrecognised_seo_plugins() );
	}

	/**
	 * Asserts a known adapter is not also reported as unrecognised.
	 *
	 * @return void
	 */
	public function test_supported_plugins_are_not_reported_as_unknown() {
		$manager = $this->manager( array( 'wordpress-seo/wp-seo.php' ) );

		$this->assertSame( array(), $manager->unrecognised_seo_plugins() );
	}

	/**
	 * Asserts ownership is reported per concern.
	 *
	 * @return void
	 */
	public function test_owner_of_names_the_plugin() {
		$manager = $this->manager( array( 'seo-by-rank-math/rank-math.php' ) );

		$this->assertSame( 'Rank Math SEO', $manager->owner_of( SeoConcern::CANONICAL )->label() );
		$this->assertNull( $manager->owner_of( SeoConcern::HREFLANG ) );
	}

	/**
	 * Asserts no adapter fatals when its plugin is absent.
	 *
	 * @return void
	 */
	public function test_adapters_describe_themselves_without_their_plugin() {
		foreach ( $this->manager()->known_adapters() as $adapter ) {
			$this->assertNotSame( '', $adapter->id() );
			$this->assertNotSame( '', $adapter->label() );
			$this->assertNotEmpty( $adapter->plugin_basenames() );

			foreach ( $adapter->owned_concerns() as $concern ) {
				$this->assertTrue( SeoConcern::is_valid( $concern ) );
			}
		}
	}
}
