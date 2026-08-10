<?php
/**
 * Installation failure record.
 *
 * @package McLogiora
 */

namespace McLogiora\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Remembers that schema installation failed, so it cannot pass unnoticed.
 *
 * Activation used to call the installer and discard its result. A migration
 * that failed left the plugin active, apparently healthy, and quietly missing
 * the tables half its features write to. Phase 12.1 found exactly that: a real
 * site had the Phase 10 tables and none of the Phase 11 ones, and nothing
 * anywhere said so.
 *
 * The record is deliberately split in two. Administrators see a short,
 * actionable sentence; the detail that names tables stays behind the same
 * capability that can act on it. Table names are not a secret, but a failure
 * notice is not the place to publish the database layout either.
 *
 * The record clears itself the moment a later install succeeds, so it states
 * a present condition rather than a historical one.
 */
final class InstallationFailure {
	const OPTION_NAME = 'mclogiora_installation_failure';

	/**
	 * Records a failed installation.
	 *
	 * @param \WP_Error $error Installer error.
	 * @return void
	 */
	public static function record( \WP_Error $error ) {
		update_option(
			self::OPTION_NAME,
			array(
				'code'    => sanitize_key( (string) $error->get_error_code() ),
				'detail'  => sanitize_text_field( (string) $error->get_error_message() ),
				'version' => defined( 'MCLOGIORA_VERSION' ) ? MCLOGIORA_VERSION : '',
				'time'    => time(),
			),
			false
		);
	}

	/**
	 * Clears any recorded failure.
	 *
	 * @return void
	 */
	public static function clear() {
		delete_option( self::OPTION_NAME );
	}

	/**
	 * Returns the recorded failure, or null when installation is healthy.
	 *
	 * @return array{code:string,detail:string,version:string,time:int}|null
	 */
	public static function get() {
		$stored = get_option( self::OPTION_NAME, null );

		if ( ! is_array( $stored ) || empty( $stored['code'] ) ) {
			return null;
		}

		return array(
			'code'    => (string) $stored['code'],
			'detail'  => isset( $stored['detail'] ) ? (string) $stored['detail'] : '',
			'version' => isset( $stored['version'] ) ? (string) $stored['version'] : '',
			'time'    => isset( $stored['time'] ) ? (int) $stored['time'] : 0,
		);
	}

	/**
	 * Returns whether installation is currently recorded as failed.
	 *
	 * @return bool
	 */
	public static function exists() {
		return null !== self::get();
	}
}
