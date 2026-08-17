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
	const MISSING_TAXONOMY    = 'mclogiora_rest_missing_taxonomy';

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
	 * Returns the missing-taxonomy error.
	 *
	 * Linking terms needs one: the taxonomy is part of a term's identity, and
	 * the workflow resolves both source and target within it.
	 *
	 * @return WP_Error
	 */
	public static function missing_taxonomy() {
		return new WP_Error(
			self::MISSING_TAXONOMY,
			__( 'A taxonomy is required when linking terms.', 'mclogiora' ),
			array( 'status' => 400 )
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
	 * Re-emits a workflow refusal as a REST error with a deliberate status.
	 *
	 * The workflow's own code is passed through rather than translated into a
	 * REST-specific one, so a refusal reported by REST, by an admin screen and
	 * by a future CLI carries the same identifier. A mapping table would be one
	 * more thing to keep in step with the domain, and it would drift.
	 *
	 * The split is between two different questions. A 400 means the request was
	 * wrong whatever state the site is in: a status that cannot be assigned to
	 * anything, or one that is not a status at all. A 409 means the request was
	 * well formed and conflicts with the state this translation is actually in
	 * -- already at that status, or not allowed to move there from where it is.
	 * Flattening both into one code would make a retry-after-fixing-the-request
	 * indistinguishable from a retry-after-the-state-changes.
	 *
	 * Workflow messages are written for people and name no table, class, query
	 * or path, so they are safe to return as-is.
	 *
	 * @param \WP_Error $error Workflow error.
	 * @return \WP_Error
	 */
	public static function from_workflow( \WP_Error $error ) {
		$code = $error->get_error_code();

		$statuses = array(

			/*
			 * The request is wrong whatever state the site is in: malformed
			 * identifiers, or a value that could never be assigned to anything.
			 */
			'mclogiora_unknown_target_status'      => 400,
			'mclogiora_original_not_assignable'    => 400,
			'mclogiora_missing_not_assignable'     => 400,
			'mclogiora_relation_status_invalid'    => 400,
			'mclogiora_cannot_link_to_self'        => 400,
			'mclogiora_invalid_source_id'          => 400,
			'mclogiora_invalid_target_id'          => 400,
			'mclogiora_missing_target_language'    => 400,
			'mclogiora_unknown_target_language'    => 400,
			'mclogiora_unknown_post_type'          => 400,

			/*
			 * Object-specific permission, surfacing from the workflow after the
			 * general capability already passed. The permission callback cannot
			 * catch these: whether a caller may edit one particular post is not
			 * knowable until the workflow has resolved which post that is.
			 */
			'mclogiora_cannot_manage_translations' => is_user_logged_in() ? 403 : 401,
			'mclogiora_cannot_edit_source'         => 403,
			'mclogiora_cannot_edit_target'         => 403,
			'mclogiora_cannot_edit_terms'          => 403,
			'mclogiora_cannot_create_translation'  => 403,

			/* A named object is not there. */
			'mclogiora_translation_item_not_found' => 404,
			'mclogiora_relation_item_not_found'    => 404,
			'mclogiora_source_not_found'           => 404,
			'mclogiora_target_not_found'           => 404,
		);

		/*
		 * Anything not named above conflicts with the current state of real
		 * objects: a slot already filled, an object already in a group, two
		 * objects whose types do not match, a status the item cannot leave, a
		 * source item that cannot be detached. Defaulting to 409 rather than
		 * 500 is deliberate -- these are refusals, not failures.
		 */
		$status = isset( $statuses[ $code ] ) ? $statuses[ $code ] : 409;

		return new WP_Error( $code, $error->get_error_message(), array( 'status' => $status ) );
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
