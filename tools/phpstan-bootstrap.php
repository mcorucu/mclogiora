<?php
/**
 * PHPStan bootstrap.
 *
 * Declares the plugin constants that mclogiora.php defines at runtime inside
 * `if ( ! defined( ... ) )` guards. PHPStan cannot resolve conditionally
 * defined constants, so it reports them as unknown without this file.
 *
 * This file is analysis-only. It is never loaded by WordPress and has no
 * effect on plugin behavior.
 *
 * @package McLogiora
 */

define( 'MCLOGIORA_VERSION', '0.0.0' );
define( 'MCLOGIORA_FILE', '' );
define( 'MCLOGIORA_PATH', '' );
define( 'MCLOGIORA_URL', '' );
define( 'MCLOGIORA_BASENAME', '' );
define( 'MCLOGIORA_TEXT_DOMAIN', 'mclogiora' );
define( 'MCLOGIORA_MINIMUM_PHP', '7.4' );
define( 'MCLOGIORA_MINIMUM_WP', '7.0' );

if ( ! class_exists( 'McLogioraPhpStanAiPromptBuilder' ) ) {
	/**
	 * Minimal WordPress AI Client shape for static analysis.
	 */
	class McLogioraPhpStanAiPromptBuilder {
		/**
		 * @return bool
		 */
		public function is_supported_for_text_generation() {
			return false;
		}

		/**
		 * @param string $instruction System instruction.
		 * @return self
		 */
		public function using_system_instruction( $instruction ) {
			unset( $instruction );

			return $this;
		}

		/**
		 * @param string $text User text.
		 * @return self
		 */
		public function with_text( $text ) {
			unset( $text );

			return $this;
		}

		/**
		 * @return string
		 */
		public function generate_text() {
			return '';
		}
	}
}

if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
	/**
	 * Static-analysis stub for WordPress 7.0's AI Client helper.
	 *
	 * @return McLogioraPhpStanAiPromptBuilder
	 */
	function wp_ai_client_prompt() {
		return new McLogioraPhpStanAiPromptBuilder();
	}
}
