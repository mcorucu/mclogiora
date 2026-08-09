<?php
/**
 * Navigation menu translation workflow.
 *
 * @package McLogiora
 */

namespace McLogiora\Menus;

use McLogiora\Capabilities\CapabilityRegistry;
use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageServiceInterface;
use McLogiora\Languages\LanguageStatus;
use McLogiora\Relations\ContentType;
use McLogiora\Relations\TranslationGroup;
use McLogiora\Relations\TranslationRelationServiceInterface;
use McLogiora\Relations\TranslationStatus;
use McLogiora\WordPress\ContentGatewayInterface;
use McLogiora\WordPress\MenuGatewayInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Creates a translated copy of a navigation menu.
 *
 * A WordPress menu is a term and its items are posts, so menus reuse the
 * existing relation model rather than needing a table of their own. The
 * translated menu is a real, separate WordPress menu with its own identity,
 * which keeps it editable through the normal Appearance screens.
 *
 * Theme location assignments are never written. Deciding which menu appears
 * in which location for a given language is rendering, and rendering is
 * Phase 12.
 */
final class MenuTranslationWorkflow {
	/**
	 * Menu item fields copied to a translated menu.
	 *
	 * This is a whitelist, not a copy of everything. Menu items are posts, so
	 * they can carry meta from any plugin that decided to attach some, and
	 * duplicating that blindly would clone third-party state mcLogiora knows
	 * nothing about. These are the core `_menu_item_*` fields WordPress itself
	 * needs in order to reproduce the structure.
	 *
	 * @var string[]
	 */
	private static $copied_fields = array(
		'menu-item-title',
		'menu-item-url',
		'menu-item-type',
		'menu-item-object',
		'menu-item-object-id',
		'menu-item-target',
		'menu-item-attr-title',
		'menu-item-description',
		'menu-item-classes',
		'menu-item-xfn',
		'menu-item-status',
		'menu-item-position',
	);

	/**
	 * Menu gateway.
	 *
	 * @var MenuGatewayInterface
	 */
	private $menus;

	/**
	 * Content gateway.
	 *
	 * @var ContentGatewayInterface
	 */
	private $gateway;

	/**
	 * Relation service.
	 *
	 * @var TranslationRelationServiceInterface
	 */
	private $relations;

	/**
	 * Language service.
	 *
	 * @var LanguageServiceInterface
	 */
	private $languages;

	/**
	 * Capability registry.
	 *
	 * @var CapabilityRegistry
	 */
	private $capabilities;

	/**
	 * Constructor.
	 *
	 * @param MenuGatewayInterface                $menus Menu gateway.
	 * @param ContentGatewayInterface             $gateway Content gateway.
	 * @param TranslationRelationServiceInterface $relations Relation service.
	 * @param LanguageServiceInterface            $languages Language service.
	 * @param CapabilityRegistry                  $capabilities Capability registry.
	 */
	public function __construct(
		MenuGatewayInterface $menus,
		ContentGatewayInterface $gateway,
		TranslationRelationServiceInterface $relations,
		LanguageServiceInterface $languages,
		CapabilityRegistry $capabilities
	) {
		$this->menus        = $menus;
		$this->gateway      = $gateway;
		$this->relations    = $relations;
		$this->languages    = $languages;
		$this->capabilities = $capabilities;
	}

	/**
	 * Returns the fields copied when duplicating a menu item.
	 *
	 * @return string[]
	 */
	public static function copied_fields() {
		return self::$copied_fields;
	}

	/**
	 * Creates a translated menu from a source menu.
	 *
	 * @param int    $source_menu_id Source menu term identifier.
	 * @param string $target_language_code Target language code.
	 * @param string $translated_name Name for the translated menu.
	 * @return array{menu_id:int,items:int,group_key:string}|\WP_Error
	 */
	public function create_translation( $source_menu_id, $target_language_code, $translated_name = '' ) {
		if ( ! $this->gateway->current_user_can( $this->capabilities->resolve( CapabilityRegistry::MANAGE_TRANSLATIONS ) ) ) {
			return new \WP_Error( 'mclogiora_cannot_manage_translations', __( 'You are not allowed to manage translations.', 'mclogiora' ) );
		}

		if ( ! $this->gateway->current_user_can( 'edit_theme_options' ) ) {
			return new \WP_Error( 'mclogiora_cannot_edit_menus', __( 'You are not allowed to edit menus.', 'mclogiora' ) );
		}

		$source = $this->menus->get_menu( (int) $source_menu_id );

		if ( null === $source ) {
			return new \WP_Error( 'mclogiora_menu_not_found', __( 'The source menu could not be found.', 'mclogiora' ) );
		}

		$language = $this->languages->get_language_by_code( (string) $target_language_code );

		if ( ! $language instanceof Language ) {
			return new \WP_Error( 'mclogiora_unknown_target_language', __( 'The selected target language does not exist.', 'mclogiora' ) );
		}

		if ( LanguageStatus::ACTIVE !== $language->status() ) {
			return new \WP_Error( 'mclogiora_inactive_target_language', __( 'The selected target language is not active.', 'mclogiora' ) );
		}

		$source_language = $this->resolve_source_language( (int) $source['term_id'] );

		if ( is_wp_error( $source_language ) ) {
			return $source_language;
		}

		if ( $source_language === $language->code() ) {
			return new \WP_Error( 'mclogiora_same_language', __( 'The target language must be different from the source language.', 'mclogiora' ) );
		}

		$group = $this->resolve_or_create_group( (int) $source['term_id'], $source_language );

		if ( is_wp_error( $group ) ) {
			return $group;
		}

		foreach ( $this->relations->get_translation_set_for_object( ContentType::TERM, (string) $source['term_id'] ) instanceof TranslationGroup
			? $this->relations->get_translation_set_for_object( ContentType::TERM, (string) $source['term_id'] )->items()
			: array() as $item ) {
			if ( $item->language_code() === $language->code() ) {
				return new \WP_Error( 'mclogiora_translation_exists', __( 'A translation already exists for this language.', 'mclogiora' ) );
			}
		}

		$name = trim( (string) $translated_name );

		if ( '' === $name ) {
			$name = $source['name'] . ' (' . strtoupper( $language->code() ) . ')';
		}

		$created_menu_id = $this->menus->create_menu( $name );

		if ( is_wp_error( $created_menu_id ) ) {
			return $created_menu_id;
		}

		$item_result = $this->copy_items( (int) $source['term_id'], (int) $created_menu_id, $language->code() );

		if ( is_wp_error( $item_result ) ) {
			// Compensating rollback for the menu this operation just created.
			$this->menus->delete_menu( (int) $created_menu_id );

			return $item_result;
		}

		$attached = $this->relations->attach_existing_object_as_translation(
			$group->group_key(),
			ContentType::TERM,
			(string) $created_menu_id,
			$language->code(),
			TranslationStatus::DRAFT
		);

		if ( is_wp_error( $attached ) ) {
			$this->menus->delete_menu( (int) $created_menu_id );

			return $attached;
		}

		return array(
			'menu_id'   => (int) $created_menu_id,
			'items'     => (int) $item_result,
			'group_key' => $group->group_key(),
		);
	}

	/**
	 * Copies menu items, preserving order and hierarchy.
	 *
	 * Items are created first with no parent, recording a map from source
	 * item id to new item id. Parents are then rebuilt from that map in a
	 * second pass, because a child can appear before its parent in menu order
	 * and the new parent id does not exist until the parent has been created.
	 * Skipping the second pass would leave translated items pointing at source
	 * item ids, silently linking the two menus together.
	 *
	 * @param int    $source_menu_id Source menu identifier.
	 * @param int    $target_menu_id Target menu identifier.
	 * @param string $language_code Target language code.
	 * @return int|\WP_Error
	 */
	private function copy_items( $source_menu_id, $target_menu_id, $language_code ) {
		$items = $this->menus->get_menu_items( $source_menu_id );
		$map   = array();
		$data  = array();

		foreach ( $items as $item ) {
			$item_data = $this->build_item_data( $item, $language_code );

			$new_id = $this->menus->add_menu_item( $target_menu_id, $item_data );

			if ( is_wp_error( $new_id ) ) {
				return $new_id;
			}

			$map[ (int) $item['db_id'] ]  = (int) $new_id;
			$data[ (int) $item['db_id'] ] = $item_data;
		}

		foreach ( $items as $item ) {
			$source_parent = (int) $item['menu_item_parent'];

			if ( $source_parent <= 0 || ! isset( $map[ $source_parent ] ) ) {
				continue;
			}

			$source_id = (int) $item['db_id'];

			/*
			 * The whole item is resent, not just the parent.
			 * wp_update_nav_menu_item() rebuilds an item from the arguments it
			 * receives, so updating with only the parent id blanks the title,
			 * URL, and every other field.
			 */
			$item_data                          = $data[ $source_id ];
			$item_data['menu-item-parent-id']   = $map[ $source_parent ];

			$result = $this->menus->update_menu_item( $target_menu_id, $map[ $source_id ], $item_data );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return count( $map );
	}

	/**
	 * Builds the whitelisted data for a copied menu item.
	 *
	 * @param array<string,mixed> $item Source menu item.
	 * @param string              $language_code Target language code.
	 * @return array<string,mixed>
	 */
	private function build_item_data( array $item, $language_code ) {
		$object_id = (int) $item['object_id'];
		$type      = (string) $item['type'];

		if ( 'post_type' === $type || 'taxonomy' === $type ) {
			$object_id = $this->resolve_linked_object( $type, $object_id, $language_code );
		}

		return array(
			'menu-item-title'       => (string) $item['title'],
			'menu-item-url'         => (string) $item['url'],
			'menu-item-type'        => $type,
			'menu-item-object'      => (string) $item['object'],
			'menu-item-object-id'   => $object_id,
			'menu-item-target'      => (string) $item['target'],
			'menu-item-attr-title'  => (string) $item['attr_title'],
			'menu-item-description' => (string) $item['description'],
			'menu-item-classes'     => (string) $item['classes'],
			'menu-item-xfn'         => (string) $item['xfn'],
			'menu-item-status'      => 'publish',
			'menu-item-position'    => (int) $item['menu_order'],
		);
	}

	/**
	 * Resolves the object a translated menu item should point at.
	 *
	 * When the linked post or term already has a translation in the target
	 * language, the copied item points at that translation. When it does not,
	 * the item keeps pointing at the source object. That fallback is
	 * deliberate: the alternative would be to invent a translation that does
	 * not exist, or to leave a broken link. Pointing at the source means the
	 * menu still works, and re-running the workflow after translating the
	 * target picks up the better link.
	 *
	 * @param string $type Menu item type.
	 * @param int    $object_id Source object identifier.
	 * @param string $language_code Target language code.
	 * @return int
	 */
	private function resolve_linked_object( $type, $object_id, $language_code ) {
		if ( $object_id <= 0 ) {
			return $object_id;
		}

		$relation_type = 'taxonomy' === $type ? ContentType::TERM : ContentType::POST;
		$group         = $this->relations->get_translation_set_for_object( $relation_type, (string) $object_id );

		if ( ! $group instanceof TranslationGroup ) {
			return $object_id;
		}

		foreach ( $group->items() as $item ) {
			if ( $item->language_code() === (string) $language_code ) {
				return (int) $item->object_id();
			}
		}

		return $object_id;
	}

	/**
	 * Returns the language of a menu already in a group.
	 *
	 * @param int $menu_id Menu identifier.
	 * @return string|\WP_Error
	 */
	private function resolve_source_language( $menu_id ) {
		$group = $this->relations->get_translation_set_for_object( ContentType::TERM, (string) $menu_id );

		if ( $group instanceof TranslationGroup ) {
			foreach ( $group->items() as $item ) {
				if ( $item->object_id() === (string) $menu_id ) {
					return $item->language_code();
				}
			}
		}

		$default = $this->languages->get_default_language();

		if ( ! $default instanceof Language ) {
			return new \WP_Error( 'mclogiora_no_default_language', __( 'Set a default language before creating translations.', 'mclogiora' ) );
		}

		return $default->code();
	}

	/**
	 * Returns the group for a source menu, creating it when necessary.
	 *
	 * @param int    $menu_id Menu identifier.
	 * @param string $source_language Source language code.
	 * @return TranslationGroup|\WP_Error
	 */
	private function resolve_or_create_group( $menu_id, $source_language ) {
		$existing = $this->relations->get_translation_set_for_object( ContentType::TERM, (string) $menu_id );

		if ( $existing instanceof TranslationGroup ) {
			return $existing;
		}

		return $this->relations->create_group_from_source_object(
			ContentType::TERM,
			(string) $menu_id,
			(string) $source_language
		);
	}
}
