<?php
/**
 * Outbound HTTP contract for translation providers.
 *
 * @package McLogiora
 */

namespace McLogiora\Suggestions;

defined( 'ABSPATH' ) || exit;

/**
 * The narrow surface a provider needs to reach its API.
 *
 * Exists so the unit suite can exercise every provider against recorded
 * payloads instead of the network. That is not a testing nicety: these are
 * metered, chargeable APIs, and a test suite that could accidentally bill a
 * contributor is a test suite nobody runs.
 */
interface TransportInterface {
	/**
	 * Sends a JSON POST and decodes the response.
	 *
	 * @param string               $url Absolute request URL.
	 * @param array<string,string> $headers Request headers.
	 * @param array<string,mixed>  $body Request body, encoded as JSON.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function post_json( $url, array $headers, array $body );

	/**
	 * Sends a form-encoded POST and decodes the response.
	 *
	 * @param string               $url Absolute request URL.
	 * @param array<string,string> $headers Request headers.
	 * @param string               $body Pre-encoded request body.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function post_form( $url, array $headers, $body );

	/**
	 * Sends a GET and decodes the response.
	 *
	 * @param string               $url Absolute request URL.
	 * @param array<string,string> $headers Request headers.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function get_json( $url, array $headers );
}
