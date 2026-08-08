<?php
/**
 * Translatable content type model.
 *
 * @package McLogiora
 */

namespace McLogiora\Content;

defined( 'ABSPATH' ) || exit;

/**
 * Describes a WordPress post type from mcLogiora's translation perspective.
 */
final class TranslatableContentType {
	/**
	 * Post type name.
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
	 * Whether the post type is public.
	 *
	 * @var bool
	 */
	private $public;

	/**
	 * Whether the post type is a WordPress built-in type.
	 *
	 * @var bool
	 */
	private $built_in;

	/**
	 * Whether mcLogiora can translate this type in the free foundation.
	 *
	 * @var bool
	 */
	private $translatable;

	/**
	 * Exclusion reason, if any.
	 *
	 * @var string
	 */
	private $exclusion_reason;

	/**
	 * Constructor.
	 *
	 * @param string $name Post type name.
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
	 * Returns the post type name.
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
	 * Returns whether the type is public.
	 *
	 * @return bool
	 */
	public function is_public() {
		return $this->public;
	}

	/**
	 * Returns whether the type is built in.
	 *
	 * @return bool
	 */
	public function is_built_in() {
		return $this->built_in;
	}

	/**
	 * Returns whether the type is translatable.
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
