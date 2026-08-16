<?php
/**
 * Translation relation REST controller.
 *
 * @package McLogiora
 */

namespace McLogiora\Api\Rest;

use McLogiora\Api\PublicApi;
use McLogiora\Capabilities\CapabilityRegistry;
use McLogiora\Relations\ContentType;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Serves translation relations over HTTP.
 *
 * Both routes here require permission, and that is a decision rather than
 * caution. A relation record names object IDs whatever state those objects are
 * in: a draft translation, a private page, a scheduled post. The relation layer
 * has never filtered by post status or by the reader, and the plan is explicit
 * that REST must not expose private post data or unpublished translation
 * content to unauthorised users. Serving these anonymously would announce that
 * a private page exists and hand over its translation's ID.
 *
 * A public projection is buildable -- filter every item by what the reader may
 * actually see -- but doing it correctly means correct read authorisation for
 * posts, terms, strings, media, menu items and widgets, six different answers
 * with six different ways to be subtly wrong. That work is deliberately
 * deferred rather than guessed at. `/languages` already serves the public case.
 *
 * Nothing is read from a repository here. Both handlers project what
 * `PublicApi` returns, so HTTP cannot drift from what the functions say.
 */
final class RelationsController {
	const RELATIONS_ROUTE    = '/relations';
	const TRANSLATIONS_ROUTE = '/translations';

	/**
	 * Public read API.
	 *
	 * @var PublicApi
	 */
	private $api;

	/**
	 * Capability registry.
	 *
	 * @var CapabilityRegistry
	 */
	private $capabilities;

	/**
	 * Constructor.
	 *
	 * @param PublicApi          $api Public read API.
	 * @param CapabilityRegistry $capabilities Capability registry.
	 */
	public function __construct( PublicApi $api, CapabilityRegistry $capabilities ) {
		$this->api          = $api;
		$this->capabilities = $capabilities;
	}

	/**
	 * Registers the relation routes.
	 *
	 * @param string $namespace_v1 REST namespace.
	 * @return void
	 */
	public function register_routes( $namespace_v1 ) {
		register_rest_route(
			$namespace_v1,
			self::RELATIONS_ROUTE,
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_relation' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => $this->object_args(),
				),
			)
		);

		register_rest_route(
			$namespace_v1,
			self::TRANSLATIONS_ROUTE,
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_translation' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array_merge(
						$this->object_args(),
						array(
							'language' => array(
								'description'       => __( 'Language code of the translation to resolve.', 'mclogiora' ),
								'type'              => 'string',
								'required'          => true,
								'validate_callback' => 'rest_validate_request_arg',
								'sanitize_callback' => 'sanitize_key',
							),
						)
					),
				),
			)
		);
	}

	/**
	 * Decides whether the request may run.
	 *
	 * @return true|\WP_Error
	 */
	public function permissions_check() {
		return RestErrors::may_read_translations( $this->capabilities ) ? true : RestErrors::forbidden();
	}

	/**
	 * Returns the translation group an object belongs to.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public function get_relation( WP_REST_Request $request ) {
		$object_type = $this->object_type( $request );

		if ( is_wp_error( $object_type ) ) {
			return $object_type;
		}

		$object_id = (int) $request->get_param( 'object_id' );
		$taxonomy  = (string) $request->get_param( 'taxonomy' );
		$group     = $this->api->translation_group( $object_id, $object_type );

		if ( null === $group ) {
			return RestErrors::relation_not_found();
		}

		$translations = array();

		foreach ( $group['translations'] as $code => $item ) {
			$translations[ (string) $code ] = $this->project_item( $item, $taxonomy );
		}

		return rest_ensure_response(
			array(
				'group_key'    => (string) $group['group_key'],
				'object_type'  => (string) $group['object_type'],
				'source'       => null === $group['source'] ? null : $this->project_item( $group['source'], $taxonomy ),
				'translations' => $translations,
			)
		);
	}

	/**
	 * Returns one translation of an object.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public function get_translation( WP_REST_Request $request ) {
		$object_type = $this->object_type( $request );

		if ( is_wp_error( $object_type ) ) {
			return $object_type;
		}

		$language = (string) $request->get_param( 'language' );

		if ( ! $this->is_configured_language( $language ) ) {
			return RestErrors::unknown_language();
		}

		$object_id = (int) $request->get_param( 'object_id' );
		$taxonomy  = (string) $request->get_param( 'taxonomy' );
		$group     = $this->api->translation_group( $object_id, $object_type );

		if ( null === $group ) {
			return RestErrors::relation_not_found();
		}

		if ( ! isset( $group['translations'][ $language ] ) ) {
			return RestErrors::translation_not_found();
		}

		return rest_ensure_response(
			array(
				'object_type' => (string) $group['object_type'],
				'object_id'   => $object_id,
				'language'    => $language,
				'source'      => null === $group['source'] ? null : $this->project_item( $group['source'], $taxonomy ),
				'translation' => $this->project_item( $group['translations'][ $language ], $taxonomy ),
			)
		);
	}

	/**
	 * Projects one relation item onto the REST shape.
	 *
	 * Rebuilt field by field rather than passed through, so a field added to
	 * the reader's projection is not published by HTTP the day it appears.
	 *
	 * @param array<string,mixed> $item Relation item projection.
	 * @param string              $taxonomy Taxonomy name, for term URLs.
	 * @return array<string,mixed>
	 */
	private function project_item( array $item, $taxonomy ) {
		return array(
			'object_id'   => (int) $item['object_id'],
			'object_type' => (string) $item['object_type'],
			'language'    => (string) $item['language'],
			'status'      => (string) $item['status'],
			'is_source'   => (bool) $item['is_source'],
			'url'         => $this->url_for( $item, $taxonomy ),
		);
	}

	/**
	 * Returns the object's URL in its own language, or null.
	 *
	 * Delegated rather than assembled. `TranslatedUrlGenerator` is the only
	 * place that decides what a translated URL looks like, and a controller
	 * building paths would eventually emit ones that do not resolve.
	 *
	 * Null is a real answer: a term whose taxonomy the caller did not name, or
	 * an object type that has no front-end URL at all, has none to give.
	 *
	 * @param array<string,mixed> $item Relation item projection.
	 * @param string              $taxonomy Taxonomy name, for term URLs.
	 * @return string|null
	 */
	private function url_for( array $item, $taxonomy ) {
		$type = (string) $item['object_type'];

		if ( ContentType::POST !== $type && ContentType::TERM !== $type ) {
			return null;
		}

		if ( ContentType::TERM === $type && '' === $taxonomy ) {
			return null;
		}

		return $this->api->language_url( (string) $item['language'], (int) $item['object_id'], $type, $taxonomy );
	}

	/**
	 * Returns the validated object type, or an error.
	 *
	 * `ContentType::is_valid()` checks the shape of an identifier, not
	 * membership: it accepts any lowercase token. REST needs the allow-list, so
	 * this compares against the enumerated set instead.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string|\WP_Error
	 */
	private function object_type( WP_REST_Request $request ) {
		$requested = (string) $request->get_param( 'object_type' );

		if ( ! in_array( $requested, ContentType::all(), true ) ) {
			return RestErrors::invalid_object_type( ContentType::all() );
		}

		return $requested;
	}

	/**
	 * Returns whether a language code is configured on this site.
	 *
	 * Checked against the configured set rather than a REST-only pattern. The
	 * language domain already owns what counts as a language, and a second
	 * answer here would eventually accept one the site does not have.
	 *
	 * @param string $code Language code.
	 * @return bool
	 */
	private function is_configured_language( $code ) {
		foreach ( $this->api->languages( array( 'status' => 'all' ) ) as $language ) {
			if ( (string) $language['code'] === $code ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns the argument definitions shared by both routes.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function object_args() {
		/*
		 * Every argument names a `validate_callback` deliberately.
		 * register_rest_route() adds no default one, and
		 * WP_REST_Request::has_valid_params() only calls what is registered, so
		 * an `enum` or a `minimum` with no callback beside it constrains
		 * nothing at all.
		 */
		return array(
			'object_type' => array(
				'description'       => __( 'Type of the object to look up.', 'mclogiora' ),
				'type'              => 'string',
				'required'          => true,
				'enum'              => ContentType::all(),
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'sanitize_key',
			),
			'object_id'   => array(
				'description'       => __( 'Identifier of the object to look up.', 'mclogiora' ),
				'type'              => 'integer',
				'required'          => true,
				'minimum'           => 1,
				'validate_callback' => array( $this, 'validate_object_id' ),
				'sanitize_callback' => 'absint',
			),
			'taxonomy'    => array(
				'description'       => __( 'Taxonomy name. Required to resolve URLs for term objects.', 'mclogiora' ),
				'type'              => 'string',
				'required'          => false,
				'default'           => '',
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'sanitize_key',
			),
		);
	}

	/**
	 * Validates an object identifier.
	 *
	 * Rejected before any lookup rather than cast. `absint( 'nonsense' )` is
	 * zero, and zero silently becomes a lookup for whatever the storage layer
	 * makes of it.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	public function validate_object_id( $value ) {
		if ( is_array( $value ) || is_object( $value ) || is_bool( $value ) || null === $value ) {
			return false;
		}

		if ( ! is_numeric( $value ) ) {
			return false;
		}

		$number = (float) $value;

		return $number > 0 && floor( $number ) === $number;
	}
}
