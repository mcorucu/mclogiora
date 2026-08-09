<?php
/**
 * Applies Phase 11 translations to front-end output.
 *
 * @package McLogiora
 */

namespace McLogiora\Routing;

use McLogiora\Contracts\ModuleInterface;
use McLogiora\Core\Container;
use McLogiora\Media\MediaTranslationService;
use McLogiora\Relations\ContentType;
use McLogiora\Relations\TranslationGroup;
use McLogiora\Relations\TranslationRelationServiceInterface;
use McLogiora\Strings\StringTranslationService;
use McLogiora\Widgets\WidgetTranslationService;

defined( 'ABSPATH' ) || exit;

/**
 * Connects the Phase 11 translation stores to WordPress output.
 *
 * Every lookup here passes the language from the one LanguageContext. Phase 11
 * built these services to take an explicit language precisely so that this
 * module could be the only place the current language is injected.
 */
final class FrontendTranslationModule implements ModuleInterface {
	/**
	 * Language context.
	 *
	 * @var LanguageContextInterface|null
	 */
	private $context = null;

	/**
	 * Request guard.
	 *
	 * @var RequestContextGuard|null
	 */
	private $guard = null;

	/**
	 * String translation service.
	 *
	 * @var StringTranslationService|null
	 */
	private $strings = null;

	/**
	 * Media translation service.
	 *
	 * @var MediaTranslationService|null
	 */
	private $media = null;

	/**
	 * Widget translation service.
	 *
	 * @var WidgetTranslationService|null
	 */
	private $widgets = null;

	/**
	 * Relation service.
	 *
	 * @var TranslationRelationServiceInterface|null
	 */
	private $relations = null;

	/**
	 * Whether a gettext lookup is already in progress.
	 *
	 * @var bool
	 */
	private $translating = false;

	/**
	 * Registers front-end translation hooks.
	 *
	 * @param Container $container Service container.
	 * @return void
	 */
	public function register( Container $container ) {
		$this->context   = $container->get( LanguageContextInterface::class );
		$this->guard     = $container->get( RequestContextGuard::class );
		$this->strings   = $container->get( StringTranslationService::class );
		$this->media     = $container->get( MediaTranslationService::class );
		$this->widgets   = $container->get( WidgetTranslationService::class );
		$this->relations = $container->get( TranslationRelationServiceInterface::class );

		add_filter( 'gettext', array( $this, 'filter_gettext' ), 10, 3 );
		add_filter( 'gettext_with_context', array( $this, 'filter_gettext_with_context' ), 10, 4 );

		add_filter( 'get_post_metadata', array( $this, 'filter_attachment_alt' ), 10, 4 );
		add_filter( 'wp_get_attachment_caption', array( $this, 'filter_attachment_caption' ), 10, 2 );

		add_filter( 'widget_text', array( $this, 'filter_widget_text' ), 10, 3 );
		add_filter( 'widget_custom_html_content', array( $this, 'filter_widget_html' ), 10, 3 );

		add_filter( 'wp_nav_menu_args', array( $this, 'filter_nav_menu_args' ) );
	}

	/**
	 * Translates a string into the current language.
	 *
	 * Returns the original when no translation exists, which is what every
	 * gettext filter must do.
	 *
	 * @param string $translation Current translation.
	 * @param string $text Source text.
	 * @param string $domain Text domain.
	 * @return string
	 */
	public function filter_gettext( $translation, $text, $domain ) {
		return $this->translate( $translation, $text, $domain, '' );
	}

	/**
	 * Translates a string with a gettext context.
	 *
	 * @param string $translation Current translation.
	 * @param string $text Source text.
	 * @param string $context Gettext context.
	 * @param string $domain Text domain.
	 * @return string
	 */
	public function filter_gettext_with_context( $translation, $text, $context, $domain ) {
		return $this->translate( $translation, $text, $domain, $context );
	}

	/**
	 * Performs the string lookup with guards applied.
	 *
	 * @param string $translation Current translation.
	 * @param string $text Source text.
	 * @param string $domain Text domain.
	 * @param string $context Gettext context.
	 * @return string
	 */
	private function translate( $translation, $text, $domain, $context ) {
		if ( ! $this->applies() ) {
			return $translation;
		}

		/*
		 * The lookup path itself calls translated strings, for example in
		 * error messages, which would re-enter this filter. The flag makes the
		 * inner call fall straight through.
		 */
		if ( $this->translating ) {
			return $translation;
		}

		$this->translating = true;

		try {
			$result = $this->strings->translate( (string) $text, $this->context->current_code(), (string) $domain, (string) $context );
		} finally {
			$this->translating = false;
		}

		return '' === $result ? $translation : $result;
	}

	/**
	 * Returns translated alternative text for an attachment.
	 *
	 * @param mixed  $value Existing short-circuit value.
	 * @param int    $object_id Attachment identifier.
	 * @param string $meta_key Meta key.
	 * @param bool   $single Whether a single value was requested.
	 * @return mixed
	 */
	public function filter_attachment_alt( $value, $object_id, $meta_key, $single ) {
		if ( '_wp_attachment_image_alt' !== $meta_key || ! $this->applies() ) {
			return $value;
		}

		$metadata = $this->media->metadata_for_language( (int) $object_id, $this->context->current_code() );

		if ( '' === $metadata['alt_text'] ) {
			return $value;
		}

		return $single ? $metadata['alt_text'] : array( $metadata['alt_text'] );
	}

	/**
	 * Returns the translated caption for an attachment.
	 *
	 * @param string $caption Current caption.
	 * @param int    $post_id Attachment identifier.
	 * @return string
	 */
	public function filter_attachment_caption( $caption, $post_id ) {
		if ( ! $this->applies() ) {
			return $caption;
		}

		$metadata = $this->media->metadata_for_language( (int) $post_id, $this->context->current_code() );

		return '' === $metadata['caption'] ? $caption : $metadata['caption'];
	}

	/**
	 * Applies a widget text translation at render time.
	 *
	 * The stored widget option is never written to; only the value being
	 * rendered is swapped.
	 *
	 * @param string $text Widget text.
	 * @param array  $instance Widget instance.
	 * @param mixed  $widget Widget object.
	 * @return string
	 */
	public function filter_widget_text( $text, $instance = array(), $widget = null ) {
		return $this->apply_widget_field( 'text', 'text', $text, $instance, $widget );
	}

	/**
	 * Applies a Custom HTML widget translation at render time.
	 *
	 * @param string $content Widget content.
	 * @param array  $instance Widget instance.
	 * @param mixed  $widget Widget object.
	 * @return string
	 */
	public function filter_widget_html( $content, $instance = array(), $widget = null ) {
		return $this->apply_widget_field( 'custom_html', 'content', $content, $instance, $widget );
	}

	/**
	 * Applies one translated widget field.
	 *
	 * @param string $widget_type Widget base identifier.
	 * @param string $field Field key.
	 * @param string $value Current value.
	 * @param array  $instance Widget instance.
	 * @param mixed  $widget Widget object.
	 * @return string
	 */
	private function apply_widget_field( $widget_type, $field, $value, $instance, $widget ) {
		if ( ! $this->applies() || ! is_array( $instance ) ) {
			return $value;
		}

		$instance_id = is_object( $widget ) && isset( $widget->number ) ? (string) $widget->number : '';

		if ( '' === $instance_id ) {
			return $value;
		}

		$applied = $this->widgets->apply_for_language(
			$widget_type,
			$instance_id,
			$this->context->current_code(),
			$instance
		);

		return isset( $applied[ $field ] ) && '' !== $applied[ $field ] ? (string) $applied[ $field ] : $value;
	}

	/**
	 * Selects the translated menu for the current language.
	 *
	 * Menus fall back to the source menu when no translation exists, unlike
	 * content pages which 404. Navigation is a wayfinding aid: making it
	 * vanish strands the visitor, while an untranslated menu still works.
	 *
	 * @param array $args Menu arguments.
	 * @return array
	 */
	public function filter_nav_menu_args( $args ) {
		if ( ! $this->applies() || ! is_array( $args ) ) {
			return $args;
		}

		$menu_id = $this->resolve_menu_id( $args );

		if ( $menu_id <= 0 ) {
			return $args;
		}

		$group = $this->relations->get_translation_set_for_object( ContentType::TERM, (string) $menu_id );

		if ( ! $group instanceof TranslationGroup ) {
			return $args;
		}

		foreach ( $group->items() as $item ) {
			if ( $item->language_code() === $this->context->current_code() ) {
				$args['menu'] = (int) $item->object_id();

				return $args;
			}
		}

		return $args;
	}

	/**
	 * Returns the menu identifier the arguments resolve to.
	 *
	 * @param array $args Menu arguments.
	 * @return int
	 */
	private function resolve_menu_id( array $args ) {
		if ( ! empty( $args['menu'] ) && is_numeric( $args['menu'] ) ) {
			return (int) $args['menu'];
		}

		if ( empty( $args['theme_location'] ) ) {
			return 0;
		}

		$locations = get_nav_menu_locations();

		if ( ! is_array( $locations ) || empty( $locations[ $args['theme_location'] ] ) ) {
			return 0;
		}

		return (int) $locations[ $args['theme_location'] ];
	}

	/**
	 * Returns whether front-end translation applies right now.
	 *
	 * @return bool
	 */
	private function applies() {
		if ( ! $this->guard->applies() ) {
			return false;
		}

		return '' !== $this->context->current_code();
	}
}
