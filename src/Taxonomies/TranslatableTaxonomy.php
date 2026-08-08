<?php
/**
 * Translatable taxonomy model.
 *
 * @package McLogiora
 */

namespace McLogiora\Taxonomies;

defined( 'ABSPATH' ) || exit;

/**
 * Describes a WordPress taxonomy from mcLogiora's translation perspective.
 */
final class TranslatableTaxonomy {
	/**
	 * Taxonomy name.
	 *
	 * @var string
	 */
	private $name;

	/**
	 * Human-readable label.
	 *
	 * @var string
	 */
	private $label;

	/**
	 * Whether the taxonomy is public.
	 *
	 * @var bool
	 */
	private $public;

	/**
	 * Whether built in.
	 *
	 * @var bool
	 */
	private $built_in;

	/**
	 * Whether translatable in the free foundation.
	 *
	 * @var bool
	 */
	private $translatable;

	/**
	 * Exclusion reason.
	 *
	 * @var string
	 */
	private $exclusion_reason;

	/**
	 * Constructor.
	 *
	 * @param string $name Taxonomy name.
	 * @param string $label Label.
	 * @param bool   $public Whether public.
	 * @param bool   $built_in Whether built in.
	 * @param bool   $translatable Whether translatable.
	 * @param string $exclusion_reason Exclusion reason.
	 */
	public function __construct( $name, $label, $public, $built_in, $translatable, $exclusion_reason = '' ) {
		$this->name             = sanitize_key( $name );
		$this->label            = sanitize_text_field( $label );
		$this->public           = (bool) $public;
		$this->built_in         = (bool) $built_in;
		$this->translatable     = (bool) $translatable;
		$this->exclusion_reason = sanitize_text_field( $exclusion_reason );
	}

	/**
	 * Returns the taxonomy name.
	 *
	 * @return string
	 */
	public function name() {
		return $this->name;
	}

	/**
	 * Returns the label.
	 *
	 * @return string
	 */
	public function label() {
		return $this->label;
	}

	/**
	 * Returns whether public.
	 *
	 * @return bool
	 */
	public function is_public() {
		return $this->public;
	}

	/**
	 * Returns whether built in.
	 *
	 * @return bool
	 */
	public function is_built_in() {
		return $this->built_in;
	}

	/**
	 * Returns whether translatable.
	 *
	 * @return bool
	 */
	public function is_translatable() {
		return $this->translatable;
	}

	/**
	 * Returns the exclusion reason.
	 *
	 * @return string
	 */
	public function exclusion_reason() {
		return $this->exclusion_reason;
	}
}
