<?php
/**
 * Provider-neutral error vocabulary for translation suggestions.
 *
 * @package McLogiora
 */

namespace McLogiora\Suggestions;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the small set of failures a suggestion can end in.
 *
 * Providers can fail in different vocabularies. The adapter boundary keeps
 * provider-specific details out of every surface that shows an error, and
 * adding a provider does not require editing the editor panel.
 *
 * So provider detail stops at the adapter boundary and one of these categories
 * comes out. The categories are chosen by what a person can *do* about them,
 * which is the only distinction a message needs to carry:
 *
 * - **Declined** -- the provider chose not to answer. Rewording may help;
 *   retrying the same text will not.
 * - **Incomplete** -- an answer began and was cut off. Retrying may help, and
 *   so may a different model.
 * - **Invalid response** -- something came back that is not a translation.
 *   Nothing the owner types will fix it; it is a provider or integration fault.
 * - **Authentication** -- the credential is wrong. Go to settings.
 * - **Rate limited / quota** -- wait, or pay. Nothing else will help.
 * - **Timeout / network** -- the site could not reach the provider.
 *
 * None of these messages carries a raw provider body, a prompt, a credential
 * or any of the submitted text. They are read by whoever is looking at the
 * screen and, in a support thread, by whoever they paste them to.
 */
final class SuggestionError {
	/**
	 * The provider intentionally refused the request.
	 */
	const DECLINED = 'mclogiora_suggestion_declined';

	/**
	 * Generation began and stopped before it finished.
	 */
	const INCOMPLETE = 'mclogiora_suggestion_incomplete';

	/**
	 * The response was malformed, empty, or missing the expected text.
	 */
	const INVALID_RESPONSE = 'mclogiora_suggestion_invalid_response';

	/**
	 * The credential was rejected.
	 */
	const AUTH_FAILED = 'mclogiora_suggestion_auth_failed';

	/**
	 * A rate limit was hit.
	 */
	const RATE_LIMITED = 'mclogiora_suggestion_rate_limited';

	/**
	 * The account is out of quota.
	 */
	const QUOTA = 'mclogiora_suggestion_quota';

	/**
	 * The provider could not be reached, or did not answer in time.
	 */
	const TIMEOUT = 'mclogiora_suggestion_timeout';

	/**
	 * The request never left, because the provider is not usable yet.
	 */
	const NOT_CONFIGURED = 'mclogiora_suggestion_not_configured';

	/**
	 * A placeholder was damaged in translation.
	 */
	const PLACEHOLDER_DAMAGE = 'mclogiora_suggestion_placeholder_damage';

	/**
	 * The source text could not be prepared safely.
	 */
	const UNPROTECTABLE = 'mclogiora_suggestion_unprotectable';

	/**
	 * The request was rejected before any provider was involved.
	 */
	const INVALID_REQUEST = 'mclogiora_suggestion_invalid_request';

	/**
	 * Builds a "provider declined" error.
	 *
	 * @param string $provider_label Human-readable provider name.
	 * @param string $detail Optional provider-supplied reason, already safe.
	 * @return \WP_Error
	 */
	public static function declined( $provider_label, $detail = '' ) {
		return self::build(
			self::DECLINED,
			sprintf(
				/* translators: %s: provider name. */
				__( '%s declined this translation request.', 'mclogiora' ),
				$provider_label
			),
			$detail
		);
	}

	/**
	 * Builds an "incomplete generation" error.
	 *
	 * @param string $provider_label Human-readable provider name.
	 * @param string $detail Optional provider-supplied reason, already safe.
	 * @return \WP_Error
	 */
	public static function incomplete( $provider_label, $detail = '' ) {
		return self::build(
			self::INCOMPLETE,
			sprintf(
				/* translators: %s: provider name. */
				__( '%s returned an incomplete translation. Try again, or choose another model.', 'mclogiora' ),
				$provider_label
			),
			$detail
		);
	}

	/**
	 * Builds an "unusable response" error.
	 *
	 * @param string $provider_label Human-readable provider name.
	 * @return \WP_Error
	 */
	public static function invalid_response( $provider_label ) {
		return self::build(
			self::INVALID_RESPONSE,
			sprintf(
				/* translators: %s: provider name. */
				__( '%s returned an invalid response.', 'mclogiora' ),
				$provider_label
			),
			''
		);
	}

	/**
	 * Builds a "not configured" error.
	 *
	 * @param string $provider_label Human-readable provider name.
	 * @return \WP_Error
	 */
	public static function not_configured( $provider_label ) {
		return self::build(
			self::NOT_CONFIGURED,
			sprintf(
				/* translators: %s: provider name. */
				__( '%s is not available for translation suggestions. Configure a connection in WordPress or in mcLogiora settings.', 'mclogiora' ),
				$provider_label
			),
			''
		);
	}

	/**
	 * Builds an error with an optional provider detail appended.
	 *
	 * The detail is bounded and stripped by whoever supplies it. It exists so
	 * an owner can tell "quota exhausted" from "model retired" without anyone
	 * having to read a log, and it is the only provider-shaped text allowed
	 * past the adapter boundary.
	 *
	 * @param string $code Error code.
	 * @param string $message Normalised message.
	 * @param string $detail Optional provider detail.
	 * @return \WP_Error
	 */
	private static function build( $code, $message, $detail ) {
		$detail = trim( (string) $detail );

		if ( '' !== $detail ) {
			$message .= ' ' . sprintf(
				/* translators: %s: short reason reported by the provider. */
				__( 'Reason given: %s', 'mclogiora' ),
				$detail
			);
		}

		return new \WP_Error( $code, $message );
	}
}
