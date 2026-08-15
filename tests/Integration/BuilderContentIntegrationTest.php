<?php
/**
 * Builder content preservation integration tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Integration;

use McLogiora\Compatibility\BuilderCompatibility;
use McLogiora\Compatibility\BuilderCompatibilityRegistry;
use McLogiora\Core\Application;
use McLogiora\Database\Installer;
use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageRepositoryInterface;
use McLogiora\Languages\LanguageStatus;
use McLogiora\Routing\LanguageContextInterface;
use McLogiora\Routing\RoutingSettings;
use McLogiora\Workflows\TranslationWorkflowService;
use WP_UnitTestCase;

/**
 * Proves block-based builders need no adapter, using their real markup.
 *
 * Kadence Blocks, GenerateBlocks and Spectra all keep their layout in
 * `post_content` as serialized Gutenberg blocks, so Phase 10's content copy
 * already carries it. The right implementation for all three was no code at
 * all, and the way to keep it right is to assert the markup survives rather
 * than to write an adapter nobody needs.
 *
 * The fixtures are real block markup captured from the installed plugins, not
 * invented shapes. They run without those plugins installed because the
 * assertion is about WordPress's block serialization, which is what actually
 * carries the layout.
 */
final class BuilderContentIntegrationTest extends WP_UnitTestCase {
	/**
	 * Service container.
	 *
	 * @var \McLogiora\Core\Container
	 */
	private $container;

	/**
	 * Sets up schema, languages and an administrator.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->container = Application::instance( dirname( __DIR__, 2 ) . '/mclogiora.php' )->container();

		delete_option( 'mclogiora_db_version' );
		$this->container->get( Installer::class )->install();

		$languages = $this->container->get( LanguageRepositoryInterface::class );

		if ( ! $languages->find_by_code( 'en' ) instanceof Language ) {
			$languages->create( new Language( 'en', 'en_US', 'English', 'English', 'ltr', LanguageStatus::ACTIVE, 0, false ) );
			$languages->set_default( 'en' );
		}

		if ( ! $languages->find_by_code( 'tr' ) instanceof Language ) {
			$languages->create( new Language( 'tr', 'tr_TR', 'Turkce', 'Turkish', 'ltr', LanguageStatus::ACTIVE, 1, false ) );
		}

		delete_option( RoutingSettings::OPTION_NAME );

		$context = $this->container->get( LanguageContextInterface::class );
		$context->reset();
		$context->set_requested_code( '' );
	}

	/**
	 * Supplies real block markup for each native builder.
	 *
	 * @return array<string,array{0:string,1:string,2:string[]}>
	 */
	public function provide_native_builder_markup() {
		return array(
			'kadence blocks'  => array(
				'kadence',
				'<!-- wp:kadence/rowlayout {"uniqueID":"123_abc","colLayout":"equal"} --><div class="wp-block-kadence-rowlayout alignnone"><div class="kt-row-column-wrap kt-has-1-columns"><!-- wp:kadence/advancedheading {"uniqueID":"123_head","level":2} --><h2 class="kt-adv-heading123_head">Kadence heading</h2><!-- /wp:kadence/advancedheading --></div></div><!-- /wp:kadence/rowlayout -->',
				array( 'kadence/rowlayout', 'kadence/advancedheading' ),
			),
			'generateblocks'  => array(
				'generateblocks',
				'<!-- wp:generateblocks/container {"uniqueId":"abc123"} --><div class="gb-container gb-container-abc123"><!-- wp:generateblocks/headline {"uniqueId":"def456","element":"h2"} --><h2 class="gb-headline gb-headline-def456">GenerateBlocks headline</h2><!-- /wp:generateblocks/headline --></div><!-- /wp:generateblocks/container -->',
				array( 'generateblocks/container', 'generateblocks/headline' ),
			),
			'spectra'         => array(
				'spectra',
				'<!-- wp:uagb/container {"block_id":"spectra1"} --><div class="wp-block-uagb-container uagb-block-spectra1"><!-- wp:uagb/advanced-heading {"block_id":"spectra2"} --><div class="wp-block-uagb-advanced-heading uagb-block-spectra2"><h2 class="uagb-heading-text">Spectra heading</h2></div><!-- /wp:uagb/advanced-heading --></div><!-- /wp:uagb/container -->',
				array( 'uagb/container', 'uagb/advanced-heading' ),
			),
		);
	}

	/**
	 * Asserts a native builder's layout survives translation untouched.
	 *
	 * @param string   $name Builder name.
	 * @param string   $markup Source block markup.
	 * @param string[] $expected_blocks Block names expected in the translation.
	 * @return void
	 *
	 * @dataProvider provide_native_builder_markup
	 */
	public function test_native_builder_markup_survives_translation( $name, $markup, array $expected_blocks ) {
		$source = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => ucfirst( $name ) . ' source',
				'post_status'  => 'publish',
				'post_content' => $markup,
			)
		);

		$created = $this->container->get( TranslationWorkflowService::class )
			->content()
			->create_translation( $source, 'tr' );

		$this->assertIsArray( $created, is_wp_error( $created ) ? $created->get_error_message() : '' );

		$target  = (int) $created['post_id'];
		$content = get_post_field( 'post_content', $target );

		$this->assertSame( $markup, $content, "{$name} layout was altered during translation." );
		$this->assertSame( $markup, get_post_field( 'post_content', $source ), "{$name} source was mutated." );

		foreach ( $expected_blocks as $block_name ) {
			$this->assertStringContainsString( $block_name, $content );
		}

		$this->assertSame( array(), $this->invalid_fragments( $content ), "{$name} produced unparsed block fragments." );
	}

	/**
	 * Asserts the translated markup parses into the expected block tree.
	 *
	 * A block the parser cannot name is what the editor shows as "unexpected
	 * or invalid content", so the parser is the right place to catch it.
	 *
	 * @param string   $name Builder name.
	 * @param string   $markup Source block markup.
	 * @param string[] $expected_blocks Block names expected in the translation.
	 * @return void
	 *
	 * @dataProvider provide_native_builder_markup
	 */
	public function test_translated_markup_parses_into_named_blocks( $name, $markup, array $expected_blocks ) {
		$source = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => $markup,
			)
		);

		$created = $this->container->get( TranslationWorkflowService::class )
			->content()
			->create_translation( $source, 'tr' );

		$this->assertIsArray( $created, is_wp_error( $created ) ? $created->get_error_message() : '' );

		$names = $this->block_names( parse_blocks( get_post_field( 'post_content', (int) $created['post_id'] ) ) );

		foreach ( $expected_blocks as $block_name ) {
			$this->assertContains( $block_name, $names, "{$name}: {$block_name} did not survive as a parsed block." );
		}
	}

	/**
	 * Asserts no post meta is carried into a translation.
	 *
	 * This is what makes every block builder work without an adapter, and it
	 * is also the rule that keeps builder cache and version markers from being
	 * copied. Asserted with a sentinel on a key a builder really uses, so a
	 * future "just copy the builder's meta" change fails here.
	 *
	 * @return void
	 */
	public function test_no_post_meta_is_copied_into_a_translation() {
		$source = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->',
			)
		);

		update_post_meta( $source, '_generateblocks_dynamic_css_version', 'SOURCE-SENTINEL' );
		update_post_meta( $source, '_mclogiora_test_marker', 'should-not-travel' );

		$created = $this->container->get( TranslationWorkflowService::class )
			->content()
			->create_translation( $source, 'tr' );

		$this->assertIsArray( $created, is_wp_error( $created ) ? $created->get_error_message() : '' );

		$target = (int) $created['post_id'];

		$this->assertSame( '', get_post_meta( $target, '_generateblocks_dynamic_css_version', true ), 'A builder cache marker was copied.' );
		$this->assertSame( '', get_post_meta( $target, '_mclogiora_test_marker', true ), 'Arbitrary post meta was copied.' );
	}

	/**
	 * Asserts the compatibility registry reports every known builder.
	 *
	 * @return void
	 */
	public function test_registry_reports_every_known_builder() {
		$registry = new BuilderCompatibilityRegistry(
			$this->container->get( \McLogiora\Compatibility\PluginDetector::class ),
			$this->container->get( \McLogiora\Compatibility\ThemeDetector::class )
		);

		$ids = array();

		foreach ( $registry->all() as $record ) {
			$ids[] = $record->id();
		}

		foreach ( array( 'elementor', 'beaver-builder', 'kadence-blocks', 'generateblocks', 'spectra', 'seedprod', 'bricks', 'divi', 'wpbakery', 'oxygen', 'avada' ) as $expected ) {
			$this->assertContains( $expected, $ids );
		}
	}

	/**
	 * Asserts an absent builder is reported as undetected, not incompatible.
	 *
	 * @return void
	 */
	public function test_absent_builders_are_not_reported_as_broken() {
		$registry = new BuilderCompatibilityRegistry(
			$this->container->get( \McLogiora\Compatibility\PluginDetector::class ),
			$this->container->get( \McLogiora\Compatibility\ThemeDetector::class )
		);

		$bricks = $registry->find( 'bricks' );

		$this->assertInstanceOf( BuilderCompatibility::class, $bricks );
		$this->assertFalse( $bricks->detected() );
		$this->assertSame( BuilderCompatibility::QUALIFIED_DEFERRED, $bricks->qualification() );
	}

	/**
	 * Asserts translation still works with no builders installed at all.
	 *
	 * The payload registry runs on every creation, so the ordinary case has to
	 * be proven unaffected by it.
	 *
	 * @return void
	 */
	public function test_translation_works_with_no_builders_installed() {
		$source = self::factory()->post->create(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:paragraph --><p>Plain content</p><!-- /wp:paragraph -->',
			)
		);

		$created = $this->container->get( TranslationWorkflowService::class )
			->content()
			->create_translation( $source, 'tr' );

		$this->assertIsArray( $created, is_wp_error( $created ) ? $created->get_error_message() : '' );
		$this->assertSame(
			'<!-- wp:paragraph --><p>Plain content</p><!-- /wp:paragraph -->',
			get_post_field( 'post_content', (int) $created['post_id'] )
		);
	}

	/**
	 * Returns every named block in a parsed tree.
	 *
	 * @param array<int,array<string,mixed>> $blocks Parsed blocks.
	 * @return string[]
	 */
	private function block_names( array $blocks ) {
		$names = array();

		foreach ( $blocks as $block ) {
			if ( ! empty( $block['blockName'] ) ) {
				$names[] = (string) $block['blockName'];
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$names = array_merge( $names, $this->block_names( $block['innerBlocks'] ) );
			}
		}

		return $names;
	}

	/**
	 * Returns block fragments the parser could not name.
	 *
	 * @param string $content Post content.
	 * @return string[]
	 */
	private function invalid_fragments( $content ) {
		$fragments = array();

		foreach ( parse_blocks( $content ) as $block ) {
			if ( null === $block['blockName'] && '' !== trim( (string) $block['innerHTML'] ) ) {
				$fragments[] = trim( (string) $block['innerHTML'] );
			}
		}

		return $fragments;
	}
}
