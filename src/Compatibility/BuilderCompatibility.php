<?php
/**
 * Builder compatibility record.
 *
 * @package McLogiora
 */

namespace McLogiora\Compatibility;

defined( 'ABSPATH' ) || exit;

/**
 * What mcLogiora knows about one builder, and how it came to know it.
 *
 * "Compatible: yes/no" cannot describe the real situation. A builder whose
 * layout is ordinary block content needs no code from mcLogiora at all and is
 * fully supported; a builder that keeps its layout in post meta needs an
 * adapter and is equally supported once it has one; a builder nobody here can
 * legally install is neither supported nor broken, it is simply unproven.
 * Collapsing those into one boolean would either overclaim or insult a builder
 * that works perfectly.
 *
 * The qualification field is the honest part. It records whether a claim was
 * demonstrated against a running copy of the builder or merely reasoned about.
 */
final class BuilderCompatibility {
	/**
	 * The builder's layout lives in `post_content` and is already copied.
	 *
	 * @var string
	 */
	const STRATEGY_NATIVE = 'native';

	/**
	 * The builder stores its layout outside `post_content` and needs an adapter.
	 *
	 * @var string
	 */
	const STRATEGY_ADAPTER = 'adapter';

	/**
	 * The builder needs nothing from mcLogiora.
	 *
	 * @var string
	 */
	const STRATEGY_NONE = 'none';

	/**
	 * The storage model has not been established from a running copy.
	 *
	 * @var string
	 */
	const STRATEGY_UNKNOWN = 'unknown';

	/**
	 * Exercised against a running copy of the builder.
	 *
	 * @var string
	 */
	const QUALIFIED_LIVE = 'live';

	/**
	 * Covered by an automated test run.
	 *
	 * @var string
	 */
	const QUALIFIED_CI = 'ci';

	/**
	 * Not proven; a legitimate copy of the builder is required.
	 *
	 * @var string
	 */
	const QUALIFIED_DEFERRED = 'deferred';

	/**
	 * Builder identifier.
	 *
	 * @var string
	 */
	private $id;

	/**
	 * Human-readable label.
	 *
	 * @var string
	 */
	private $label;

	/**
	 * Payload strategy.
	 *
	 * @var string
	 */
	private $strategy;

	/**
	 * Qualification state.
	 *
	 * @var string
	 */
	private $qualification;

	/**
	 * Version the claim was established against, when there is one.
	 *
	 * @var string
	 */
	private $tested_version;

	/**
	 * Whether mcLogiora renders its own controls inside the builder's editor.
	 *
	 * @var bool
	 */
	private $editor_ui;

	/**
	 * Whether the builder is installed on this site.
	 *
	 * @var bool
	 */
	private $detected = false;

	/**
	 * Version installed on this site, when detectable.
	 *
	 * @var string
	 */
	private $installed_version = '';

	/**
	 * Constructor.
	 *
	 * @param string $id Builder identifier.
	 * @param string $label Human-readable label.
	 * @param string $strategy Payload strategy.
	 * @param string $qualification Qualification state.
	 * @param string $tested_version Version the claim was established against.
	 * @param bool   $editor_ui Whether mcLogiora renders controls in the builder's editor.
	 */
	public function __construct( $id, $label, $strategy, $qualification, $tested_version = '', $editor_ui = false ) {
		$this->id             = (string) $id;
		$this->label          = (string) $label;
		$this->strategy       = (string) $strategy;
		$this->qualification  = (string) $qualification;
		$this->tested_version = (string) $tested_version;
		$this->editor_ui      = (bool) $editor_ui;
	}

	/**
	 * Records what was found on this site.
	 *
	 * @param bool   $detected Whether the builder is installed.
	 * @param string $version Installed version, when detectable.
	 * @return self
	 */
	public function with_detection( $detected, $version = '' ) {
		$clone                    = clone $this;
		$clone->detected          = (bool) $detected;
		$clone->installed_version = (string) $version;

		return $clone;
	}

	/**
	 * Returns the builder identifier.
	 *
	 * @return string
	 */
	public function id() {
		return $this->id;
	}

	/**
	 * Returns the human-readable label.
	 *
	 * @return string
	 */
	public function label() {
		return $this->label;
	}

	/**
	 * Returns the payload strategy.
	 *
	 * @return string
	 */
	public function strategy() {
		return $this->strategy;
	}

	/**
	 * Returns the qualification state.
	 *
	 * @return string
	 */
	public function qualification() {
		return $this->qualification;
	}

	/**
	 * Returns the version the claim was established against.
	 *
	 * @return string
	 */
	public function tested_version() {
		return $this->tested_version;
	}

	/**
	 * Returns whether the builder is installed here.
	 *
	 * @return bool
	 */
	public function detected() {
		return $this->detected;
	}

	/**
	 * Returns the installed version, when detectable.
	 *
	 * @return string
	 */
	public function installed_version() {
		return $this->installed_version;
	}

	/**
	 * Returns whether mcLogiora renders controls inside the builder's editor.
	 *
	 * @return bool
	 */
	public function has_editor_ui() {
		return $this->editor_ui;
	}

	/**
	 * Returns whether translated content keeps its layout.
	 *
	 * True for both native content and a working adapter: how the layout
	 * survives matters to a developer, not to the person translating.
	 *
	 * @return bool
	 */
	public function preserves_layout() {
		return in_array( $this->strategy, array( self::STRATEGY_NATIVE, self::STRATEGY_ADAPTER ), true );
	}

	/**
	 * Returns a sentence describing the compatibility state.
	 *
	 * Never says "unsupported" about a builder that simply needs no code.
	 *
	 * @return string
	 */
	public function status_label() {
		if ( self::QUALIFIED_DEFERRED === $this->qualification ) {
			return $this->detected
				? __( 'Detected — compatibility not yet verified', 'mclogiora' )
				: __( 'Not verified — a licensed copy is required to qualify', 'mclogiora' );
		}

		switch ( $this->strategy ) {
			case self::STRATEGY_NATIVE:
				return __( 'Compatible — layout travels with the content', 'mclogiora' );
			case self::STRATEGY_ADAPTER:
				return __( 'Compatible — layout copied for translations', 'mclogiora' );
			case self::STRATEGY_NONE:
				return __( 'Compatible — nothing extra required', 'mclogiora' );
			default:
				return __( 'Detected — compatibility not yet verified', 'mclogiora' );
		}
	}
}
