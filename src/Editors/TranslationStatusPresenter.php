<?php
/**
 * Translation status presentation.
 *
 * @package McLogiora
 */

namespace McLogiora\Editors;

use McLogiora\Relations\TranslationStatus;

defined( 'ABSPATH' ) || exit;

/**
 * The one place a translation status becomes something a person reads.
 *
 * Before this existed the list-table column carried its own switch statement,
 * and each editor integration would have grown another. Three vocabularies for
 * one state model is how "Needs review" in one screen becomes "Review needed"
 * in the next, and how a status added later gets rendered in one place and
 * silently omitted elsewhere.
 *
 * Presentation is text first. A colour is offered as a hint and never carries
 * meaning on its own, because a status a reader can only get from a coloured
 * dot is a status some readers do not get at all.
 */
final class TranslationStatusPresenter {
	/**
	 * Returns the human-readable label for a status.
	 *
	 * @param string $status Status key.
	 * @return string
	 */
	public function label( $status ) {
		switch ( $status ) {
			case TranslationStatus::ORIGINAL:
				return __( 'Original', 'mclogiora' );
			case TranslationStatus::DRAFT:
				return __( 'Draft', 'mclogiora' );
			case TranslationStatus::TRANSLATED:
				return __( 'Translated', 'mclogiora' );
			case TranslationStatus::NEEDS_REVIEW:
				return __( 'Needs review', 'mclogiora' );
			case TranslationStatus::NEEDS_UPDATE:
				return __( 'Needs update', 'mclogiora' );
			case TranslationStatus::MACHINE_SUGGESTED:
				return __( 'Machine suggested', 'mclogiora' );
			case TranslationStatus::DISABLED:
				return __( 'Disabled', 'mclogiora' );
			default:
				return __( 'Missing', 'mclogiora' );
		}
	}

	/**
	 * Returns a sentence explaining what the status means.
	 *
	 * Shown to everyone, not tucked into a tooltip. "Needs update" is only
	 * useful if the reader knows it means the source moved on.
	 *
	 * @param string $status Status key.
	 * @return string
	 */
	public function description( $status ) {
		switch ( $status ) {
			case TranslationStatus::ORIGINAL:
				return __( 'This is the source content other languages are translated from.', 'mclogiora' );
			case TranslationStatus::DRAFT:
				return __( 'A translation exists and is still being worked on.', 'mclogiora' );
			case TranslationStatus::TRANSLATED:
				return __( 'This translation is complete.', 'mclogiora' );
			case TranslationStatus::NEEDS_REVIEW:
				return __( 'This translation has not been checked against the source yet.', 'mclogiora' );
			case TranslationStatus::NEEDS_UPDATE:
				return __( 'The source content changed after this translation was last updated.', 'mclogiora' );
			case TranslationStatus::MACHINE_SUGGESTED:
				return __( 'A suggested translation is waiting for a person to review it.', 'mclogiora' );
			case TranslationStatus::DISABLED:
				return __( 'This language is switched off for this content.', 'mclogiora' );
			default:
				return __( 'No translation exists for this language yet.', 'mclogiora' );
		}
	}

	/**
	 * Returns a coarse tone for a status.
	 *
	 * A hint for styling only. Every caller is expected to render the label as
	 * well, so nothing here is load-bearing.
	 *
	 * @param string $status Status key.
	 * @return string One of `neutral`, `positive`, `attention`, `muted`.
	 */
	public function tone( $status ) {
		switch ( $status ) {
			case TranslationStatus::TRANSLATED:
				return 'positive';
			case TranslationStatus::NEEDS_UPDATE:
			case TranslationStatus::NEEDS_REVIEW:
				return 'attention';
			case TranslationStatus::DISABLED:
			case TranslationStatus::MISSING:
				return 'muted';
			default:
				return 'neutral';
		}
	}

	/**
	 * Returns a screen-reader sentence naming the language and its status.
	 *
	 * A row reading "Turkce -- Needs update" is unambiguous visually because
	 * the column header supplies the rest. Read aloud in sequence it is not,
	 * so the accessible name carries the whole fact.
	 *
	 * @param string $language_name Language name as shown.
	 * @param string $status Status key.
	 * @return string
	 */
	public function accessible_label( $language_name, $status ) {
		return sprintf(
			/* translators: 1: language name, 2: translation status. */
			__( '%1$s: %2$s', 'mclogiora' ),
			$language_name,
			$this->label( $status )
		);
	}

	/**
	 * Returns the full presentation payload for a status.
	 *
	 * @param string $status Status key.
	 * @return array<string,string>
	 */
	public function present( $status ) {
		$status = TranslationStatus::is_valid( $status ) ? $status : TranslationStatus::MISSING;

		return array(
			'status'      => $status,
			'label'       => $this->label( $status ),
			'description' => $this->description( $status ),
			'tone'        => $this->tone( $status ),
		);
	}
}
