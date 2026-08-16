<?php
/**
 * Languages REST controller.
 *
 * @package McLogiora
 */

namespace McLogiora\Api\Rest;

use McLogiora\Api\PublicApi;
use McLogiora\Capabilities\CapabilityRegistry;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Serves the configured language list over HTTP.
 *
 * The active language set is genuinely public. A page carrying a language
 * switcher already publishes every field this route returns -- code, BCP 47
 * tag, locale, native name, text direction, and which language is the
 * unprefixed default -- and the hreflang block publishes most of them again on
 * pages that carry no switcher at all. Refusing to serve over HTTP what the
 * markup already serves would be security theatre.
 *
 * Inactive languages are different. A language that has been configured but not
 * enabled is unpublished site configuration: it says what the owner is planning,
 * and nothing on the front end reveals it. Asking for those requires the same
 * capability the Languages screen requires.
 *
 * There is deliberately no "current language" field. `LanguageContext` resolves
 * from the routing prefix mcLogiora itself put in the URL, and REST requests
 * carry none, so "current" during a REST request is always the site default.
 * Reporting it would be a confident answer to a question REST cannot ask.
 */
final class LanguagesController {
	const ROUTE = '/languages';

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
	 * Registers the language routes.
	 *
	 * @param string $namespace_v1 REST namespace.
	 * @return void
	 */
	public function register_routes( $namespace_v1 ) {
		register_rest_route(
			$namespace_v1,
			self::ROUTE,
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => array(

						/*
						 * `validate_callback` is not optional decoration.
						 * register_rest_route() adds no default one, and
						 * WP_REST_Request::has_valid_params() only calls what is
						 * registered -- so without this line the `enum` below is
						 * documentation that enforces nothing.
						 */
						'status' => array(
							'description'       => __( 'Which languages to return. Requesting all requires permission to manage translations.', 'mclogiora' ),
							'type'              => 'string',
							'default'           => 'active',
							'enum'              => array( 'active', 'all' ),
							'required'          => false,
							'validate_callback' => 'rest_validate_request_arg',
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
				'schema' => array( $this, 'get_item_schema' ),
			)
		);
	}

	/**
	 * Decides whether the request may run.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function get_items_permissions_check( WP_REST_Request $request ) {
		if ( 'all' !== $this->status( $request ) ) {
			return true;
		}

		return RestErrors::may_read_translations( $this->capabilities ) ? true : RestErrors::forbidden();
	}

	/**
	 * Returns the configured languages.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_items( WP_REST_Request $request ) {
		$languages = $this->api->languages( array( 'status' => $this->status( $request ) ) );
		$items     = array();

		/*
		 * Rebuilt field by field rather than passed through. The reader already
		 * returns an array, but handing its output straight to the response
		 * would mean any field a future projection gains is published by this
		 * route on the day it is added, without anyone deciding to publish it.
		 */
		foreach ( $languages as $language ) {
			$items[] = array(
				'code'         => (string) $language['code'],
				'locale'       => (string) $language['locale'],
				'tag'          => (string) $language['tag'],
				'native_name'  => (string) $language['native_name'],
				'english_name' => (string) $language['english_name'],
				'direction'    => (string) $language['direction'],
				'is_active'    => (bool) $language['is_active'],
				'is_default'   => (bool) $language['is_default'],
				'order'        => (int) $language['order'],
				'home_url'     => $this->api->language_url( (string) $language['code'] ),
			);
		}

		return rest_ensure_response( $items );
	}

	/**
	 * Returns the normalised status argument.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string
	 */
	private function status( WP_REST_Request $request ) {
		return 'all' === $request->get_param( 'status' ) ? 'all' : 'active';
	}

	/**
	 * Returns the language resource schema.
	 *
	 * @return array<string,mixed>
	 */
	public function get_item_schema() {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'mclogiora-language',
			'type'       => 'object',
			'properties' => array(
				'code'         => array(
					'description' => __( 'Language code used in URLs.', 'mclogiora' ),
					'type'        => 'string',
					'readonly'    => true,
				),
				'locale'       => array(
					'description' => __( 'WordPress locale.', 'mclogiora' ),
					'type'        => 'string',
					'readonly'    => true,
				),
				'tag'          => array(
					'description' => __( 'BCP 47 language tag.', 'mclogiora' ),
					'type'        => 'string',
					'readonly'    => true,
				),
				'native_name'  => array(
					'description' => __( 'Language name in the language itself.', 'mclogiora' ),
					'type'        => 'string',
					'readonly'    => true,
				),
				'english_name' => array(
					'description' => __( 'Language name in English.', 'mclogiora' ),
					'type'        => 'string',
					'readonly'    => true,
				),
				'direction'    => array(
					'description' => __( 'Text direction.', 'mclogiora' ),
					'type'        => 'string',
					'enum'        => array( 'ltr', 'rtl' ),
					'readonly'    => true,
				),
				'is_active'    => array(
					'description' => __( 'Whether the language is enabled.', 'mclogiora' ),
					'type'        => 'boolean',
					'readonly'    => true,
				),
				'is_default'   => array(
					'description' => __( 'Whether this is the site default language.', 'mclogiora' ),
					'type'        => 'boolean',
					'readonly'    => true,
				),
				'order'        => array(
					'description' => __( 'Display order.', 'mclogiora' ),
					'type'        => 'integer',
					'readonly'    => true,
				),
				'home_url'     => array(
					'description' => __( 'Site home URL in this language.', 'mclogiora' ),
					'type'        => array( 'string', 'null' ),
					'readonly'    => true,
				),
			),
		);
	}
}
