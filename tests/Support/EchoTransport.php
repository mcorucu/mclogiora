<?php
/**
 * Recording transport that echoes the submitted text.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Support;

use McLogiora\Suggestions\TransportInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Answers every translation request with the text it was given, prefixed.
 *
 * A canned response cannot exercise the placeholder shield. The shield replaces
 * each placeholder with a protected token before the request leaves, and
 * restores it from the token in the answer -- so a provider double that returns
 * a fixed string has dropped every token, and the shield correctly refuses the
 * result. That refusal is worth testing, but it makes the success path
 * unreachable.
 *
 * A real provider returns the submitted text translated, with protected tokens
 * left alone. Echoing the text back is the smallest honest imitation of that,
 * and it lets the round trip -- shield out, restore in -- be asserted end to end.
 */
final class EchoTransport implements TransportInterface {
	/**
	 * Prefix applied to the echoed text.
	 *
	 * @var string
	 */
	private $prefix;

	/**
	 * Recorded requests.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private $requests = array();

	/**
	 * Builds the transport.
	 *
	 * @param string $prefix Prefix applied to the echoed text.
	 */
	public function __construct( $prefix = 'TR::' ) {
		$this->prefix = (string) $prefix;
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
		$this->record( 'POST', $url, $headers, $body );

		return array( 'translations' => array( array( 'text' => $this->prefix, 'detected_source_language' => 'EN' ) ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $url Absolute request URL.
	 * @param array<string,string> $headers Request headers.
	 * @param string               $body Request body.
	 */
	public function post_form( $url, array $headers, $body ) {
		$this->record( 'POST', $url, $headers, $body );

		$fields = array();

		parse_str( (string) $body, $fields );

		$text = isset( $fields['text'] ) ? (string) $fields['text'] : '';

		return array(
			'translations' => array(
				array(
					'text'                     => $this->prefix . $text,
					'detected_source_language' => 'EN',
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $url Absolute request URL.
	 * @param array<string,string> $headers Request headers.
	 */
	public function get_json( $url, array $headers ) {
		$this->record( 'GET', $url, $headers, null );

		return array();
	}

	/**
	 * Records one request.
	 *
	 * @param string                          $method HTTP method.
	 * @param string                          $url Absolute request URL.
	 * @param array<string,string>            $headers Request headers.
	 * @param array<string,mixed>|string|null $body Request body.
	 * @return void
	 */
	private function record( $method, $url, array $headers, $body ) {
		$this->requests[] = array(
			'method'  => $method,
			'url'     => $url,
			'headers' => $headers,
			'body'    => $body,
		);
	}
}
