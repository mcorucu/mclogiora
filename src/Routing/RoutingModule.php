<?php
/**
 * Multilingual routing module.
 *
 * @package McLogiora
 */

namespace McLogiora\Routing;

use McLogiora\Contracts\ModuleInterface;
use McLogiora\Core\Container;
use McLogiora\Core\RuntimeReadiness;
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
	const PATH_VAR   = 'mclogiora_path';
	const FLUSH_FLAG = 'mclogiora_flush_rewrite';
	const RULES_HASH = 'mclogiora_rewrite_hash';

	/**
	 * Language context.
	 *
	 * @var LanguageContext|null
	 */
	private $context = null;

	/**
	 * Runtime readiness.
	 *
	 * @var RuntimeReadiness|null
	 */
	private $readiness = null;

	/**
	 * Routing settings.
	 *
	 * @var RoutingSettings|null
	 */
	private $settings = null;

	/**
	 * URL generator.
	 *
	 * @var TranslatedUrlGenerator|null
	 */
	private $urls = null;

	/**
	 * Containers whose routing hooks have already been registered.
	 *
	 * The application normally registers one module instance. Keeping this
	 * guard at the container level also makes explicit registrations harmless:
	 * a second instance must not process the already-normalised query and reset
	 * a valid requested language back to the default.
	 *
	 * @var array<string,bool>
	 */
	private static $registered_containers = array();

	/**
	 * Registers routing hooks.
	 *
	 * @param Container $container Service container.
	 * @return void
	 */
	public function register( Container $container ) {
		$this->context   = $container->get( LanguageContextInterface::class );
		$this->readiness = $container->get( RuntimeReadiness::class );
		$this->settings  = $container->get( RoutingSettings::class );
		$this->urls      = $container->get( TranslatedUrlGenerator::class );

		$container_id = spl_object_hash( $container );

		if ( isset( self::$registered_containers[ $container_id ] ) ) {
			return;
		}

		self::$registered_containers[ $container_id ] = true;

		add_filter( 'query_vars', array( $this, 'register_query_var' ) );
		add_action( 'init', array( $this, 'register_rewrite_rules' ), 20 );
		add_action( 'parse_request', array( $this, 'resolve_request_language' ) );
		add_filter( 'redirect_canonical', array( $this, 'preserve_prefixed_front_page_canonical' ), 10, 2 );
		add_action( 'admin_init', array( $this, 'maybe_flush_rewrite_rules' ), 99 );
	}

	/**
	 * Registers the internal routing query vars.
	 *
	 * Both are required. WordPress discards anything a rewrite rule produces
	 * that has not been registered here, so omitting the path var silently
	 * throws away everything after the language prefix and every translated
	 * URL resolves to the site home.
	 *
	 * @param array<int,string> $vars Query vars.
	 * @return array<int,string>
	 */
	public function register_query_var( $vars ) {
		if ( ! is_array( $vars ) ) {
			return $vars;
		}

		$vars[] = self::QUERY_VAR;
		$vars[] = self::PATH_VAR;

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
				'index.php?' . self::QUERY_VAR . '=' . $prefix . '&' . self::PATH_VAR . '=$matches[1]',
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
	 * Rewrite rules are registered in the admin as well as on the front end,
	 * so this is deliberately the schema question alone and not the wider
	 * front-end runtime gate.
	 *
	 * @return bool
	 */
	private function schema_ready() {
		return $this->readiness instanceof RuntimeReadiness && $this->readiness->is_schema_ready();
	}

	/**
	 * Resolves the request language from the parsed query.
	 *
	 * @param \WP $wp Current WordPress environment.
	 * @return void
	 */
	public function resolve_request_language( $wp ) {
		if ( ! $this->readiness->is_frontend_runtime() ) {
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

		/*
		 * These query vars are routing implementation details, not content
		 * query arguments. Leaving the language var in the main query makes
		 * WordPress treat a prefixed bare-home request as a non-empty custom
		 * query. That prevents WP_Query from applying its normal static-front
		 * page correction, so `/en/` falls through to the posts index and the
		 * theme selects `home.html` instead of `front-page.html`.
		 *
		 * The language is already held by LanguageContext for the rest of the
		 * request. Removing both internal vars here lets core, the theme, and
		 * other plugins see the same content query they would see without the
		 * language prefix.
		 */
		if ( ! is_object( $wp ) || ! isset( $wp->query_vars ) || ! is_array( $wp->query_vars ) ) {
			return;
		}

		unset( $wp->query_vars[ self::QUERY_VAR ] );

		if ( ! isset( $wp->query_vars[ self::PATH_VAR ] ) ) {
			return;
		}

		$path = (string) $wp->query_vars[ self::PATH_VAR ];

		unset( $wp->query_vars[ self::PATH_VAR ] );

		/*
		 * The path var only means anything underneath a language prefix that
		 * actually matched. Honouring it on an unprefixed request would let a
		 * hand-written query string re-route any URL to any other.
		 */
		if ( '' === $this->context->requested_code() ) {
			return;
		}

		$this->reparse_inner_path( $wp, $path );
	}

	/**
	 * Keeps a configured default-language front page at its prefixed URL.
	 *
	 * WordPress's canonical redirect for a static front page is always built
	 * from `home_url( '/' )`. That is correct when the default language lives
	 * at the root, but it strips the configured default-language prefix after
	 * the routing vars have been removed from the main query. The prefix is
	 * routing state, so this narrow filter preserves it only for the front-page
	 * request that arrived through that configured prefix.
	 *
	 * @param string|false $redirect_url Candidate canonical URL.
	 * @param string       $requested_url URL WordPress is canonicalising.
	 * @return string|false
	 */
	public function preserve_prefixed_front_page_canonical( $redirect_url, $requested_url ) {
		if ( ! is_front_page() || ! $this->settings->default_language_has_prefix() ) {
			return $redirect_url;
		}

		$default = $this->context->default_language();

		if ( ! $default instanceof Language || $this->context->current_code() !== $default->code() ) {
			return $redirect_url;
		}

		$home_path      = $this->path_from_url( $this->urls->home_url_for( $default->code() ) );
		$requested_path = $this->path_from_url( $requested_url );

		if ( '' === $home_path || '' === $requested_path ) {
			return $redirect_url;
		}

		if ( $requested_path === $home_path || 0 === strpos( $requested_path, $home_path . '/' ) ) {
			return false;
		}

		return $redirect_url;
	}

	/**
	 * Returns a normalised path from a URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function path_from_url( $url ) {
		$path = wp_parse_url( (string) $url, PHP_URL_PATH );

		return untrailingslashit( '/' . ltrim( is_string( $path ) ? $path : '', '/' ) );
	}

	/**
	 * Re-parses the path that followed the language prefix.
	 *
	 * @param \WP    $wp Current WordPress environment.
	 * @param string $raw_path Path captured by the language rewrite rule.
	 * @return void
	 */
	private function reparse_inner_path( $wp, $raw_path ) {
		$path = trim( $raw_path, '/' );

		if ( '' === $path ) {
			return;
		}

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

			if ( ! $this->verbose_page_rule_matches( $rewrite, $target, $matches ) ) {
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
	 * Decides whether a matched rule survives WordPress's verbose page check.
	 *
	 * This mirrors the verbose-page-rule branch of `WP::parse_request()`. When
	 * the permalink structure starts with `%postname%`, WordPress turns on
	 * `use_verbose_page_rules` and the page rule becomes a catch-all that
	 * matches any single-segment path, posts included. Core does not trust that
	 * match: it resolves the captured slug with `get_page_by_path()` and, when
	 * no usable page comes back, it `continue`s to the next rule so the post
	 * rule further down gets its turn.
	 *
	 * Without this, every translated post under a language prefix resolved to
	 * `pagename=<slug>`, found no page, and returned a 404 -- while the sitemap
	 * went on advertising that same URL.
	 *
	 * The check runs against the unexpanded rewrite target, because that is
	 * where the literal `pagename=$matches[N]` marker still exists.
	 *
	 * @param \WP_Rewrite $rewrite Rewrite API.
	 * @param string      $target Unexpanded rewrite target.
	 * @param string[]    $matches Matches captured by the rule pattern.
	 * @return bool
	 */
	private function verbose_page_rule_matches( \WP_Rewrite $rewrite, $target, array $matches ) {
		if ( ! $rewrite->use_verbose_page_rules ) {
			return true;
		}

		if ( ! preg_match( '/pagename=\$matches\[([0-9]+)\]/', $target, $varmatch ) ) {
			return true;
		}

		if ( ! isset( $matches[ $varmatch[1] ] ) ) {
			return false;
		}

		$page = get_page_by_path( $matches[ $varmatch[1] ] );

		if ( ! $page ) {
			return false;
		}

		$post_status_obj = get_post_status_object( $page->post_status );

		if ( ! $post_status_obj instanceof \stdClass ) {
			return false;
		}

		/*
		 * Core's own wording: a status that is neither public, protected nor
		 * private, and is excluded from search, is not something a page rule
		 * may claim.
		 */
		if ( ! $post_status_obj->public && ! $post_status_obj->protected
			&& ! $post_status_obj->private && $post_status_obj->exclude_from_search
		) {
			return false;
		}

		return true;
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
