<?php
/**
 * Public template tags.
 *
 * @package McLogiora
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'mclogiora_language_switcher' ) ) {
	/**
	 * Returns the language switcher markup.
	 *
	 * Intended for use in theme templates:
	 *
	 *     echo mclogiora_language_switcher( array( 'style' => 'dropdown' ) );
	 *
	 * @param array<string,mixed> $args Display overrides.
	 * @return string
	 */
	function mclogiora_language_switcher( array $args = array() ) {
		$module = \McLogiora\Core\Application::instance()->container();

		if ( ! $module->has( \McLogiora\Switcher\SwitcherModule::class ) ) {
			return '';
		}

		return $module->get( \McLogiora\Switcher\SwitcherModule::class )->render( $args );
	}
}

if ( ! function_exists( 'mclogiora_the_language_switcher' ) ) {
	/**
	 * Prints the language switcher.
	 *
	 * @param array<string,mixed> $args Display overrides.
	 * @return void
	 */
	function mclogiora_the_language_switcher( array $args = array() ) {
		echo wp_kses_post( mclogiora_language_switcher( $args ) );
	}
}

if ( ! function_exists( 'mclogiora_current_language' ) ) {
	/**
	 * Returns the current language code for the request.
	 *
	 * @return string
	 */
	function mclogiora_current_language() {
		$container = \McLogiora\Core\Application::instance()->container();

		if ( ! $container->has( \McLogiora\Routing\LanguageContextInterface::class ) ) {
			return '';
		}

		return $container->get( \McLogiora\Routing\LanguageContextInterface::class )->current_code();
	}
}
