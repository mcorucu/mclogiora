<?php
/**
 * Language switcher markup.
 *
 * @package McLogiora
 */

namespace McLogiora\Switcher;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the switcher view model as accessible HTML.
 *
 * Every mode produces real links or a real form control, never a div with a
 * click handler, so keyboard and screen-reader users get working navigation
 * without any JavaScript. The dropdown is a `<select>` inside a `<form>` with
 * a submit button, which works with JavaScript disabled and is enhanced by it
 * rather than dependent on it.
 */
final class SwitcherRenderer {
	/**
	 * Switcher view model.
	 *
	 * @var LanguageSwitcher
	 */
	private $switcher;

	/**
	 * Constructor.
	 *
	 * @param LanguageSwitcher $switcher Switcher view model.
	 */
	public function __construct( LanguageSwitcher $switcher ) {
		$this->switcher = $switcher;
	}

	/**
	 * Renders the switcher.
	 *
	 * @param array<string,mixed> $overrides Instance overrides.
	 * @return string
	 */
	public function render( array $overrides = array() ) {
		$options = $this->switcher->options( $overrides );
		$items   = $this->switcher->items( $overrides );

		if ( empty( $items ) ) {
			return '';
		}

		if ( SwitcherStyle::DROPDOWN === $options['style'] ) {
			return $this->render_dropdown( $items, $options );
		}

		return $this->render_list( $items, $options );
	}

	/**
	 * Renders the list-based modes.
	 *
	 * @param array<int,array<string,mixed>> $items Switcher items.
	 * @param array<string,mixed>            $options Resolved options.
	 * @return string
	 */
	private function render_list( array $items, array $options ) {
		$classes = $this->wrapper_classes( $options );
		$html    = '<nav class="' . esc_attr( $classes ) . '" aria-label="' . esc_attr__( 'Language', 'mclogiora' ) . '">';
		$html   .= '<ul class="mclogiora-switcher__list">';

		foreach ( $items as $item ) {
			$html .= $this->render_item( $item, $options );
		}

		$html .= '</ul></nav>';

		return $html;
	}

	/**
	 * Renders one list item.
	 *
	 * @param array<string,mixed> $item Switcher item.
	 * @param array<string,mixed> $options Resolved options.
	 * @return string
	 */
	private function render_item( array $item, array $options ) {
		$label   = $this->label( $item, $options );
		$classes = 'mclogiora-switcher__item';

		if ( $item['is_current'] ) {
			$classes .= ' is-current';
		}

		if ( ! $item['available'] ) {
			$classes .= ' is-unavailable';
		}

		$html = '<li class="' . esc_attr( $classes ) . '">';

		if ( ! $item['available'] || null === $item['url'] ) {
			/*
			 * An unavailable language is announced as such rather than being
			 * rendered as a dead link, so a screen reader user is told why it
			 * cannot be chosen.
			 */
			$html .= '<span class="mclogiora-switcher__label" lang="' . esc_attr( $this->language_tag( $item ) ) . '" dir="' . esc_attr( $item['direction'] ) . '">';
			$html .= esc_html( $label );
			$html .= '<span class="screen-reader-text"> ' . esc_html__( '(translation not available)', 'mclogiora' ) . '</span>';
			$html .= '</span>';
			$html .= '</li>';

			return $html;
		}

		$current_markup = $item['is_current']
			? ' aria-current="true"><span class="screen-reader-text">' . esc_html__( 'Current language:', 'mclogiora' ) . ' </span>'
			: '>';

		$tag = $this->language_tag( $item );

		$html .= '<a class="mclogiora-switcher__link" href="' . esc_url( $item['url'] ) . '"';
		$html .= ' lang="' . esc_attr( $tag ) . '"';
		$html .= ' hreflang="' . esc_attr( $tag ) . '"';
		$html .= ' dir="' . esc_attr( $item['direction'] ) . '"';
		$html .= $current_markup;
		$html .= esc_html( $label );
		$html .= '</a></li>';

		return $html;
	}

	/**
	 * Renders the dropdown mode.
	 *
	 * @param array<int,array<string,mixed>> $items Switcher items.
	 * @param array<string,mixed>            $options Resolved options.
	 * @return string
	 */
	private function render_dropdown( array $items, array $options ) {
		$id      = 'mclogiora-switcher-' . wp_rand( 1000, 9999 );
		$classes = $this->wrapper_classes( $options );

		$html  = '<form class="' . esc_attr( $classes ) . '" method="get" action="' . esc_url( home_url( '/' ) ) . '">';
		$html .= '<label class="screen-reader-text" for="' . esc_attr( $id ) . '">' . esc_html__( 'Choose a language', 'mclogiora' ) . '</label>';
		$html .= '<select class="mclogiora-switcher__select" id="' . esc_attr( $id ) . '" name="mclogiora_switch_to" onchange="if(this.value){window.location.href=this.value;}">';

		foreach ( $items as $item ) {
			if ( ! $item['available'] || null === $item['url'] ) {
				$html .= '<option value="" disabled lang="' . esc_attr( $this->language_tag( $item ) ) . '">';
				$html .= esc_html( $this->label( $item, $options ) );
				$html .= '</option>';

				continue;
			}

			$html .= '<option value="' . esc_url( $item['url'] ) . '" lang="' . esc_attr( $this->language_tag( $item ) ) . '"';
			$html .= selected( $item['is_current'], true, false );
			$html .= '>' . esc_html( $this->label( $item, $options ) ) . '</option>';
		}

		$html .= '</select>';
		$html .= '<noscript><button type="submit" class="mclogiora-switcher__submit">' . esc_html__( 'Go', 'mclogiora' ) . '</button></noscript>';
		$html .= '</form>';

		return $html;
	}

	/**
	 * Builds the label for one language.
	 *
	 * @param array<string,mixed> $item Switcher item.
	 * @param array<string,mixed> $options Resolved options.
	 * @return string
	 */
	private function label( array $item, array $options ) {
		$parts = array();

		if ( $options['show_flag'] ) {
			$flag = $this->flag_for( (string) $item['code'] );

			if ( '' !== $flag ) {
				$parts[] = $flag;
			}
		}

		if ( $options['show_name'] ) {
			$parts[] = (string) $item['name'];
		}

		if ( $options['show_code'] ) {
			$parts[] = strtoupper( (string) $item['code'] );
		}

		if ( empty( $parts ) ) {
			$parts[] = (string) $item['name'];
		}

		return implode( ' ', $parts );
	}

	/**
	 * Returns an optional flag character for a language.
	 *
	 * Flags are opt-in and deliberately unopinionated. A language is not a
	 * country: Spanish is not Spain, Arabic is not any single state, and
	 * English belongs to no flag in particular. Rather than guess, mcLogiora
	 * only shows a flag when a site explicitly maps one through this filter,
	 * and the accessible label never depends on it.
	 *
	 * @param string $code Language code.
	 * @return string
	 */
	private function flag_for( $code ) {
		return (string) apply_filters( 'mclogiora_switcher_flag', '', $code );
	}

	/**
	 * Builds the wrapper class list.
	 *
	 * @param array<string,mixed> $options Resolved options.
	 * @return string
	 */
	private function wrapper_classes( array $options ) {
		$classes = 'mclogiora-switcher mclogiora-switcher--' . $options['style'];

		if ( '' !== $options['class'] ) {
			$classes .= ' ' . $options['class'];
		}

		return $classes;
	}

	/**
	 * Returns the BCP 47 tag for a switcher item.
	 *
	 * The internal language code is the fallback, never the first choice.
	 * `lang` and `hreflang` mean the same thing here as they do in the document
	 * head, and the two describing the same page differently is a contradiction
	 * a reader -- human or machine -- has to resolve for itself.
	 *
	 * @param array<string,mixed> $item Switcher item.
	 * @return string
	 */
	private function language_tag( array $item ) {
		if ( isset( $item['tag'] ) && '' !== (string) $item['tag'] ) {
			return (string) $item['tag'];
		}

		return isset( $item['code'] ) ? (string) $item['code'] : '';
	}
}
