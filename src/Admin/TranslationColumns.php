<?php
/**
 * Translation status column for post list tables.
 *
 * @package McLogiora
 */

namespace McLogiora\Admin;

use McLogiora\Content\ContentTypeRegistryInterface;
use McLogiora\Contracts\ModuleInterface;
use McLogiora\Core\Container;
use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageServiceInterface;
use McLogiora\Relations\ContentType;
use McLogiora\Relations\TranslationGroup;
use McLogiora\Relations\TranslationItem;
use McLogiora\Relations\TranslationRelationServiceInterface;
use McLogiora\Relations\TranslationStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a compact language column to supported post list tables.
 *
 * This uses the standard WordPress column hooks only. It renders text and
 * links, adds no scripts, and does not alter the list table markup.
 */
final class TranslationColumns implements ModuleInterface {
	const COLUMN_KEY = 'mclogiora_languages';

	/**
	 * Language service.
	 *
	 * @var LanguageServiceInterface|null
	 */
	private $languages = null;

	/**
	 * Relation service.
	 *
	 * @var TranslationRelationServiceInterface|null
	 */
	private $relations = null;

	/**
	 * Content type registry.
	 *
	 * @var ContentTypeRegistryInterface|null
	 */
	private $content_types = null;

	/**
	 * Registers list table hooks.
	 *
	 * @param Container $container Service container.
	 * @return void
	 */
	public function register( Container $container ) {
		if ( ! is_admin() ) {
			return;
		}

		$this->languages     = $container->get( LanguageServiceInterface::class );
		$this->relations     = $container->get( TranslationRelationServiceInterface::class );
		$this->content_types = $container->get( ContentTypeRegistryInterface::class );

		add_action( 'admin_init', array( $this, 'register_columns' ) );
	}

	/**
	 * Registers the column for every translatable post type.
	 *
	 * @return void
	 */
	public function register_columns() {
		foreach ( $this->content_types->translatable() as $type ) {
			$post_type = $type->name();

			add_filter( "manage_{$post_type}_posts_columns", array( $this, 'add_column' ) );
			add_action( "manage_{$post_type}_posts_custom_column", array( $this, 'render_column' ), 10, 2 );
		}
	}

	/**
	 * Adds the language column.
	 *
	 * @param array<string,string> $columns Existing columns.
	 * @return array<string,string>
	 */
	public function add_column( $columns ) {
		if ( ! is_array( $columns ) ) {
			return $columns;
		}

		$columns[ self::COLUMN_KEY ] = __( 'Languages', 'mclogiora' );

		return $columns;
	}

	/**
	 * Renders the language column.
	 *
	 * @param string $column Column key.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	public function render_column( $column, $post_id ) {
		if ( self::COLUMN_KEY !== $column ) {
			return;
		}

		$languages = $this->languages->get_active_languages();

		if ( empty( $languages ) ) {
			echo '<span class="mclogiora-muted-line">' . esc_html__( 'No active languages', 'mclogiora' ) . '</span>';

			return;
		}

		$group = $this->relations->get_translation_set_for_object( ContentType::POST, (string) $post_id );
		$items = $group instanceof TranslationGroup ? $group->items() : array();

		echo '<ul class="mclogiora-language-column">';

		foreach ( $languages as $language ) {
			if ( ! $language instanceof Language ) {
				continue;
			}

			$this->render_language_row( $language, $items, (int) $post_id );
		}

		echo '</ul>';
	}

	/**
	 * Renders a single language row.
	 *
	 * @param Language          $language Language.
	 * @param TranslationItem[] $items Group items.
	 * @param int               $post_id Post ID.
	 * @return void
	 */
	private function render_language_row( Language $language, array $items, $post_id ) {
		$match = null;

		foreach ( $items as $item ) {
			if ( $item->language_code() === $language->code() ) {
				$match = $item;
				break;
			}
		}

		$code = strtoupper( $language->code() );

		echo '<li>';
		echo '<strong>' . esc_html( $code ) . '</strong> ';

		if ( null === $match ) {
			echo '<span class="mclogiora-muted-line">' . esc_html( $this->status_label( TranslationStatus::MISSING ) ) . '</span>';
			echo '</li>';

			return;
		}

		$label     = $this->status_label( $match->status() );
		$target_id = (int) $match->object_id();

		if ( $target_id === $post_id ) {
			echo '<span>' . esc_html( $label ) . '</span>';
			echo '</li>';

			return;
		}

		$edit_link = get_edit_post_link( $target_id );

		if ( is_string( $edit_link ) && '' !== $edit_link ) {
			printf(
				'<a href="%1$s">%2$s</a>',
				esc_url( $edit_link ),
				esc_html( $label )
			);
		} else {
			echo '<span>' . esc_html( $label ) . '</span>';
		}

		echo '</li>';
	}

	/**
	 * Returns a translated label for a status.
	 *
	 * @param string $status Status key.
	 * @return string
	 */
	private function status_label( $status ) {
		switch ( $status ) {
			case TranslationStatus::ORIGINAL:
				return __( 'Original', 'mclogiora' );
			case TranslationStatus::DRAFT:
				return __( 'Draft', 'mclogiora' );
			case TranslationStatus::TRANSLATED:
				return __( 'Translated', 'mclogiora' );
			case TranslationStatus::NEEDS_REVIEW:
				return __( 'Needs review', 'mclogiora' );
			case TranslationStatus::NEEDS_UPDATE:
				return __( 'Needs update', 'mclogiora' );
			case TranslationStatus::MACHINE_SUGGESTED:
				return __( 'Machine suggested', 'mclogiora' );
			case TranslationStatus::DISABLED:
				return __( 'Disabled', 'mclogiora' );
			default:
				return __( 'Missing', 'mclogiora' );
		}
	}
}
