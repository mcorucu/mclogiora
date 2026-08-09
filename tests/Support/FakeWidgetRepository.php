<?php
/**
 * In-memory widget translation repository for tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Support;

use McLogiora\Widgets\WidgetTranslation;
use McLogiora\Widgets\WidgetTranslationRepositoryInterface;

/**
 * Stores widget translations in memory.
 */
final class FakeWidgetRepository implements WidgetTranslationRepositoryInterface {
	/**
	 * Translations keyed by "widget_key:language".
	 *
	 * @var array<string,WidgetTranslation>
	 */
	private $translations = array();

	/**
	 * {@inheritDoc}
	 *
	 * @param WidgetTranslation $translation Translation.
	 * @return WidgetTranslation|\WP_Error
	 */
	public function save( WidgetTranslation $translation ) {
		$this->translations[ $translation->widget_key() . ':' . $translation->language_code() ] = $translation;

		return $translation;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $widget_key Widget key.
	 * @param string $language_code Language code.
	 * @return WidgetTranslation|null
	 */
	public function find( $widget_key, $language_code ) {
		$key = (string) $widget_key . ':' . (string) $language_code;

		return isset( $this->translations[ $key ] ) ? $this->translations[ $key ] : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $widget_key Widget key.
	 * @return WidgetTranslation[]
	 */
	public function all_for_widget( $widget_key ) {
		$found = array();

		foreach ( $this->translations as $translation ) {
			if ( $translation->widget_key() === (string) $widget_key ) {
				$found[] = $translation;
			}
		}

		return $found;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $widget_key Widget key.
	 * @param string $language_code Language code.
	 * @return bool|\WP_Error
	 */
	public function delete( $widget_key, $language_code ) {
		unset( $this->translations[ (string) $widget_key . ':' . (string) $language_code ] );

		return true;
	}
}
