<?php
/**
 * First-run setup state.
 *
 * @package McLogiora
 */

namespace McLogiora\Setup;

use McLogiora\Database\TableNames;

defined( 'ABSPATH' ) || exit;

/**
 * Stores the small amount of lifecycle state needed by the setup wizard.
 *
 * Configuration itself remains in the language and routing services. This
 * option only records the user journey and the one-time activation hand-off.
 */
final class SetupState {
	const OPTION_NAME = 'mclogiora_setup_state';
	const NOT_STARTED = 'not_started';
	const IN_PROGRESS = 'in_progress';
	const COMPLETED   = 'completed';
	const DISMISSED   = 'dismissed';

	/**
	 * Returns the normalized state.
	 *
	 * @return array{status:string,activation_pending:bool}
	 */
	public static function get() {
		$stored = get_option( self::OPTION_NAME, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$status = isset( $stored['status'] ) ? sanitize_key( $stored['status'] ) : self::NOT_STARTED;

		if ( ! in_array( $status, array( self::NOT_STARTED, self::IN_PROGRESS, self::COMPLETED, self::DISMISSED ), true ) ) {
			$status = self::NOT_STARTED;
		}

		return array(
			'status'             => $status,
			'activation_pending' => ! empty( $stored['activation_pending'] ),
		);
	}

	/**
	 * Returns the current lifecycle status.
	 *
	 * @return string
	 */
	public static function status() {
		return self::get()['status'];
	}

	/**
	 * Marks a successful fresh activation for the next eligible admin request.
	 * Existing language data and a dismissed journey are never hijacked.
	 *
	 * @return void
	 */
	public static function mark_activation_pending() {
		$state = self::get();

		if ( self::site_has_language_data() || self::DISMISSED === $state['status'] || self::COMPLETED === $state['status'] ) {
			return;
		}

		$state['activation_pending'] = true;
		update_option( self::OPTION_NAME, $state, false );
	}

	/**
	 * Returns whether a redirect is waiting to be consumed.
	 *
	 * @return bool
	 */
	public static function has_pending_activation() {
		return self::get()['activation_pending'];
	}

	/**
	 * Consumes the one-time activation hand-off.
	 *
	 * @return bool Whether a marker was consumed.
	 */
	public static function consume_activation() {
		$state = self::get();

		if ( ! $state['activation_pending'] ) {
			return false;
		}

		$state['activation_pending'] = false;
		$state['status']             = self::IN_PROGRESS;
		update_option( self::OPTION_NAME, $state, false );

		return true;
	}

	/**
	 * Starts or resumes setup without changing configuration.
	 *
	 * @return void
	 */
	public static function begin() {
		$state = self::get();

		if ( self::COMPLETED === $state['status'] ) {
			return;
		}

		$state['status']             = self::IN_PROGRESS;
		$state['activation_pending'] = false;
		update_option( self::OPTION_NAME, $state, false );
	}

	/**
	 * Leaves setup available from the dashboard without a recurring redirect.
	 *
	 * @return void
	 */
	public static function dismiss() {
		$state                       = self::get();
		$state['status']             = self::DISMISSED;
		$state['activation_pending'] = false;
		update_option( self::OPTION_NAME, $state, false );
	}

	/**
	 * Marks setup complete. The caller must validate real prerequisites first.
	 *
	 * @return void
	 */
	public static function complete() {
		$state                       = self::get();
		$state['status']             = self::COMPLETED;
		$state['activation_pending'] = false;
		update_option( self::OPTION_NAME, $state, false );
	}

	/**
	 * Returns whether the installed schema contains any language data.
	 *
	 * @return bool
	 */
	private static function site_has_language_data() {
		global $wpdb;

		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return false;
		}

		$db    = $wpdb;
		$table = ( new TableNames( $db ) )->languages();

		if ( function_exists( 'get_option' ) && ! get_option( 'mclogiora_db_version', '' ) ) {
			return false;
		}

		$count = $db->get_var(
			$db->prepare(
				'SELECT COUNT(*) FROM %i',
				$table
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- setup must inspect the current table state; the identifier is a TableNames value and the result is request-local.

		return absint( $count ) > 0;
	}
}
