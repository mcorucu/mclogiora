<?php
/**
 * Widget translation service.
 *
 * @package McLogiora
 */

namespace McLogiora\Widgets;

use McLogiora\Capabilities\CapabilityRegistry;
use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageServiceInterface;
use McLogiora\Languages\LanguageStatus;
use McLogiora\Relations\TranslationStatus;
use McLogiora\WordPress\ContentGatewayInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Saves and reads translated widget field values.
 *
 * Only fields an adapter declared are ever stored, and the source widget
 * instance is never written to. Applying a translation at render time is
 * Phase 12's job; this service only takes an explicitly named language.
 */
final class WidgetTranslationService {
	/**
	 * Repository.
	 *
	 * @var WidgetTranslationRepositoryInterface
	 */
	private $repository;

	/**
	 * Adapter registry.
	 *
	 * @var WidgetAdapterRegistry
	 */
	private $adapters;

	/**
	 * Language service.
	 *
	 * @var LanguageServiceInterface
	 */
	private $languages;

	/**
	 * Content gateway.
	 *
	 * @var ContentGatewayInterface
	 */
	private $gateway;

	/**
	 * Capability registry.
	 *
	 * @var CapabilityRegistry
	 */
	private $capabilities;

	/**
	 * Constructor.
	 *
	 * @param WidgetTranslationRepositoryInterface $repository Repository.
	 * @param WidgetAdapterRegistry                $adapters Adapter registry.
	 * @param LanguageServiceInterface             $languages Language service.
	 * @param ContentGatewayInterface              $gateway Content gateway.
	 * @param CapabilityRegistry                   $capabilities Capability registry.
	 */
	public function __construct(
		WidgetTranslationRepositoryInterface $repository,
		WidgetAdapterRegistry $adapters,
		LanguageServiceInterface $languages,
		ContentGatewayInterface $gateway,
		CapabilityRegistry $capabilities
	) {
		$this->repository   = $repository;
		$this->adapters     = $adapters;
		$this->languages    = $languages;
		$this->gateway      = $gateway;
		$this->capabilities = $capabilities;
	}

	/**
	 * Builds the stable key for a widget instance.
	 *
	 * @param string $widget_type Widget base identifier.
	 * @param string $instance_id Widget instance identifier.
	 * @return string
	 */
	public function widget_key( $widget_type, $instance_id ) {
		return sanitize_key( (string) $widget_type ) . ':' . sanitize_key( (string) $instance_id );
	}

	/**
	 * Saves translated fields for a widget instance.
	 *
	 * @param string               $widget_type Widget base identifier.
	 * @param string               $instance_id Widget instance identifier.
	 * @param string               $language_code Language code.
	 * @param array<string,string> $fields Submitted field values.
	 * @return WidgetTranslation|\WP_Error
	 */
	public function save( $widget_type, $instance_id, $language_code, array $fields ) {
		if ( ! $this->gateway->current_user_can( $this->capabilities->resolve( CapabilityRegistry::MANAGE_TRANSLATIONS ) ) ) {
			return new \WP_Error( 'mclogiora_cannot_manage_translations', __( 'You are not allowed to manage translations.', 'mclogiora' ) );
		}

		if ( ! $this->gateway->current_user_can( 'edit_theme_options' ) ) {
			return new \WP_Error( 'mclogiora_cannot_edit_widgets', __( 'You are not allowed to edit widgets.', 'mclogiora' ) );
		}

		$adapter = $this->adapters->for_type( $widget_type );

		if ( null === $adapter ) {
			return new \WP_Error(
				'mclogiora_widget_not_supported',
				__( 'This widget type has no translation adapter, so mcLogiora will not modify it.', 'mclogiora' )
			);
		}

		$language = $this->languages->get_language_by_code( (string) $language_code );

		if ( ! $language instanceof Language ) {
			return new \WP_Error( 'mclogiora_unknown_target_language', __( 'The selected target language does not exist.', 'mclogiora' ) );
		}

		if ( LanguageStatus::ACTIVE !== $language->status() ) {
			return new \WP_Error( 'mclogiora_inactive_target_language', __( 'The selected target language is not active.', 'mclogiora' ) );
		}

		$allowed  = array_keys( $adapter->translatable_fields() );
		$filtered = array();

		foreach ( $allowed as $field ) {
			$filtered[ $field ] = isset( $fields[ $field ] ) ? (string) $fields[ $field ] : '';
		}

		$key         = $this->widget_key( $widget_type, $instance_id );
		$translation = new WidgetTranslation( $key, $adapter->id(), $language->code(), $filtered, TranslationStatus::TRANSLATED );

		if ( $translation->is_empty() ) {
			$deleted = $this->repository->delete( $key, $language->code() );

			return is_wp_error( $deleted ) ? $deleted : $translation;
		}

		return $this->repository->save( $translation );
	}

	/**
	 * Returns a widget instance with translations applied for a language.
	 *
	 * Returns the instance unchanged when the widget type is unsupported or
	 * has no translation, so an unknown widget is never mutated.
	 *
	 * @param string              $widget_type Widget base identifier.
	 * @param string              $instance_id Widget instance identifier.
	 * @param string              $language_code Language code.
	 * @param array<string,mixed> $instance Widget instance options.
	 * @return array<string,mixed>
	 */
	public function apply_for_language( $widget_type, $instance_id, $language_code, array $instance ) {
		$adapter = $this->adapters->for_type( $widget_type );

		if ( null === $adapter ) {
			return $instance;
		}

		$translation = $this->repository->find(
			$this->widget_key( $widget_type, $instance_id ),
			(string) $language_code
		);

		if ( ! $translation instanceof WidgetTranslation ) {
			return $instance;
		}

		return $adapter->apply( $instance, $translation->fields() );
	}

	/**
	 * Returns the adapter registry.
	 *
	 * @return WidgetAdapterRegistry
	 */
	public function adapters() {
		return $this->adapters;
	}

	/**
	 * Returns stored translations for a widget instance.
	 *
	 * @param string $widget_type Widget base identifier.
	 * @param string $instance_id Widget instance identifier.
	 * @return WidgetTranslation[]
	 */
	public function all_for_widget( $widget_type, $instance_id ) {
		return $this->repository->all_for_widget( $this->widget_key( $widget_type, $instance_id ) );
	}
}
