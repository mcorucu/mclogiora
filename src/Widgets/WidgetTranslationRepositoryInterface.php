<?php
/**
 * Widget translation repository contract.
 *
 * @package McLogiora
 */

namespace McLogiora\Widgets;

defined( 'ABSPATH' ) || exit;

/**
 * Stores translated widget field values.
 */
interface WidgetTranslationRepositoryInterface {
	/**
	 * Saves a widget translation.
	 *
	 * @param WidgetTranslation $translation Translation.
	 * @return WidgetTranslation|\WP_Error
	 */
	public function save( WidgetTranslation $translation );

	/**
	 * Finds a widget translation.
	 *
	 * @param string $widget_key Widget instance key.
	 * @param string $language_code Language code.
	 * @return WidgetTranslation|null
	 */
	public function find( $widget_key, $language_code );

	/**
	 * Returns every translation for a widget instance.
	 *
	 * @param string $widget_key Widget instance key.
	 * @return WidgetTranslation[]
	 */
	public function all_for_widget( $widget_key );

	/**
	 * Deletes a widget translation.
	 *
	 * @param string $widget_key Widget instance key.
	 * @param string $language_code Language code.
	 * @return bool|\WP_Error
	 */
	public function delete( $widget_key, $language_code );
}
