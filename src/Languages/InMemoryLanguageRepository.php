<?php
/**
 * In-memory language repository.
 *
 * @package McLogiora
 */

namespace McLogiora\Languages;

defined( 'ABSPATH' ) || exit;

/**
 * Provides mock language data without persistence.
 */
final class InMemoryLanguageRepository implements LanguageRepositoryInterface {
	/**
	 * Mock language records.
	 *
	 * @var Language[]
	 */
	private $languages;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->languages = array(
			new Language( 'en', 'en_US', 'English', 'English', 'ltr', LanguageStatus::ACTIVE, 1, true ),
			new Language( 'tr', 'tr_TR', 'Turkce', 'Turkish', 'ltr', LanguageStatus::ACTIVE, 2, false ),
			new Language( 'ar', 'ar', 'Al-Arabiyah', 'Arabic', 'rtl', LanguageStatus::INACTIVE, 3, false ),
		);
	}

	/**
	 * Returns all known languages ordered for display.
	 *
	 * @return Language[]
	 */
	public function all() {
		$languages = $this->languages;

		usort(
			$languages,
			static function ( Language $a, Language $b ) {
				return $a->order() - $b->order();
			}
		);

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

		foreach ( $this->languages as $language ) {
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

		foreach ( $this->languages as $language ) {
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
		if ( $this->find_by_code( $language->code() ) instanceof Language ) {
			return new \WP_Error( 'mclogiora_duplicate_language_code', __( 'A language with this language code already exists.', 'mclogiora' ) );
		}

		$this->languages[] = $language;

		if ( $language->is_default() ) {
			$this->set_default( $language->code() );
		}

		return $this->find_by_code( $language->code() );
	}

	/**
	 * Updates a language.
	 *
	 * @param Language $language Language entity.
	 * @return Language|\WP_Error
	 */
	public function update( Language $language ) {
		foreach ( $this->languages as $index => $existing ) {
			if ( $existing->code() === $language->code() ) {
				$this->languages[ $index ] = $language;

				if ( $language->is_default() ) {
					$this->set_default( $language->code() );
				}

				return $this->find_by_code( $language->code() );
			}
		}

		return new \WP_Error( 'mclogiora_language_not_found', __( 'The language could not be found.', 'mclogiora' ) );
	}

	/**
	 * Enables a language.
	 *
	 * @param string $code Language code.
	 * @return Language|\WP_Error
	 */
	public function enable( $code ) {
		return $this->replace_status( $code, LanguageStatus::ACTIVE );
	}

	/**
	 * Disables a language.
	 *
	 * @param string $code Language code.
	 * @return Language|\WP_Error
	 */
	public function disable( $code ) {
		$language = $this->find_by_code( $code );

		if ( $language instanceof Language && $language->is_default() ) {
			return new \WP_Error( 'mclogiora_disable_default_language', __( 'The default language cannot be disabled.', 'mclogiora' ) );
		}

		return $this->replace_status( $code, LanguageStatus::INACTIVE );
	}

	/**
	 * Deletes a language when no integrity rule blocks it.
	 *
	 * @param string $code Language code.
	 * @return bool|\WP_Error
	 */
	public function delete( $code ) {
		$language = $this->find_by_code( $code );

		if ( ! $language instanceof Language ) {
			return new \WP_Error( 'mclogiora_language_not_found', __( 'The language could not be found.', 'mclogiora' ) );
		}

		if ( $language->is_default() ) {
			return new \WP_Error( 'mclogiora_delete_default_language', __( 'The default language cannot be deleted.', 'mclogiora' ) );
		}

		foreach ( $this->languages as $index => $existing ) {
			if ( $existing->code() === $language->code() ) {
				unset( $this->languages[ $index ] );
				$this->languages = array_values( $this->languages );
				return true;
			}
		}

		return true;
	}

	/**
	 * Sets the default language.
	 *
	 * @param string $code Language code.
	 * @return Language|\WP_Error
	 */
	public function set_default( $code ) {
		$target = $this->find_by_code( $code );

		if ( ! $target instanceof Language ) {
			return new \WP_Error( 'mclogiora_language_not_found', __( 'The language could not be found.', 'mclogiora' ) );
		}

		foreach ( $this->languages as $index => $language ) {
			$this->languages[ $index ] = new Language(
				$language->code(),
				$language->locale(),
				$language->native_name(),
				$language->english_name(),
				$language->direction(),
				$language->code() === $target->code() ? LanguageStatus::ACTIVE : $language->status(),
				$language->order(),
				$language->code() === $target->code()
			);
		}

		return $this->find_by_code( $target->code() );
	}

	/**
	 * Reorders languages by language code sequence.
	 *
	 * @param string[] $language_codes Ordered language codes.
	 * @return bool|\WP_Error
	 */
	public function reorder( array $language_codes ) {
		$order = 1;

		foreach ( $language_codes as $code ) {
			$language = $this->find_by_code( $code );

			if ( ! $language instanceof Language ) {
				continue;
			}

			$this->update(
				new Language(
					$language->code(),
					$language->locale(),
					$language->native_name(),
					$language->english_name(),
					$language->direction(),
					$language->status(),
					$order,
					$language->is_default()
				)
			);

			++$order;
		}

		return true;
	}

	/**
	 * Replaces status for an in-memory language.
	 *
	 * @param string $code Language code.
	 * @param string $status Status.
	 * @return Language|\WP_Error
	 */
	private function replace_status( $code, $status ) {
		$language = $this->find_by_code( $code );

		if ( ! $language instanceof Language ) {
			return new \WP_Error( 'mclogiora_language_not_found', __( 'The language could not be found.', 'mclogiora' ) );
		}

		return $this->update(
			new Language(
				$language->code(),
				$language->locale(),
				$language->native_name(),
				$language->english_name(),
				$language->direction(),
				$status,
				$language->order(),
				$language->is_default()
			)
		);
	}
}
