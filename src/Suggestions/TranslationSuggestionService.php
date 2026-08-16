<?php
/**
 * Application entry point for translation suggestions.
 *
 * @package McLogiora
 */

namespace McLogiora\Suggestions;

defined( 'ABSPATH' ) || exit;

/**
 * The only way anything in mcLogiora asks a provider for a translation.
 *
 * Every surface -- the block editor panel, the Classic metabox, the String
 * Manager, taxonomy and media screens -- comes through here. That is not
 * tidiness: it is what makes the guarantees below checkable. A second path to
 * a provider would be a second place for the master switch to be forgotten, a
 * second place for a capability check to be skipped, and a second place for
 * content to leave the site.
 *
 * ## Generate mutates nothing
 *
 * {@see self::generate()} performs no write of any kind. It does not update a
 * post, a term, a media record, a stored string, a relation or a translation
 * status. It does not autosave, create a draft, or mark an editor dirty. It
 * returns a value and forgets.
 *
 * This is the invariant the whole two-step design rests on, and it is worth
 * being blunt about why. A generated suggestion is a machine's guess that a
 * human has not yet read. If generating one moved content or status, then a
 * user who clicked the button to *see* what a provider would say would have
 * already changed their site -- and "Discard" would be a lie, because there
 * would be something to undo. Applying is a separate, explicit act.
 *
 * ## What is sent, and what is not
 *
 * A request carries the one field being translated, its languages, and the
 * instruction text. It does not carry the post body, the author, the site URL,
 * post meta, other fields of the same object, or any identifier for the object
 * itself. The provider is told what to translate; it is not told whose site it
 * came from or what else is on the page.
 */
final class TranslationSuggestionService {
	/**
	 * Settings reader.
	 *
	 * @var SuggestionSettings
	 */
	private $settings;

	/**
	 * Provider registry.
	 *
	 * @var ProviderRegistry
	 */
	private $providers;

	/**
	 * Builds the service.
	 *
	 * @param SuggestionSettings $settings Settings reader.
	 * @param ProviderRegistry   $providers Provider registry.
	 */
	public function __construct( SuggestionSettings $settings, ProviderRegistry $providers ) {
		$this->settings  = $settings;
		$this->providers = $providers;
	}

	/**
	 * Returns whether the feature can currently produce anything.
	 *
	 * Used by every surface to decide whether to render a suggestion control
	 * at all. Reaches no network and writes nothing, so it is safe to call
	 * while rendering.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( ! $this->settings->is_enabled() ) {
			return false;
		}

		$provider = $this->providers->find( $this->settings->provider_id() );

		return null !== $provider && $provider->is_configured();
	}

	/**
	 * Produces a suggestion for review. Writes nothing.
	 *
	 * The checks run in this order deliberately: the cheapest and most
	 * absolute first, so that a site with the feature switched off does the
	 * least possible work and, more importantly, cannot reach a provider
	 * through any argument combination.
	 *
	 * @param string $surface Surface identifier from {@see SuggestionSurface}.
	 * @param string $source_text Text to translate.
	 * @param string $source_language Source language code.
	 * @param string $target_language Target language code.
	 * @param array  $options Optional locales and an untranslated context hint.
	 * @return SuggestionResult|\WP_Error
	 */
	public function generate( $surface, $source_text, $source_language, $target_language, array $options = array() ) {
		if ( ! $this->settings->is_enabled() ) {
			return new \WP_Error(
				SuggestionError::NOT_CONFIGURED,
				__( 'Translation suggestions are switched off for this site.', 'mclogiora' )
			);
		}

		if ( ! SuggestionSurface::is_supported( $surface ) ) {
			/*
			 * An allow-list miss is a programming error rather than a user
			 * error, but it is reported rather than thrown: the caller is a
			 * request handler, and a fatal here would take out the editor.
			 */
			return new \WP_Error(
				SuggestionError::INVALID_REQUEST,
				__( 'Translation suggestions are not available for this field.', 'mclogiora' )
			);
		}

		$source_text = (string) $source_text;

		if ( '' === trim( $source_text ) ) {
			return new \WP_Error(
				SuggestionError::INVALID_REQUEST,
				__( 'There is nothing to translate.', 'mclogiora' )
			);
		}

		$source_language = (string) $source_language;
		$target_language = (string) $target_language;

		if ( '' === $source_language || '' === $target_language ) {
			return new \WP_Error(
				SuggestionError::INVALID_REQUEST,
				__( 'A source and target language are both required.', 'mclogiora' )
			);
		}

		if ( $source_language === $target_language ) {
			return new \WP_Error(
				SuggestionError::INVALID_REQUEST,
				__( 'The source and target languages are the same.', 'mclogiora' )
			);
		}

		$provider = $this->providers->find( $this->settings->provider_id() );

		if ( null === $provider ) {
			return new \WP_Error(
				SuggestionError::NOT_CONFIGURED,
				__( 'No translation provider is selected for this site.', 'mclogiora' )
			);
		}

		if ( ! $provider->is_configured() ) {
			/*
			 * Covers both halves of "configured": a missing credential and,
			 * for the language models, a model the owner has not chosen yet.
			 * Neither may be guessed at on their behalf.
			 */
			return new \WP_Error(
				SuggestionError::NOT_CONFIGURED,
				sprintf(
					/* translators: %s: provider name. */
					__( '%s is not fully configured. Add a credential and choose a model.', 'mclogiora' ),
					$provider->get_label()
				)
			);
		}

		if ( ! $provider->supports_language_pair( $source_language, $target_language ) ) {
			return new \WP_Error(
				SuggestionError::INVALID_REQUEST,
				sprintf(
					/* translators: %s: provider name. */
					__( '%s cannot translate between these languages.', 'mclogiora' ),
					$provider->get_label()
				)
			);
		}

		return $provider->suggest(
			$this->build_request( $surface, $source_text, $source_language, $target_language, $options )
		);
	}

	/**
	 * Builds the smallest request that can answer the question.
	 *
	 * Only the named field's text travels. The object it belongs to, its
	 * identifier, its other fields, its author and the site it lives on are
	 * all absent, because a provider that is never told them cannot retain,
	 * log or leak them.
	 *
	 * @param string $surface Surface identifier.
	 * @param string $source_text Text to translate.
	 * @param string $source_language Source language code.
	 * @param string $target_language Target language code.
	 * @param array  $options Optional locales and context hint.
	 * @return SuggestionRequest
	 */
	private function build_request( $surface, $source_text, $source_language, $target_language, array $options ) {
		return new SuggestionRequest(
			$source_text,
			$source_language,
			$target_language,
			array(
				'source_locale' => isset( $options['source_locale'] ) ? (string) $options['source_locale'] : '',
				'target_locale' => isset( $options['target_locale'] ) ? (string) $options['target_locale'] : '',
				'surface'       => $surface,
				'format'        => SuggestionSurface::allows_html( $surface )
					? SuggestionRequest::FORMAT_HTML
					: SuggestionRequest::FORMAT_TEXT,

				/*
				 * A short disambiguation hint, when a caller has one worth
				 * sending -- a term's taxonomy label, say. It is never the
				 * object's other content, and callers are responsible for
				 * keeping it to something a stranger could read without
				 * learning anything private.
				 */
				'context'       => isset( $options['context'] ) ? (string) $options['context'] : '',
			)
		);
	}
}
