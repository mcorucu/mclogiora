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
use McLogiora\Taxonomies\TaxonomyRegistryInterface;

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

	/** @var TaxonomyRegistryInterface|null */
	private $taxonomies = null;

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
		$this->taxonomies    = $container->get( TaxonomyRegistryInterface::class );

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

		foreach ( $this->taxonomies->translatable() as $taxonomy ) {
			$name = $taxonomy->name();
			add_filter( "manage_edit-{$name}_columns", array( $this, 'add_column' ) );
			add_filter( "manage_{$name}_custom_column", array( $this, 'render_term_column' ), 10, 3 );
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
			$default = $this->languages->get_default_language();
			if ( $default instanceof Language && $default->code() === $language->code() ) {
				echo '<span>' . esc_html( $this->status_label( TranslationStatus::ORIGINAL ) ) . '</span>';
				echo '</li>';
				return;
			}
			$title = get_the_title( $post_id );
			$this->render_missing_action( $post_id, $language, $title, false, '' );
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
	 * Renders the language column for a supported taxonomy list table.
	 *
	 * @param string $column Column key.
	 * @param int    $term_id Term ID.
	 * @param string $taxonomy Taxonomy name.
	 * @return void
	 */
	public function render_term_column( $column, $term_id, $taxonomy ) {
		if ( self::COLUMN_KEY !== $column ) {
			return $column;
		}
		ob_start();
		$term = get_term( (int) $term_id, $taxonomy );
		$languages = $this->languages->get_active_languages();
		$group = $this->relations->get_translation_set_for_object( ContentType::TERM, (string) $term_id );
		$items = $group instanceof TranslationGroup ? $group->items() : array();
		echo '<ul class="mclogiora-language-column">';
		foreach ( $languages as $language ) {
			if ( ! $language instanceof Language ) {
				continue;
			}
			$match = null;
			foreach ( $items as $item ) {
				if ( $item->language_code() === $language->code() ) {
					$match = $item;
					break;
				}
			}
			echo '<li><strong>' . esc_html( strtoupper( $language->code() ) ) . '</strong> ';
			if ( null === $match ) {
				$default = $this->languages->get_default_language();
				if ( $default instanceof Language && $default->code() === $language->code() ) {
					echo '<span>' . esc_html( $this->status_label( TranslationStatus::ORIGINAL ) ) . '</span>';
					echo '</li>';
					continue;
				}
				$this->render_missing_action( $term_id, $language, $term instanceof \WP_Term ? $term->name : '', true, $taxonomy );
			} else {
				$link = get_edit_term_link( (int) $match->object_id(), $taxonomy );
				printf( '<a href="%1$s">%2$s</a>', esc_url( $link ), esc_html( $this->status_label( $match->status() ) ) );
			}
			echo '</li>';
		}
		echo '</ul>';

		return (string) ob_get_clean();
	}

	/**
	 * Renders the explicit missing-language form.
	 *
	 * @param int      $source_id Source object ID.
	 * @param Language $language Target language.
	 * @param string   $title Source title.
	 * @param bool     $term Whether term workflow is used.
	 * @param string   $taxonomy Taxonomy name.
	 * @return void
	 */
	private function render_missing_action( $source_id, Language $language, $title, $term, $taxonomy ) {
		$action = $term ? 'mclogiora_create_term_translation' : 'mclogiora_create_translation';
		$label  = sprintf( __( 'Add %s translation for %s', 'mclogiora' ), strtoupper( $language->code() ), $title );
		printf( '<form method="post" action="%1$s" class="mclogiora-inline-form"><input type="hidden" name="action" value="%2$s"><input type="hidden" name="source_id" value="%3$d">', esc_url( admin_url( 'admin-post.php' ) ), esc_attr( $action ), (int) $source_id );
		if ( $term ) {
			printf( '<input type="hidden" name="taxonomy" value="%1$s"><input type="hidden" name="translated_name" value="%2$s"><input type="hidden" name="translated_description" value="">', esc_attr( $taxonomy ), esc_attr( $title ) );
		}
		printf( '<input type="hidden" name="target_language" value="%1$s">%2$s<button type="submit" class="button-link" aria-label="%3$s">+ %4$s</button></form>', esc_attr( $language->code() ), wp_nonce_field( TranslationActionController::NONCE_ACTION, TranslationActionController::NONCE_NAME, true, false ), esc_attr( $label ), esc_html( strtoupper( $language->code() ) ) );
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
