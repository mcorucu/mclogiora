<?php
/**
 * Shared text-field widget adapter behaviour.
 *
 * @package McLogiora
 */

namespace McLogiora\Widgets;

defined( 'ABSPATH' ) || exit;

/**
 * Base for adapters whose translatable fields are plain named strings.
 */
abstract class AbstractTextFieldAdapter implements WidgetAdapterInterface {
	/**
	 * Extracts the translatable source values from a widget instance.
	 *
	 * Only declared fields are read. Anything else in the instance is
	 * ignored rather than carried along.
	 *
	 * @param array<string,mixed> $instance Widget instance options.
	 * @return array<string,string>
	 */
	public function extract( array $instance ) {
		$values = array();

		foreach ( array_keys( $this->translatable_fields() ) as $field ) {
			$values[ $field ] = isset( $instance[ $field ] ) && is_scalar( $instance[ $field ] )
				? (string) $instance[ $field ]
				: '';
		}

		return $values;
	}

	/**
	 * Returns an instance with translated values applied.
	 *
	 * Untranslated or empty fields keep their source value, so a partially
	 * translated widget still renders completely.
	 *
	 * @param array<string,mixed>  $instance Widget instance options.
	 * @param array<string,string> $translated Translated field values.
	 * @return array<string,mixed>
	 */
	public function apply( array $instance, array $translated ) {
		$applied = $instance;

		foreach ( array_keys( $this->translatable_fields() ) as $field ) {
			if ( ! isset( $translated[ $field ] ) ) {
				continue;
			}

			$value = (string) $translated[ $field ];

			if ( '' === $value ) {
				continue;
			}

			$applied[ $field ] = $value;
		}

		return $applied;
	}
}
