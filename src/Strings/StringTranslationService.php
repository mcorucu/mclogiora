<?php
/**
 * String translation service.
 *
 * @package McLogiora
 */

namespace McLogiora\Strings;

use McLogiora\Cache\CacheInterface;
use McLogiora\Relations\TranslationStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Looks up translated strings for an explicitly named language.
 *
 * This service never asks "what language is the current request?". Deciding
 * that is Phase 12's job, and building a second answer to it here would
 * create two competing sources of truth. Callers pass the language they want,
 * and get the original string back when no translation exists.
 *
 * Nothing here hooks `gettext`. Registering a global filter would apply
 * translations based on a frontend language this phase has not defined.
 */
final class StringTranslationService {
	/**
	 * String repository.
	 *
	 * @var StringRepositoryInterface
	 */
	private $repository;

	/**
	 * Cache.
	 *
	 * @var CacheInterface
	 */
	private $cache;

	/**
	 * Constructor.
	 *
	 * @param StringRepositoryInterface $repository String repository.
	 * @param CacheInterface            $cache Cache.
	 */
	public function __construct( StringRepositoryInterface $repository, CacheInterface $cache ) {
		$this->repository = $repository;
		$this->cache      = $cache;
	}

	/**
	 * Translates a string into an explicitly named language.
	 *
	 * @param string $text Source text.
	 * @param string $language_code Target language code.
	 * @param string $text_domain Text domain.
	 * @param string $context Gettext context.
	 * @return string
	 */
	public function translate( $text, $language_code, $text_domain = '', $context = '' ) {
		$text          = (string) $text;
		$language_code = (string) $language_code;

		if ( '' === $language_code || '' === $text ) {
			return $text;
		}

		$hash      = StringSource::hash_for( $text, $text_domain, $context );
		$cache_key = $this->cache_key( $hash, $language_code );
		$cached    = $this->cache->get( $cache_key );

		if ( is_string( $cached ) ) {
			return '' === $cached ? $text : $cached;
		}

		$source = $this->repository->find_by_hash( $hash );

		if ( ! $source instanceof StringSource ) {
			$this->cache->set( $cache_key, '' );

			return $text;
		}

		$translation = $this->repository->find_translation( $source->id(), $language_code );

		if ( ! $translation instanceof StringTranslation || '' === $translation->text() ) {
			$this->cache->set( $cache_key, '' );

			return $text;
		}

		$this->cache->set( $cache_key, $translation->text() );

		return $translation->text();
	}

	/**
	 * Returns whether a translation exists for an explicit language.
	 *
	 * @param string $text Source text.
	 * @param string $language_code Target language code.
	 * @param string $text_domain Text domain.
	 * @param string $context Gettext context.
	 * @return bool
	 */
	public function has_translation( $text, $language_code, $text_domain = '', $context = '' ) {
		$source = $this->repository->find_by_hash( StringSource::hash_for( $text, $text_domain, $context ) );

		if ( ! $source instanceof StringSource ) {
			return false;
		}

		$translation = $this->repository->find_translation( $source->id(), (string) $language_code );

		return $translation instanceof StringTranslation && '' !== $translation->text();
	}

	/**
	 * Saves a translation and invalidates the affected cache entry.
	 *
	 * @param int    $string_id Source string identifier.
	 * @param string $language_code Language code.
	 * @param string $translated_text Translated text.
	 * @param string $status Translation status.
	 * @return StringTranslation|\WP_Error
	 */
	public function save_translation( $string_id, $language_code, $translated_text, $status = TranslationStatus::TRANSLATED ) {
		$source = $this->repository->find( (int) $string_id );

		if ( ! $source instanceof StringSource ) {
			return new \WP_Error( 'mclogiora_string_not_found', __( 'The string could not be found.', 'mclogiora' ) );
		}

		$saved = $this->repository->save_translation(
			new StringTranslation( $source->id(), $language_code, $translated_text, $status )
		);

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$this->cache->delete( $this->cache_key( $source->hash(), (string) $language_code ) );

		return $saved;
	}

	/**
	 * Returns the cache key for a string and language.
	 *
	 * @param string $hash Identity hash.
	 * @param string $language_code Language code.
	 * @return string
	 */
	private function cache_key( $hash, $language_code ) {
		return 'mclogiora_string_' . $hash . '_' . $language_code;
	}
}
