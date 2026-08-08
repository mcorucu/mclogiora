<?php
/**
 * Registered source string.
 *
 * @package McLogiora
 */

namespace McLogiora\Strings;

defined( 'ABSPATH' ) || exit;

/**
 * A translatable string as it exists in source, independent of any language.
 *
 * Identity is the hash of text, text domain, and context together. Two
 * strings with identical text but different contexts are different strings:
 * gettext contexts exist precisely because the same word can need different
 * translations, and collapsing them would make one of the two wrong.
 */
final class StringSource {
	/**
	 * Internal identifier.
	 *
	 * @var int
	 */
	private $id;

	/**
	 * Stable identity hash.
	 *
	 * @var string
	 */
	private $hash;

	/**
	 * Source text.
	 *
	 * @var string
	 */
	private $text;

	/**
	 * Text domain.
	 *
	 * @var string
	 */
	private $text_domain;

	/**
	 * Gettext context.
	 *
	 * @var string
	 */
	private $context;

	/**
	 * Source type.
	 *
	 * @var string
	 */
	private $source_type;

	/**
	 * Human-readable source reference.
	 *
	 * @var string
	 */
	private $source_reference;

	/**
	 * Source line number.
	 *
	 * @var int
	 */
	private $source_line;

	/**
	 * Whether the string was not seen in the most recent scan.
	 *
	 * @var bool
	 */
	private $stale;

	/**
	 * Constructor.
	 *
	 * @param int    $id Internal identifier.
	 * @param string $text Source text.
	 * @param string $text_domain Text domain.
	 * @param string $context Gettext context.
	 * @param string $source_type Source type.
	 * @param string $source_reference Source reference.
	 * @param int    $source_line Source line.
	 * @param bool   $stale Whether the string is stale.
	 */
	public function __construct( $id, $text, $text_domain = '', $context = '', $source_type = StringSourceType::MANUAL, $source_reference = '', $source_line = 0, $stale = false ) {
		$this->id               = (int) $id;
		$this->text             = (string) $text;
		$this->text_domain      = (string) $text_domain;
		$this->context          = (string) $context;
		$this->source_type      = StringSourceType::is_valid( $source_type ) ? (string) $source_type : StringSourceType::MANUAL;
		$this->source_reference = (string) $source_reference;
		$this->source_line      = (int) $source_line;
		$this->stale            = (bool) $stale;
		$this->hash             = self::hash_for( $this->text, $this->text_domain, $this->context );
	}

	/**
	 * Returns the stable identity hash for a string.
	 *
	 * @param string $text Source text.
	 * @param string $text_domain Text domain.
	 * @param string $context Gettext context.
	 * @return string
	 */
	public static function hash_for( $text, $text_domain = '', $context = '' ) {
		return sha1( (string) $text . "\x1f" . (string) $text_domain . "\x1f" . (string) $context );
	}

	/**
	 * Returns the internal identifier.
	 *
	 * @return int
	 */
	public function id() {
		return $this->id;
	}

	/**
	 * Returns the identity hash.
	 *
	 * @return string
	 */
	public function hash() {
		return $this->hash;
	}

	/**
	 * Returns the source text.
	 *
	 * @return string
	 */
	public function text() {
		return $this->text;
	}

	/**
	 * Returns the text domain.
	 *
	 * @return string
	 */
	public function text_domain() {
		return $this->text_domain;
	}

	/**
	 * Returns the gettext context.
	 *
	 * @return string
	 */
	public function context() {
		return $this->context;
	}

	/**
	 * Returns the source type.
	 *
	 * @return string
	 */
	public function source_type() {
		return $this->source_type;
	}

	/**
	 * Returns the source reference.
	 *
	 * @return string
	 */
	public function source_reference() {
		return $this->source_reference;
	}

	/**
	 * Returns the source line.
	 *
	 * @return int
	 */
	public function source_line() {
		return $this->source_line;
	}

	/**
	 * Returns whether the string is stale.
	 *
	 * @return bool
	 */
	public function is_stale() {
		return $this->stale;
	}
}
