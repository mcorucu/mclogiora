<?php
/**
 * REST error vocabulary.
 *
 * @package McLogiora
 */

namespace McLogiora\Api\Rest;

use McLogiora\Capabilities\CapabilityRegistry;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The complete set of errors mcLogiora's REST routes return.
 *
 * Collected in one place so the codes are a deliberate vocabulary rather than
 * whatever each handler happened to type. Consumers branch on the code, so a
 * code is a contract and the message is not.
 *
 * Messages are written for a developer reading a response body and say only
 * what the caller did wrong. No SQL, no table name, no class name, no path.
 */
final class RestErrors {
	const FORBIDDEN           = 'mclogiora_rest_forbidden';
	const INVALID_OBJECT_TYPE = 'mclogiora_rest_invalid_object_type';
	const UNKNOWN_LANGUAGE    = 'mclogiora_rest_unknown_language';
	const RELATION_NOT_FOUND  = 'mclogiora_rest_relation_not_found';
	const TRANSLATION_MISSING = 'mclogiora_rest_translation_not_found';

	/**
	 * Returns the permission error for the current visitor.
	 *
	 * WordPress distinguishes "you did not say who you are" from "you did, and
	 * it is not enough", and REST clients rely on that difference to decide
	 * whether retrying with credentials is worth it.
	 *
	 * @return WP_Error
	 */
	public static function forbidden() {
		$status = is_user_logged_in() ? 403 : 401;

		return new WP_Error(
			self::FORBIDDEN,
			__( 'You are not allowed to read mcLogiora translation data.', 'mclogiora' ),
			array( 'status' => $status )
		);
	}

	/**
	 * Returns the invalid object type error.
	 *
	 * @param string[] $allowed Allowed object types.
	 * @return WP_Error
	 */
	public static function invalid_object_type( array $allowed ) {
		return new WP_Error(
			self::INVALID_OBJECT_TYPE,
			__( 'Unknown object type.', 'mclogiora' ),
			array(
				'status'  => 400,
				'allowed' => array_values( $allowed ),
			)
		);
	}

	/**
	 * Returns the unknown language error.
	 *
	 * @return WP_Error
	 */
	public static function unknown_language() {
		return new WP_Error(
			self::UNKNOWN_LANGUAGE,
			__( 'That language is not configured on this site.', 'mclogiora' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Returns the missing relation error.
	 *
	 * @return WP_Error
	 */
	public static function relation_not_found() {
		return new WP_Error(
			self::RELATION_NOT_FOUND,
			__( 'That object does not belong to a translation group.', 'mclogiora' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Returns the missing translation error.
	 *
	 * @return WP_Error
	 */
	public static function translation_not_found() {
		return new WP_Error(
			self::TRANSLATION_MISSING,
			__( 'That object has no translation in the requested language.', 'mclogiora' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Returns whether the current user may read mcLogiora translation data.
	 *
	 * Routed through `CapabilityRegistry` so REST checks the same boundary every
	 * admin screen and every write path already checks. A second capability
	 * decision living in a controller is how one surface ends up stricter than
	 * another for the same data.
	 *
	 * @param CapabilityRegistry $capabilities Capability registry.
	 * @return bool
	 */
	public static function may_read_translations( CapabilityRegistry $capabilities ) {
		return current_user_can( $capabilities->resolve( CapabilityRegistry::MANAGE_TRANSLATIONS ) );
	}
}
