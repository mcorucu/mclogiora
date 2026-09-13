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
				'style'        => isset( $instance['style'] ) ? $instance['style'] : null,
				'show_name'    => isset( $instance['show_name'] ) ? $instance['show_name'] : null,
				'show_code'    => isset( $instance['show_code'] ) ? $instance['show_code'] : null,
				'show_flag'    => isset( $instance['show_flag'] ) ? $instance['show_flag'] : null,
				'show_current' => isset( $instance['show_current'] ) ? $instance['show_current'] : null,
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
	 * @return string|null
	 */
	public function form( $instance ): ?string {
		$title = isset( $instance['title'] ) ? (string) $instance['title'] : '';
		$style = isset( $instance['style'] ) ? (string) $instance['style'] : SwitcherStyle::COMPACT;
		$show_name = isset( $instance['show_name'] ) ? (bool) $instance['show_name'] : false;
		$show_code = isset( $instance['show_code'] ) ? (bool) $instance['show_code'] : true;
		$show_flag = isset( $instance['show_flag'] ) ? (bool) $instance['show_flag'] : true;
		$show_current = isset( $instance['show_current'] ) ? (bool) $instance['show_current'] : true;

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
		<p>
			<label><input type="checkbox" name="<?php echo esc_attr( $this->get_field_name( 'show_name' ) ); ?>" value="1" <?php checked( $show_name ); ?>> <?php esc_html_e( 'Show language name', 'mclogiora' ); ?></label><br>
			<label><input type="checkbox" name="<?php echo esc_attr( $this->get_field_name( 'show_code' ) ); ?>" value="1" <?php checked( $show_code ); ?>> <?php esc_html_e( 'Show language code', 'mclogiora' ); ?></label><br>
			<label><input type="checkbox" name="<?php echo esc_attr( $this->get_field_name( 'show_flag' ) ); ?>" value="1" <?php checked( $show_flag ); ?>> <?php esc_html_e( 'Show bundled flag when available', 'mclogiora' ); ?></label><br>
			<label><input type="checkbox" name="<?php echo esc_attr( $this->get_field_name( 'show_current' ) ); ?>" value="1" <?php checked( $show_current ); ?>> <?php esc_html_e( 'Include current language', 'mclogiora' ); ?></label>
		</p>
		<?php

		return null;
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
			'title'        => isset( $new_instance['title'] ) ? sanitize_text_field( $new_instance['title'] ) : '',
			'style'        => SwitcherStyle::is_valid( $style ) ? $style : SwitcherStyle::COMPACT,
			'show_name'    => ! empty( $new_instance['show_name'] ),
			'show_code'    => ! empty( $new_instance['show_code'] ),
			'show_flag'    => ! empty( $new_instance['show_flag'] ),
			'show_current' => ! empty( $new_instance['show_current'] ),
		);
	}

	/**
	 * Returns the HTML permitted in switcher output.
	 *
	 * @return array<string,array<string,bool>>
	 */
	private function allowed_html() {
		$attributes = array(
			'class'                   => true,
			'id'                      => true,
			'href'                    => true,
			'lang'                    => true,
			'hreflang'                => true,
			'dir'                     => true,
			'aria-label'              => true,
			'aria-expanded'           => true,
			'aria-haspopup'           => true,
			'aria-hidden'             => true,
			'aria-disabled'           => true,
			'aria-current'            => true,
			'role'                    => true,
			'src'                     => true,
			'width'                   => true,
			'height'                  => true,
			'alt'                     => true,
			'decoding'                => true,
			'value'                   => true,
			'selected'                => true,
			'disabled'                => true,
			'for'                     => true,
			'name'                    => true,
			'method'                  => true,
			'action'                  => true,
			'type'                    => true,
			'data-mclogiora-switcher' => true,
			'data-mclogiora-compact'  => true,
		);

		return array(
			'div'      => $attributes,
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
			'details'  => $attributes,
			'summary'  => $attributes,
			'img'      => $attributes,
		);
	}
}
