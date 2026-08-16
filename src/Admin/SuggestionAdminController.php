<?php
/**
 * Translation suggestion endpoints for the admin screens.
 *
 * @package McLogiora
 */

namespace McLogiora\Admin;

use McLogiora\Capabilities\CapabilityRegistry;
use McLogiora\Contracts\ModuleInterface;
use McLogiora\Core\Container;
use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageServiceInterface;
use McLogiora\Relations\ContentType;
use McLogiora\Relations\TranslationGroup;
use McLogiora\Relations\TranslationItem;
use McLogiora\Relations\TranslationRelationServiceInterface;
use McLogiora\Relations\TranslationStatus;
use McLogiora\Strings\StringRepositoryInterface;
use McLogiora\Strings\StringSource;
use McLogiora\Suggestions\SuggestionPreview;
use McLogiora\Suggestions\SuggestionPreviewStore;
use McLogiora\Suggestions\SuggestionSurface;
use McLogiora\Suggestions\TranslationSuggestionApplyService;
use McLogiora\Suggestions\TranslationSuggestionService;

defined( 'ABSPATH' ) || exit;

/**
 * Serves Generate, Apply and Discard for the admin screens.
 *
 * The Block and Classic editors have their own controller because a post is
 * identified by one id. Everything else mcLogiora can translate is identified
 * by an id *and* a target language -- an interface string in Turkish, an
 * attachment's Turkish alt text -- so those surfaces share this endpoint
 * instead.
 *
 * What is deliberately identical to the editor controller is the shape of the
 * trust boundary. The browser sends an object id, a surface name and a target
 * language. It never sends the text to translate: this class resolves the
 * authoritative source itself from the object the id names. That is the whole
 * reason the endpoint cannot be used as a general-purpose translation proxy
 * against the owner's provider quota, and it is why the source value is read
 * here rather than accepted from the request.
 */
final class SuggestionAdminController implements ModuleInterface {
	const NONCE_ACTION = 'mclogiora_admin_suggestion';

	/**
	 * Surfaces this controller serves, mapped to their content type.
	 *
	 * An allow-list. Post fields are absent because the editor controller owns
	 * them, and anything not named here is refused before a provider is reached.
	 *
	 * @var array<string,string>
	 */
	private static $surfaces = array(
		SuggestionSurface::STRING            => ContentType::STRING,
		SuggestionSurface::TERM_NAME         => ContentType::TERM,
		SuggestionSurface::TERM_DESCRIPTION  => ContentType::TERM,
		SuggestionSurface::MEDIA_TITLE       => ContentType::MEDIA,
		SuggestionSurface::MEDIA_ALT         => ContentType::MEDIA,
		SuggestionSurface::MEDIA_CAPTION     => ContentType::MEDIA,
		SuggestionSurface::MEDIA_DESCRIPTION => ContentType::MEDIA,
	);

	/**
	 * Suggestion service.
	 *
	 * @var TranslationSuggestionService|null
	 */
	private $suggestions = null;

	/**
	 * Apply service.
	 *
	 * @var TranslationSuggestionApplyService|null
	 */
	private $applier = null;

	/**
	 * Preview store.
	 *
	 * @var SuggestionPreviewStore|null
	 */
	private $previews = null;

	/**
	 * String repository.
	 *
	 * @var StringRepositoryInterface|null
	 */
	private $strings = null;

	/**
	 * Language service.
	 *
	 * @var LanguageServiceInterface|null
	 */
	private $languages = null;

	/**
	 * Relation service.
	 *
	 * @var TranslationRelationServiceInterface|null
	 */
	private $relations = null;

	/**
	 * Effective capability.
	 *
	 * @var string
	 */
	private $capability = 'manage_options';

	/**
	 * Registers the admin endpoints.
	 *
	 * @param Container $container Service container.
	 * @return void
	 */
	public function register( Container $container ) {
		$this->suggestions = $container->get( TranslationSuggestionService::class );
		$this->applier     = $container->get( TranslationSuggestionApplyService::class );
		$this->previews    = $container->get( SuggestionPreviewStore::class );
		$this->strings     = $container->get( StringRepositoryInterface::class );
		$this->languages   = $container->get( LanguageServiceInterface::class );
		$this->relations   = $container->get( TranslationRelationServiceInterface::class );
		$this->capability  = $container->get( CapabilityRegistry::class )
			->resolve( CapabilityRegistry::MANAGE_TRANSLATIONS );

		if ( ! is_admin() ) {
			/*
			 * These are admin screen endpoints. Registering them on the front
			 * end would add handlers to every visitor request for no reason.
			 */
			return;
		}

		add_action( 'wp_ajax_mclogiora_admin_generate_suggestion', array( $this, 'handle_generate' ) );
		add_action( 'wp_ajax_mclogiora_admin_apply_suggestion', array( $this, 'handle_apply' ) );
		add_action( 'wp_ajax_mclogiora_admin_discard_suggestion', array( $this, 'handle_discard' ) );
	}

	/**
	 * Produces a suggestion for review. Writes nothing.
	 *
	 * @return void
	 */
	public function handle_generate() {
		$request = $this->validated_request();
		$source  = $this->source_text( $request );

		if ( '' === trim( $source ) ) {
			wp_send_json_error(
				array( 'message' => __( 'The source text is empty, so there is nothing to translate.', 'mclogiora' ) ),
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
				'object_type'     => $request['object_type'],
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
				'surface'  => $request['surface'],
				'objectId' => (string) $request['target_id'],
				'language' => $request['target_language'],
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

		$applied = $this->applier->apply(
			$this->token(),
			array(
				'user_id'         => get_current_user_id(),
				'object_type'     => $request['object_type'],
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
				'surface'  => $request['surface'],
				'objectId' => (string) $request['target_id'],
				'language' => $request['target_language'],
				'text'     => $applied instanceof SuggestionPreview ? $applied->text() : '',
				'status'   => $this->applied_status( $request['surface'] ),
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
				'object_type'     => $request['object_type'],
				'target_id'       => (string) $request['target_id'],
				'surface'         => $request['surface'],
				'target_language' => $request['target_language'],
			)
		);

		wp_send_json_success(
			array(
				'surface'  => $request['surface'],
				'objectId' => (string) $request['target_id'],
				'language' => $request['target_language'],
			)
		);
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

		if ( ! current_user_can( $this->capability ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You are not allowed to manage translations.', 'mclogiora' ) ),
				403
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified immediately above.
		$surface = isset( $_POST['surface'] ) ? sanitize_key( wp_unslash( $_POST['surface'] ) ) : '';

		if ( ! isset( self::$surfaces[ $surface ] ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Translation suggestions are not available for that field.', 'mclogiora' ) ),
				400
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in check_ajax_referer().
		$object_id = isset( $_POST['objectId'] ) ? absint( wp_unslash( $_POST['objectId'] ) ) : 0;

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in check_ajax_referer().
		$language = isset( $_POST['language'] ) ? sanitize_key( wp_unslash( $_POST['language'] ) ) : '';

		if ( $object_id <= 0 ) {
			wp_send_json_error(
				array( 'message' => __( 'That translation could not be found.', 'mclogiora' ) ),
				400
			);
		}

		$target = $this->languages->get_language_by_code( $language );

		if ( ! $target instanceof Language ) {
			wp_send_json_error(
				array( 'message' => __( 'That language is not configured on this site.', 'mclogiora' ) ),
				400
			);
		}

		if ( ContentType::TERM === self::$surfaces[ $surface ] ) {
			return $this->resolve_term_request( $surface, $object_id, (string) $target->code() );
		}

		if ( ContentType::MEDIA === self::$surfaces[ $surface ] ) {
			return $this->resolve_media_request( $surface, $object_id, (string) $target->code() );
		}

		return $this->resolve_string_request( $surface, $object_id, (string) $target->code() );
	}

	/**
	 * Resolves an interface-string request.
	 *
	 * A string has no relation row. Its source is the registered string itself
	 * and its source language is the site default, so the context is derived
	 * from configuration rather than looked up.
	 *
	 * @param string $surface Requested surface.
	 * @param int    $string_id String identifier.
	 * @param string $target_language Target language code.
	 * @return array<string,mixed>
	 */
	private function resolve_string_request( $surface, $string_id, $target_language ) {
		$default = $this->languages->get_default_language();

		if ( ! $default instanceof Language ) {
			wp_send_json_error(
				array( 'message' => __( 'No default language is configured on this site.', 'mclogiora' ) ),
				400
			);
		}

		if ( $default->code() === $target_language ) {
			/*
			 * The default language is what everything is translated *from*.
			 * Translating into it would let someone spend quota rewriting the
			 * original.
			 */
			wp_send_json_error(
				array( 'message' => __( 'This is the source language, so there is nothing to translate into.', 'mclogiora' ) ),
				400
			);
		}

		return array(
			'surface'         => $surface,
			'object_type'     => self::$surfaces[ $surface ],
			'source_id'       => $string_id,
			'target_id'       => $string_id,
			'source_language' => (string) $default->code(),
			'target_language' => $target_language,
		);
	}

	/**
	 * Resolves a taxonomy term request from its translation relation.
	 *
	 * A term is relation-backed, so nothing about the pairing is taken from the
	 * request beyond the target term's own id. The group says which term is the
	 * source and which languages the two sides speak, which is what makes a
	 * request naming an unrelated term, the wrong language or the source term
	 * itself impossible to honour rather than merely discouraged.
	 *
	 * @param string $surface Requested surface.
	 * @param int    $term_id Target term identifier.
	 * @param string $target_language Target language code.
	 * @return array<string,mixed>
	 */
	private function resolve_term_request( $surface, $term_id, $target_language ) {
		$term = get_term( $term_id );

		if ( ! $term instanceof \WP_Term ) {
			wp_send_json_error(
				array( 'message' => __( 'That term could not be found.', 'mclogiora' ) ),
				400
			);
		}

		if ( ! current_user_can( 'edit_term', $term_id ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You are not allowed to edit this term.', 'mclogiora' ) ),
				403
			);
		}

		$group = $this->relations instanceof TranslationRelationServiceInterface
			? $this->relations->get_translation_set_for_object( ContentType::TERM, (string) $term_id )
			: null;

		if ( ! $group instanceof TranslationGroup ) {
			wp_send_json_error(
				array( 'message' => __( 'This term is not part of a translation group.', 'mclogiora' ) ),
				400
			);
		}

		$target = null;
		$source = null;

		foreach ( $group->items() as $item ) {
			if ( ! $item instanceof TranslationItem ) {
				continue;
			}

			if ( (int) $item->object_id() === (int) $term_id ) {
				$target = $item;
			}

			if ( TranslationStatus::ORIGINAL === $item->status() ) {
				$source = $item;
			}
		}

		if ( ! $target instanceof TranslationItem || ! $source instanceof TranslationItem ) {
			wp_send_json_error(
				array( 'message' => __( 'The source of this translated term could not be found.', 'mclogiora' ) ),
				400
			);
		}

		if ( (int) $source->object_id() === (int) $term_id ) {
			/*
			 * The source term is the thing being translated from. Offering to
			 * translate it into its own language is nonsense, and allowing it
			 * would let someone spend quota rewriting the original.
			 */
			wp_send_json_error(
				array( 'message' => __( 'This is the source term, so there is nothing to translate into.', 'mclogiora' ) ),
				400
			);
		}

		if ( (string) $target->language_code() !== $target_language ) {
			wp_send_json_error(
				array( 'message' => __( 'That language does not match this translated term.', 'mclogiora' ) ),
				400
			);
		}

		$source_term = get_term( (int) $source->object_id() );

		if ( ! $source_term instanceof \WP_Term ) {
			wp_send_json_error(
				array( 'message' => __( 'The source of this translated term could not be found.', 'mclogiora' ) ),
				400
			);
		}

		if ( (string) $source_term->taxonomy !== (string) $term->taxonomy ) {
			wp_send_json_error(
				array( 'message' => __( 'The source term belongs to a different taxonomy.', 'mclogiora' ) ),
				400
			);
		}

		return array(
			'surface'         => $surface,
			'object_type'     => ContentType::TERM,
			'source_id'       => (int) $source->object_id(),
			'target_id'       => (int) $term_id,
			'source_language' => (string) $source->language_code(),
			'target_language' => $target_language,
		);
	}


	/**
	 * Resolves an attachment metadata request.
	 *
	 * An attachment carries its own default-language text on the post itself --
	 * title, caption and description as post fields, alternative text as post
	 * meta -- and the translated values live in a per-language row keyed by
	 * attachment and language. So the source is the attachment as WordPress
	 * stores it, and only the target language comes from the request.
	 *
	 * Nothing about the file is reachable from here. The four metadata fields are
	 * the entire allow-list, checked before this point, which is why a request
	 * naming a filename, a URL, a MIME type or a dimension has nowhere to land.
	 *
	 * @param string $surface Requested surface.
	 * @param int    $attachment_id Attachment identifier.
	 * @param string $target_language Target language code.
	 * @return array<string,mixed>
	 */
	private function resolve_media_request( $surface, $attachment_id, $target_language ) {
		$attachment = get_post( $attachment_id );

		if ( ! $attachment instanceof \WP_Post || 'attachment' !== $attachment->post_type ) {
			wp_send_json_error(
				array( 'message' => __( 'That attachment could not be found.', 'mclogiora' ) ),
				400
			);
		}

		if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You are not allowed to edit this attachment.', 'mclogiora' ) ),
				403
			);
		}

		$default = $this->languages->get_default_language();

		if ( ! $default instanceof Language ) {
			wp_send_json_error(
				array( 'message' => __( 'No default language is configured on this site.', 'mclogiora' ) ),
				400
			);
		}

		if ( $default->code() === $target_language ) {
			/*
			 * The attachment's own metadata *is* the default language. Offering to
			 * translate it into its own language would let someone spend quota
			 * rewriting the original.
			 */
			wp_send_json_error(
				array( 'message' => __( 'This is the source language, so there is nothing to translate into.', 'mclogiora' ) ),
				400
			);
		}

		return array(
			'surface'         => $surface,
			'object_type'     => ContentType::MEDIA,
			'source_id'       => (int) $attachment_id,
			'target_id'       => (int) $attachment_id,
			'source_language' => (string) $default->code(),
			'target_language' => $target_language,
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
	 * Reads the authoritative source value for a request.
	 *
	 * Resolved from the object the id names, never taken from the request. A
	 * string is identified by its own row, so its text domain and context come
	 * with it: two identical source strings registered under different domains
	 * are different rows and translate independently.
	 *
	 * @param array<string,mixed> $request Validated request.
	 * @return string
	 */
	private function source_text( array $request ) {
		if ( SuggestionSurface::MEDIA_TITLE === $request['surface'] ) {
			return (string) $this->source_attachment( $request )->post_title;
		}

		if ( SuggestionSurface::MEDIA_ALT === $request['surface'] ) {
			return (string) get_post_meta( (int) $request['source_id'], '_wp_attachment_image_alt', true );
		}

		if ( SuggestionSurface::MEDIA_CAPTION === $request['surface'] ) {
			return (string) $this->source_attachment( $request )->post_excerpt;
		}

		if ( SuggestionSurface::MEDIA_DESCRIPTION === $request['surface'] ) {
			return (string) $this->source_attachment( $request )->post_content;
		}

		if ( SuggestionSurface::TERM_NAME === $request['surface'] ) {
			return (string) $this->source_term( $request )->name;
		}

		if ( SuggestionSurface::TERM_DESCRIPTION === $request['surface'] ) {
			return (string) $this->source_term( $request )->description;
		}

		$source = $this->strings->find( (int) $request['source_id'] );

		if ( ! $source instanceof StringSource ) {
			wp_send_json_error(
				array( 'message' => __( 'That string could not be found.', 'mclogiora' ) ),
				400
			);
		}

		return (string) $source->text();
	}

	/**
	 * Returns the attachment a request resolved to.
	 *
	 * @param array<string,mixed> $request Validated request.
	 * @return \WP_Post
	 */
	private function source_attachment( array $request ) {
		$attachment = get_post( (int) $request['source_id'] );

		if ( ! $attachment instanceof \WP_Post ) {
			wp_send_json_error(
				array( 'message' => __( 'That attachment could not be found.', 'mclogiora' ) ),
				400
			);
		}

		return $attachment;
	}

	/**
	 * Returns the source term a request resolved to.
	 *
	 * Named explicitly per field by the caller rather than resolved from a
	 * variable, for the same reason the apply service writes two literal term
	 * columns: a field name that reaches a property lookup from request data is a
	 * hole waiting to be widened.
	 *
	 * @param array<string,mixed> $request Validated request.
	 * @return \WP_Term
	 */
	private function source_term( array $request ) {
		$term = get_term( (int) $request['source_id'] );

		if ( ! $term instanceof \WP_Term ) {
			wp_send_json_error(
				array( 'message' => __( 'The source of this translated term could not be found.', 'mclogiora' ) ),
				400
			);
		}

		return $term;
	}

	/**
	 * Returns the status a surface honestly reports after Apply.
	 *
	 * Strings carry their own status column and terms are relation-backed, and
	 * both record the machine-suggested value, so a suggestion is reported as a
	 * suggestion rather than passed off as a finished translation. Media has no
	 * such state and its real status is reported instead, because a screen that
	 * claimed one would disagree with the database.
	 *
	 * @param string $surface Applied surface.
	 * @return string
	 */
	private function applied_status( $surface ) {
		if ( ContentType::MEDIA === self::$surfaces[ $surface ] ) {
			/*
			 * Media metadata is stored by MediaTranslationService, which records
			 * TRANSLATED and has no machine-suggested state. Reporting
			 * machine_suggested here to match the other surfaces would be a
			 * comfortable lie: the database would say one thing and the screen
			 * another. The real status is returned instead, and the screen says
			 * a suggestion was applied without claiming a status that does not
			 * exist. Giving media that state is a schema change, not a Phase 16
			 * presentation detail.
			 */
			return TranslationStatus::TRANSLATED;
		}

		return TranslationStatus::MACHINE_SUGGESTED;
	}
}
