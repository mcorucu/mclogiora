<?php
/**
 * In-memory language service for tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Support;

use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageServiceInterface;
use McLogiora\Languages\LanguageStatus;

/**
 * Serves a fixed language set.
 */
final class FakeLanguageService implements LanguageServiceInterface {
	/**
	 * Languages.
	 *
	 * @var Language[]
	 */
	private $languages;

	/**
	 * Constructor.
	 *
	 * @param Language[] $languages Languages.
	 */
	public function __construct( array $languages ) {
		$this->languages = $languages;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return Language|null
	 */
	public function get_default_language() {
		foreach ( $this->languages as $language ) {
			if ( $language->is_default() ) {
				return $language;
			}
		}

		return null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return Language[]
	 */
	public function get_active_languages() {
		$active = array();

		foreach ( $this->languages as $language ) {
			if ( LanguageStatus::ACTIVE === $language->status() ) {
				$active[] = $language;
			}
		}

		return $active;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return Language[]
	 */
	public function get_languages() {
		return $this->languages;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $code Language code.
	 * @return Language|null
	 */
	public function get_language_by_code( $code ) {
		foreach ( $this->languages as $language ) {
			if ( $language->code() === (string) $code ) {
				return $language;
			}
		}

		return null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $locale Locale.
	 * @return Language|null
	 */
	public function get_language_by_locale( $locale ) {
		foreach ( $this->languages as $language ) {
			if ( $language->locale() === (string) $locale ) {
				return $language;
			}
		}

		return null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string,mixed> $data Language data.
	 * @return Language|\WP_Error
	 */
	public function create_language( array $data ) {
		unset( $data );

		return new \WP_Error( 'mclogiora_not_supported', 'Not used in tests.' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string              $code Language code.
	 * @param array<string,mixed> $data Language data.
	 * @return Language|\WP_Error
	 */
	public function update_language( $code, array $data ) {
		unset( $code, $data );

		return new \WP_Error( 'mclogiora_not_supported', 'Not used in tests.' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $code Language code.
	 * @return Language|\WP_Error
	 */
	public function enable_language( $code ) {
		unset( $code );

		return new \WP_Error( 'mclogiora_not_supported', 'Not used in tests.' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $code Language code.
	 * @return Language|\WP_Error
	 */
	public function disable_language( $code ) {
		unset( $code );

		return new \WP_Error( 'mclogiora_not_supported', 'Not used in tests.' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $code Language code.
	 * @return bool|\WP_Error
	 */
	public function delete_language( $code ) {
		unset( $code );

		return new \WP_Error( 'mclogiora_not_supported', 'Not used in tests.' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $code Language code.
	 * @return Language|\WP_Error
	 */
	public function set_default_language( $code ) {
		unset( $code );

		return new \WP_Error( 'mclogiora_not_supported', 'Not used in tests.' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string[] $ordered_codes Ordered codes.
	 * @return bool|\WP_Error
	 */
	public function reorder_languages( array $ordered_codes ) {
		unset( $ordered_codes );

		return new \WP_Error( 'mclogiora_not_supported', 'Not used in tests.' );
	}
}
