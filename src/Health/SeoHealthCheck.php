<?php
/**
 * Multilingual SEO diagnostics.
 *
 * @package McLogiora
 */

namespace McLogiora\Health;

use McLogiora\Core\InstallationFailure;
use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageRepositoryInterface;
use McLogiora\Languages\LanguageTag;
use McLogiora\Seo\SeoCompatibilityManager;
use McLogiora\Seo\SeoConcern;

defined( 'ABSPATH' ) || exit;

/**
 * Reports conditions that would make multilingual SEO output wrong.
 *
 * Strictly read-only. Nothing here repairs anything, because every plausible
 * repair -- changing a language's locale, deactivating a plugin, rewriting a
 * relation -- is a decision with consequences the plugin is not entitled to
 * make on someone's behalf.
 *
 * The checks are the ones whose failure is silent. A missing translation is
 * visible in the Translation Manager; a language whose locale cannot form a
 * valid `hreflang` value looks completely normal in the admin and simply
 * vanishes from the annotations.
 */
final class SeoHealthCheck {
	const SEVERITY_OK      = 'ok';
	const SEVERITY_NOTICE  = 'notice';
	const SEVERITY_WARNING = 'warning';

	/**
	 * Language repository.
	 *
	 * @var LanguageRepositoryInterface
	 */
	private $languages;

	/**
	 * SEO compatibility manager.
	 *
	 * @var SeoCompatibilityManager
	 */
	private $compatibility;

	/**
	 * Constructor.
	 *
	 * @param LanguageRepositoryInterface $languages Language repository.
	 * @param SeoCompatibilityManager     $compatibility Compatibility manager.
	 */
	public function __construct( LanguageRepositoryInterface $languages, SeoCompatibilityManager $compatibility ) {
		$this->languages     = $languages;
		$this->compatibility = $compatibility;
	}

	/**
	 * Returns the diagnostic report.
	 *
	 * @return array<int,array{id:string,severity:string,label:string,detail:string}>
	 */
	public function report() {
		return array_merge(
			$this->language_tag_findings(),
			$this->ownership_findings(),
			$this->installation_findings()
		);
	}

	/**
	 * Returns findings about language tags.
	 *
	 * @return array<int,array{id:string,severity:string,label:string,detail:string}>
	 */
	private function language_tag_findings() {
		$findings = array();
		$seen     = array();
		$invalid  = array();
		$repeated = array();

		foreach ( $this->languages->active() as $language ) {
			if ( ! $language instanceof Language ) {
				continue;
			}

			$tag = LanguageTag::for_language( $language );

			if ( '' === $tag ) {
				$invalid[] = $language->code();
				continue;
			}

			if ( isset( $seen[ $tag ] ) ) {
				$repeated[] = $tag;
				continue;
			}

			$seen[ $tag ] = $language->code();
		}

		if ( ! empty( $invalid ) ) {
			$findings[] = array(
				'id'       => 'invalid_language_tag',
				'severity' => self::SEVERITY_WARNING,
				'label'    => __( 'Some languages cannot produce a valid language tag', 'mclogiora' ),
				'detail'   => sprintf(
					/* translators: %s: comma-separated list of language codes. */
					__( 'These languages are left out of hreflang and og:locale until their locale is corrected: %s', 'mclogiora' ),
					implode( ', ', $invalid )
				),
			);
		}

		if ( ! empty( $repeated ) ) {
			$findings[] = array(
				'id'       => 'duplicate_language_tag',
				'severity' => self::SEVERITY_WARNING,
				'label'    => __( 'Two languages share one language tag', 'mclogiora' ),
				'detail'   => sprintf(
					/* translators: %s: comma-separated list of language tags. */
					__( 'Only the first language is annotated for each repeated tag: %s', 'mclogiora' ),
					implode( ', ', array_unique( $repeated ) )
				),
			);
		}

		if ( empty( $findings ) ) {
			$findings[] = array(
				'id'       => 'language_tags',
				'severity' => self::SEVERITY_OK,
				'label'    => __( 'Every active language has a valid language tag', 'mclogiora' ),
				'detail'   => implode( ', ', array_keys( $seen ) ),
			);
		}

		return $findings;
	}

	/**
	 * Returns findings about which plugin owns which SEO output.
	 *
	 * @return array<int,array{id:string,severity:string,label:string,detail:string}>
	 */
	private function ownership_findings() {
		$findings  = array();
		$delegated = array();

		foreach ( SeoConcern::all() as $concern ) {
			$owner = $this->compatibility->owner_of( $concern );

			if ( null !== $owner ) {
				$delegated[] = $concern . ' -> ' . $owner->label();
			}
		}

		$findings[] = array(
			'id'       => 'seo_ownership',
			'severity' => self::SEVERITY_OK,
			'label'    => empty( $delegated )
				? __( 'mcLogiora provides all multilingual SEO output', 'mclogiora' )
				: __( 'Some SEO output is left to another plugin', 'mclogiora' ),
			'detail'   => empty( $delegated ) ? '' : implode( ', ', $delegated ),
		);

		$unknown = $this->compatibility->unrecognised_seo_plugins();

		if ( ! empty( $unknown ) ) {
			$findings[] = array(
				'id'       => 'unrecognised_seo_plugin',
				'severity' => self::SEVERITY_NOTICE,
				'label'    => __( 'Another SEO plugin may also output canonical metadata', 'mclogiora' ),
				'detail'   => sprintf(
					/* translators: %s: comma-separated list of plugin files. */
					__( 'mcLogiora has no adapter for these, so it has not changed what it outputs. Check the page source for duplicate canonical tags: %s', 'mclogiora' ),
					implode( ', ', $unknown )
				),
			);
		}

		return $findings;
	}

	/**
	 * Returns findings about schema installation.
	 *
	 * @return array<int,array{id:string,severity:string,label:string,detail:string}>
	 */
	private function installation_findings() {
		$failure = InstallationFailure::get();

		if ( null === $failure ) {
			return array();
		}

		return array(
			array(
				'id'       => 'installation_failed',
				'severity' => self::SEVERITY_WARNING,
				'label'    => __( 'Database setup did not finish', 'mclogiora' ),
				'detail'   => $failure['detail'],
			),
		);
	}
}
