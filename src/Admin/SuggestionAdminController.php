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
		SuggestionSurface::STRING => ContentType::STRING,
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

		$default = $this->languages->get_default_language();

		if ( ! $default instanceof Language ) {
			wp_send_json_error(
				array( 'message' => __( 'No default language is configured on this site.', 'mclogiora' ) ),
				400
			);
		}

		if ( $default->code() === $target->code() ) {
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
			'source_id'       => $object_id,
			'target_id'       => $object_id,
			'source_language' => (string) $default->code(),
			'target_language' => (string) $target->code(),
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
		if ( SuggestionSurface::STRING !== $request['surface'] ) {
			return '';
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
	 * Returns the status a surface honestly reports after Apply.
	 *
	 * Strings carry their own status column and record the machine-suggested
	 * value, so a suggested string is reported as suggested rather than passed
	 * off as a finished translation.
	 *
	 * @param string $surface Applied surface.
	 * @return string
	 */
	private function applied_status( $surface ) {
		if ( SuggestionSurface::STRING === $surface ) {
			return \McLogiora\Relations\TranslationStatus::MACHINE_SUGGESTED;
		}

		return '';
	}
}
