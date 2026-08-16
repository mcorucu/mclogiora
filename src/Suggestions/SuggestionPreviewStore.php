<?php
/**
 * Short-lived storage for translation suggestion previews.
 *
 * @package McLogiora
 */

namespace McLogiora\Suggestions;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps generated suggestions server-side between Generate and Apply.
 *
 * Transients, deliberately. A preview is disposable by nature -- it exists for
 * as long as somebody is looking at it and is worthless afterwards -- so it
 * does not belong in a table that has to be migrated, backed up, cleaned and
 * reasoned about forever. Transients also expire on their own, which means the
 * cleanup story is "there isn't one" rather than a scheduled job that can fail
 * quietly and leave stale suggestions behind.
 *
 * On a site with a persistent object cache this lands in memory, which is the
 * right place for it. On a site without one it lands in the options table and
 * WordPress removes it when it expires.
 */
final class SuggestionPreviewStore {
	/**
	 * Transient name prefix.
	 */
	const PREFIX = 'mclogiora_suggestion_preview_';

	/**
	 * How long a preview stays valid, in seconds.
	 *
	 * Fifteen minutes. Long enough to read a suggestion, compare it against
	 * the source, think about it and go and check something else; short enough
	 * that a token captured from a log or a shared screen is worthless by the
	 * time anyone tries to use it. A user who takes longer regenerates, which
	 * costs one request and no correctness.
	 */
	const LIFETIME = 900;

	/**
	 * Length of the generated token.
	 */
	const TOKEN_LENGTH = 32;

	/**
	 * Creates and stores a preview for a generated suggestion.
	 *
	 * @param SuggestionResult    $result Verified provider result.
	 * @param array<string,mixed> $context Binding facts for the preview.
	 * @return SuggestionPreview|\WP_Error
	 */
	public function create( SuggestionResult $result, array $context ) {
		$user_id = isset( $context['user_id'] ) ? (int) $context['user_id'] : 0;

		if ( $user_id <= 0 ) {
			/*
			 * A preview with no owner could be applied by anyone who guessed
			 * its token, so an unauthenticated generation is refused outright
			 * rather than stored with a zero owner.
			 */
			return new \WP_Error(
				SuggestionError::INVALID_REQUEST,
				__( 'A translation suggestion cannot be stored without a signed-in user.', 'mclogiora' )
			);
		}

		$now = time();

		$preview = new SuggestionPreview(
			array(
				'token'           => $this->generate_token(),
				'text'            => $result->text(),
				'provider_id'     => $result->provider_id(),
				'model'           => $result->model(),
				'surface'         => isset( $context['surface'] ) ? (string) $context['surface'] : '',
				'object_type'     => isset( $context['object_type'] ) ? (string) $context['object_type'] : '',
				'source_id'       => isset( $context['source_id'] ) ? (string) $context['source_id'] : '',
				'target_id'       => isset( $context['target_id'] ) ? (string) $context['target_id'] : '',
				'source_language' => isset( $context['source_language'] ) ? (string) $context['source_language'] : '',
				'target_language' => isset( $context['target_language'] ) ? (string) $context['target_language'] : '',
				'user_id'         => $user_id,
				'created_at'      => $now,
				'expires_at'      => $now + self::LIFETIME,
			)
		);

		if ( ! set_transient( $this->key( $preview->token() ), $preview->to_array(), self::LIFETIME ) ) {
			return new \WP_Error(
				SuggestionError::INVALID_REQUEST,
				__( 'The translation suggestion could not be stored for review.', 'mclogiora' )
			);
		}

		return $preview;
	}

	/**
	 * Returns a stored preview, or null when it is absent or expired.
	 *
	 * An expired preview is indistinguishable from one that never existed,
	 * which is the correct amount of information to give a caller who is
	 * guessing tokens.
	 *
	 * @param string $token Preview token.
	 * @return SuggestionPreview|null
	 */
	public function find( $token ) {
		$token = (string) $token;

		if ( '' === $token ) {
			return null;
		}

		$stored = get_transient( $this->key( $token ) );

		if ( ! is_array( $stored ) ) {
			return null;
		}

		$preview = new SuggestionPreview( $stored );

		/*
		 * Belt and braces over the transient's own expiry. A persistent object
		 * cache that returns a stale entry, or a clock that moved, must not be
		 * able to resurrect a preview past its stated lifetime.
		 */
		if ( $preview->expires_at() > 0 && $preview->expires_at() <= time() ) {
			$this->discard( $token );

			return null;
		}

		return $preview;
	}

	/**
	 * Removes a preview after it has been applied.
	 *
	 * Separate from {@see self::discard()} only in name. Having both makes the
	 * call sites read as what they mean, and a grep for one does not return
	 * the other.
	 *
	 * @param string $token Preview token.
	 * @return bool
	 */
	public function consume( $token ) {
		return $this->discard( $token );
	}

	/**
	 * Removes a preview the user chose not to apply.
	 *
	 * @param string $token Preview token.
	 * @return bool
	 */
	public function discard( $token ) {
		$token = (string) $token;

		if ( '' === $token ) {
			return false;
		}

		return (bool) delete_transient( $this->key( $token ) );
	}

	/**
	 * Returns a cryptographically strong opaque token.
	 *
	 * `wp_generate_password()` is WordPress's own CSPRNG wrapper; asking it for
	 * an alphanumeric string gives a value that is safe inside a transient key
	 * and reveals nothing about the user, object, field, language or provider
	 * it belongs to. Anything derived from those facts -- a hash of the post
	 * ID, a counter, a timestamp -- would let a holder of one token reason
	 * about the existence of others.
	 *
	 * @return string
	 */
	private function generate_token() {
		return wp_generate_password( self::TOKEN_LENGTH, false, false );
	}

	/**
	 * Returns the transient name for a token.
	 *
	 * @param string $token Preview token.
	 * @return string
	 */
	private function key( $token ) {
		return self::PREFIX . preg_replace( '/[^A-Za-z0-9]/', '', (string) $token );
	}
}
