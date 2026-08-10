<?php
/**
 * Language switcher view model.
 *
 * @package McLogiora
 */

namespace McLogiora\Switcher;

use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageTag;
use McLogiora\Relations\ContentType;
use McLogiora\Routing\LanguageContextInterface;
use McLogiora\Routing\MissingTranslationPolicy;
use McLogiora\Routing\RoutingSettings;
use McLogiora\Routing\TranslatedUrlGenerator;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the list of languages a switcher should display.
 *
 * Templates consume this model and never query repositories themselves, so
 * every switcher surface shows the same languages and the same URLs. It is
 * also the only place the missing-translation policy is applied.
 */
final class LanguageSwitcher {
	/**
	 * Language context.
	 *
	 * @var LanguageContextInterface
	 */
	private $context;

	/**
	 * URL generator.
	 *
	 * @var TranslatedUrlGenerator
	 */
	private $urls;

	/**
	 * Routing settings.
	 *
	 * @var RoutingSettings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param LanguageContextInterface $context Language context.
	 * @param TranslatedUrlGenerator   $urls URL generator.
	 * @param RoutingSettings          $settings Routing settings.
	 */
	public function __construct(
		LanguageContextInterface $context,
		TranslatedUrlGenerator $urls,
		RoutingSettings $settings
	) {
		$this->context  = $context;
		$this->urls     = $urls;
		$this->settings = $settings;
	}

	/**
	 * Returns the resolved switcher options for a set of overrides.
	 *
	 * Per-instance attributes override the global settings without changing
	 * them, so one shortcode can be compact while the sidebar stays inline.
	 *
	 * @param array<string,mixed> $overrides Instance overrides.
	 * @return array<string,mixed>
	 */
	public function options( array $overrides = array() ) {
		$settings = $this->settings->all();

		$options = array(
			'style'        => $settings['switcher_style'],
			'show_name'    => (bool) $settings['switcher_show_name'],
			'show_code'    => (bool) $settings['switcher_show_code'],
			'show_flag'    => (bool) $settings['switcher_show_flag'],
			'show_current' => (bool) $settings['switcher_show_current'],
			'missing'      => $settings['switcher_missing'],
			'class'        => '',
		);

		foreach ( $overrides as $key => $value ) {
			if ( ! array_key_exists( $key, $options ) ) {
				continue;
			}

			$options[ $key ] = $value;
		}

		if ( ! SwitcherStyle::is_valid( $options['style'] ) ) {
			$options['style'] = SwitcherStyle::INLINE;
		}

		if ( ! MissingTranslationPolicy::is_valid( $options['missing'] ) ) {
			$options['missing'] = MissingTranslationPolicy::HIDE;
		}

		foreach ( array( 'show_name', 'show_code', 'show_flag', 'show_current' ) as $flag ) {
			$options[ $flag ] = $this->to_bool( $options[ $flag ] );
		}

		/*
		 * A switcher with no visible label is unusable and inaccessible, so a
		 * configuration that hides everything falls back to the native name.
		 */
		if ( ! $options['show_name'] && ! $options['show_code'] && ! $options['show_flag'] ) {
			$options['show_name'] = true;
		}

		$options['class'] = sanitize_html_class( (string) $options['class'] );

		return $options;
	}

	/**
	 * Returns the languages to display, in configured order.
	 *
	 * @param array<string,mixed> $overrides Instance overrides.
	 * @return array<int,array<string,mixed>>
	 */
	public function items( array $overrides = array() ) {
		$options = $this->options( $overrides );
		$current = $this->context->current_code();
		$items   = array();

		foreach ( $this->context->available() as $language ) {
			if ( ! $language instanceof Language ) {
				continue;
			}

			$is_current = $language->code() === $current;

			if ( $is_current && ! $options['show_current'] ) {
				continue;
			}

			$url = $is_current ? $this->current_url( $language ) : $this->url_for( $language );

			if ( null === $url ) {
				if ( MissingTranslationPolicy::HIDE === $options['missing'] ) {
					continue;
				}

				if ( MissingTranslationPolicy::HOME === $options['missing'] ) {
					$url = $this->urls->home_url_for( $language->code() );
				}
			}

			$items[] = array(
				'code'       => $language->code(),
				'tag'        => LanguageTag::for_language( $language ),
				'locale'     => $language->locale(),
				'name'       => $language->native_name(),
				'english'    => $language->english_name(),
				'direction'  => $language->direction(),
				'url'        => $url,
				'is_current' => $is_current,
				'available'  => null !== $url,
			);
		}

		return $items;
	}

	/**
	 * Returns the URL of the current page in another language.
	 *
	 * Returns null when no translation exists. A plausible-looking URL is
	 * never invented: linking to a page that does not exist, or to source
	 * content pretending to be translated, is worse than offering nothing.
	 *
	 * @param Language $language Target language.
	 * @return string|null
	 */
	private function url_for( Language $language ) {
		$queried = $this->queried_object();

		if ( null === $queried ) {
			return $this->urls->home_url_for( $language->code() );
		}

		if ( ContentType::POST === $queried['type'] ) {
			return $this->urls->post_url( $queried['id'], $language->code() );
		}

		return $this->urls->term_url( $queried['id'], $queried['taxonomy'], $language->code() );
	}

	/**
	 * Returns the URL representing the current page in its own language.
	 *
	 * @param Language $language Current language.
	 * @return string|null
	 */
	private function current_url( Language $language ) {
		$queried = $this->queried_object();

		if ( null === $queried ) {
			return $this->urls->home_url_for( $language->code() );
		}

		if ( ContentType::POST === $queried['type'] ) {
			$permalink = get_permalink( $queried['id'] );

			return is_string( $permalink ) ? $permalink : null;
		}

		$link = get_term_link( $queried['id'], $queried['taxonomy'] );

		return is_string( $link ) ? $link : null;
	}

	/**
	 * Describes the object the current request resolved to.
	 *
	 * @return array{type:string,id:int,taxonomy:string}|null
	 */
	private function queried_object() {
		if ( ! function_exists( 'get_queried_object' ) ) {
			return null;
		}

		$object = get_queried_object();

		if ( $object instanceof \WP_Post ) {
			return array(
				'type'     => ContentType::POST,
				'id'       => (int) $object->ID,
				'taxonomy' => '',
			);
		}

		if ( $object instanceof \WP_Term ) {
			return array(
				'type'     => ContentType::TERM,
				'id'       => (int) $object->term_id,
				'taxonomy' => (string) $object->taxonomy,
			);
		}

		return null;
	}

	/**
	 * Casts a mixed option value to a boolean.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	private function to_bool( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}

		return in_array( strtolower( (string) $value ), array( '1', 'true', 'yes', 'on' ), true );
	}
}
