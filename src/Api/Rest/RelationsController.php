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
use McLogiora\Relations\TranslationStatus;
use McLogiora\Workflows\TranslationWorkflowService;
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
	 * Translation workflow service, or null when unavailable.
	 *
	 * @var TranslationWorkflowService|null
	 */
	private $workflows;

	/**
	 * Constructor.
	 *
	 * @param PublicApi                       $api Public read API.
	 * @param CapabilityRegistry              $capabilities Capability registry.
	 * @param TranslationWorkflowService|null $workflows Workflow service.
	 */
	public function __construct( PublicApi $api, CapabilityRegistry $capabilities, $workflows = null ) {
		$this->api          = $api;
		$this->capabilities = $capabilities;
		$this->workflows    = $workflows instanceof TranslationWorkflowService ? $workflows : null;
	}

	/**
	 * Registers the relation routes.
	 *
	 * @param string $namespace_v1 REST namespace.
	 * @return void
	 */
	public function register_routes( $namespace_v1 ) {
		$language_arg = array(
			'language' => array(
				'description'       => __( 'Language code of the translation.', 'mclogiora' ),
				'type'              => 'string',
				'required'          => true,
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'sanitize_key',
			),
		);

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
				array(

					/*
					 * The resource is the relation, so POST here adds a
					 * membership and DELETE removes one. Neither creates nor
					 * destroys the post or term itself -- that distinction is
					 * the whole reason these live under /relations rather than
					 * under a content path.
					 */
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_relation_membership' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array_merge( $this->link_args(), $language_arg ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_relation_membership' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array_merge( $this->membership_args(), $language_arg ),
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
					'args'                => array_merge( $this->object_args(), $language_arg ),
				),
				array(

					/*
					 * POST on a collection creates. This route creates a real
					 * WordPress post, which is why it is not folded into the
					 * status handler below: one verb meaning both "make a new
					 * draft" and "change a status" would be decided by which
					 * parameters happened to be present.
					 */
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_translation' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array_merge( $this->create_args(), $language_arg ),
				),
				array(

					/*
					 * PUT and PATCH only. This was registered EDITABLE while
					 * the status change was the sole write here; POST now
					 * belongs to creation, and a partial update is what PATCH
					 * is for. PUT is kept because core's own controllers accept
					 * it for updates and clients expect it.
					 */
					'methods'             => 'PUT, PATCH',
					'callback'            => array( $this, 'update_translation_status' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array_merge(
						$this->object_args(),
						$language_arg,
						array(
							'status' => array(
								'description'       => __( 'Translation status to move to.', 'mclogiora' ),
								'type'              => 'string',
								'required'          => true,
								'enum'              => TranslationStatus::all(),
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

		return rest_ensure_response( $this->project_group( $group, $taxonomy ) );
	}

	/**
	 * Projects a translation group onto the REST shape.
	 *
	 * One implementation, shared by the read and by a successful link, so a
	 * caller that links and a caller that reads see the same resource.
	 *
	 * @param array<string,mixed> $group Group projection.
	 * @param string              $taxonomy Taxonomy name, for term URLs.
	 * @return array<string,mixed>
	 */
	private function project_group( array $group, $taxonomy ) {
		$translations = array();

		foreach ( $group['translations'] as $code => $item ) {
			$translations[ (string) $code ] = $this->project_item( $item, $taxonomy );
		}

		return array(
			'group_key'    => (string) $group['group_key'],
			'object_type'  => (string) $group['object_type'],
			'source'       => null === $group['source'] ? null : $this->project_item( $group['source'], $taxonomy ),
			'translations' => $translations,
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
	 * Links an existing object into a translation group.
	 *
	 * Adds relation membership and nothing else. No post and no term is
	 * created, and neither the source nor the target object is edited: the
	 * workflow attaches a relation record over content that already belongs to
	 * the user.
	 *
	 * Posts and terms share this transport but not a code path. The two
	 * workflows validate different things -- post type against post type,
	 * taxonomy against taxonomy -- and collapsing them into one generic
	 * repository call to save a branch here would discard exactly the checks
	 * that stop a category becoming the translation of a page.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public function create_relation_membership( WP_REST_Request $request ) {
		if ( ! $this->workflows instanceof TranslationWorkflowService ) {
			return RestErrors::relation_not_found();
		}

		$object_type = $this->membership_object_type( $request );

		if ( is_wp_error( $object_type ) ) {
			return $object_type;
		}

		$language = (string) $request->get_param( 'language' );

		if ( ! $this->is_configured_language( $language ) ) {
			return RestErrors::unknown_language();
		}

		$source_id = (int) $request->get_param( 'source_id' );
		$target_id = (int) $request->get_param( 'target_id' );
		$taxonomy  = (string) $request->get_param( 'taxonomy' );

		if ( ContentType::TERM === $object_type ) {
			if ( '' === $taxonomy ) {
				return RestErrors::missing_taxonomy();
			}

			$result = $this->workflows->taxonomy()->link_existing( $source_id, $taxonomy, $target_id, $language );
		} else {
			$result = $this->workflows->content()->link_existing( $source_id, $target_id, $language );
		}

		if ( is_wp_error( $result ) ) {
			return RestErrors::from_workflow( $result );
		}

		$group = $this->api->translation_group( $source_id, $object_type );

		if ( null === $group ) {
			return RestErrors::relation_not_found();
		}

		return rest_ensure_response( $this->project_group( $group, $taxonomy ) );
	}

	/**
	 * Detaches an object from its translation group.
	 *
	 * **This removes relation membership. It does not delete the WordPress post
	 * or term.** The object keeps its content, meta, status, revisions and
	 * assignments, and is never trashed. Deleting content is WordPress's own
	 * job and is deliberately not reachable from this namespace.
	 *
	 * The source item of a group cannot be detached; the domain refuses it
	 * because doing so would orphan every translation hanging off it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public function delete_relation_membership( WP_REST_Request $request ) {
		if ( ! $this->workflows instanceof TranslationWorkflowService ) {
			return RestErrors::relation_not_found();
		}

		$object_type = $this->membership_object_type( $request );

		if ( is_wp_error( $object_type ) ) {
			return $object_type;
		}

		$language = (string) $request->get_param( 'language' );

		if ( ! $this->is_configured_language( $language ) ) {
			return RestErrors::unknown_language();
		}

		$object_id = (int) $request->get_param( 'object_id' );

		$result = ContentType::TERM === $object_type
			? $this->workflows->taxonomy()->unlink( $object_id, $language )
			: $this->workflows->content()->unlink( $object_id, $language );

		if ( is_wp_error( $result ) ) {
			return RestErrors::from_workflow( $result );
		}

		return rest_ensure_response(
			array(
				'object_type' => $object_type,
				'object_id'   => $object_id,
				'language'    => $language,
				'detached'    => true,
			)
		);
	}

	/**
	 * Creates a translation of an existing post or term.
	 *
	 * This is the only route in the namespace that brings a WordPress object
	 * into existence, and the handler is deliberately the thinnest of the lot
	 * because of it. Every creation default belongs to the workflow.
	 *
	 * For posts: the new post is a draft carrying the source's type, title,
	 * content, excerpt, menu order and author, with no slug, parent, meta or
	 * terms. For terms: the new term takes the caller's name and description,
	 * a provisional language-scoped slug the workflow derives, and a parent
	 * only when the source's parent already has a translation in the same
	 * language. REST supplies none of those and accepts no `slug`, `parent`,
	 * `term_id` or meta, so this route can never become a `wp_insert_post` or
	 * `wp_insert_term` proxy with a translation record attached.
	 *
	 * Posts and terms share the transport, not a code path. Their creation
	 * rules differ in every particular that matters, and the two workflows are
	 * where those differences live.
	 *
	 * Nothing here is translated. A created post starts as a copy of the
	 * source's text and a created term takes the name the caller supplied; no
	 * provider is contacted either way.
	 *
	 * Creation never adopts an object that already exists. If WordPress
	 * reports the term as already present, that error is returned rather than
	 * quietly treating the existing term as the translation — linking one is
	 * `POST /relations`, and the caller must choose it deliberately.
	 *
	 * Rollback is the workflow's. If the relation write, or the builder
	 * payload step for posts, fails after the object exists, the workflow
	 * removes the object it just created. There is deliberately no
	 * compensation code in this controller — a second implementation would
	 * eventually disagree with the first about what "clean up" means.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public function create_translation( WP_REST_Request $request ) {
		if ( ! $this->workflows instanceof TranslationWorkflowService ) {
			return RestErrors::relation_not_found();
		}

		$object_type = $this->membership_object_type( $request );

		if ( is_wp_error( $object_type ) ) {
			return $object_type;
		}

		$language = (string) $request->get_param( 'language' );

		if ( ! $this->is_configured_language( $language ) ) {
			return RestErrors::unknown_language();
		}

		$source_id = (int) $request->get_param( 'source_id' );
		$taxonomy  = (string) $request->get_param( 'taxonomy' );

		if ( ContentType::TERM === $object_type ) {
			if ( '' === $taxonomy ) {
				return RestErrors::missing_taxonomy();
			}

			/*
			 * The name is passed through as the caller sent it, minus
			 * sanitization. Whether an empty one is acceptable is the domain's
			 * answer, and it already has a specific error for it; rejecting it
			 * here would replace that answer with a generic one.
			 */
			$result = $this->workflows->taxonomy()->create_translation(
				$source_id,
				$taxonomy,
				$language,
				(string) $request->get_param( 'translated_name' ),
				(string) $request->get_param( 'translated_description' )
			);
		} else {
			$result = $this->workflows->content()->create_translation( $source_id, $language );
		}

		if ( is_wp_error( $result ) ) {
			return RestErrors::from_workflow( $result );
		}

		$group = $this->api->translation_group( $source_id, $object_type );

		if ( null === $group ) {
			return RestErrors::relation_not_found();
		}

		return rest_ensure_response( $this->project_group( $group, $taxonomy ) );
	}

	/**
	 * Moves a translation to a new status.
	 *
	 * The whole handler is a mapping: HTTP arguments in, one workflow call,
	 * the Slice 1 projection out. It decides nothing. Whether a transition is
	 * legal, whether the source item may change status at all, and whether the
	 * caller may manage translations are all `TranslationWorkflowService`'s
	 * answers, and restating any of them here would create a second rulebook
	 * that eventually disagrees with the admin screens.
	 *
	 * Nothing is written through a repository or `$wpdb` from this class.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public function update_translation_status( WP_REST_Request $request ) {
		$object_type = $this->object_type( $request );

		if ( is_wp_error( $object_type ) ) {
			return $object_type;
		}

		$language = (string) $request->get_param( 'language' );

		if ( ! $this->is_configured_language( $language ) ) {
			return RestErrors::unknown_language();
		}

		if ( ! $this->workflows instanceof TranslationWorkflowService ) {
			return RestErrors::relation_not_found();
		}

		$object_id = (int) $request->get_param( 'object_id' );

		$result = $this->workflows->change_status(
			$object_type,
			$object_id,
			$language,
			(string) $request->get_param( 'status' )
		);

		if ( is_wp_error( $result ) ) {
			return RestErrors::from_workflow( $result );
		}

		$taxonomy = (string) $request->get_param( 'taxonomy' );
		$group    = $this->api->translation_group( $object_id, $object_type );

		if ( null === $group || ! isset( $group['translations'][ $language ] ) ) {
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
	 * Returns the object types that can hold relation membership.
	 *
	 * Only posts and terms have link and unlink workflows. This is not REST
	 * narrowing the domain vocabulary; it is REST declaring which of the
	 * domain's operations exist.
	 *
	 * @return string[]
	 */
	private function membership_types() {
		return array( ContentType::POST, ContentType::TERM );
	}

	/**
	 * Returns the validated object type for a membership request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string|\WP_Error
	 */
	private function membership_object_type( WP_REST_Request $request ) {
		$requested = (string) $request->get_param( 'object_type' );

		if ( ! in_array( $requested, $this->membership_types(), true ) ) {
			return RestErrors::invalid_object_type( $this->membership_types() );
		}

		return $requested;
	}

	/**
	 * Returns the arguments identifying an existing membership.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function membership_args() {
		$args = $this->object_args();

		$args['object_type']['enum'] = $this->membership_types();

		unset( $args['taxonomy'] );

		return $args;
	}

	/**
	 * Returns the arguments for creating a translation.
	 *
	 * Deliberately three fields. Every WordPress post field a caller might
	 * want to set is absent, because the workflow decides them and a route
	 * that accepted them would be a content-creation endpoint wearing a
	 * translation label.
	 *
	 * The object type enum holds `post` alone: taxonomy creation is a separate
	 * slice, and listing a type this route cannot serve would be a promise the
	 * handler breaks.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function create_args() {
		return array(
			'object_type'            => array(
				'description'       => __( 'Type of the object to translate.', 'mclogiora' ),
				'type'              => 'string',
				'required'          => true,
				'enum'              => $this->membership_types(),
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'sanitize_key',
			),
			'source_id'              => array(
				'description'       => __( 'Identifier of the object to create a translation of.', 'mclogiora' ),
				'type'              => 'integer',
				'required'          => true,
				'minimum'           => 1,
				'validate_callback' => array( $this, 'validate_object_id' ),
				'sanitize_callback' => 'absint',
			),
			'taxonomy'               => array(
				'description'       => __( 'Taxonomy name. Required when translating a term.', 'mclogiora' ),
				'type'              => 'string',
				'required'          => false,
				'default'           => '',
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'sanitize_key',
			),
			'translated_name'        => array(
				'description'       => __( 'Name for the translated term. Required when translating a term.', 'mclogiora' ),
				'type'              => 'string',
				'required'          => false,
				'default'           => '',
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'translated_description' => array(
				'description'       => __( 'Description for the translated term. Empty by default.', 'mclogiora' ),
				'type'              => 'string',
				'required'          => false,
				'default'           => '',
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'sanitize_textarea_field',
			),
		);
	}

	/**
	 * Returns the arguments for creating a membership.
	 *
	 * `source_id` and `target_id` replace `object_id`: linking names two
	 * objects, and calling either of them "the object" would leave a caller
	 * guessing which one moves.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function link_args() {
		$args = $this->object_args();

		$args['object_type']['enum'] = $this->membership_types();

		unset( $args['object_id'] );

		$args['source_id'] = array(
			'description'       => __( 'Identifier of the source object whose group the target joins.', 'mclogiora' ),
			'type'              => 'integer',
			'required'          => true,
			'minimum'           => 1,
			'validate_callback' => array( $this, 'validate_object_id' ),
			'sanitize_callback' => 'absint',
		);

		$args['target_id'] = array(
			'description'       => __( 'Identifier of the existing object to link as a translation.', 'mclogiora' ),
			'type'              => 'integer',
			'required'          => true,
			'minimum'           => 1,
			'validate_callback' => array( $this, 'validate_object_id' ),
			'sanitize_callback' => 'absint',
		);

		$args['taxonomy']['description'] = __( 'Taxonomy name. Required when linking terms.', 'mclogiora' );

		return $args;
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
