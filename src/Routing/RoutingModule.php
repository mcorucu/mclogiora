<?php
/**
 * Multilingual routing module.
 *
 * @package McLogiora
 */

namespace McLogiora\Routing;

use McLogiora\Contracts\ModuleInterface;
use McLogiora\Core\Container;
use McLogiora\Languages\Language;
use McLogiora\Relations\ContentType;

defined( 'ABSPATH' ) || exit;

/**
 * Registers rewrite rules and resolves the request language.
 *
 * All rewrite handling lives here. Scattering it through admin classes or
 * translation services is how a plugin ends up flushing rewrite rules from
 * three different places and nobody being able to say why permalinks broke.
 */
final class RoutingModule implements ModuleInterface {
	const QUERY_VAR  = 'mclogiora_lang';
	const FLUSH_FLAG = 'mclogiora_flush_rewrite';
	const RULES_HASH = 'mclogiora_rewrite_hash';

	/**
	 * Language context.
	 *
	 * @var LanguageContext|null
	 */
	private $context = null;

	/**
	 * Request context guard.
	 *
	 * @var RequestContextGuard|null
	 */
	private $guard = null;

	/**
	 * Routing settings.
	 *
	 * @var RoutingSettings|null
	 */
	private $settings = null;

	/**
	 * Registers routing hooks.
	 *
	 * @param Container $container Service container.
	 * @return void
	 */
	public function register( Container $container ) {
		$this->context  = $container->get( LanguageContextInterface::class );
		$this->guard    = $container->get( RequestContextGuard::class );
		$this->settings = $container->get( RoutingSettings::class );

		add_filter( 'query_vars', array( $this, 'register_query_var' ) );
		add_action( 'init', array( $this, 'register_rewrite_rules' ), 20 );
		add_action( 'parse_request', array( $this, 'resolve_request_language' ) );
		add_action( 'admin_init', array( $this, 'maybe_flush_rewrite_rules' ), 99 );
	}

	/**
	 * Registers the internal language query var.
	 *
	 * @param array<int,string> $vars Query vars.
	 * @return array<int,string>
	 */
	public function register_query_var( $vars ) {
		if ( ! is_array( $vars ) ) {
			return $vars;
		}

		$vars[] = self::QUERY_VAR;

		return $vars;
	}

	/**
	 * Registers a language prefix rule for each routable language.
	 *
	 * A single rule per language forwards everything after the prefix back
	 * through WordPress's own parser, so core, themes, and other plugins keep
	 * their rules and mcLogiora only adds the language segment.
	 *
	 * @return void
	 */
	public function register_rewrite_rules() {
		if ( ! $this->schema_ready() ) {
			return;
		}

		foreach ( $this->prefixes() as $prefix ) {
			add_rewrite_rule(
				'^' . $prefix . '/?$',
				'index.php?' . self::QUERY_VAR . '=' . $prefix,
				'top'
			);

			add_rewrite_rule(
				'^' . $prefix . '/(.+?)/?$',
				'index.php?' . self::QUERY_VAR . '=' . $prefix . '&mclogiora_path=$matches[1]',
				'top'
			);
		}

		add_rewrite_tag( '%' . self::QUERY_VAR . '%', '([a-z0-9-]+)' );
	}

	/**
	 * Returns the prefixes that should be routable.
	 *
	 * @return string[]
	 */
	public function prefixes() {
		if ( ! $this->schema_ready() ) {
			return array();
		}

		$prefixes = array();
		$default  = $this->context->default_language();

		foreach ( $this->context->available() as $language ) {
			if ( ! $language instanceof Language ) {
				continue;
			}

			if ( $default instanceof Language
				&& $language->code() === $default->code()
				&& ! $this->settings->default_language_has_prefix() ) {
				continue;
			}

			$prefixes[] = $language->code();
		}

		return $prefixes;
	}

	/**
	 * Returns whether the language schema is available to query.
	 *
	 * Routing runs on `init`, which also fires while WordPress is installing
	 * itself and during activation, before mcLogiora's tables exist. Querying
	 * for languages at that point asks the database about missing tables on
	 * every hook invocation, so the routing layer stays completely inert until
	 * there is a schema to read.
	 *
	 * @return bool
	 */
	private function schema_ready() {
		if ( function_exists( 'wp_installing' ) && wp_installing() ) {
			return false;
		}

		return '' !== (string) get_option( 'mclogiora_db_version', '' );
	}

	/**
	 * Resolves the request language from the parsed query.
	 *
	 * @param \WP $wp Current WordPress environment.
	 * @return void
	 */
	public function resolve_request_language( $wp ) {
		if ( ! $this->guard->applies() ) {
			return;
		}

		$requested = '';

		if ( is_object( $wp ) && isset( $wp->query_vars[ self::QUERY_VAR ] ) ) {
			$requested = sanitize_key( (string) $wp->query_vars[ self::QUERY_VAR ] );
		}

		/*
		 * The query var is untrusted: it comes from the URL. LanguageContext
		 * discards anything that is not an active configured language, so an
		 * unknown or inactive prefix falls back to the default rather than
		 * becoming a language of its own.
		 */
		$this->context->set_requested_code( $requested );

		if ( is_object( $wp ) && isset( $wp->query_vars['mclogiora_path'] ) ) {
			$this->reparse_inner_path( $wp );
		}
	}

	/**
	 * Re-parses the path that followed the language prefix.
	 *
	 * @param \WP $wp Current WordPress environment.
	 * @return void
	 */
	private function reparse_inner_path( $wp ) {
		$path = trim( (string) $wp->query_vars['mclogiora_path'], '/' );

		unset( $wp->query_vars['mclogiora_path'] );

		if ( '' === $path ) {
			return;
		}

		$language = $this->context->current_code();
		$resolved = $this->resolve_path_query( $path );

		if ( empty( $resolved ) ) {
			/*
			 * An unresolvable path under a language prefix is a 404, not a
			 * silent fallback to the source language. Serving source content
			 * under a translated URL misrepresents the page to readers and
			 * search engines alike.
			 */
			$wp->query_vars['error'] = '404';

			return;
		}

		$wp->query_vars = array_merge( $wp->query_vars, $resolved );

		$wp->query_vars[ self::QUERY_VAR ] = $language;
	}

	/**
	 * Turns a path into query vars using WordPress's own rewrite rules.
	 *
	 * @param string $path Path without the language prefix.
	 * @return array<string,mixed>
	 */
	private function resolve_path_query( $path ) {
		$rewrite = $GLOBALS['wp_rewrite'];

		if ( ! $rewrite instanceof \WP_Rewrite ) {
			return array();
		}

		foreach ( (array) $rewrite->wp_rewrite_rules() as $pattern => $target ) {
			if ( 0 === strpos( $pattern, '^' . $this->context->current_code() . '/' ) ) {
				continue;
			}

			if ( ! preg_match( "#^{$pattern}#", $path, $matches ) ) {
				continue;
			}

			$query = preg_replace( '!^.+\?!', '', $target );
			$query = addslashes( \WP_MatchesMapRegex::apply( $query, $matches ) );

			parse_str( $query, $vars );

			return $vars;
		}

		return array();
	}

	/**
	 * Flushes rewrite rules only when the routable prefixes have changed.
	 *
	 * Flushing is expensive, so this runs in the admin only and compares a
	 * fingerprint of the current prefix set before doing anything. A front-end
	 * request never reaches this method at all, which is the strongest form of
	 * the guarantee that ordinary traffic does not rebuild rewrite rules.
	 *
	 * @return void
	 */
	public function maybe_flush_rewrite_rules() {
		if ( ! $this->schema_ready() ) {
			return;
		}

		$hash   = md5( wp_json_encode( $this->prefixes() ) . '|' . ( $this->settings->default_language_has_prefix() ? '1' : '0' ) );
		$stored = (string) get_option( self::RULES_HASH, '' );

		if ( $hash === $stored ) {
			return;
		}

		flush_rewrite_rules( false );
		update_option( self::RULES_HASH, $hash, true );
	}

	/**
	 * Marks the rewrite rules as needing a rebuild.
	 *
	 * @return void
	 */
	public static function invalidate_rules() {
		delete_option( self::RULES_HASH );
	}

	/**
	 * Returns the relation content type for a WordPress post.
	 *
	 * @return string
	 */
	public function post_object_type() {
		return ContentType::POST;
	}
}
