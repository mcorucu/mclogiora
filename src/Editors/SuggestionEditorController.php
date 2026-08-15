<?php
/**
 * Editor-facing endpoints for translation suggestions.
 *
 * @package McLogiora
 */

namespace McLogiora\Editors;

use McLogiora\Contracts\ModuleInterface;
use McLogiora\Core\Container;
use McLogiora\Relations\ContentType;
use McLogiora\Relations\TranslationItem;
use McLogiora\Relations\TranslationRelationServiceInterface;
use McLogiora\Relations\TranslationStatus;
use McLogiora\Suggestions\SuggestionPreview;
use McLogiora\Suggestions\SuggestionPreviewStore;
use McLogiora\Suggestions\SuggestionSurface;
use McLogiora\Suggestions\TranslationSuggestionApplyService;
use McLogiora\Suggestions\TranslationSuggestionService;

defined( 'ABSPATH' ) || exit;

/**
 * Serves Generate, Apply and Discard to both editors.
 *
 * Gutenberg and the Classic metabox share this one controller. Two
 * implementations would mean two capability checks, two nonce policies and two
 * chances to get the binding wrong, on a feature that spends the owner's money
 * and writes to their content.
 *
 * ## The browser never supplies the text to translate
 *
 * This is the single most important decision in this class. A caller sends an
 * object identifier and a field name; the server looks up that object's
 * translation group, finds the source object, and reads the field from it.
 *
 * The obvious alternative -- letting the editor post the source string it
 * already has on screen -- would turn this endpoint into a general-purpose
 * "translate arbitrary text using the site owner's paid account" proxy. Any
 * contributor-level account, or anything able to make a request as one, could
 * pump unlimited text through it. Resolving the text server-side means the
 * only thing that can be translated is a field that genuinely exists on a
 * genuine source object in a genuine translation group, which bounds the spend
 * to the site's own content.
 *
 * ## What is deliberately not here
 *
 * No provider logic, no credentials and no transport. The controller asks
 * {@see TranslationSuggestionService} for a suggestion and
 * {@see TranslationSuggestionApplyService} to apply one, and it could not
 * reach a provider directly if it tried.
 */
final class SuggestionEditorController implements ModuleInterface {
	/**
	 * Nonce action shared by the editor endpoints.
	 */
	const NONCE_ACTION = 'mclogiora_suggestion_editor';

	/**
	 * Fields the editors may ask about, mapped to suggestion surfaces.
	 *
	 * An explicit map, not a naming convention. A caller-supplied field name
	 * never becomes a surface identifier by string manipulation.
	 *
	 * @var array<string,string>
	 */
	private static $fields = array(
		'title'   => SuggestionSurface::POST_TITLE,
		'excerpt' => SuggestionSurface::POST_EXCERPT,
	);

	/**
	 * Suggestion generator.
	 *
	 * @var TranslationSuggestionService|null
	 */
	private $suggestions;

	/**
	 * Apply service.
	 *
	 * @var TranslationSuggestionApplyService|null
	 */
	private $applier;

	/**
	 * Preview storage.
	 *
	 * @var SuggestionPreviewStore|null
	 */
	private $previews;

	/**
	 * Relation service.
	 *
	 * @var TranslationRelationServiceInterface|null
	 */
	private $relations;

	/**
	 * Registers the editor endpoints.
	 *
	 * @param Container $container Service container.
	 * @return void
	 */
	public function register( Container $container ) {
		$this->suggestions = $container->get( TranslationSuggestionService::class );
		$this->applier     = $container->get( TranslationSuggestionApplyService::class );
		$this->previews    = $container->get( SuggestionPreviewStore::class );
		$this->relations   = $container->get( TranslationRelationServiceInterface::class );

		if ( ! is_admin() ) {
			/*
			 * These are editor endpoints. Registering them on the front end
			 * would add handlers to every visitor request for no reason.
			 */
			return;
		}

		add_action( 'wp_ajax_mclogiora_generate_suggestion', array( $this, 'handle_generate' ) );
		add_action( 'wp_ajax_mclogiora_apply_suggestion', array( $this, 'handle_apply' ) );
		add_action( 'wp_ajax_mclogiora_discard_suggestion', array( $this, 'handle_discard' ) );
	}

	/**
	 * Produces a suggestion for review. Writes nothing.
	 *
	 * @return void
	 */
	public function handle_generate() {
		$request = $this->validated_request();

		$source = $this->source_text( $request['source_id'], $request['field'] );

		if ( '' === trim( $source ) ) {
			wp_send_json_error(
				array( 'message' => __( 'The source field is empty, so there is nothing to translate.', 'mclogiora' ) ),
				400
			);
		}

		$result = $this->suggestions->generate(
			$request['surface'],
			$source,
			$request['source_language'],
			$request['target_language'],
			array( 'target_locale' => '' )
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		$preview = $this->previews->create(
			$result,
			array(
				'user_id'         => get_current_user_id(),
				'object_type'     => ContentType::POST,
				'source_id'       => (string) $request['source_id'],
				'target_id'       => (string) $request['target_id'],
				'surface'         => $request['surface'],
				'source_language' => $request['source_language'],
				'target_language' => $request['target_language'],
			)
		);

		if ( is_wp_error( $preview ) ) {
			wp_send_json_error( array( 'message' => $preview->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'token'    => $preview->token(),
				'text'     => $preview->text(),
				'provider' => $preview->provider_id(),
				'model'    => $preview->model(),
				'field'    => $request['field'],
			)
		);
	}

	/**
	 * Applies a previously generated suggestion.
	 *
	 * @return void
	 */
	public function handle_apply() {
		$request = $this->validated_request();
		$token   = $this->token();

		$applied = $this->applier->apply(
			$token,
			array(
				'user_id'         => get_current_user_id(),
				'object_type'     => ContentType::POST,
				'target_id'       => (string) $request['target_id'],
				'surface'         => $request['surface'],
				'target_language' => $request['target_language'],
			)
		);

		if ( is_wp_error( $applied ) ) {
			wp_send_json_error( array( 'message' => $applied->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'field'  => $request['field'],
				'text'   => $applied instanceof SuggestionPreview ? $applied->text() : '',
				'status' => TranslationStatus::MACHINE_SUGGESTED,
			)
		);
	}

	/**
	 * Discards a preview without touching content.
	 *
	 * @return void
	 */
	public function handle_discard() {
		$request = $this->validated_request();

		$this->applier->discard(
			$this->token(),
			array(
				'user_id'         => get_current_user_id(),
				'object_type'     => ContentType::POST,
				'target_id'       => (string) $request['target_id'],
				'surface'         => $request['surface'],
				'target_language' => $request['target_language'],
			)
		);

		wp_send_json_success( array( 'field' => $request['field'] ) );
	}

	/**
	 * Validates the request and resolves its translation context.
	 *
	 * Every rejection here happens before any provider is reached, so a
	 * malformed or unauthorised request costs the owner nothing.
	 *
	 * @return array<string,mixed>
	 */
	private function validated_request() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified immediately above.
		$target_id = isset( $_POST['objectId'] ) ? absint( wp_unslash( $_POST['objectId'] ) ) : 0;

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified immediately above.
		$field = isset( $_POST['field'] ) ? sanitize_key( wp_unslash( $_POST['field'] ) ) : '';

		if ( ! isset( self::$fields[ $field ] ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Translation suggestions are not available for that field.', 'mclogiora' ) ),
				400
			);
		}

		if ( $target_id <= 0 || ! current_user_can( 'edit_post', $target_id ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You are not allowed to edit this translation.', 'mclogiora' ) ),
				403
			);
		}

		$set = $this->relations->get_translation_set_for_object( ContentType::POST, (string) $target_id );

		if ( ! is_array( $set ) || array() === $set ) {
			wp_send_json_error(
				array( 'message' => __( 'This content is not part of a translation group.', 'mclogiora' ) ),
				400
			);
		}

		$target = null;
		$source = null;

		foreach ( $set as $item ) {
			if ( ! $item instanceof TranslationItem ) {
				continue;
			}

			if ( (int) $item->object_id() === $target_id ) {
				$target = $item;
			}

			if ( TranslationStatus::ORIGINAL === $item->status() ) {
				$source = $item;
			}
		}

		if ( ! $target instanceof TranslationItem || ! $source instanceof TranslationItem ) {
			wp_send_json_error(
				array( 'message' => __( 'The source of this translation could not be found.', 'mclogiora' ) ),
				400
			);
		}

		if ( (int) $source->object_id() === $target_id ) {
			/*
			 * The source is the thing being translated from. Offering to
			 * translate it into its own language is nonsense, and allowing it
			 * would let someone spend quota rewriting the original.
			 */
			wp_send_json_error(
				array( 'message' => __( 'This is the source content, so there is nothing to translate into.', 'mclogiora' ) ),
				400
			);
		}

		return array(
			'target_id'       => $target_id,
			'source_id'       => (int) $source->object_id(),
			'field'           => $field,
			'surface'         => self::$fields[ $field ],
			'source_language' => (string) $source->language_code(),
			'target_language' => (string) $target->language_code(),
		);
	}

	/**
	 * Returns the preview token from the request.
	 *
	 * @return string
	 */
	private function token() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in validated_request().
		return isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
	}

	/**
	 * Reads the authoritative source value for a field.
	 *
	 * Named explicitly per field rather than resolved from a variable, for the
	 * same reason the apply service writes two literal columns: a field name
	 * that reaches a property lookup from request data is a hole waiting to be
	 * widened.
	 *
	 * @param int    $source_id Source post identifier.
	 * @param string $field Requested field.
	 * @return string
	 */
	private function source_text( $source_id, $field ) {
		$post = get_post( (int) $source_id );

		if ( ! $post instanceof \WP_Post ) {
			return '';
		}

		return 'title' === $field ? (string) $post->post_title : (string) $post->post_excerpt;
	}
}
