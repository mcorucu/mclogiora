<?php
/**
 * Editor-facing translation view model.
 *
 * @package McLogiora
 */

namespace McLogiora\Editors;

use McLogiora\Admin\TranslationActionController;
use McLogiora\Capabilities\CapabilityRegistry;
use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageServiceInterface;
use McLogiora\Relations\ContentType;
use McLogiora\Relations\TranslationGroup;
use McLogiora\Relations\TranslationItem;
use McLogiora\Relations\TranslationRelationServiceInterface;
use McLogiora\Relations\TranslationStatus;
use McLogiora\Routing\TranslatedUrlGenerator;

defined( 'ABSPATH' ) || exit;

/**
 * Answers, once, everything an editor UI needs to know about one object.
 *
 * The Block Editor, the Classic metabox and the Elementor panel all ask the
 * same questions: what language is this, what is it translated from, which
 * languages exist, which are missing, and what may this user do about it. Left
 * to themselves each surface would derive those answers separately and drift
 * apart -- one showing a language the other hides, one offering an action the
 * other forbids.
 *
 * So this is the only thing that reads relations on their behalf. The editors
 * render; they do not decide.
 *
 * Capability is resolved here too, rather than per surface, because "can this
 * person create a translation" must not have three answers.
 */
final class EditorTranslationModel {
	/**
	 * Language service.
	 *
	 * @var LanguageServiceInterface
	 */
	private $languages;

	/**
	 * Relation service.
	 *
	 * @var TranslationRelationServiceInterface
	 */
	private $relations;

	/**
	 * URL generator.
	 *
	 * @var TranslatedUrlGenerator
	 */
	private $urls;

	/**
	 * Status presenter.
	 *
	 * @var TranslationStatusPresenter
	 */
	private $presenter;

	/**
	 * Capability registry.
	 *
	 * @var CapabilityRegistry
	 */
	private $capabilities;

	/**
	 * Constructor.
	 *
	 * @param LanguageServiceInterface            $languages Language service.
	 * @param TranslationRelationServiceInterface $relations Relation service.
	 * @param TranslatedUrlGenerator              $urls URL generator.
	 * @param TranslationStatusPresenter          $presenter Status presenter.
	 * @param CapabilityRegistry                  $capabilities Capability registry.
	 */
	public function __construct(
		LanguageServiceInterface $languages,
		TranslationRelationServiceInterface $relations,
		TranslatedUrlGenerator $urls,
		TranslationStatusPresenter $presenter,
		CapabilityRegistry $capabilities
	) {
		$this->languages    = $languages;
		$this->relations    = $relations;
		$this->urls         = $urls;
		$this->presenter    = $presenter;
		$this->capabilities = $capabilities;
	}

	/**
	 * Builds the translation model for a post.
	 *
	 * Returns null when there is nothing meaningful to show: no post, no
	 * default language configured, or no active languages. A panel that
	 * renders an empty shell is worse than a panel that stays away.
	 *
	 * @param int $post_id Post identifier.
	 * @return array<string,mixed>|null
	 */
	public function for_post( $post_id ) {
		$post_id = (int) $post_id;
		$post    = $post_id > 0 ? get_post( $post_id ) : null;

		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		$default = $this->languages->get_default_language();
		$active  = $this->languages->get_active_languages();

		if ( ! $default instanceof Language || empty( $active ) ) {
			return null;
		}

		$group     = $this->relations->get_translation_set_for_object( ContentType::POST, (string) $post_id );
		$own       = $this->item_for_object( $group, $post_id );
		$source    = $this->source_item( $group );
		$is_source = $own instanceof TranslationItem ? $own->is_original() : true;

		/*
		 * An object with no relation is not yet part of any group, so it is
		 * the source of a group that does not exist yet. Its language is the
		 * site default -- never guessed from the request.
		 */
		$current_code = $own instanceof TranslationItem ? $own->language_code() : $default->code();
		$source_code  = $source instanceof TranslationItem ? $source->language_code() : $current_code;
		$source_id    = $source instanceof TranslationItem ? (int) $source->object_id() : $post_id;

		$may_manage = current_user_can( $this->capabilities->resolve( CapabilityRegistry::MANAGE_TRANSLATIONS ) );

		return array(
			'objectId'        => $post_id,
			'objectType'      => ContentType::POST,
			'postType'        => (string) $post->post_type,
			'isSource'        => $is_source,
			'groupKey'        => $group instanceof TranslationGroup ? (string) $group->group_key() : '',
			'currentLanguage' => $this->language_payload( $current_code ),
			'sourceLanguage'  => $this->language_payload( $source_code ),
			'sourceObjectId'  => $source_id,
			'sourceEditUrl'   => $source_id !== $post_id ? $this->edit_url( $source_id ) : '',
			'sourceViewUrl'   => $source_id !== $post_id ? $this->view_url( $source_id ) : '',
			'canManage'       => $may_manage,
			'createAction'    => $may_manage ? $this->create_action( $source_id ) : null,
			'languages'       => $this->language_rows( $group, $active, $current_code, $source_code, $source_id, $may_manage ),
		);
	}

	/**
	 * Builds one row per active language.
	 *
	 * @param TranslationGroup|null $group Translation group.
	 * @param Language[]            $active Active languages.
	 * @param string                $current_code Language of the edited object.
	 * @param string                $source_code Language of the source object.
	 * @param int                   $source_id Source object identifier.
	 * @param bool                  $may_manage Whether the user may act.
	 * @return array<int,array<string,mixed>>
	 */
	private function language_rows( $group, array $active, $current_code, $source_code, $source_id, $may_manage ) {
		$rows = array();

		foreach ( $active as $language ) {
			if ( ! $language instanceof Language ) {
				continue;
			}

			$code = $language->code();
			$item = $this->item_for_language( $group, $code );

			$status = $this->status_for( $item, $code, $source_code );
			$target = $item instanceof TranslationItem ? (int) $item->object_id() : 0;

			$rows[] = array(
				'code'            => $code,
				'name'            => $language->native_name(),
				'englishName'     => $language->english_name(),
				'locale'          => $language->locale(),
				'direction'       => $language->direction(),
				'isCurrent'       => $code === $current_code,
				'isSource'        => $code === $source_code,
				'objectId'        => $target,
				'status'          => $this->presenter->present( $status ),
				'accessibleLabel' => $this->presenter->accessible_label( $language->native_name(), $status ),
				'editUrl'         => $target > 0 ? $this->edit_url( $target ) : '',
				'viewUrl'         => $target > 0 ? $this->view_url( $target ) : '',
				'isMissing'       => TranslationStatus::MISSING === $status,
				'needsUpdate'     => TranslationStatus::NEEDS_UPDATE === $status,
				'sourceChange'    => TranslationStatus::NEEDS_UPDATE === $status ? $this->source_change( $item ) : null,

				/*
				 * A create action is offered only where one would actually
				 * succeed. Rendering a button the workflow will refuse is a
				 * promise the UI cannot keep.
				 */
				'canCreate'       => $may_manage && TranslationStatus::MISSING === $status && $code !== $source_code,
			);
		}

		return $rows;
	}

	/**
	 * Resolves the status to show for one language.
	 *
	 * @param TranslationItem|null $item Relation item, when one exists.
	 * @param string               $code Language code.
	 * @param string               $source_code Source language code.
	 * @return string
	 */
	private function status_for( $item, $code, $source_code ) {
		if ( $item instanceof TranslationItem ) {
			return $item->status();
		}

		return $code === $source_code ? TranslationStatus::ORIGINAL : TranslationStatus::MISSING;
	}

	/**
	 * Returns what is known about a source change, or null.
	 *
	 * Only what the relation already records. No diff is computed and no
	 * content is read: the honest answer to "what changed" is a Phase 16
	 * question, and inventing a partial one here would be worse than saying
	 * plainly that the source moved.
	 *
	 * @param TranslationItem|null $item Relation item.
	 * @return array<string,mixed>|null
	 */
	private function source_change( $item ) {
		if ( ! $item instanceof TranslationItem ) {
			return null;
		}

		$source_modified      = (int) $item->source_modified();
		$translation_modified = (int) $item->translation_modified();

		return array(
			'message'             => __( 'Source content changed after this translation was last updated.', 'mclogiora' ),
			'sourceModified'      => $source_modified > 0 ? $this->format_timestamp( $source_modified ) : '',
			'translationModified' => $translation_modified > 0 ? $this->format_timestamp( $translation_modified ) : '',
		);
	}

	/**
	 * Formats a timestamp in the site's own settings.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string
	 */
	private function format_timestamp( $timestamp ) {
		return (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	/**
	 * Returns the relation item for an object, when the group holds one.
	 *
	 * @param TranslationGroup|null $group Translation group.
	 * @param int                   $object_id Object identifier.
	 * @return TranslationItem|null
	 */
	private function item_for_object( $group, $object_id ) {
		if ( ! $group instanceof TranslationGroup ) {
			return null;
		}

		foreach ( $group->items() as $item ) {
			if ( (int) $item->object_id() === (int) $object_id ) {
				return $item;
			}
		}

		return null;
	}

	/**
	 * Returns the relation item for a language, when the group holds one.
	 *
	 * @param TranslationGroup|null $group Translation group.
	 * @param string                $code Language code.
	 * @return TranslationItem|null
	 */
	private function item_for_language( $group, $code ) {
		if ( ! $group instanceof TranslationGroup ) {
			return null;
		}

		foreach ( $group->items() as $item ) {
			if ( $item->language_code() === (string) $code ) {
				return $item;
			}
		}

		return null;
	}

	/**
	 * Returns the group's original item.
	 *
	 * @param TranslationGroup|null $group Translation group.
	 * @return TranslationItem|null
	 */
	private function source_item( $group ) {
		if ( ! $group instanceof TranslationGroup ) {
			return null;
		}

		$original = $group->original();

		return $original instanceof TranslationItem ? $original : null;
	}

	/**
	 * Returns the language payload for a code.
	 *
	 * @param string $code Language code.
	 * @return array<string,string>
	 */
	private function language_payload( $code ) {
		$language = $this->languages->get_language_by_code( $code );

		if ( ! $language instanceof Language ) {
			return array(
				'code'      => (string) $code,
				'name'      => (string) $code,
				'locale'    => '',
				'direction' => 'ltr',
			);
		}

		return array(
			'code'      => $language->code(),
			'name'      => $language->native_name(),
			'locale'    => $language->locale(),
			'direction' => $language->direction(),
		);
	}

	/**
	 * Describes the server-side create-translation action.
	 *
	 * The editor never creates content itself. It posts to the same
	 * `admin-post` endpoint the Translation Manager uses, so capability,
	 * nonce, validation and rollback all stay in one place and JavaScript
	 * holds no authority it could be tricked out of.
	 *
	 * @param int $source_id Source object identifier.
	 * @return array<string,string>
	 */
	private function create_action( $source_id ) {
		return array(
			'url'        => admin_url( 'admin-post.php' ),
			'action'     => 'mclogiora_create_translation',
			'nonceField' => TranslationActionController::NONCE_NAME,
			'nonce'      => wp_create_nonce( TranslationActionController::NONCE_ACTION ),
			'sourceId'   => (string) $source_id,
		);
	}

	/**
	 * Returns the WordPress edit URL for a post.
	 *
	 * @param int $post_id Post identifier.
	 * @return string
	 */
	private function edit_url( $post_id ) {
		$url = get_edit_post_link( (int) $post_id, 'raw' );

		return is_string( $url ) ? $url : '';
	}

	/**
	 * Returns the front-end URL for a post, when it has one.
	 *
	 * Only published content gets a view link. Offering to view a draft sends
	 * the reader to a 404 or a preview they did not ask for.
	 *
	 * Built through `TranslatedUrlGenerator` rather than `get_permalink()`.
	 * The permalink filters stay inert in the admin by design -- rewriting
	 * links there would show editors URLs that do not match what they are
	 * editing -- so `get_permalink()` would hand back a translation's URL
	 * without its language prefix, and the View link would open the wrong
	 * language or a 404. The generator answers for the object's own language
	 * whatever context it is asked from.
	 *
	 * @param int $post_id Post identifier.
	 * @return string
	 */
	private function view_url( $post_id ) {
		if ( 'publish' !== get_post_status( (int) $post_id ) ) {
			return '';
		}

		$url = $this->urls->own_post_url( (int) $post_id );

		return is_string( $url ) ? $url : '';
	}
}
