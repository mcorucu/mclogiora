<?php
/**
 * Builder compatibility registry.
 *
 * @package McLogiora
 */

namespace McLogiora\Compatibility;

defined( 'ABSPATH' ) || exit;

/**
 * The evidence table for builder compatibility.
 *
 * Every entry records what was established, how it was established, and
 * against which version. Entries marked deferred are not claims of
 * incompatibility -- they are an admission that nobody here could install the
 * builder legally, and that a claim without a running copy would be a guess
 * dressed as a fact.
 *
 * Detection prefers a class or function the builder itself defines over a
 * plugin basename. Basenames were the previous approach and were wrong for two
 * of the ten: Beaver Builder's free edition ships as
 * `beaver-builder-lite-version/fl-builder.php` and SeedProd as
 * `coming-soon/coming-soon.php`, so both were silently never detected. A class
 * name survives an edition change and a directory rename; a basename does not.
 */
final class BuilderCompatibilityRegistry {
	/**
	 * Plugin detector.
	 *
	 * @var PluginDetector
	 */
	private $plugins;

	/**
	 * Theme detector.
	 *
	 * @var ThemeDetector
	 */
	private $themes;

	/**
	 * Constructor.
	 *
	 * @param PluginDetector $plugins Plugin detector.
	 * @param ThemeDetector  $themes Theme detector.
	 */
	public function __construct( PluginDetector $plugins, ThemeDetector $themes ) {
		$this->plugins = $plugins;
		$this->themes  = $themes;
	}

	/**
	 * Returns every known builder with its compatibility record.
	 *
	 * @return BuilderCompatibility[]
	 */
	public function all() {
		$records = array();

		foreach ( $this->definitions() as $definition ) {
			$record = new BuilderCompatibility(
				$definition['id'],
				$definition['label'],
				$definition['strategy'],
				$definition['qualification'],
				$definition['tested_version'],
				$definition['editor_ui']
			);

			$records[] = $record->with_detection(
				$this->is_present( $definition ),
				$this->version_of( $definition )
			);
		}

		return $records;
	}

	/**
	 * Returns the builders installed on this site.
	 *
	 * @return BuilderCompatibility[]
	 */
	public function detected() {
		return array_values(
			array_filter(
				$this->all(),
				static function ( BuilderCompatibility $record ) {
					return $record->detected();
				}
			)
		);
	}

	/**
	 * Returns one builder's record, or null.
	 *
	 * @param string $id Builder identifier.
	 * @return BuilderCompatibility|null
	 */
	public function find( $id ) {
		foreach ( $this->all() as $record ) {
			if ( $record->id() === (string) $id ) {
				return $record;
			}
		}

		return null;
	}

	/**
	 * Returns whether a builder is present.
	 *
	 * @param array<string,mixed> $definition Builder definition.
	 * @return bool
	 */
	private function is_present( array $definition ) {
		foreach ( $definition['classes'] as $class ) {
			if ( class_exists( $class ) ) {
				return true;
			}
		}

		foreach ( $definition['functions'] as $function ) {
			if ( function_exists( $function ) ) {
				return true;
			}
		}

		foreach ( $definition['basenames'] as $basename ) {
			if ( $this->plugins->is_active( $basename ) ) {
				return true;
			}
		}

		if ( '' !== $definition['theme'] ) {
			$theme = $this->themes->detect();

			if ( isset( $theme['id'] ) && strtolower( (string) $theme['id'] ) === strtolower( $definition['theme'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns the installed version of a builder, when it exposes one.
	 *
	 * @param array<string,mixed> $definition Builder definition.
	 * @return string
	 */
	private function version_of( array $definition ) {
		if ( '' !== $definition['version_constant'] && defined( $definition['version_constant'] ) ) {
			return (string) constant( $definition['version_constant'] );
		}

		return '';
	}

	/**
	 * Returns the builder definitions.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function definitions() {
		$deferred = BuilderCompatibility::QUALIFIED_DEFERRED;
		$live     = BuilderCompatibility::QUALIFIED_LIVE;

		return array(
			$this->definition(
				'elementor',
				__( 'Elementor', 'mclogiora' ),
				BuilderCompatibility::STRATEGY_ADAPTER,
				$live,
				'4.2.2',
				array(
					'classes'          => array( '\\Elementor\\Plugin' ),
					'version_constant' => 'ELEMENTOR_VERSION',
					'basenames'        => array( 'elementor/elementor.php' ),
				)
			),
			$this->definition(
				'beaver-builder',
				__( 'Beaver Builder', 'mclogiora' ),
				BuilderCompatibility::STRATEGY_ADAPTER,
				$live,
				'2.10.3.2',
				array(
					'classes'          => array( '\\FLBuilderModel' ),
					'version_constant' => 'FL_BUILDER_VERSION',

					/*
					 * The free edition ships as `beaver-builder-lite-version`
					 * and the paid one has historically used `bb-plugin`.
					 * Both are listed, and the class check above makes the
					 * directory name irrelevant either way.
					 */
					'basenames'        => array(
						'beaver-builder-lite-version/fl-builder.php',
						'bb-plugin/fl-builder.php',
					),
				)
			),
			$this->definition(
				'kadence-blocks',
				__( 'Kadence Blocks', 'mclogiora' ),
				BuilderCompatibility::STRATEGY_NATIVE,
				$live,
				'3.7.9.1',
				array(
					'classes'          => array( '\\Kadence_Blocks_Frontend' ),
					'version_constant' => 'KADENCE_BLOCKS_VERSION',
					'basenames'        => array( 'kadence-blocks/kadence-blocks.php' ),
				)
			),
			$this->definition(
				'generateblocks',
				__( 'GenerateBlocks', 'mclogiora' ),
				BuilderCompatibility::STRATEGY_NATIVE,
				$live,
				'2.4.0',
				array(
					'classes'          => array( '\\GenerateBlocks_Block' ),
					'version_constant' => 'GENERATEBLOCKS_VERSION',
					'basenames'        => array( 'generateblocks/plugin.php' ),
				)
			),
			$this->definition(
				'spectra',
				__( 'Spectra', 'mclogiora' ),
				BuilderCompatibility::STRATEGY_NATIVE,
				$live,
				'2.20.1',
				array(
					'classes'          => array( '\\UAGB_Loader', '\\UAGB_Init_Blocks' ),
					'version_constant' => 'UAGB_VER',
					'basenames'        => array( 'ultimate-addons-for-gutenberg/ultimate-addons-for-gutenberg.php' ),
				)
			),
			$this->definition(
				'seedprod',
				__( 'SeedProd', 'mclogiora' ),
				BuilderCompatibility::STRATEGY_NONE,
				$live,
				'6.20.8',
				array(
					'functions'        => array( 'seedprod_lite_post_type' ),
					'version_constant' => 'SEEDPROD_VERSION',

					/*
					 * SeedProd's directory is `coming-soon`, which is the slug
					 * its wordpress.org listing has always used.
					 */
					'basenames'        => array( 'coming-soon/coming-soon.php', 'seedprod/seedprod.php' ),
				)
			),
			$this->definition(
				'bricks',
				__( 'Bricks', 'mclogiora' ),
				BuilderCompatibility::STRATEGY_UNKNOWN,
				$deferred,
				'',
				array(
					'theme'     => 'bricks',
					'basenames' => array(),
				)
			),
			$this->definition(
				'divi',
				__( 'Divi', 'mclogiora' ),
				BuilderCompatibility::STRATEGY_UNKNOWN,
				$deferred,
				'',
				array(
					'theme'     => 'Divi',
					'basenames' => array( 'divi-builder/divi-builder.php' ),
				)
			),
			$this->definition(
				'wpbakery',
				__( 'WPBakery', 'mclogiora' ),
				BuilderCompatibility::STRATEGY_UNKNOWN,
				$deferred,
				'',
				array(
					'classes'   => array( '\\Vc_Manager' ),
					'basenames' => array( 'js_composer/js_composer.php' ),
				)
			),
			$this->definition(
				'oxygen',
				__( 'Oxygen', 'mclogiora' ),
				BuilderCompatibility::STRATEGY_UNKNOWN,
				$deferred,
				'',
				array(
					'basenames' => array( 'oxygen/functions.php' ),
				)
			),
			$this->definition(
				'avada',
				__( 'Avada', 'mclogiora' ),
				BuilderCompatibility::STRATEGY_UNKNOWN,
				$deferred,
				'',
				array(
					'theme'     => 'Avada',
					'basenames' => array( 'fusion-builder/fusion-builder.php' ),
				)
			),
		);
	}

	/**
	 * Normalises a builder definition.
	 *
	 * @param string              $id Identifier.
	 * @param string              $label Label.
	 * @param string              $strategy Payload strategy.
	 * @param string              $qualification Qualification state.
	 * @param string              $tested_version Version qualified against.
	 * @param array<string,mixed> $signals Detection signals.
	 * @return array<string,mixed>
	 */
	private function definition( $id, $label, $strategy, $qualification, $tested_version, array $signals ) {
		return array(
			'id'               => $id,
			'label'            => $label,
			'strategy'         => $strategy,
			'qualification'    => $qualification,
			'tested_version'   => $tested_version,
			'editor_ui'        => false,
			'classes'          => isset( $signals['classes'] ) ? $signals['classes'] : array(),
			'functions'        => isset( $signals['functions'] ) ? $signals['functions'] : array(),
			'basenames'        => isset( $signals['basenames'] ) ? $signals['basenames'] : array(),
			'theme'            => isset( $signals['theme'] ) ? $signals['theme'] : '',
			'version_constant' => isset( $signals['version_constant'] ) ? $signals['version_constant'] : '',
		);
	}
}
