<?php
/**
 * Builder compatibility reporting tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Unit;

use McLogiora\Compatibility\BuilderCompatibility;
use McLogiora\Editors\Payload\BeaverBuilderPayloadAdapter;
use PHPUnit\Framework\TestCase;

/**
 * Covers how compatibility is described, and the Beaver adapter's guards.
 */
final class BuilderCompatibilityTest extends TestCase {
	/**
	 * Asserts a builder needing no code is still reported as compatible.
	 *
	 * A block builder whose layout is ordinary post content works perfectly
	 * and needs nothing from mcLogiora. Describing that as unsupported would
	 * be wrong about the builder and wrong about the plugin.
	 *
	 * @return void
	 */
	public function test_native_builder_is_compatible_without_an_adapter() {
		$record = new BuilderCompatibility(
			'kadence-blocks',
			'Kadence Blocks',
			BuilderCompatibility::STRATEGY_NATIVE,
			BuilderCompatibility::QUALIFIED_LIVE,
			'3.7.9.1'
		);

		$this->assertTrue( $record->preserves_layout() );
		$this->assertStringContainsString( 'Compatible', $record->status_label() );
		$this->assertStringNotContainsString( 'not yet verified', $record->status_label() );
	}

	/**
	 * Asserts an adapter-backed builder is described as compatible too.
	 *
	 * @return void
	 */
	public function test_adapter_builder_is_compatible() {
		$record = new BuilderCompatibility(
			'beaver-builder',
			'Beaver Builder',
			BuilderCompatibility::STRATEGY_ADAPTER,
			BuilderCompatibility::QUALIFIED_LIVE,
			'2.10.3.2'
		);

		$this->assertTrue( $record->preserves_layout() );
		$this->assertStringContainsString( 'Compatible', $record->status_label() );
	}

	/**
	 * Asserts a deferred builder never claims layout preservation.
	 *
	 * This is the claim that must not leak. Nothing was proven, so nothing is
	 * promised.
	 *
	 * @return void
	 */
	public function test_deferred_builder_claims_nothing() {
		$record = new BuilderCompatibility(
			'bricks',
			'Bricks',
			BuilderCompatibility::STRATEGY_UNKNOWN,
			BuilderCompatibility::QUALIFIED_DEFERRED
		);

		$this->assertFalse( $record->preserves_layout() );
		$this->assertStringContainsString( 'not', strtolower( $record->status_label() ) );
		$this->assertSame( '', $record->tested_version() );
	}

	/**
	 * Asserts a deferred builder that is installed says so.
	 *
	 * "Not detected" and "detected but unproven" are different facts and a
	 * site owner needs to tell them apart.
	 *
	 * @return void
	 */
	public function test_detected_but_deferred_is_distinguishable() {
		$record = new BuilderCompatibility(
			'divi',
			'Divi',
			BuilderCompatibility::STRATEGY_UNKNOWN,
			BuilderCompatibility::QUALIFIED_DEFERRED
		);

		$absent  = $record->with_detection( false );
		$present = $record->with_detection( true, '5.0' );

		$this->assertNotSame( $absent->status_label(), $present->status_label() );
		$this->assertStringContainsString( 'Detected', $present->status_label() );
		$this->assertSame( '5.0', $present->installed_version() );
		$this->assertFalse( $present->preserves_layout(), 'Being installed proves nothing.' );
	}

	/**
	 * Asserts detection is recorded without mutating the shared record.
	 *
	 * @return void
	 */
	public function test_detection_does_not_mutate_the_original_record() {
		$record   = new BuilderCompatibility( 'oxygen', 'Oxygen', BuilderCompatibility::STRATEGY_UNKNOWN, BuilderCompatibility::QUALIFIED_DEFERRED );
		$detected = $record->with_detection( true, '4.0' );

		$this->assertFalse( $record->detected() );
		$this->assertTrue( $detected->detected() );
	}

	/**
	 * Asserts no status label ever calls a builder unsupported.
	 *
	 * @return void
	 */
	public function test_no_status_label_uses_unsupported_language() {
		$strategies = array(
			BuilderCompatibility::STRATEGY_NATIVE,
			BuilderCompatibility::STRATEGY_ADAPTER,
			BuilderCompatibility::STRATEGY_NONE,
			BuilderCompatibility::STRATEGY_UNKNOWN,
		);

		foreach ( $strategies as $strategy ) {
			foreach ( array( BuilderCompatibility::QUALIFIED_LIVE, BuilderCompatibility::QUALIFIED_DEFERRED ) as $qualification ) {
				$label = ( new BuilderCompatibility( 'x', 'X', $strategy, $qualification ) )->status_label();

				$this->assertStringNotContainsString( 'unsupported', strtolower( $label ) );
				$this->assertStringNotContainsString( 'incompatible', strtolower( $label ) );
				$this->assertStringNotContainsString( 'premium', strtolower( $label ) );
			}
		}
	}

	/**
	 * Asserts the Beaver adapter is inert when Beaver Builder is absent.
	 *
	 * The unit suite runs without it loaded, so this is the no-dependency
	 * guarantee stated as a test.
	 *
	 * @return void
	 */
	public function test_beaver_adapter_is_inert_without_beaver_builder() {
		$adapter = new BeaverBuilderPayloadAdapter();

		$this->assertSame( 'beaver-builder', $adapter->id() );
		$this->assertFalse( $adapter->is_available() );
		$this->assertFalse( $adapter->applies_to( 1 ) );
		$this->assertTrue( $adapter->copy( 1, 2 ), 'An absent builder must never fail a translation.' );
	}
}
