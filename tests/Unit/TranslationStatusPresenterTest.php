<?php
/**
 * Translation status presentation tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Unit;

use McLogiora\Editors\TranslationStatusPresenter;
use McLogiora\Relations\TranslationStatus;
use PHPUnit\Framework\TestCase;

/**
 * Covers the single status vocabulary shared by every editor surface.
 */
final class TranslationStatusPresenterTest extends TestCase {
	/**
	 * Presenter under test.
	 *
	 * @var TranslationStatusPresenter
	 */
	private $presenter;

	/**
	 * Sets up the presenter.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->presenter = new TranslationStatusPresenter();
	}

	/**
	 * Asserts every status in the model has its own label and description.
	 *
	 * A status the state machine can produce but the UI cannot name would be
	 * rendered as "Missing" to a reader, which is a different fact.
	 *
	 * @return void
	 */
	public function test_every_status_has_a_distinct_label_and_description() {
		$labels       = array();
		$descriptions = array();

		foreach ( TranslationStatus::all() as $status ) {
			$presented = $this->presenter->present( $status );

			$this->assertNotSame( '', $presented['label'], "No label for {$status}." );
			$this->assertNotSame( '', $presented['description'], "No description for {$status}." );

			$labels[]       = $presented['label'];
			$descriptions[] = $presented['description'];
		}

		$this->assertSame( count( $labels ), count( array_unique( $labels ) ), 'Two statuses share a label.' );
		$this->assertSame( count( $descriptions ), count( array_unique( $descriptions ) ), 'Two statuses share a description.' );
	}

	/**
	 * Asserts an unknown status degrades to missing rather than blank.
	 *
	 * @return void
	 */
	public function test_unknown_status_is_presented_as_missing() {
		$presented = $this->presenter->present( 'not_a_real_status' );

		$this->assertSame( TranslationStatus::MISSING, $presented['status'] );
		$this->assertSame( $this->presenter->label( TranslationStatus::MISSING ), $presented['label'] );
	}

	/**
	 * Asserts the tone is only ever a hint alongside a label.
	 *
	 * Colour must not be the only way to tell two states apart, so every
	 * status is required to carry text regardless of the tone it maps to.
	 *
	 * @return void
	 */
	public function test_tone_never_replaces_the_label() {
		foreach ( TranslationStatus::all() as $status ) {
			$presented = $this->presenter->present( $status );

			$this->assertContains(
				$presented['tone'],
				array( 'neutral', 'positive', 'attention', 'muted' ),
				"Unexpected tone for {$status}."
			);
			$this->assertNotSame( '', trim( $presented['label'] ) );
		}
	}

	/**
	 * Asserts outdated translations are marked for attention.
	 *
	 * @return void
	 */
	public function test_needs_update_is_an_attention_state() {
		$this->assertSame( 'attention', $this->presenter->tone( TranslationStatus::NEEDS_UPDATE ) );
		$this->assertSame( 'attention', $this->presenter->tone( TranslationStatus::NEEDS_REVIEW ) );
		$this->assertSame( 'positive', $this->presenter->tone( TranslationStatus::TRANSLATED ) );
	}

	/**
	 * Asserts the needs-update description names the cause.
	 *
	 * @return void
	 */
	public function test_needs_update_description_explains_the_cause() {
		$this->assertStringContainsString(
			'source content changed',
			$this->presenter->description( TranslationStatus::NEEDS_UPDATE )
		);
	}

	/**
	 * Asserts the accessible label carries language and status together.
	 *
	 * @return void
	 */
	public function test_accessible_label_names_the_language_and_the_status() {
		$label = $this->presenter->accessible_label( 'Turkce', TranslationStatus::NEEDS_UPDATE );

		$this->assertStringContainsString( 'Turkce', $label );
		$this->assertStringContainsString( $this->presenter->label( TranslationStatus::NEEDS_UPDATE ), $label );
	}
}
