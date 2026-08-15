<?php
/**
 * Outbound HTTP transport for translation suggestion providers.
 *
 * @package McLogiora
 */

namespace McLogiora\Suggestions;

defined( 'ABSPATH' ) || exit;

/**
 * Performs provider requests through the WordPress HTTP API.
 *
 * No vendor SDKs are shipped. Every provider talks through this one class
 * and the functions WordPress already provides, which keeps the plugin free of
 * Composer runtime dependencies, keeps it installable from WordPress.org
 * without a build step, and means a site's existing HTTP filters, proxy
 * configuration and request timeouts apply to suggestion traffic exactly as
 * they do to every other outbound request WordPress makes.
 *
 * Three deliberate hardening choices live here rather than in each provider,
 * because getting one of them wrong in one provider would be a security defect
 * in all but name:
 *
 * - **Safe requests only.** `wp_safe_remote_*` is used rather than
 *   `wp_remote_*`, so a request can never be aimed at a private or loopback
 *   address. Provider hosts are compile-time constants and Phase 16 exposes no
 *   custom-endpoint setting, so this is a second lock on a door that has no
 *   handle -- which is the point.
 * - **Redirects are never followed.** These requests carry a credential in a
 *   header. Following a redirect would re-send that header to whatever host the
 *   redirect named, so `redirection` is zero and a redirect is reported as a
 *   failure instead.
 * - **Nothing sensitive reaches an error message.** Errors carry a status and
 *   a provider-supplied message, never request headers and never the submitted
 *   text, because error strings end up in admin notices and in logs.
 */
final class HttpTransport {
	/**
	 * Request timeout in seconds.
	 *
	 * @var int
	 */
	private $timeout;

	/**
	 * Builds a transport.
	 *
	 * @param int $timeout Request timeout in seconds.
	 */
	public function __construct( $timeout = 20 ) {
		$this->timeout = $this->clamp_timeout( $timeout );
	}

	/**
	 * Returns a copy using a different timeout.
	 *
	 * @param int $timeout Request timeout in seconds.
	 * @return self
	 */
	public function with_timeout( $timeout ) {
		return new self( $timeout );
	}

	/**
	 * Sends a JSON POST and decodes the response.
	 *
	 * @param string               $url Absolute request URL.
	 * @param array<string,string> $headers Request headers.
	 * @param array<string,mixed>  $body Request body, encoded as JSON.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function post_json( $url, array $headers, array $body ) {
		$encoded = wp_json_encode( $body );

		if ( false === $encoded ) {
			return new \WP_Error(
				'mclogiora_suggestion_invalid_request',
				__( 'The translation request could not be encoded.', 'mclogiora' )
			);
		}

		return $this->dispatch(
			'POST',
			$url,
			array_merge( array( 'Content-Type' => 'application/json' ), $headers ),
			$encoded
		);
	}

	/**
	 * Sends a form-encoded POST and decodes the response.
	 *
	 * Kept alongside the JSON method because not every translation API speaks
	 * JSON on the way in.
	 *
	 * @param string               $url Absolute request URL.
	 * @param array<string,string> $headers Request headers.
	 * @param string               $body Pre-encoded request body.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function post_form( $url, array $headers, $body ) {
		return $this->dispatch(
			'POST',
			$url,
			array_merge( array( 'Content-Type' => 'application/x-www-form-urlencoded' ), $headers ),
			(string) $body
		);
	}

	/**
	 * Sends a GET and decodes the response.
	 *
	 * @param string               $url Absolute request URL.
	 * @param array<string,string> $headers Request headers.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function get_json( $url, array $headers ) {
		return $this->dispatch( 'GET', $url, $headers, null );
	}

	/**
	 * Performs the request and normalises whatever comes back.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $url Absolute request URL.
	 * @param array<string,string> $headers Request headers.
	 * @param string|null          $body Request body.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function dispatch( $method, $url, array $headers, $body ) {
		$args = array(
			'method'      => $method,
			'headers'     => $headers,
			'timeout'     => $this->timeout,
			'redirection' => 0,
			'sslverify'   => true,
			'blocking'    => true,
		);

		if ( null !== $body ) {
			$args['body'] = $body;
		}

		$response = 'GET' === $method
			? wp_safe_remote_get( $url, $args )
			: wp_safe_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			/*
			 * A transport failure carries WordPress's own message, which
			 * describes the connection rather than the payload, so it is safe
			 * to surface and genuinely useful when diagnosing a blocked host
			 * or an expired certificate.
			 */
			return new \WP_Error(
				'mclogiora_suggestion_network',
				sprintf(
					/* translators: %s: underlying transport error. */
					__( 'The translation provider could not be reached: %s', 'mclogiora' ),
					$response->get_error_message()
				)
			);
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$raw     = (string) wp_remote_retrieve_body( $response );
		$decoded = json_decode( $raw, true );

		if ( $status >= 200 && $status < 300 ) {
			if ( ! is_array( $decoded ) ) {
				return new \WP_Error(
					'mclogiora_suggestion_bad_response',
					__( 'The translation provider returned a response mcLogiora could not read.', 'mclogiora' )
				);
			}

			return $decoded;
		}

		return $this->error_for_status( $status, is_array( $decoded ) ? $decoded : array() );
	}

	/**
	 * Maps a provider status code onto a stable mcLogiora error code.
	 *
	 * Callers -- the settings screen, the editor panel, the String Manager --
	 * all need to tell "your key is wrong" apart from "you are out of quota"
	 * apart from "the provider is having a bad day", and each of those needs a
	 * different message and a different suggested action. Doing that mapping
	 * once here means every surface says the same thing about the same
	 * failure.
	 *
	 * @param int                 $status HTTP status code.
	 * @param array<string,mixed> $decoded Decoded response body, if any.
	 * @return \WP_Error
	 */
	private function error_for_status( $status, array $decoded ) {
		$detail = $this->extract_message( $decoded );

		switch ( true ) {
			case 401 === $status || 403 === $status:
				$code    = 'mclogiora_suggestion_auth';
				$message = __( 'The translation provider rejected the stored credential.', 'mclogiora' );
				break;

			case 429 === $status:
				$code    = 'mclogiora_suggestion_rate_limited';
				$message = __( 'The translation provider is rate limiting this site. Try again shortly.', 'mclogiora' );
				break;

			/*
			 * 456 is not a standard status. DeepL documents it as "quota
			 * exceeded", which is a billing state rather than a fault, so it is
			 * reported alongside 402 rather than as a generic failure.
			 */
			case 402 === $status || 456 === $status:
				$code    = 'mclogiora_suggestion_quota';
				$message = __( 'The translation provider reports that this account is out of quota.', 'mclogiora' );
				break;

			case $status >= 500:
				$code    = 'mclogiora_suggestion_provider_error';
				$message = __( 'The translation provider reported a temporary error. Try again shortly.', 'mclogiora' );
				break;

			case $status >= 300 && $status < 400:
				$code    = 'mclogiora_suggestion_network';
				$message = __( 'The translation provider redirected the request, which mcLogiora does not follow.', 'mclogiora' );
				break;

			default:
				$code    = 'mclogiora_suggestion_invalid_request';
				$message = __( 'The translation provider rejected the request.', 'mclogiora' );
				break;
		}

		if ( '' !== $detail ) {
			$message = sprintf(
				/* translators: 1: normalised message, 2: provider-supplied detail. */
				__( '%1$s Provider said: %2$s', 'mclogiora' ),
				$message,
				$detail
			);
		}

		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}

	/**
	 * Pulls a human-readable message out of a provider's error body.
	 *
	 * The four launch providers each nest it somewhere different, and a site
	 * owner staring at a failing key benefits from the provider's own wording.
	 * Anything unrecognised yields an empty string rather than a dump of the
	 * response, so an unexpected body shape cannot spill into an admin notice.
	 *
	 * @param array<string,mixed> $decoded Decoded response body.
	 * @return string
	 */
	private function extract_message( array $decoded ) {
		$candidates = array();

		if ( isset( $decoded['error']['message'] ) && is_string( $decoded['error']['message'] ) ) {
			$candidates[] = $decoded['error']['message'];
		}

		if ( isset( $decoded['message'] ) && is_string( $decoded['message'] ) ) {
			$candidates[] = $decoded['message'];
		}

		if ( isset( $decoded['error']['status'] ) && is_string( $decoded['error']['status'] ) ) {
			$candidates[] = $decoded['error']['status'];
		}

		foreach ( $candidates as $candidate ) {
			$candidate = trim( wp_strip_all_tags( $candidate ) );

			if ( '' !== $candidate ) {
				/*
				 * Bounded because this is third-party text heading for an admin
				 * notice, and a provider that returns a wall of prose should
				 * not be able to take over the screen.
				 */
				return mb_substr( $candidate, 0, 300 );
			}
		}

		return '';
	}

	/**
	 * Keeps a configured timeout inside a defensible range.
	 *
	 * A timeout of zero would mean "wait forever", which on a synchronous admin
	 * request means a locked editor. The ceiling exists for the same reason.
	 *
	 * @param int $timeout Requested timeout in seconds.
	 * @return int
	 */
	private function clamp_timeout( $timeout ) {
		$timeout = (int) $timeout;

		if ( $timeout < 5 ) {
			return 5;
		}

		if ( $timeout > 60 ) {
			return 60;
		}

		return $timeout;
	}
}
