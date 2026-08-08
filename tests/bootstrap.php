<?php
/**
 * Test bootstrap.
 *
 * mcLogiora's workflow and domain layers are deliberately free of direct
 * WordPress calls, so they can be tested without a WordPress installation.
 * The few WordPress primitives they do rely on -- WP_Error, translation
 * functions, and a handful of sanitizers -- are stubbed here.
 *
 * These stubs are intentionally faithful but minimal. They are not a
 * WordPress emulator, and tests must not rely on behaviour beyond what is
 * defined below.
 *
 * @package McLogiora
 */

define( 'MCLOGIORA_TESTS_BOOTSTRAPPED', true );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal WP_Error stub.
	 */
	class WP_Error {
		/**
		 * Error code.
		 *
		 * @var string
		 */
		private $code;

		/**
		 * Error message.
		 *
		 * @var string
		 */
		private $message;

		/**
		 * Error data.
		 *
		 * @var mixed
		 */
		private $data;

		/**
		 * Constructor.
		 *
		 * @param string $code Error code.
		 * @param string $message Error message.
		 * @param mixed  $data Error data.
		 */
		public function __construct( $code = '', $message = '', $data = null ) {
			$this->code    = (string) $code;
			$this->message = (string) $message;
			$this->data    = $data;
		}

		/**
		 * Returns the error code.
		 *
		 * @return string
		 */
		public function get_error_code() {
			return $this->code;
		}

		/**
		 * Returns the error message.
		 *
		 * @return string
		 */
		public function get_error_message() {
			return $this->message;
		}

		/**
		 * Returns the error data.
		 *
		 * @return mixed
		 */
		public function get_error_data() {
			return $this->data;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * Returns whether a value is a WP_Error.
	 *
	 * @param mixed $thing Value to check.
	 * @return bool
	 */
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Returns the string unchanged.
	 *
	 * @param string $text Text.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function __( $text, $domain = 'default' ) {
		unset( $domain );

		return $text;
	}
}

if ( ! function_exists( '_n' ) ) {
	/**
	 * Returns the singular or plural string.
	 *
	 * @param string $single Singular text.
	 * @param string $plural Plural text.
	 * @param int    $number Count.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function _n( $single, $plural, $number, $domain = 'default' ) {
		unset( $domain );

		return 1 === (int) $number ? $single : $plural;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	/**
	 * Returns escaped text.
	 *
	 * @param string $text Text.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function esc_html__( $text, $domain = 'default' ) {
		unset( $domain );

		return htmlspecialchars( $text, ENT_QUOTES );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * Encodes a value as JSON.
	 *
	 * @param mixed $data Data.
	 * @return string
	 */
	function wp_json_encode( $data ) {
		return (string) wp_json_encode_fallback( $data );
	}

	/**
	 * JSON encoding helper.
	 *
	 * @param mixed $data Data.
	 * @return string
	 */
	function wp_json_encode_fallback( $data ) {
		return json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- test stub.
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	/**
	 * Returns a slug for a string.
	 *
	 * @param string $title Title.
	 * @return string
	 */
	function sanitize_title( $title ) {
		$title = strtolower( (string) $title );
		$title = preg_replace( '/[^a-z0-9]+/', '-', $title );

		return trim( (string) $title, '-' );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * Returns a sanitized key.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * Returns a trimmed, tag-free string.
	 *
	 * @param string $str Value.
	 * @return string
	 */
	function sanitize_text_field( $str ) {
		return trim( strip_tags( (string) $str ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test stub.
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	/**
	 * Returns a trimmed, tag-free multiline string.
	 *
	 * @param string $str Value.
	 * @return string
	 */
	function sanitize_textarea_field( $str ) {
		return trim( strip_tags( (string) $str ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test stub.
	}
}

if ( ! function_exists( 'absint' ) ) {
	/**
	 * Returns a non-negative integer.
	 *
	 * @param mixed $maybeint Value.
	 * @return int
	 */
	function absint( $maybeint ) {
		return abs( (int) $maybeint );
	}
}

if ( ! function_exists( 'current_time' ) ) {
	/**
	 * Returns a formatted time.
	 *
	 * @param string $type Format type.
	 * @param int    $gmt Whether to use GMT.
	 * @return string
	 */
	function current_time( $type, $gmt = 0 ) {
		unset( $type, $gmt );

		return gmdate( 'Y-m-d H:i:s' );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Returns the value unchanged.
	 *
	 * @param string $hook Hook name.
	 * @param mixed  $value Value.
	 * @return mixed
	 */
	function apply_filters( $hook, $value ) {
		unset( $hook );

		return $value;
	}
}
