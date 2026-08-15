<?php
/**
 * Applies a reviewed translation suggestion.
 *
 * @package McLogiora
 */

namespace McLogiora\Suggestions;

use McLogiora\Relations\ContentType;
use McLogiora\Relations\TranslationStatus;
use McLogiora\Workflows\TranslationWorkflowService;

defined( 'ABSPATH' ) || exit;

/**
 * The only operation in mcLogiora that writes a machine-generated translation.
 *
 * Kept rigidly separate from {@see TranslationSuggestionService}. Generate
 * produces a proposal and touches nothing; Apply is the explicit, authenticated
 * act of accepting one. Collapsing them into a single method with a flag is
 * how "show me what it would say" quietly becomes "change my site", and the
 * separation is what makes Generate's zero-mutation promise checkable rather
 * than merely claimed.
 *
 * ## The suggestion text is never accepted from the caller
 *
 * Apply takes a token, not a translation. The text it writes is the text the
 * server generated, verified and stored. A caller who tampers with the payload
 * is writing into a field that is not read.
 *
 * ## Only supported fields, by explicit mapping
 *
 * There is no path from a caller-supplied field name to a WordPress update
 * call. The surface is matched against a fixed list and each match names its
 * own column. A field that is not in {@see SuggestionSurface} cannot be
 * reached, however the request is shaped.
 */
final class TranslationSuggestionApplyService {
	/**
	 * Preview storage.
	 *
	 * @var SuggestionPreviewStore
	 */
	private $previews;

	/**
	 * Workflow service used for relation status changes.
	 *
	 * @var TranslationWorkflowService
	 */
	private $workflows;

	/**
	 * Media translation storage, when wired.
	 *
	 * @var \McLogiora\Media\MediaTranslationService|null
	 */
	private $media;

	/**
	 * String translation storage, when wired.
	 *
	 * @var \McLogiora\Strings\StringTranslationService|null
	 */
	private $strings;

	/**
	 * Builds the service.
	 *
	 * The two storage services are optional so the post path stays
	 * constructible on its own, which keeps its unit tests free of
	 * dependencies they do not exercise. A surface whose service is absent
	 * refuses rather than half-writing.
	 *
	 * @param SuggestionPreviewStore                           $previews Preview storage.
	 * @param TranslationWorkflowService                       $workflows Workflow service.
	 * @param \McLogiora\Media\MediaTranslationService|null    $media Media translation storage.
	 * @param \McLogiora\Strings\StringTranslationService|null $strings String translation storage.
	 */
	public function __construct(
		SuggestionPreviewStore $previews,
		TranslationWorkflowService $workflows,
		$media = null,
		$strings = null
	) {
		$this->previews  = $previews;
		$this->workflows = $workflows;
		$this->media     = $media;
		$this->strings   = $strings;
	}

	/**
	 * Applies a stored suggestion to its bound target.
	 *
	 * @param string $token Preview token.
	 * @param array  $context Expected binding, supplied by the caller.
	 * @return SuggestionPreview|\WP_Error The applied preview, or a failure.
	 */
	public function apply( $token, array $context ) {
		$preview = $this->previews->find( $token );

		if ( ! $preview instanceof SuggestionPreview ) {
			/*
			 * Covers an unknown token, an expired one and one already consumed
			 * by an earlier apply. All three are the same fact from here --
			 * there is no such preview -- and answering them differently would
			 * tell a caller which tokens once existed.
			 */
			return new \WP_Error(
				SuggestionError::INVALID_REQUEST,
				__( 'This translation suggestion is no longer available. Generate a new one.', 'mclogiora' )
			);
		}

		$user_id = isset( $context['user_id'] ) ? (int) $context['user_id'] : 0;

		if ( ! $preview->belongs_to(
			$user_id,
			isset( $context['object_type'] ) ? $context['object_type'] : '',
			isset( $context['target_id'] ) ? $context['target_id'] : '',
			isset( $context['surface'] ) ? $context['surface'] : '',
			isset( $context['target_language'] ) ? $context['target_language'] : ''
		) ) {
			/*
			 * The token was real but the context is not the one it was made
			 * for: another user, another object, another field or another
			 * language. The preview is deliberately left in place -- deleting
			 * it here would let anyone holding a stolen token destroy the
			 * legitimate owner's work with a wrong-context request.
			 */
			return new \WP_Error(
				SuggestionError::INVALID_REQUEST,
				__( 'This translation suggestion does not belong to this content.', 'mclogiora' )
			);
		}

		if ( ! SuggestionSurface::is_supported( $preview->surface() ) ) {
			return new \WP_Error(
				SuggestionError::INVALID_REQUEST,
				__( 'Translation suggestions cannot be applied to this field.', 'mclogiora' )
			);
		}

		return $this->apply_to_surface( $preview );
	}

	/**
	 * Discards a preview without applying it.
	 *
	 * @param string $token Preview token.
	 * @param array  $context Expected binding, supplied by the caller.
	 * @return true|\WP_Error
	 */
	public function discard( $token, array $context ) {
		$preview = $this->previews->find( $token );

		if ( ! $preview instanceof SuggestionPreview ) {
			return true;
		}

		if ( ! $preview->belongs_to(
			isset( $context['user_id'] ) ? (int) $context['user_id'] : 0,
			isset( $context['object_type'] ) ? $context['object_type'] : '',
			isset( $context['target_id'] ) ? $context['target_id'] : '',
			isset( $context['surface'] ) ? $context['surface'] : '',
			isset( $context['target_language'] ) ? $context['target_language'] : ''
		) ) {
			return new \WP_Error(
				SuggestionError::INVALID_REQUEST,
				__( 'This translation suggestion does not belong to this content.', 'mclogiora' )
			);
		}

		$this->previews->discard( $token );

		return true;
	}

	/**
	 * Routes an applicable preview to the writer for its surface.
	 *
	 * @param SuggestionPreview $preview Validated preview.
	 * @return SuggestionPreview|\WP_Error
	 */
	private function apply_to_surface( SuggestionPreview $preview ) {
		switch ( $preview->surface() ) {
			case SuggestionSurface::POST_TITLE:
			case SuggestionSurface::POST_EXCERPT:
				return $this->apply_to_post( $preview );

			case SuggestionSurface::TERM_NAME:
			case SuggestionSurface::TERM_DESCRIPTION:
				return $this->apply_to_term( $preview );

			case SuggestionSurface::MEDIA_TITLE:
			case SuggestionSurface::MEDIA_ALT:
			case SuggestionSurface::MEDIA_CAPTION:
			case SuggestionSurface::MEDIA_DESCRIPTION:
				return $this->apply_to_media( $preview );

			case SuggestionSurface::STRING:
				return $this->apply_to_string( $preview );

			default:
				return new \WP_Error(
					SuggestionError::INVALID_REQUEST,
					__( 'Applying suggestions to this field is not available yet.', 'mclogiora' )
				);
		}
	}

	/**
	 * Writes a suggestion to a term field and records the review state.
	 *
	 * Terms are relation-backed like posts, so they get the same
	 * field-then-status ordering and the same compensating restore.
	 *
	 * @param SuggestionPreview $preview Validated preview.
	 * @return SuggestionPreview|\WP_Error
	 */
	private function apply_to_term( SuggestionPreview $preview ) {
		$term_id = (int) $preview->target_id();
		$term    = get_term( $term_id );

		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error(
				SuggestionError::INVALID_REQUEST,
				__( 'The translated term this suggestion belongs to no longer exists.', 'mclogiora' )
			);
		}

		if ( ! current_user_can( 'edit_term', $term_id ) ) {
			return new \WP_Error(
				SuggestionError::INVALID_REQUEST,
				__( 'You are not allowed to edit this term.', 'mclogiora' )
			);
		}

		$is_name  = SuggestionSurface::TERM_NAME === $preview->surface();
		$previous = $is_name ? (string) $term->name : (string) $term->description;

		$updated = $this->write_term_field( $term_id, $term->taxonomy, $is_name, $preview->text() );

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		$status = $this->workflows->change_status(
			ContentType::TERM,
			$term_id,
			$preview->target_language(),
			TranslationStatus::MACHINE_SUGGESTED
		);

		if ( is_wp_error( $status ) ) {
			$this->write_term_field( $term_id, $term->taxonomy, $is_name, $previous );

			return $status;
		}

		$this->previews->consume( $preview->token() );

		return $preview;
	}

	/**
	 * Writes one of the two permitted term fields.
	 *
	 * The slug is never passed, so `wp_update_term()` leaves it alone. That is
	 * the whole reason this is two literal calls rather than one dynamic
	 * array: a machine-translated slug would change every URL the term owns,
	 * silently, and no care at the call site makes a `[$field => $value]`
	 * payload safe from that.
	 *
	 * @param int    $term_id Term identifier.
	 * @param string $taxonomy Taxonomy name.
	 * @param bool   $is_name Whether the name is being written.
	 * @param string $value Value to write.
	 * @return array<string,int>|\WP_Error
	 */
	private function write_term_field( $term_id, $taxonomy, $is_name, $value ) {
		if ( $is_name ) {
			return wp_update_term( (int) $term_id, (string) $taxonomy, array( 'name' => (string) $value ) );
		}

		return wp_update_term( (int) $term_id, (string) $taxonomy, array( 'description' => (string) $value ) );
	}

	/**
	 * Writes a suggestion to one translated media metadata field.
	 *
	 * Phase 11's media service replaces the whole per-language record on save,
	 * so the current values are read first and exactly one is replaced.
	 * Writing only the suggested field would silently erase the translator's
	 * title, caption and description -- a data-loss bug that a test asserting
	 * "the alt text changed" would happily pass.
	 *
	 * Media translations carry no machine-review state: that service records
	 * every save as translated, and inventing a relation row to mimic
	 * `machine_suggested` would be a schema change made for UI symmetry rather
	 * than for truth. The suggestion is applied; no status is claimed.
	 *
	 * @param SuggestionPreview $preview Validated preview.
	 * @return SuggestionPreview|\WP_Error
	 */
	private function apply_to_media( SuggestionPreview $preview ) {
		if ( null === $this->media ) {
			return new \WP_Error(
				SuggestionError::INVALID_REQUEST,
				__( 'Media translations are not available on this site.', 'mclogiora' )
			);
		}

		$attachment_id = (int) $preview->target_id();
		$language      = $preview->target_language();
		$current       = $this->media->metadata_for_language( $attachment_id, $language );

		if ( ! is_array( $current ) ) {
			return new \WP_Error(
				SuggestionError::INVALID_REQUEST,
				__( 'The translated media metadata could not be read.', 'mclogiora' )
			);
		}

		$fields = array(
			'title'       => isset( $current['title'] ) ? (string) $current['title'] : '',
			'alt_text'    => isset( $current['alt_text'] ) ? (string) $current['alt_text'] : '',
			'caption'     => isset( $current['caption'] ) ? (string) $current['caption'] : '',
			'description' => isset( $current['description'] ) ? (string) $current['description'] : '',
		);

		switch ( $preview->surface() ) {
			case SuggestionSurface::MEDIA_TITLE:
				$fields['title'] = $preview->text();
				break;

			case SuggestionSurface::MEDIA_ALT:
				$fields['alt_text'] = $preview->text();
				break;

			case SuggestionSurface::MEDIA_CAPTION:
				$fields['caption'] = $preview->text();
				break;

			default:
				$fields['description'] = $preview->text();
				break;
		}

		$saved = $this->media->save( $attachment_id, $language, $fields );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$this->previews->consume( $preview->token() );

		return $preview;
	}

	/**
	 * Stores a suggested interface-string translation.
	 *
	 * String translations are not relation-backed, so no relation status is
	 * touched. They carry their own status column, and it accepts the
	 * machine-suggested value honestly, so a suggested string is recorded as
	 * suggested rather than passed off as a finished translation.
	 *
	 * @param SuggestionPreview $preview Validated preview.
	 * @return SuggestionPreview|\WP_Error
	 */
	private function apply_to_string( SuggestionPreview $preview ) {
		if ( null === $this->strings ) {
			return new \WP_Error(
				SuggestionError::INVALID_REQUEST,
				__( 'String translations are not available on this site.', 'mclogiora' )
			);
		}

		$saved = $this->strings->save_translation(
			(int) $preview->target_id(),
			$preview->target_language(),
			$preview->text(),
			TranslationStatus::MACHINE_SUGGESTED
		);

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$this->previews->consume( $preview->token() );

		return $preview;
	}

	/**
	 * Writes a suggestion to a post field and records the review state.
	 *
	 * The order matters and so does the undo. The field is written first
	 * because it is the thing the user asked for; the status follows because
	 * it describes the field. If the status cannot be recorded, the field is
	 * put back, because a translation carrying machine text under a status
	 * that says a human wrote it is worse than no change at all -- it is a
	 * silent lie that survives until somebody notices the wording.
	 *
	 * @param SuggestionPreview $preview Validated preview.
	 * @return SuggestionPreview|\WP_Error
	 */
	private function apply_to_post( SuggestionPreview $preview ) {
		$target_id = (int) $preview->target_id();
		$post      = get_post( $target_id );

		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error(
				SuggestionError::INVALID_REQUEST,
				__( 'The translation this suggestion belongs to no longer exists.', 'mclogiora' )
			);
		}

		if ( ! current_user_can( 'edit_post', $target_id ) ) {
			return new \WP_Error(
				SuggestionError::INVALID_REQUEST,
				__( 'You are not allowed to edit this translation.', 'mclogiora' )
			);
		}

		$is_title = SuggestionSurface::POST_TITLE === $preview->surface();
		$previous = $is_title ? (string) $post->post_title : (string) $post->post_excerpt;

		$updated = $this->write_post_field( $target_id, $is_title, $preview->text() );

		if ( is_wp_error( $updated ) ) {
			/*
			 * Nothing was written, so nothing is undone and the status is
			 * untouched. The preview survives so the user can retry without
			 * paying for another generation.
			 */
			return $updated;
		}

		$status = $this->workflows->change_status(
			ContentType::POST,
			$target_id,
			$preview->target_language(),
			TranslationStatus::MACHINE_SUGGESTED
		);

		if ( is_wp_error( $status ) ) {
			$this->write_post_field( $target_id, $is_title, $previous );

			return $status;
		}

		$this->previews->consume( $preview->token() );

		return $preview;
	}

	/**
	 * Writes one of the two permitted post fields.
	 *
	 * The two column names are written out as literals in separate branches
	 * rather than assembled from a variable. That is not a concession to the
	 * static analyser -- it is the allow-list made structural. With a dynamic
	 * key there exists, somewhere in the type system, a path where a string
	 * reaches `wp_update_post()` as a column name; with two literal arrays
	 * there does not, and no future edit can introduce one without being
	 * obvious in review.
	 *
	 * @param int    $target_id Post identifier.
	 * @param bool   $is_title Whether the title is being written.
	 * @param string $value Value to write.
	 * @return int|\WP_Error
	 */
	private function write_post_field( $target_id, $is_title, $value ) {
		if ( $is_title ) {
			return wp_update_post(
				array(
					'ID'         => (int) $target_id,
					'post_title' => (string) $value,
				),
				true
			);
		}

		return wp_update_post(
			array(
				'ID'           => (int) $target_id,
				'post_excerpt' => (string) $value,
			),
			true
		);
	}
}
