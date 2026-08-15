<?php
/**
 * Translation suggestion result value object.
 *
 * @package McLogiora
 */

namespace McLogiora\Suggestions;

defined( 'ABSPATH' ) || exit;

/**
 * Carries a provider's answer plus what mcLogiora knows about its quality.
 *
 * A suggestion is never applied automatically, so this object exists to give a
 * reviewer everything they need to judge one: the text, who produced it, which
 * model produced it, and any warning raised while checking it.
 *
 * Warnings are deliberately not errors. A suggestion whose placeholders came
 * back altered is still worth showing -- a translator can fix a placeholder far
 * faster than they can retype a sentence -- but it must never be shown as if
 * nothing were wrong.
 */
final class SuggestionResult {
	/**
	 * Suggested text.
	 *
	 * @var string
	 */
	private $text;

	/**
	 * Provider identifier.
	 *
	 * @var string
	 */
	private $provider_id;

	/**
	 * Model identifier, or an empty string for providers without one.
	 *
	 * @var string
	 */
	private $model;

	/**
	 * Human-readable warnings raised while verifying the suggestion.
	 *
	 * @var string[]
	 */
	private $warnings;

	/**
	 * Builds a result.
	 *
	 * @param string   $text Suggested text.
	 * @param string   $provider_id Provider identifier.
	 * @param string   $model Model identifier.
	 * @param string[] $warnings Warnings raised during verification.
	 */
	public function __construct( $text, $provider_id, $model = '', array $warnings = array() ) {
		$this->text        = (string) $text;
		$this->provider_id = (string) $provider_id;
		$this->model       = (string) $model;
		$this->warnings    = array_values( array_filter( array_map( 'strval', $warnings ) ) );
	}

	/**
	 * Returns the suggested text.
	 *
	 * @return string
	 */
	public function text() {
		return $this->text;
	}

	/**
	 * Returns the provider identifier.
	 *
	 * @return string
	 */
	public function provider_id() {
		return $this->provider_id;
	}

	/**
	 * Returns the model identifier.
	 *
	 * @return string
	 */
	public function model() {
		return $this->model;
	}

	/**
	 * Returns the verification warnings.
	 *
	 * @return string[]
	 */
	public function warnings() {
		return $this->warnings;
	}

	/**
	 * Returns whether anything was flagged during verification.
	 *
	 * @return bool
	 */
	public function has_warnings() {
		return array() !== $this->warnings;
	}

	/**
	 * Returns a copy with different text and any additional warnings.
	 *
	 * @param string   $text Replacement text.
	 * @param string[] $warnings Warnings to append.
	 * @return self
	 */
	public function with_text( $text, array $warnings = array() ) {
		return new self(
			$text,
			$this->provider_id,
			$this->model,
			array_merge( $this->warnings, $warnings )
		);
	}
}
