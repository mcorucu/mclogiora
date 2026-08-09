<?php
/**
 * Routing and switcher settings.
 *
 * @package McLogiora
 */

namespace McLogiora\Routing;

use McLogiora\Switcher\SwitcherStyle;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the Phase 12 settings.
 *
 * Stored in a single versioned option rather than one option per setting, so
 * reading the routing configuration on a front-end request is one autoloaded
 * lookup rather than a dozen.
 */
final class RoutingSettings {
	const OPTION_NAME = 'mclogiora_routing_settings';

	/**
	 * Returns the default settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'url_strategy'            => UrlStrategy::DIRECTORY,
			'default_language_prefix' => false,
			'switcher_style'          => 'inline',
			'switcher_show_name'      => true,
			'switcher_show_code'      => false,
			'switcher_show_flag'      => false,
			'switcher_show_current'   => true,
			'switcher_missing'        => MissingTranslationPolicy::HIDE,
		);
	}

	/**
	 * Returns the stored settings merged over the defaults.
	 *
	 * @return array<string,mixed>
	 */
	public function all() {
		$stored = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array_merge( self::defaults(), $stored );
	}

	/**
	 * Returns one setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public function get( $key ) {
		$all = $this->all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : null;
	}

	/**
	 * Returns whether the default language carries a URL prefix.
	 *
	 * The default is false: the default language lives at the site root, so an
	 * existing single-language site keeps every URL it already had. Turning it
	 * on would change every published permalink at once.
	 *
	 * @return bool
	 */
	public function default_language_has_prefix() {
		return (bool) $this->get( 'default_language_prefix' );
	}

	/**
	 * Returns the configured URL strategy, falling back to a supported one.
	 *
	 * @return string
	 */
	public function url_strategy() {
		$strategy = (string) $this->get( 'url_strategy' );

		return UrlStrategy::is_supported( $strategy ) ? $strategy : UrlStrategy::DIRECTORY;
	}

	/**
	 * Saves validated settings.
	 *
	 * Returns whether the change affects routing, so the caller can flush
	 * rewrite rules only when the URL shape actually changed.
	 *
	 * @param array<string,mixed> $input Raw settings.
	 * @return array{settings:array<string,mixed>,routing_changed:bool}
	 */
	public function save( array $input ) {
		$current  = $this->all();
		$defaults = self::defaults();
		$clean    = array();

		$clean['url_strategy']            = UrlStrategy::is_supported( isset( $input['url_strategy'] ) ? $input['url_strategy'] : '' )
			? (string) $input['url_strategy']
			: $defaults['url_strategy'];
		$clean['default_language_prefix'] = ! empty( $input['default_language_prefix'] );
		$clean['switcher_style']          = SwitcherStyle::is_valid( isset( $input['switcher_style'] ) ? $input['switcher_style'] : '' )
			? (string) $input['switcher_style']
			: $defaults['switcher_style'];
		$clean['switcher_show_name']      = ! empty( $input['switcher_show_name'] );
		$clean['switcher_show_code']      = ! empty( $input['switcher_show_code'] );
		$clean['switcher_show_flag']      = ! empty( $input['switcher_show_flag'] );
		$clean['switcher_show_current']   = ! empty( $input['switcher_show_current'] );
		$clean['switcher_missing']        = MissingTranslationPolicy::is_valid( isset( $input['switcher_missing'] ) ? $input['switcher_missing'] : '' )
			? (string) $input['switcher_missing']
			: $defaults['switcher_missing'];

		/*
		 * A switcher that shows nothing at all is a configuration mistake, not
		 * a preference. Fall back to showing the native name.
		 */
		if ( ! $clean['switcher_show_name'] && ! $clean['switcher_show_code'] && ! $clean['switcher_show_flag'] ) {
			$clean['switcher_show_name'] = true;
		}

		update_option( self::OPTION_NAME, $clean, true );

		$routing_changed = $current['url_strategy'] !== $clean['url_strategy']
			|| (bool) $current['default_language_prefix'] !== $clean['default_language_prefix'];

		return array(
			'settings'        => $clean,
			'routing_changed' => $routing_changed,
		);
	}
}
