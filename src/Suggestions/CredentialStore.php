<?php
/**
 * Storage for translation provider credentials.
 *
 * @package McLogiora
 */

namespace McLogiora\Suggestions;

defined( 'ABSPATH' ) || exit;

/**
 * Stores, masks and removes the API keys a site owner brings.
 *
 * No credentials of any kind are shipped with the plugin. Every key here
 * belongs to the site owner, is entered by the site owner, and is billed to
 * the site owner.
 *
 * ## On not pretending to encrypt
 *
 * A key stored in `wp_options` is stored as the owner typed it. That is a
 * deliberate refusal rather than an oversight. Encrypting it would require a
 * key, that key would have to live in the same database or the same filesystem
 * as the ciphertext, and anything able to read one could read the other. The
 * result would look like protection while providing none, which is worse than
 * being plain about it: an owner who believes a secret is encrypted makes
 * different decisions about backups, exports and database access than one who
 * knows it is not.
 *
 * What is offered instead is a way to keep the secret out of the database
 * entirely. Defining a constant in `wp-config.php` takes precedence over any
 * stored value:
 *
 *     define( 'MCLOGIORA_OPENAI_API_KEY', '...' );
 *
 * That is real protection -- the secret never reaches the database, never
 * reaches a database backup, and never reaches a migration export -- and it
 * costs the owner one line.
 *
 * ## What never happens
 *
 * A stored key is never returned to the browser, never rendered into a form
 * field, never exposed through REST, never written to a log, and never placed
 * in an error message. After a key is saved, every screen shows only
 * {@see self::masked()}, and the owner's options are to replace it or remove
 * it -- never to read it back.
 */
final class CredentialStore {
	/**
	 * Option name prefix for stored credentials.
	 */
	const OPTION_PREFIX = 'mclogiora_suggestion_key_';

	/**
	 * Returns the constant name that overrides storage for a provider.
	 *
	 * @param string $provider_id Provider identifier.
	 * @return string
	 */
	public function constant_name( $provider_id ) {
		$slug = strtoupper( str_replace( '-', '_', (string) $provider_id ) );

		return 'MCLOGIORA_' . $slug . '_API_KEY';
	}

	/**
	 * Returns whether the credential comes from a constant rather than storage.
	 *
	 * The settings screen uses this to explain why a key cannot be edited
	 * there, instead of silently ignoring what the owner types.
	 *
	 * @param string $provider_id Provider identifier.
	 * @return bool
	 */
	public function is_defined_by_constant( $provider_id ) {
		$constant = $this->constant_name( $provider_id );

		return defined( $constant ) && '' !== (string) constant( $constant );
	}

	/**
	 * Returns whether a credential is available for a provider.
	 *
	 * @param string $provider_id Provider identifier.
	 * @return bool
	 */
	public function has( $provider_id ) {
		return '' !== $this->get( $provider_id );
	}

	/**
	 * Returns the credential for internal use by a provider.
	 *
	 * The only legitimate caller is the provider that is about to place this
	 * value into an outbound request header. Nothing else should call it, and
	 * nothing that renders should ever call it.
	 *
	 * @param string $provider_id Provider identifier.
	 * @return string
	 */
	public function get( $provider_id ) {
		$constant = $this->constant_name( $provider_id );

		if ( defined( $constant ) ) {
			$value = (string) constant( $constant );

			if ( '' !== $value ) {
				return $value;
			}
		}

		return (string) get_option( $this->option_name( $provider_id ), '' );
	}

	/**
	 * Returns a display-safe fingerprint of the stored credential.
	 *
	 * Enough for an owner to recognise which key is in place -- when several
	 * people administer a site, "is that my key or my colleague's?" is a real
	 * question -- and not enough to use, reconstruct or transcribe.
	 *
	 * @param string $provider_id Provider identifier.
	 * @return string Empty when nothing is stored.
	 */
	public function masked( $provider_id ) {
		$value = $this->get( $provider_id );

		if ( '' === $value ) {
			return '';
		}

		$length = strlen( $value );

		/*
		 * A short value reveals proportionally more per character, so nothing
		 * of it is shown at all. Only its presence is reported.
		 */
		if ( $length <= 8 ) {
			return str_repeat( '*', 8 );
		}

		return str_repeat( '*', 8 ) . substr( $value, -4 );
	}

	/**
	 * Stores a credential.
	 *
	 * Whitespace is trimmed because keys are almost always pasted, and a
	 * trailing newline produces an authentication failure that looks exactly
	 * like a wrong key.
	 *
	 * @param string $provider_id Provider identifier.
	 * @param string $value Credential value.
	 * @return bool Whether anything was stored.
	 */
	public function save( $provider_id, $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return false;
		}

		if ( $this->is_defined_by_constant( $provider_id ) ) {
			/*
			 * Storing alongside a constant would put a secret in the database
			 * that can never be used, which is all of the risk and none of the
			 * benefit.
			 */
			return false;
		}

		return update_option( $this->option_name( $provider_id ), $value, false );
	}

	/**
	 * Removes a stored credential.
	 *
	 * @param string $provider_id Provider identifier.
	 * @return bool
	 */
	public function remove( $provider_id ) {
		return delete_option( $this->option_name( $provider_id ) );
	}

	/**
	 * Returns the option name for a provider.
	 *
	 * @param string $provider_id Provider identifier.
	 * @return string
	 */
	private function option_name( $provider_id ) {
		return self::OPTION_PREFIX . sanitize_key( (string) $provider_id );
	}
}
