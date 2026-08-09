<?php
/**
 * Request context guards.
 *
 * @package McLogiora
 */

namespace McLogiora\Routing;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether multilingual behaviour applies to the current request.
 *
 * Rewriting URLs and swapping translations is a front-end concern. Doing it
 * inside wp-admin would make editors see links that do not match the content
 * they are editing, and doing it during cron or WP-CLI would silently change
 * what those processes write. Each guard here exists because the alternative
 * causes a specific, observable problem.
 */
final class RequestContextGuard {
	/**
	 * Returns whether mcLogiora should alter the current request.
	 *
	 * @return bool
	 */
	public function applies() {
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return false;
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return false;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return false;
		}

		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return false;
		}

		if ( function_exists( 'is_admin' ) && is_admin() ) {
			return false;
		}

		if ( function_exists( 'is_preview' ) && is_preview() ) {
			return false;
		}

		return true;
	}
}
