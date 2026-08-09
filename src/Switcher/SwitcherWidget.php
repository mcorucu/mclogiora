<?php
/**
 * Classic language switcher widget.
 *
 * @package McLogiora
 */

namespace McLogiora\Switcher;

defined( 'ABSPATH' ) || exit;

/**
 * Classic widget wrapper around the shared switcher renderer.
 */
final class SwitcherWidget extends \WP_Widget {
	/**
	 * Switcher module.
	 *
	 * @var SwitcherModule|null
	 */
	private $module = null;

	/**
	 * Constructor.
	 *
	 * @param SwitcherModule|null $module Switcher module.
	 */
	public function __construct( $module = null ) {
		$this->module = $module;

		parent::__construct(
			'mclogiora_switcher',
			__( 'mcLogiora Language Switcher', 'mclogiora' ),
			array( 'description' => __( 'Lets visitors change the site language.', 'mclogiora' ) )
		);
	}

	/**
	 * Renders the widget.
	 *
	 * @param array<string,mixed> $args Sidebar arguments.
	 * @param array<string,mixed> $instance Widget instance.
	 * @return void
	 */
	public function widget( $args, $instance ) {
		if ( ! $this->module instanceof SwitcherModule ) {
			return;
		}

		$html = $this->module->render(
			array(
				'style' => isset( $instance['style'] ) ? $instance['style'] : null,
			)
		);

		if ( '' === $html ) {
			return;
		}

		$before = isset( $args['before_widget'] ) ? (string) $args['before_widget'] : '';
		$after  = isset( $args['after_widget'] ) ? (string) $args['after_widget'] : '';
		$title  = isset( $instance['title'] ) ? (string) $instance['title'] : '';

		echo wp_kses_post( $before );

		if ( '' !== $title ) {
			$before_title = isset( $args['before_title'] ) ? (string) $args['before_title'] : '';
			$after_title  = isset( $args['after_title'] ) ? (string) $args['after_title'] : '';

			echo wp_kses_post( $before_title ) . esc_html( $title ) . wp_kses_post( $after_title );
		}

		echo wp_kses( $html, $this->allowed_html() );
		echo wp_kses_post( $after );
	}

	/**
	 * Renders the widget form.
	 *
	 * @param array<string,mixed> $instance Widget instance.
	 * @return void
	 */
	public function form( $instance ) {
		$title = isset( $instance['title'] ) ? (string) $instance['title'] : '';
		$style = isset( $instance['style'] ) ? (string) $instance['style'] : SwitcherStyle::INLINE;

		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title', 'mclogiora' ); ?></label>
			<input class="widefat" type="text"
				id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>"
				value="<?php echo esc_attr( $title ); ?>">
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'style' ) ); ?>"><?php esc_html_e( 'Style', 'mclogiora' ); ?></label>
			<select class="widefat"
				id="<?php echo esc_attr( $this->get_field_id( 'style' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'style' ) ); ?>">
				<?php foreach ( SwitcherStyle::labels() as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $style, $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php
	}

	/**
	 * Sanitizes submitted widget settings.
	 *
	 * @param array<string,mixed> $new_instance New settings.
	 * @param array<string,mixed> $old_instance Previous settings.
	 * @return array<string,mixed>
	 */
	public function update( $new_instance, $old_instance ) {
		unset( $old_instance );

		$style = isset( $new_instance['style'] ) ? sanitize_key( $new_instance['style'] ) : SwitcherStyle::INLINE;

		return array(
			'title' => isset( $new_instance['title'] ) ? sanitize_text_field( $new_instance['title'] ) : '',
			'style' => SwitcherStyle::is_valid( $style ) ? $style : SwitcherStyle::INLINE,
		);
	}

	/**
	 * Returns the HTML permitted in switcher output.
	 *
	 * @return array<string,array<string,bool>>
	 */
	private function allowed_html() {
		$attributes = array(
			'class'        => true,
			'id'           => true,
			'href'         => true,
			'lang'         => true,
			'hreflang'     => true,
			'dir'          => true,
			'aria-label'   => true,
			'aria-current' => true,
			'value'        => true,
			'selected'     => true,
			'disabled'     => true,
			'for'          => true,
			'name'         => true,
			'method'       => true,
			'action'       => true,
			'type'         => true,
			'onchange'     => true,
		);

		return array(
			'nav'      => $attributes,
			'ul'       => $attributes,
			'li'       => $attributes,
			'a'        => $attributes,
			'span'     => $attributes,
			'form'     => $attributes,
			'select'   => $attributes,
			'option'   => $attributes,
			'label'    => $attributes,
			'button'   => $attributes,
			'noscript' => $attributes,
		);
	}
}
