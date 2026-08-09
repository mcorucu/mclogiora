<?php
/**
 * Translated URL generation.
 *
 * @package McLogiora
 */

namespace McLogiora\Routing;

use McLogiora\Languages\Language;
use McLogiora\Relations\ContentType;
use McLogiora\Relations\TranslationGroup;
use McLogiora\Relations\TranslationRelationServiceInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Builds multilingual URLs.
 *
 * This is the only place that decides what a translated URL looks like.
 * Switchers, templates, and the future SEO phase all call in here rather than
 * assembling paths themselves, because a second implementation would drift and
 * start emitting URLs that do not resolve.
 *
 * It never fabricates a URL for a translation that does not exist. Returning
 * a plausible-looking path for missing content is how a site ends up serving
 * source-language text under a translated URL.
 */
final class TranslatedUrlGenerator {
	/**
	 * Relation service.
	 *
	 * @var TranslationRelationServiceInterface
	 */
	private $relations;

	/**
	 * Routing settings.
	 *
	 * @var RoutingSettings
	 */
	private $settings;

	/**
	 * Language context.
	 *
	 * @var LanguageContextInterface
	 */
	private $context;

	/**
	 * Per-request memo of resolved translations.
	 *
	 * @var array<string,int|null>
	 */
	private $memo = array();

	/**
	 * Constructor.
	 *
	 * @param TranslationRelationServiceInterface $relations Relation service.
	 * @param RoutingSettings                     $settings Routing settings.
	 * @param LanguageContextInterface            $context Language context.
	 */
	public function __construct(
		TranslationRelationServiceInterface $relations,
		RoutingSettings $settings,
		LanguageContextInterface $context
	) {
		$this->relations = $relations;
		$this->settings  = $settings;
		$this->context   = $context;
	}

	/**
	 * Returns whether a language needs a URL prefix.
	 *
	 * @param string $language_code Language code.
	 * @return bool
	 */
	public function language_has_prefix( $language_code ) {
		$default = $this->context->default_language();

		if ( ! $default instanceof Language ) {
			return false;
		}

		if ( $default->code() !== (string) $language_code ) {
			return true;
		}

		return $this->settings->default_language_has_prefix();
	}

	/**
	 * Returns the URL prefix segment for a language, or an empty string.
	 *
	 * @param string $language_code Language code.
	 * @return string
	 */
	public function prefix_for( $language_code ) {
		return $this->language_has_prefix( $language_code ) ? (string) $language_code : '';
	}

	/**
	 * Applies a language prefix to an absolute site URL.
	 *
	 * Rebuilding the path rather than string-concatenating keeps query strings
	 * and fragments intact, which matters for paginated and filtered URLs.
	 *
	 * @param string $url Absolute URL within the site.
	 * @param string $language_code Language code.
	 * @return string
	 */
	public function apply_prefix( $url, $language_code ) {
		$prefix = $this->prefix_for( $language_code );

		if ( '' === $prefix ) {
			return $url;
		}

		$home = $this->raw_home_url();

		if ( 0 !== strpos( $url, $home ) ) {
			return $url;
		}

		$remainder = substr( $url, strlen( $home ) );

		return $home . $prefix . '/' . ltrim( (string) $remainder, '/' );
	}

	/**
	 * Returns the home URL for a language.
	 *
	 * @param string $language_code Language code.
	 * @return string
	 */
	public function home_url_for( $language_code ) {
		$prefix = $this->prefix_for( $language_code );
		$home   = $this->raw_home_url();

		return '' === $prefix ? $home : $home . $prefix . '/';
	}

	/**
	 * Returns the site home URL without mcLogiora's own filters applied.
	 *
	 * The home_url() function is filtered by PermalinkModule, so asking for it from inside
	 * the generator would re-enter that filter and prefix an already prefixed
	 * URL.
	 *
	 * @return string
	 */
	private function raw_home_url() {
		$home = PermalinkFilters::without_filters(
			static function () {
				return home_url( '/' );
			}
		);

		return is_string( $home ) ? $home : '/';
	}

	/**
	 * Returns the translated object ID for a language, or null.
	 *
	 * @param string $object_type Relation content type.
	 * @param int    $object_id Object identifier.
	 * @param string $language_code Target language code.
	 * @return int|null
	 */
	public function translated_object_id( $object_type, $object_id, $language_code ) {
		$key = $object_type . ':' . (int) $object_id . ':' . (string) $language_code;

		if ( array_key_exists( $key, $this->memo ) ) {
			return $this->memo[ $key ];
		}

		$group = $this->relations->get_translation_set_for_object( (string) $object_type, (string) $object_id );

		if ( ! $group instanceof TranslationGroup ) {
			$this->memo[ $key ] = null;

			return null;
		}

		$resolved = null;

		/*
		 * The whole group is walked once and every language it contains is
		 * memoised, not just the one asked for. A switcher asks about every
		 * language in turn, so resolving them one query at a time would make
		 * rendering it an N+1 problem.
		 */
		foreach ( $group->items() as $item ) {
			$item_key = $object_type . ':' . (int) $object_id . ':' . $item->language_code();

			$this->memo[ $item_key ] = (int) $item->object_id();

			if ( $item->language_code() === (string) $language_code ) {
				$resolved = (int) $item->object_id();
			}
		}

		if ( ! array_key_exists( $key, $this->memo ) ) {
			$this->memo[ $key ] = null;
		}

		return $resolved;
	}

	/**
	 * Returns the URL of a post in a language, or null when untranslated.
	 *
	 * @param int    $post_id Post identifier.
	 * @param string $language_code Target language code.
	 * @return string|null
	 */
	public function post_url( $post_id, $language_code ) {
		$target = $this->translated_object_id( ContentType::POST, (int) $post_id, $language_code );

		if ( null === $target ) {
			return null;
		}

		$permalink = $this->raw_permalink( $target );

		if ( '' === $permalink ) {
			return null;
		}

		return $this->apply_prefix( $permalink, $language_code );
	}

	/**
	 * Returns the URL of a term in a language, or null when untranslated.
	 *
	 * @param int    $term_id Term identifier.
	 * @param string $taxonomy Taxonomy name.
	 * @param string $language_code Target language code.
	 * @return string|null
	 */
	public function term_url( $term_id, $taxonomy, $language_code ) {
		$target = $this->translated_object_id( ContentType::TERM, (int) $term_id, $language_code );

		if ( null === $target ) {
			return null;
		}

		$link = $this->raw_term_link( $target, (string) $taxonomy );

		if ( '' === $link ) {
			return null;
		}

		return $this->apply_prefix( $link, $language_code );
	}

	/**
	 * Returns a permalink without mcLogiora's own filters applied.
	 *
	 * The permalink filters call back into this generator, so asking
	 * WordPress for a link while already inside one would recurse. The
	 * generator is the layer that adds prefixes, so it must read the raw
	 * WordPress link and add the prefix itself.
	 *
	 * @param int $post_id Post identifier.
	 * @return string
	 */
	private function raw_permalink( $post_id ) {
		$link = PermalinkFilters::without_filters(
			static function () use ( $post_id ) {
				return get_permalink( (int) $post_id );
			}
		);

		return is_string( $link ) ? $link : '';
	}

	/**
	 * Returns a term link without mcLogiora's own filters applied.
	 *
	 * @param int    $term_id Term identifier.
	 * @param string $taxonomy Taxonomy name.
	 * @return string
	 */
	private function raw_term_link( $term_id, $taxonomy ) {
		$link = PermalinkFilters::without_filters(
			static function () use ( $term_id, $taxonomy ) {
				return get_term_link( (int) $term_id, (string) $taxonomy );
			}
		);

		return is_string( $link ) ? $link : '';
	}

	/**
	 * Clears the per-request memo.
	 *
	 * @return void
	 */
	public function reset() {
		$this->memo = array();
	}
}
