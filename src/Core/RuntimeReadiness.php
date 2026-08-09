<?php
/**
 * Runtime lifecycle and readiness.
 *
 * @package McLogiora
 */

namespace McLogiora\Core;

use McLogiora\Database\DatabaseVersionManager;

defined( 'ABSPATH' ) || exit;

/**
 * The single authority on whether multilingual runtime behaviour may run.
 *
 * Every module needs the same handful of answers: is WordPress installing
 * itself, does mcLogiora's schema exist yet, is this an ordinary front-end
 * request. Letting each module answer those questions for itself is how the
 * checks drift apart, and one module ends up doing work during installation
 * that its neighbour correctly refuses to do.
 *
 * The ordering inside {@see self::is_frontend_runtime()} is deliberate and
 * load-bearing. Conditional query tags such as `is_preview()` must never be
 * called before WordPress has created the main query: WordPress answers that
 * with `_doing_it_wrong()`, whose message is built with `__()`, which re-enters
 * the `gettext` filter, which asks this class again. That is an unbounded
 * recursion, and it is why the query-availability check comes first.
 */
final class RuntimeReadiness {
	/**
	 * Memoised positive schema answer.
	 *
	 * Only a `true` result is remembered. A request can go from "no schema" to
	 * "schema installed" when activation runs, but never back, so caching the
	 * negative would be the only direction that could serve a stale answer.
	 *
	 * @var bool
	 */
	private $schema_ready = false;

	/**
	 * Returns whether WordPress is installing or upgrading itself.
	 *
	 * `wp_installing()` already folds in the `WP_INSTALLING` constant that a
	 * bare installer defines before WordPress loads, so there is nothing to
	 * check separately.
	 *
	 * @return bool
	 */
	public function is_installing() {
		return function_exists( 'wp_installing' ) && wp_installing();
	}

	/**
	 * Returns whether the request is being served to wp-admin.
	 *
	 * @return bool
	 */
	public function is_admin_request() {
		return function_exists( 'is_admin' ) && is_admin();
	}

	/**
	 * Returns whether this is a REST API request.
	 *
	 * @return bool
	 */
	public function is_rest_request() {
		return defined( 'REST_REQUEST' ) && REST_REQUEST;
	}

	/**
	 * Returns whether this is an admin-ajax request.
	 *
	 * @return bool
	 */
	public function is_ajax_request() {
		return function_exists( 'wp_doing_ajax' ) && wp_doing_ajax();
	}

	/**
	 * Returns whether this is a WP-Cron run.
	 *
	 * @return bool
	 */
	public function is_cron() {
		return function_exists( 'wp_doing_cron' ) && wp_doing_cron();
	}

	/**
	 * Returns whether this is a WP-CLI run.
	 *
	 * @return bool
	 */
	public function is_cli() {
		return defined( 'WP_CLI' ) && WP_CLI;
	}

	/**
	 * Returns whether this is an autosave request.
	 *
	 * @return bool
	 */
	public function is_autosave() {
		return defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE;
	}

	/**
	 * Returns whether WordPress has created the main query.
	 *
	 * Until it has, conditional query tags are unsafe to call. WordPress
	 * creates the query after `plugins_loaded`, so any code running at or
	 * before that hook is in this window.
	 *
	 * @return bool
	 */
	public function is_query_available() {
		return isset( $GLOBALS['wp_query'] ) && $GLOBALS['wp_query'] instanceof \WP_Query;
	}

	/**
	 * Returns whether mcLogiora's own schema exists.
	 *
	 * @return bool
	 */
	public function is_schema_ready() {
		if ( $this->schema_ready ) {
			return true;
		}

		if ( $this->is_installing() ) {
			return false;
		}

		$version = (string) get_option( DatabaseVersionManager::OPTION_NAME, '' );

		if ( '' === $version || '0' === $version ) {
			return false;
		}

		$this->schema_ready = true;

		return true;
	}

	/**
	 * Returns whether multilingual front-end behaviour applies right now.
	 *
	 * Each exclusion exists because the alternative causes a specific,
	 * observable problem. Rewriting URLs inside wp-admin shows editors links
	 * that do not match what they are editing. Doing it during cron or WP-CLI
	 * silently changes what those processes write. Doing it during a REST
	 * request would translate the block editor's own payloads; multilingual
	 * REST output is a deliberate non-goal of Phase 12.
	 *
	 * @return bool
	 */
	public function is_frontend_runtime() {
		if ( $this->is_installing() ) {
			return false;
		}

		if ( $this->is_cli() || $this->is_cron() || $this->is_autosave() ) {
			return false;
		}

		if ( $this->is_ajax_request() || $this->is_rest_request() || $this->is_admin_request() ) {
			return false;
		}

		/*
		 * Must precede is_preview(). See the class docblock: calling a
		 * conditional query tag this early recurses through gettext.
		 */
		if ( ! $this->is_query_available() ) {
			return false;
		}

		if ( function_exists( 'is_preview' ) && is_preview() ) {
			return false;
		}

		return $this->is_schema_ready();
	}

	/**
	 * Clears memoised state.
	 *
	 * Used by tests and after the schema is installed within one request.
	 *
	 * @return void
	 */
	public function reset() {
		$this->schema_ready = false;
	}
}
