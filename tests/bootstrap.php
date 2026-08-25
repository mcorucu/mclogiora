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

if ( ! function_exists( 'get_locale' ) ) {
	/**
	 * Returns the deterministic test site locale.
	 *
	 * @return string
	 */
	function get_locale() {
		return 'en_US';
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
	 * The flag argument is honoured rather than dropped. The portable package
	 * format fixes its encoding flags deliberately, and a stub that ignored
	 * them would let a unit test pass on bytes WordPress would never produce.
	 *
	 * @param mixed $data Data.
	 * @param int   $options Encoding flags.
	 * @param int   $depth Maximum depth.
	 * @return string
	 */
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return (string) wp_json_encode_fallback( $data, $options, $depth );
	}

	/**
	 * JSON encoding helper.
	 *
	 * @param mixed $data Data.
	 * @param int   $options Encoding flags.
	 * @param int   $depth Maximum depth.
	 * @return string
	 */
	function wp_json_encode_fallback( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- test stub.
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

if ( ! function_exists( 'wp_unslash' ) ) {
	/**
	 * Returns test input unchanged, matching WordPress for unslashed values.
	 *
	 * @param mixed $value Input value.
	 * @return mixed
	 */
	function wp_unslash( $value ) {
		return $value;
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

if ( ! function_exists( 'home_url' ) ) {
	/**
	 * Returns a site URL for tests.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	function home_url( $path = '' ) {
		return 'https://example.test/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	/**
	 * Returns an escaped attribute value.
	 *
	 * @param string $text Value.
	 * @return string
	 */
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Returns escaped HTML text.
	 *
	 * @param string $text Value.
	 * @return string
	 */
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	/**
	 * Returns a minimally sanitised URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	function esc_url( $url ) {
		return htmlspecialchars( (string) $url, ENT_QUOTES );
	}
}

if ( ! function_exists( 'esc_attr_e' ) ) {
	/**
	 * Echoes an escaped attribute value.
	 *
	 * @param string $text Text.
	 * @param string $domain Text domain.
	 * @return void
	 */
	function esc_attr_e( $text, $domain = 'default' ) {
		unset( $domain );

		echo esc_attr( $text ); // phpcs:ignore WordPress.Security.EscapeOutput -- test stub.
	}
}

if ( ! function_exists( 'esc_attr__' ) ) {
	/**
	 * Returns an escaped, translated attribute value.
	 *
	 * @param string $text Text.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function esc_attr__( $text, $domain = 'default' ) {
		unset( $domain );

		return esc_attr( $text );
	}
}

if ( ! function_exists( 'wp_rand' ) ) {
	/**
	 * Returns a pseudo-random integer.
	 *
	 * @param int $min Minimum.
	 * @param int $max Maximum.
	 * @return int
	 */
	function wp_rand( $min = 0, $max = 0 ) {
		return random_int( (int) $min, 0 === (int) $max ? PHP_INT_MAX : (int) $max );
	}
}

if ( ! function_exists( 'selected' ) ) {
	/**
	 * Returns the selected attribute when two values match.
	 *
	 * @param mixed $selected Current value.
	 * @param mixed $current Value to compare against.
	 * @param bool  $display Whether to echo the result.
	 * @return string
	 */
	function selected( $selected, $current = true, $display = true ) {
		$result = (string) $selected === (string) $current ? " selected='selected'" : '';

		if ( $display ) {
			echo $result; // phpcs:ignore WordPress.Security.EscapeOutput -- test stub.
		}

		return $result;
	}
}

if ( ! function_exists( 'sanitize_html_class' ) ) {
	/**
	 * Returns a safe HTML class.
	 *
	 * @param string $class_name Class name.
	 * @return string
	 */
	function sanitize_html_class( $class_name ) {
		return (string) preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $class_name );
	}
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Returns a stored option from the in-memory test store.
	 *
	 * @param string $name Option name.
	 * @param mixed  $default_value Default value.
	 * @return mixed
	 */
	function get_option( $name, $default_value = false ) {
		return array_key_exists( $name, $GLOBALS['mclogiora_test_options'] )
			? $GLOBALS['mclogiora_test_options'][ $name ]
			: $default_value;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * Stores an option in the in-memory test store.
	 *
	 * @param string $name Option name.
	 * @param mixed  $value Value.
	 * @param bool   $autoload Autoload flag.
	 * @return bool
	 */
	function update_option( $name, $value, $autoload = true ) {
		unset( $autoload );
		$GLOBALS['mclogiora_test_options'][ $name ] = $value;

		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	/**
	 * Removes an option from the in-memory test store.
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	function delete_option( $name ) {
		unset( $GLOBALS['mclogiora_test_options'][ $name ] );

		return true;
	}
}

$GLOBALS['mclogiora_test_options'] = array();

if ( ! function_exists( 'get_site_option' ) ) {
	/**
	 * Returns a network option from the in-memory test store.
	 *
	 * @param string $name Option name.
	 * @param mixed  $default_value Default value.
	 * @return mixed
	 */
	function get_site_option( $name, $default_value = false ) {
		return get_option( $name, $default_value );
	}
}

if ( ! class_exists( 'WP_Post' ) ) {
	/**
	 * Minimal WP_Post stub.
	 */
	class WP_Post {
		/**
		 * Post identifier.
		 *
		 * @var int
		 */
		public $ID = 0;

		/**
		 * Constructor.
		 *
		 * @param int $id Post identifier.
		 */
		public function __construct( $id = 0 ) {
			$this->ID = (int) $id;
		}
	}
}

if ( ! class_exists( 'WP_Term' ) ) {
	/**
	 * Minimal WP_Term stub.
	 */
	class WP_Term {
		/**
		 * Term identifier.
		 *
		 * @var int
		 */
		public $term_id = 0;

		/**
		 * Taxonomy name.
		 *
		 * @var string
		 */
		public $taxonomy = '';

		/**
		 * Constructor.
		 *
		 * @param int    $term_id Term identifier.
		 * @param string $taxonomy Taxonomy name.
		 */
		public function __construct( $term_id = 0, $taxonomy = '' ) {
			$this->term_id  = (int) $term_id;
			$this->taxonomy = (string) $taxonomy;
		}
	}
}

if ( ! function_exists( 'get_queried_object' ) ) {
	/**
	 * Returns the object the test says the request resolved to.
	 *
	 * @return mixed
	 */
	function get_queried_object() {
		return isset( $GLOBALS['mclogiora_test_queried_object'] )
			? $GLOBALS['mclogiora_test_queried_object']
			: null;
	}
}

if ( ! function_exists( 'get_permalink' ) ) {
	/**
	 * Returns a predictable permalink for tests.
	 *
	 * @param int $post_id Post identifier.
	 * @return string
	 */
	function get_permalink( $post_id = 0 ) {
		return 'https://example.test/post-' . (int) $post_id . '/';
	}
}

if ( ! function_exists( 'get_term_link' ) ) {
	/**
	 * Returns a predictable term link for tests.
	 *
	 * @param int    $term_id Term identifier.
	 * @param string $taxonomy Taxonomy name.
	 * @return string
	 */
	function get_term_link( $term_id = 0, $taxonomy = '' ) {
		unset( $taxonomy );

		return 'https://example.test/term-' . (int) $term_id . '/';
	}
}

$GLOBALS['mclogiora_test_queried_object'] = null;

/*
 * Lifecycle switches. RuntimeReadiness asks WordPress a handful of
 * environment questions; these stubs let a test state the answer instead of
 * simulating a whole request.
 */
$GLOBALS['mclogiora_test_installing'] = false;
$GLOBALS['mclogiora_test_is_admin']   = false;
$GLOBALS['mclogiora_test_doing_ajax'] = false;
$GLOBALS['mclogiora_test_doing_cron'] = false;
$GLOBALS['mclogiora_test_is_preview'] = false;

if ( ! function_exists( 'wp_installing' ) ) {
	/**
	 * Returns whether WordPress reports itself as installing.
	 *
	 * @return bool
	 */
	function wp_installing() {
		return (bool) $GLOBALS['mclogiora_test_installing'];
	}
}

if ( ! function_exists( 'is_admin' ) ) {
	/**
	 * Returns whether the test says this is an admin request.
	 *
	 * @return bool
	 */
	function is_admin() {
		return (bool) $GLOBALS['mclogiora_test_is_admin'];
	}
}

if ( ! function_exists( 'wp_doing_ajax' ) ) {
	/**
	 * Returns whether the test says this is an ajax request.
	 *
	 * @return bool
	 */
	function wp_doing_ajax() {
		return (bool) $GLOBALS['mclogiora_test_doing_ajax'];
	}
}

if ( ! function_exists( 'wp_doing_cron' ) ) {
	/**
	 * Returns whether the test says this is a cron run.
	 *
	 * @return bool
	 */
	function wp_doing_cron() {
		return (bool) $GLOBALS['mclogiora_test_doing_cron'];
	}
}

if ( ! function_exists( 'is_preview' ) ) {
	/**
	 * Returns whether the test says this is a preview request.
	 *
	 * @return bool
	 */
	function is_preview() {
		return (bool) $GLOBALS['mclogiora_test_is_preview'];
	}
}

if ( ! class_exists( 'WP_Query' ) ) {
	/**
	 * Minimal WP_Query stub. Its presence is what tells RuntimeReadiness that
	 * conditional query tags are safe to call.
	 */
	class WP_Query {
	}
}

/*
 * Transient stubs backing the suggestion preview store.
 *
 * Faithful in the two ways the tests depend on: a stored value survives until
 * it is deleted, and an expired value is indistinguishable from one that was
 * never set. Expiry is driven by a test-settable clock offset rather than by
 * real time, so a fifteen-minute lifetime can be exercised in microseconds.
 */
$GLOBALS['mclogiora_test_transients']  = array();
$GLOBALS['mclogiora_test_clock_offset'] = 0;

if ( ! function_exists( 'set_transient' ) ) {
	/**
	 * Stores a value with an expiry.
	 *
	 * @param string $key Transient name.
	 * @param mixed  $value Value to store.
	 * @param int    $expiration Lifetime in seconds.
	 * @return bool
	 */
	function set_transient( $key, $value, $expiration = 0 ) {
		$GLOBALS['mclogiora_test_transients'][ $key ] = array(
			'value'   => $value,
			'expires' => $expiration > 0 ? time() + (int) $GLOBALS['mclogiora_test_clock_offset'] + (int) $expiration : 0,
		);

		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	/**
	 * Returns a stored value, or false when absent or expired.
	 *
	 * @param string $key Transient name.
	 * @return mixed
	 */
	function get_transient( $key ) {
		if ( ! isset( $GLOBALS['mclogiora_test_transients'][ $key ] ) ) {
			return false;
		}

		$entry = $GLOBALS['mclogiora_test_transients'][ $key ];
		$now   = time() + (int) $GLOBALS['mclogiora_test_clock_offset'];

		if ( 0 !== $entry['expires'] && $entry['expires'] <= $now ) {
			unset( $GLOBALS['mclogiora_test_transients'][ $key ] );

			return false;
		}

		return $entry['value'];
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	/**
	 * Removes a stored value.
	 *
	 * @param string $key Transient name.
	 * @return bool
	 */
	function delete_transient( $key ) {
		if ( ! isset( $GLOBALS['mclogiora_test_transients'][ $key ] ) ) {
			return false;
		}

		unset( $GLOBALS['mclogiora_test_transients'][ $key ] );

		return true;
	}
}

if ( ! function_exists( 'wp_generate_password' ) ) {
	/**
	 * Returns a random alphanumeric string.
	 *
	 * @param int  $length Desired length.
	 * @param bool $special_chars Whether to include punctuation.
	 * @param bool $extra_special_chars Whether to include extra punctuation.
	 * @return string
	 */
	function wp_generate_password( $length = 12, $special_chars = true, $extra_special_chars = false ) {
		$alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
		$password = '';

		for ( $i = 0; $i < (int) $length; $i++ ) {
			$password .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
		}

		return $password;
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	/**
	 * Returns the test's current user identifier.
	 *
	 * @return int
	 */
	function get_current_user_id() {
		return isset( $GLOBALS['mclogiora_test_current_user'] ) ? (int) $GLOBALS['mclogiora_test_current_user'] : 0;
	}
}
