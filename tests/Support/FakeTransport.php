<?php
/**
 * Recording HTTP transport double.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Support;

use McLogiora\Suggestions\TransportInterface;

/**
 * Records outbound requests and returns canned responses.
 *
 * Provider tests run against this rather than the network. These are metered,
 * chargeable APIs: a suite that could bill a contributor for running it is a
 * suite that stops being run.
 */
final class FakeTransport implements TransportInterface {
	/**
	 * Queued responses, returned in order.
	 *
	 * @var array<int,array<string,mixed>|\WP_Error>
	 */
	private $responses = array();

	/**
	 * Every request that was made.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private $requests = array();

	/**
	 * Queues a response.
	 *
	 * @param array<string,mixed>|\WP_Error $response Response to return.
	 * @return self
	 */
	public function will_return( $response ) {
		$this->responses[] = $response;

		return $this;
	}

	/**
	 * Returns every recorded request.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function requests() {
		return $this->requests;
	}

	/**
	 * Returns the most recent recorded request.
	 *
	 * @return array<string,mixed>|null
	 */
	public function last_request() {
		if ( array() === $this->requests ) {
			return null;
		}

		return $this->requests[ count( $this->requests ) - 1 ];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $url Absolute request URL.
	 * @param array<string,string> $headers Request headers.
	 * @param array<string,mixed>  $body Request body.
	 */
	public function post_json( $url, array $headers, array $body ) {
		return $this->record( 'POST', $url, $headers, $body );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $url Absolute request URL.
	 * @param array<string,string> $headers Request headers.
	 * @param string               $body Request body.
	 */
	public function post_form( $url, array $headers, $body ) {
		return $this->record( 'POST', $url, $headers, $body );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $url Absolute request URL.
	 * @param array<string,string> $headers Request headers.
	 */
	public function get_json( $url, array $headers ) {
		return $this->record( 'GET', $url, $headers, null );
	}

	/**
	 * Records a request and returns the next queued response.
	 *
	 * @param string                     $method HTTP method.
	 * @param string                     $url Absolute request URL.
	 * @param array<string,string>       $headers Request headers.
	 * @param array<string,mixed>|string|null $body Request body.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function record( $method, $url, array $headers, $body ) {
		$this->requests[] = array(
			'method'  => $method,
			'url'     => $url,
			'headers' => $headers,
			'body'    => $body,
		);

		if ( array() === $this->responses ) {
			return array();
		}

		return array_shift( $this->responses );
	}
}
