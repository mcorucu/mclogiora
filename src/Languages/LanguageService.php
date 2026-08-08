<?php
/**
 * Language service.
 *
 * @package McLogiora
 */

namespace McLogiora\Languages;

defined( 'ABSPATH' ) || exit;

/**
 * Provides language domain reads and controlled writes.
 */
final class LanguageService implements LanguageServiceInterface {
	/**
	 * Language repository.
	 *
	 * @var LanguageRepositoryInterface
	 */
	private $repository;

	/**
	 * Locale validator.
	 *
	 * @var LocaleValidator
	 */
	private $locale_validator;

	/**
	 * RTL detector.
	 *
	 * @var RtlDetector
	 */
	private $rtl_detector;

	/**
	 * Constructor.
	 *
	 * @param LanguageRepositoryInterface $repository Language repository.
	 * @param LocaleValidator|null        $locale_validator Locale validator.
	 * @param RtlDetector|null            $rtl_detector RTL detector.
	 */
	public function __construct( LanguageRepositoryInterface $repository, $locale_validator = null, $rtl_detector = null ) {
		$this->repository       = $repository;
		$this->locale_validator = $locale_validator instanceof LocaleValidator ? $locale_validator : new LocaleValidator();
		$this->rtl_detector     = $rtl_detector instanceof RtlDetector ? $rtl_detector : new RtlDetector();
	}

	/**
	 * Returns the default language.
	 *
	 * @return Language|null
	 */
	public function get_default_language() {
		return $this->repository->default_language();
	}

	/**
	 * Returns active languages.
	 *
	 * @return Language[]
	 */
	public function get_active_languages() {
		return $this->repository->active();
	}

	/**
	 * Returns all languages.
	 *
	 * @return Language[]
	 */
	public function get_languages() {
		return $this->repository->all();
	}

	/**
	 * Finds a language by language code.
	 *
	 * @param string $code Language code.
	 * @return Language|null
	 */
	public function get_language_by_code( $code ) {
		return $this->repository->find_by_code( $code );
	}

	/**
	 * Finds a language by locale.
	 *
	 * @param string $locale Locale.
	 * @return Language|null
	 */
	public function get_language_by_locale( $locale ) {
		return $this->repository->find_by_locale( $locale );
	}

	/**
	 * Creates a language from sanitized domain input.
	 *
	 * @param array<string, mixed> $data Language data.
	 * @return Language|\WP_Error
	 */
	public function create_language( array $data ) {
		$language = $this->language_from_data( $data );

		if ( is_wp_error( $language ) ) {
			return $language;
		}

		return $this->repository->create( $language );
	}

	/**
	 * Updates a language from sanitized domain input.
	 *
	 * @param string               $code Language code.
	 * @param array<string, mixed> $data Language data.
	 * @return Language|\WP_Error
	 */
	public function update_language( $code, array $data ) {
		$existing = $this->repository->find_by_code( $code );

		if ( ! $existing instanceof Language ) {
			return new \WP_Error( 'mclogiora_language_not_found', __( 'The language could not be found.', 'mclogiora' ) );
		}

		$data['code']    = $existing->code();
		$data['default'] = $existing->is_default();
		$language        = $this->language_from_data( $data, $existing );

		if ( is_wp_error( $language ) ) {
			return $language;
		}

		return $this->repository->update( $language );
	}

	/**
	 * Enables a language.
	 *
	 * @param string $code Language code.
	 * @return Language|\WP_Error
	 */
	public function enable_language( $code ) {
		return $this->repository->enable( $code );
	}

	/**
	 * Disables a language.
	 *
	 * @param string $code Language code.
	 * @return Language|\WP_Error
	 */
	public function disable_language( $code ) {
		return $this->repository->disable( $code );
	}

	/**
	 * Deletes a language when safe.
	 *
	 * @param string $code Language code.
	 * @return bool|\WP_Error
	 */
	public function delete_language( $code ) {
		return $this->repository->delete( $code );
	}

	/**
	 * Sets the default language.
	 *
	 * @param string $code Language code.
	 * @return Language|\WP_Error
	 */
	public function set_default_language( $code ) {
		return $this->repository->set_default( $code );
	}

	/**
	 * Reorders languages.
	 *
	 * @param array<string, mixed> $ordered_codes Ordered language codes.
	 * @return bool|\WP_Error
	 */
	public function reorder_languages( array $ordered_codes ) {
		$weighted_codes = array();

		foreach ( $ordered_codes as $code => $order ) {
			$weighted_codes[ sanitize_key( (string) $code ) ] = absint( $order );
		}

		asort( $weighted_codes, SORT_NUMERIC );

		return $this->repository->reorder( array_keys( $weighted_codes ) );
	}

	/**
	 * Builds a language entity from request/domain data.
	 *
	 * @param array<string, mixed> $data Language data.
	 * @param Language|null        $existing Existing language.
	 * @return Language|\WP_Error
	 */
	private function language_from_data( array $data, $existing = null ) {
		$code         = isset( $data['code'] ) ? sanitize_key( wp_unslash( $data['code'] ) ) : '';
		$locale       = isset( $data['locale'] ) ? sanitize_text_field( wp_unslash( $data['locale'] ) ) : '';
		$native_name  = isset( $data['native_name'] ) ? sanitize_text_field( wp_unslash( $data['native_name'] ) ) : '';
		$english_name = isset( $data['english_name'] ) ? sanitize_text_field( wp_unslash( $data['english_name'] ) ) : '';
		$direction    = isset( $data['direction'] ) ? sanitize_key( wp_unslash( $data['direction'] ) ) : '';
		$status       = isset( $data['status'] ) ? sanitize_key( wp_unslash( $data['status'] ) ) : LanguageStatus::ACTIVE;
		$order        = isset( $data['order'] ) ? absint( $data['order'] ) : 0;
		$is_default   = ! empty( $data['default'] );

		if ( $existing instanceof Language ) {
			$code         = '' !== $code ? $code : $existing->code();
			$locale       = '' !== $locale ? $locale : $existing->locale();
			$native_name  = '' !== $native_name ? $native_name : $existing->native_name();
			$english_name = '' !== $english_name ? $english_name : $existing->english_name();
			$direction    = '' !== $direction ? $direction : $existing->direction();
			$status       = LanguageStatus::is_valid( $status ) ? $status : $existing->status();
			$order        = 0 !== $order ? $order : $existing->order();
			$is_default   = $is_default || $existing->is_default();
		}

		if ( '' === $code ) {
			return new \WP_Error( 'mclogiora_invalid_language_code', __( 'Language code is required.', 'mclogiora' ) );
		}

		if ( ! $this->locale_validator->is_valid( $locale ) ) {
			return new \WP_Error( 'mclogiora_invalid_locale', __( 'Use a valid WordPress locale such as en_US or tr_TR.', 'mclogiora' ) );
		}

		if ( '' === $direction ) {
			$direction = $this->rtl_detector->is_rtl( $code ) || $this->rtl_detector->is_rtl( $locale ) ? 'rtl' : 'ltr';
		}

		if ( ! LanguageStatus::is_valid( $status ) ) {
			$status = LanguageStatus::ACTIVE;
		}

		if ( $is_default ) {
			$status = LanguageStatus::ACTIVE;
		}

		return new Language(
			$code,
			$locale,
			$native_name,
			$english_name,
			$direction,
			$status,
			$order,
			$is_default
		);
	}
}
