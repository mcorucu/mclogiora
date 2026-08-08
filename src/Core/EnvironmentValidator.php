<?php
/**
 * Environment validation.
 *
 * @package McLogiora
 */

namespace McLogiora\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Validates minimum runtime requirements.
 */
final class EnvironmentValidator {
	/**
	 * Validation errors.
	 *
	 * @var string[]|null
	 */
	private $errors = null;

	/**
	 * Returns whether the environment is supported.
	 *
	 * @return bool
	 */
	public function is_valid() {
		return empty( $this->get_errors() );
	}

	/**
	 * Returns validation errors.
	 *
	 * @return string[]
	 */
	public function get_errors() {
		if ( null !== $this->errors ) {
			return $this->errors;
		}

		global $wp_version;

		$this->errors = array();

		if ( version_compare( PHP_VERSION, MCLOGIORA_MINIMUM_PHP, '<' ) ) {
			$this->errors[] = sprintf(
				/* translators: 1: required PHP version, 2: current PHP version. */
				__( 'mcLogiora requires PHP %1$s or higher. This site is running PHP %2$s.', 'mclogiora' ),
				MCLOGIORA_MINIMUM_PHP,
				PHP_VERSION
			);
		}

		if ( isset( $wp_version ) && version_compare( $wp_version, MCLOGIORA_MINIMUM_WP, '<' ) ) {
			$this->errors[] = sprintf(
				/* translators: 1: required WordPress version, 2: current WordPress version. */
				__( 'mcLogiora requires WordPress %1$s or higher. This site is running WordPress %2$s.', 'mclogiora' ),
				MCLOGIORA_MINIMUM_WP,
				$wp_version
			);
		}

		return $this->errors;
	}

	/**
	 * Renders an admin notice for unsupported environments.
	 *
	 * @return void
	 */
	public function render_admin_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$errors = $this->get_errors();

		if ( empty( $errors ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'mcLogiora is not running.', 'mclogiora' ) . '</strong></p><ul>';

		foreach ( $errors as $error ) {
			echo '<li>' . esc_html( $error ) . '</li>';
		}

		echo '</ul></div>';
	}
}
