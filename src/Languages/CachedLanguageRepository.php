<?php
/**
 * Cached language repository.
 *
 * @package McLogiora
 */

namespace McLogiora\Languages;

use McLogiora\Cache\CacheInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Adds object-cache reads around a language repository.
 */
final class CachedLanguageRepository implements LanguageRepositoryInterface {
	const CACHE_KEY_ALL = 'languages_all';

	/**
	 * Inner repository.
	 *
	 * @var LanguageRepositoryInterface
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
	 * @param LanguageRepositoryInterface $repository Inner repository.
	 * @param CacheInterface              $cache Cache.
	 */
	public function __construct( LanguageRepositoryInterface $repository, CacheInterface $cache ) {
		$this->repository = $repository;
		$this->cache      = $cache;
	}

	/**
	 * Returns all languages.
	 *
	 * @return Language[]
	 */
	public function all() {
		$cached = $this->cache->get( self::CACHE_KEY_ALL );

		if ( false !== $cached ) {
			return $cached;
		}

		$languages = $this->repository->all();
		$this->cache->set( self::CACHE_KEY_ALL, $languages );

		return $languages;
	}

	/**
	 * Finds a language by language code.
	 *
	 * @param string $code Language code.
	 * @return Language|null
	 */
	public function find_by_code( $code ) {
		$code = sanitize_key( (string) $code );

		foreach ( $this->all() as $language ) {
			if ( $language->code() === $code ) {
				return $language;
			}
		}

		return null;
	}

	/**
	 * Finds a language by locale.
	 *
	 * @param string $locale Locale.
	 * @return Language|null
	 */
	public function find_by_locale( $locale ) {
		$locale = sanitize_text_field( (string) $locale );

		foreach ( $this->all() as $language ) {
			if ( $language->locale() === $locale ) {
				return $language;
			}
		}

		return null;
	}

	/**
	 * Returns active languages.
	 *
	 * @return Language[]
	 */
	public function active() {
		return array_values(
			array_filter(
				$this->all(),
				static function ( Language $language ) {
					return $language->is_active();
				}
			)
		);
	}

	/**
	 * Returns the default language.
	 *
	 * @return Language|null
	 */
	public function default_language() {
		foreach ( $this->all() as $language ) {
			if ( $language->is_default() ) {
				return $language;
			}
		}

		return null;
	}

	/**
	 * Creates a language.
	 *
	 * @param Language $language Language entity.
	 * @return Language|\WP_Error
	 */
	public function create( Language $language ) {
		$result = $this->repository->create( $language );
		$this->invalidate_after_write( $result );

		return $result;
	}

	/**
	 * Updates a language.
	 *
	 * @param Language $language Language entity.
	 * @return Language|\WP_Error
	 */
	public function update( Language $language ) {
		$result = $this->repository->update( $language );
		$this->invalidate_after_write( $result );

		return $result;
	}

	/**
	 * Enables a language.
	 *
	 * @param string $code Language code.
	 * @return Language|\WP_Error
	 */
	public function enable( $code ) {
		$result = $this->repository->enable( $code );
		$this->invalidate_after_write( $result );

		return $result;
	}

	/**
	 * Disables a language.
	 *
	 * @param string $code Language code.
	 * @return Language|\WP_Error
	 */
	public function disable( $code ) {
		$result = $this->repository->disable( $code );
		$this->invalidate_after_write( $result );

		return $result;
	}

	/**
	 * Deletes a language when no integrity rule blocks it.
	 *
	 * @param string $code Language code.
	 * @return bool|\WP_Error
	 */
	public function delete( $code ) {
		$result = $this->repository->delete( $code );
		$this->invalidate_after_write( $result );

		return $result;
	}

	/**
	 * Sets the default language.
	 *
	 * @param string $code Language code.
	 * @return Language|\WP_Error
	 */
	public function set_default( $code ) {
		$result = $this->repository->set_default( $code );
		$this->invalidate_after_write( $result );

		return $result;
	}

	/**
	 * Reorders languages by language code sequence.
	 *
	 * @param string[] $language_codes Ordered language codes.
	 * @return bool|\WP_Error
	 */
	public function reorder( array $language_codes ) {
		$result = $this->repository->reorder( $language_codes );
		$this->invalidate_after_write( $result );

		return $result;
	}

	/**
	 * Invalidates the language list cache after successful writes.
	 *
	 * @param mixed $result Write result.
	 * @return void
	 */
	private function invalidate_after_write( $result ) {
		if ( is_wp_error( $result ) ) {
			return;
		}

		$this->cache->delete( self::CACHE_KEY_ALL );
	}
}
