<?php
/**
 * Wrong-language route correction.
 *
 * @package McLogiora
 */

namespace McLogiora\Routing;

use McLogiora\Contracts\ModuleInterface;
use McLogiora\Core\Container;
use McLogiora\Core\RuntimeReadiness;
use McLogiora\Relations\ContentType;

defined( 'ABSPATH' ) || exit;

/**
 * Sends a translated object to the route that belongs to its own language.
 *
 * A stored object's language comes from its translation relation, not from
 * whichever route the visitor arrived through. WordPress will happily resolve
 * a Turkish translation from an unprefixed English URL, because the slug is
 * unique site-wide and core knows nothing about languages. The result was the
 * same content answering at two addresses, the unprefixed one announcing
 * `lang="en-US"` and a self-referential canonical that appeared in neither of
 * its own hreflang alternates.
 *
 * One address wins. The object's own language route is authoritative, and
 * every other route that reaches the object is redirected there permanently.
 */
final class ObjectLanguageRedirect implements ModuleInterface {
	/**
	 * Decision: leave the request alone.
	 *
	 * @var string
	 */
	const STAY = 'stay';

	/**
	 * Decision: send the request to the object's own language URL.
	 *
	 * @var string
	 */
	const REDIRECT = 'redirect';

	/**
	 * Decision: the object does not belong on this route.
	 *
	 * @var string
	 */
	const NOT_FOUND = 'not_found';

	/**
	 * Language context.
	 *
	 * @var LanguageContextInterface|null
	 */
	private $context = null;

	/**
	 * URL generator.
	 *
	 * @var TranslatedUrlGenerator|null
	 */
	private $urls = null;

	/**
	 * Runtime readiness.
	 *
	 * @var RuntimeReadiness|null
	 */
	private $readiness = null;

	/**
	 * Registers the redirect.
	 *
	 * @param Container $container Service container.
	 * @return void
	 */
	public function register( Container $container ) {
		if ( ! $this->prepare( $container ) ) {
			return;
		}

		/*
		 * `wp` rather than `template_redirect`, because one of the two outcomes
		 * is a 404 and the main query must still be turnable into one when the
		 * decision is made.
		 */
		add_action( 'wp', array( $this, 'enforce' ), 5 );
	}

	/**
	 * Wires the module's dependencies without hooking anything.
	 *
	 * Kept separate from `register()` so the policy can be asserted against a
	 * real request without the assertion itself triggering a redirect.
	 *
	 * @param Container $container Service container.
	 * @return bool Whether the module is usable.
	 */
	public function prepare( Container $container ) {
		$this->readiness = $container->get( RuntimeReadiness::class );

		if ( $this->readiness->is_installing() ) {
			return false;
		}

		$this->context = $container->get( LanguageContextInterface::class );
		$this->urls    = $container->get( TranslatedUrlGenerator::class );

		return true;
	}

	/**
	 * Applies the route decision for the current request.
	 *
	 * @return void
	 */
	public function enforce() {
		$decision = $this->decide();

		if ( self::STAY === $decision['action'] ) {
			return;
		}

		if ( self::NOT_FOUND === $decision['action'] ) {
			$this->send_404();

			return;
		}

		/*
		 * Something has already started the response, so the redirect can no
		 * longer be sent. Warning about it and serving the page is better than
		 * emitting a header error over half-written output.
		 */
		if ( headers_sent() ) {
			return;
		}

		wp_safe_redirect( $decision['target'], 301, 'mcLogiora' );

		exit;
	}

	/**
	 * Decides what should happen to the current request.
	 *
	 * Returned rather than acted on, so the policy can be asserted directly
	 * without a test having to survive a redirect or an `exit`.
	 *
	 * @return array{action:string,target:string}
	 */
	public function decide() {
		$stay = array(
			'action' => self::STAY,
			'target' => '',
		);

		if ( ! $this->readiness instanceof RuntimeReadiness || ! $this->readiness->is_frontend_runtime() ) {
			return $stay;
		}

		if ( is_feed() || is_embed() || is_preview() || is_robots() || is_trackback() || is_404() ) {
			return $stay;
		}

		$resolved = $this->resolve_queried_object();

		if ( null === $resolved ) {
			return $stay;
		}

		list( $object_type, $object_id ) = $resolved;

		$language = $this->urls->language_for_object( $object_type, $object_id );

		if ( $language === $this->context->current_code() ) {
			return $stay;
		}

		if ( null === $language ) {
			/*
			 * An object with no translation relation belongs to the default
			 * language. Reached through a language prefix it is precisely the
			 * case Phase 12 refused to allow: source content served under a
			 * translated URL, misrepresenting the page to readers and search
			 * engines. There is no translation to send anyone to, so this is a
			 * genuine 404 rather than a redirect.
			 */
			return $this->request_is_prefixed()
				? array(
					'action' => self::NOT_FOUND,
					'target' => '',
				)
				: $stay;
		}

		$target = ContentType::POST === $object_type
			? $this->urls->own_post_url( $object_id )
			: $this->urls->own_term_url( $object_id, $this->queried_taxonomy() );

		if ( ! is_string( $target ) || '' === $target ) {
			return $stay;
		}

		$target = $this->carry_query_args( $target );

		/*
		 * Loop protection. If the authoritative URL is the one already being
		 * served, redirecting would bounce the request against itself until
		 * the browser gives up. Compared as paths, because `home_url()` is
		 * itself one of the filters this module exists to correct.
		 */
		if ( $this->path_of( $target ) === $this->requested_path() ) {
			return $stay;
		}

		return array(
			'action' => self::REDIRECT,
			'target' => $target,
		);
	}

	/**
	 * Turns the current request into a 404.
	 *
	 * @return void
	 */
	private function send_404() {
		global $wp_query;

		if ( $wp_query instanceof \WP_Query ) {
			$wp_query->set_404();
		}

		status_header( 404 );
		nocache_headers();
	}

	/**
	 * Returns whether the current request arrived through a language prefix.
	 *
	 * @return bool
	 */
	private function request_is_prefixed() {
		return '' !== $this->urls->prefix_for( $this->context->current_code() );
	}

	/**
	 * Returns the relation type and id of the queried object.
	 *
	 * @return array{0:string,1:int}|null
	 */
	private function resolve_queried_object() {
		if ( is_singular() ) {
			$post_id = (int) get_queried_object_id();

			return $post_id > 0 ? array( ContentType::POST, $post_id ) : null;
		}

		if ( is_category() || is_tag() || is_tax() ) {
			$term_id = (int) get_queried_object_id();

			return $term_id > 0 ? array( ContentType::TERM, $term_id ) : null;
		}

		return null;
	}

	/**
	 * Returns the taxonomy of the queried term.
	 *
	 * @return string
	 */
	private function queried_taxonomy() {
		$term = get_queried_object();

		return $term instanceof \WP_Term ? (string) $term->taxonomy : '';
	}

	/**
	 * Carries the request's own query arguments across the redirect.
	 *
	 * Only arguments WordPress did not consume for routing travel with the
	 * request. mcLogiora's own routing vars are dropped deliberately: putting
	 * them back would let a hand-written URL steer the redirect.
	 *
	 * @param string $target Target URL.
	 * @return string
	 */
	private function carry_query_args( $target ) {
		if ( empty( $_GET ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return $target;
		}

		$carried = array();

		foreach ( wp_unslash( $_GET ) as $raw_key => $raw_value ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( ! is_scalar( $raw_value ) ) {
				continue;
			}

			$key = sanitize_key( (string) $raw_key );

			if ( '' === $key || in_array( $key, array( RoutingModule::QUERY_VAR, RoutingModule::PATH_VAR ), true ) ) {
				continue;
			}

			$carried[ $key ] = sanitize_text_field( (string) $raw_value );
		}

		return empty( $carried ) ? $target : add_query_arg( $carried, $target );
	}

	/**
	 * Returns the path of the request currently being served.
	 *
	 * @return string
	 */
	private function requested_path() {
		$requested = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		return $this->normalise_path( (string) $requested );
	}

	/**
	 * Returns the path component of an absolute URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function path_of( $url ) {
		$path = wp_parse_url( (string) $url, PHP_URL_PATH );

		return $this->normalise_path( is_string( $path ) ? $path : '' );
	}

	/**
	 * Normalises a path for comparison.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private function normalise_path( $path ) {
		$path = (string) strtok( $path, '?#' );

		return untrailingslashit( '/' . ltrim( $path, '/' ) );
	}
}
