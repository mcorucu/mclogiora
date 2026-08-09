<?php
/**
 * Language switcher surfaces.
 *
 * @package McLogiora
 */

namespace McLogiora\Switcher;

use McLogiora\Contracts\ModuleInterface;
use McLogiora\Core\Constants;
use McLogiora\Core\Container;
use McLogiora\Core\RuntimeReadiness;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the places a switcher can appear.
 *
 * Every surface renders through the same renderer and view model, so a
 * shortcode, a block, and a widget all produce identical markup and identical
 * URLs. Styles load only when a switcher has actually been rendered.
 */
final class SwitcherModule implements ModuleInterface {
	const SHORTCODE = 'mclogiora_switcher';

	/**
	 * Renderer.
	 *
	 * @var SwitcherRenderer|null
	 */
	private $renderer = null;

	/**
	 * Plugin constants.
	 *
	 * @var Constants|null
	 */
	private $constants = null;

	/**
	 * Runtime readiness.
	 *
	 * @var RuntimeReadiness|null
	 */
	private $readiness = null;

	/**
	 * Whether a switcher has been rendered on this request.
	 *
	 * @var bool
	 */
	private $rendered = false;

	/**
	 * Registers switcher surfaces.
	 *
	 * @param Container $container Service container.
	 * @return void
	 */
	public function register( Container $container ) {
		$this->readiness = $container->get( RuntimeReadiness::class );

		if ( $this->readiness->is_installing() ) {
			return;
		}

		$this->renderer  = $container->get( SwitcherRenderer::class );
		$this->constants = $container->get( Constants::class );

		add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );
		add_action( 'init', array( $this, 'register_block' ) );
		add_action( 'widgets_init', array( $this, 'register_widget' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_styles' ) );
		add_action( 'wp_footer', array( $this, 'maybe_print_styles' ) );
	}

	/**
	 * Renders the shortcode.
	 *
	 * Attributes are whitelisted through the view model's option resolver, so
	 * a shortcode can never inject an arbitrary URL or class.
	 *
	 * @param array<string,mixed>|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'style'        => null,
				'show_name'    => null,
				'show_code'    => null,
				'show_flag'    => null,
				'show_current' => null,
				'missing'      => null,
				'class'        => null,
			),
			is_array( $atts ) ? $atts : array(),
			self::SHORTCODE
		);

		$overrides = array();

		foreach ( $atts as $key => $value ) {
			if ( null !== $value ) {
				$overrides[ $key ] = $value;
			}
		}

		return $this->render( $overrides );
	}

	/**
	 * Registers the switcher block.
	 *
	 * @return void
	 */
	public function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		if ( ! $this->readiness instanceof RuntimeReadiness || $this->readiness->is_installing() ) {
			return;
		}

		$args = array(
			'api_version'     => 2,
			'title'           => __( 'Language Switcher', 'mclogiora' ),
			'category'        => 'widgets',
			'attributes'      => array(
				'style'       => array(
					'type'    => 'string',
					'default' => SwitcherStyle::INLINE,
				),
				'showName'    => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'showCode'    => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'showCurrent' => array(
					'type'    => 'boolean',
					'default' => true,
				),
			),
			'render_callback' => array( $this, 'render_block' ),
		);

		// phpcs:ignore Squiz.Commenting.InlineComment.InvalidEndChar -- static analysis directive.
		/* @phpstan-ignore-next-line argument.type -- register_block_type() accepts a block metadata array. */
		register_block_type( 'mclogiora/language-switcher', $args );
	}

	/**
	 * Renders the switcher block.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @return string
	 */
	public function render_block( $attributes ) {
		$attributes = is_array( $attributes ) ? $attributes : array();

		return $this->render(
			array(
				'style'        => isset( $attributes['style'] ) ? $attributes['style'] : null,
				'show_name'    => isset( $attributes['showName'] ) ? $attributes['showName'] : null,
				'show_code'    => isset( $attributes['showCode'] ) ? $attributes['showCode'] : null,
				'show_current' => isset( $attributes['showCurrent'] ) ? $attributes['showCurrent'] : null,
			)
		);
	}

	/**
	 * Registers the classic widget.
	 *
	 * @return void
	 */
	public function register_widget() {
		if ( ! class_exists( '\WP_Widget' ) ) {
			return;
		}

		register_widget( new SwitcherWidget( $this ) );
	}

	/**
	 * Renders a switcher and records that styles are needed.
	 *
	 * @param array<string,mixed> $overrides Instance overrides.
	 * @return string
	 */
	public function render( array $overrides = array() ) {
		$filtered = array();

		foreach ( $overrides as $key => $value ) {
			if ( null !== $value ) {
				$filtered[ $key ] = $value;
			}
		}

		$html = $this->renderer->render( $filtered );

		if ( '' !== $html ) {
			$this->rendered = true;
		}

		return $html;
	}

	/**
	 * Registers the switcher stylesheet without enqueuing it.
	 *
	 * @return void
	 */
	public function register_styles() {
		wp_register_style(
			'mclogiora-switcher',
			$this->constants->url() . 'assets/css/switcher.css',
			array(),
			$this->constants->version()
		);
	}

	/**
	 * Enqueues the stylesheet only when a switcher was actually rendered.
	 *
	 * @return void
	 */
	public function maybe_print_styles() {
		if ( ! $this->rendered ) {
			return;
		}

		wp_enqueue_style( 'mclogiora-switcher' );
		wp_print_styles( 'mclogiora-switcher' );
	}
}
