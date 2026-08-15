<?php
/**
 * Payload adapter registry tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Unit;

use McLogiora\Editors\Payload\AcfPayloadAdapter;
use McLogiora\Editors\Payload\ElementorPayloadAdapter;
use McLogiora\Editors\Payload\PayloadAdapterRegistry;
use McLogiora\Editors\Payload\TranslationPayloadAdapterInterface;
use PHPUnit\Framework\TestCase;

/**
 * Covers adapter selection and failure propagation.
 */
final class PayloadAdapterRegistryTest extends TestCase {
	/**
	 * Builds an adapter double.
	 *
	 * @param string          $id Identifier.
	 * @param bool            $available Whether the integration is installed.
	 * @param bool            $applies Whether it has work to do.
	 * @param true|\WP_Error  $result Copy result.
	 * @param array<int,string> $log Shared call log.
	 * @return TranslationPayloadAdapterInterface
	 */
	private function adapter( $id, $available, $applies, $result, array &$log ) {
		return new class( $id, $available, $applies, $result, $log ) implements TranslationPayloadAdapterInterface {
			// phpcs:disable Squiz.Commenting.VariableComment.Missing, Squiz.Commenting.FunctionComment.Missing
			private $id;
			private $available;
			private $applies;
			private $result;
			private $log;

			public function __construct( $id, $available, $applies, $result, array &$log ) {
				$this->id        = $id;
				$this->available = $available;
				$this->applies   = $applies;
				$this->result    = $result;
				$this->log       = &$log;
			}

			public function id() {
				return $this->id;
			}

			public function is_available() {
				return $this->available;
			}

			public function applies_to( $source_id ) {
				return $this->applies;
			}

			public function copy( $source_id, $target_id ) {
				$this->log[] = $this->id;

				return $this->result;
			}
			// phpcs:enable
		};
	}

	/**
	 * Asserts an adapter whose integration is absent is never consulted.
	 *
	 * @return void
	 */
	public function test_unavailable_adapters_are_skipped() {
		$log      = array();
		$registry = new PayloadAdapterRegistry();

		$registry->add( $this->adapter( 'absent', false, true, true, $log ) );

		$this->assertTrue( $registry->prepare( 1, 2 ) );
		$this->assertSame( array(), $log, 'An absent integration must never be asked to copy.' );
		$this->assertSame( array(), $registry->available() );
	}

	/**
	 * Asserts an adapter with nothing to do is not asked to copy.
	 *
	 * @return void
	 */
	public function test_inapplicable_adapters_are_skipped() {
		$log      = array();
		$registry = new PayloadAdapterRegistry();

		$registry->add( $this->adapter( 'idle', true, false, true, $log ) );

		$this->assertTrue( $registry->prepare( 1, 2 ) );
		$this->assertSame( array(), $log );
	}

	/**
	 * Asserts an applicable adapter runs.
	 *
	 * @return void
	 */
	public function test_applicable_adapter_runs() {
		$log      = array();
		$registry = new PayloadAdapterRegistry();

		$registry->add( $this->adapter( 'builder', true, true, true, $log ) );

		$this->assertTrue( $registry->prepare( 1, 2 ) );
		$this->assertSame( array( 'builder' ), $log );
	}

	/**
	 * Asserts the first failure stops the run and is returned.
	 *
	 * A half-prepared draft is worse than a refused one, and the workflow can
	 * only roll back if it hears about the failure.
	 *
	 * @return void
	 */
	public function test_first_failure_stops_the_run() {
		$log      = array();
		$registry = new PayloadAdapterRegistry();

		$registry->add( $this->adapter( 'first', true, true, new \WP_Error( 'boom', 'Copy failed.' ), $log ) );
		$registry->add( $this->adapter( 'second', true, true, true, $log ) );

		$result = $registry->prepare( 1, 2 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'boom', $result->get_error_code() );
		$this->assertSame( array( 'first' ), $log, 'Nothing after a failure should run.' );
	}

	/**
	 * Asserts the shipped adapters are inert when their plugin is absent.
	 *
	 * The unit suite runs without Elementor or ACF loaded, so this is the
	 * no-dependency guarantee stated as a test rather than an intention.
	 *
	 * @return void
	 */
	public function test_shipped_adapters_are_inert_without_their_plugins() {
		$elementor = new ElementorPayloadAdapter();
		$acf       = new AcfPayloadAdapter();

		$this->assertFalse( $elementor->is_available() );
		$this->assertFalse( $elementor->applies_to( 1 ) );
		$this->assertTrue( $elementor->copy( 1, 2 ), 'An absent builder must not fail a translation.' );

		$this->assertFalse( $acf->is_available() );
		$this->assertFalse( $acf->applies_to( 1 ) );
		$this->assertTrue( $acf->copy( 1, 2 ) );
		$this->assertSame( array(), $acf->field_group_titles( 1 ) );
	}

	/**
	 * Asserts ACF never seeds values in this phase.
	 *
	 * Documented as deliberate in ADR-0016. Asserted here so a later change
	 * has to be a decision rather than an accident.
	 *
	 * @return void
	 */
	public function test_acf_adapter_copies_nothing_by_design() {
		$acf = new AcfPayloadAdapter();

		$this->assertFalse( $acf->applies_to( 999 ) );
	}
}
