<?php
/**
 * Classic Editor translation metabox.
 *
 * @package McLogiora
 */

namespace McLogiora\Editors;

use McLogiora\Contracts\ModuleInterface;
use McLogiora\Core\Container;
use McLogiora\Core\RuntimeReadiness;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the translation panel as an ordinary WordPress metabox.
 *
 * Feature parity with the Block Editor panel, not visual parity: the same
 * languages, the same statuses, the same actions, drawn the way the Classic
 * Editor draws things. Someone who learns one surface knows the other.
 *
 * The metabox is a plain form posting to `admin-post`, so it works with
 * JavaScript unavailable and shares one server-side authority with every other
 * translation action in the plugin.
 */
final class ClassicEditorMetabox implements ModuleInterface {
	const SCREEN_ID = 'mclogiora-translations';

	/**
	 * Translation model.
	 *
	 * @var EditorTranslationModel|null
	 */
	private $model = null;

	/**
	 * Runtime readiness.
	 *
	 * @var RuntimeReadiness|null
	 */
	private $readiness = null;

	/**
	 * Create-translation forms waiting to be printed outside the post form.
	 *
	 * @var array<int,array<string,string>>
	 */
	private $pending_forms = array();

	/**
	 * Registers the metabox.
	 *
	 * @param Container $container Service container.
	 * @return void
	 */
	public function register( Container $container ) {
		$this->readiness = $container->get( RuntimeReadiness::class );

		if ( $this->readiness->is_installing() || ! is_admin() ) {
			return;
		}

		$this->model = $container->get( EditorTranslationModel::class );

		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_footer', array( $this, 'print_pending_forms' ) );
	}

	/**
	 * Registers the metabox for post types that can be translated.
	 *
	 * Registered only when the Block Editor is not handling this post type, so
	 * the two surfaces never both appear.
	 *
	 * @param string   $post_type Post type name.
	 * @param \WP_Post $post Post being edited.
	 * @return void
	 */
	public function add_meta_box( $post_type, $post ) {
		if ( ! $post instanceof \WP_Post || $this->uses_block_editor( $post_type ) ) {
			return;
		}

		if ( null === $this->model || null === $this->model->for_post( (int) $post->ID ) ) {
			return;
		}

		add_meta_box(
			self::SCREEN_ID,
			__( 'mcLogiora Translations', 'mclogiora' ),
			array( $this, 'render' ),
			$post_type,
			'side',
			'default'
		);
	}

	/**
	 * Loads the shared panel styles on Classic editing screens only.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public function enqueue( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen instanceof \WP_Screen || $this->uses_block_editor( (string) $screen->post_type ) ) {
			return;
		}

		wp_enqueue_style(
			BlockEditorPanel::HANDLE,
			MCLOGIORA_URL . 'assets/css/editor-panel.css',
			array(),
			MCLOGIORA_VERSION
		);
	}

	/**
	 * Renders the metabox.
	 *
	 * @param \WP_Post $post Post being edited.
	 * @return void
	 */
	public function render( $post ) {
		$model = $post instanceof \WP_Post && null !== $this->model
			? $this->model->for_post( (int) $post->ID )
			: null;

		if ( null === $model ) {
			return;
		}

		echo '<div class="mclogiora-metabox mclogiora-editor">';

		$this->render_summary( $model );

		echo '<ul class="mclogiora-editor__list">';

		foreach ( $model['languages'] as $row ) {
			$this->render_row( $model, $row );
		}

		echo '</ul>';

		if ( empty( $model['canManage'] ) ) {
			echo '<p class="mclogiora-editor__meta">' . esc_html__( 'You do not have permission to change translations.', 'mclogiora' ) . '</p>';
		}

		echo '</div>';
	}

	/**
	 * Renders the language and source summary.
	 *
	 * @param array<string,mixed> $model Translation model.
	 * @return void
	 */
	private function render_summary( array $model ) {
		echo '<div class="mclogiora-editor__summary">';

		printf(
			'<p><strong>%1$s</strong> <span lang="%2$s" dir="%3$s">%4$s</span></p>',
			esc_html__( 'Language', 'mclogiora' ),
			esc_attr( $model['currentLanguage']['code'] ),
			esc_attr( $model['currentLanguage']['direction'] ),
			esc_html( $model['currentLanguage']['name'] )
		);

		printf(
			'<p><strong>%1$s</strong> <span lang="%2$s" dir="%3$s">%4$s</span>',
			esc_html__( 'Source', 'mclogiora' ),
			esc_attr( $model['sourceLanguage']['code'] ),
			esc_attr( $model['sourceLanguage']['direction'] ),
			esc_html( $model['sourceLanguage']['name'] )
		);

		if ( ! empty( $model['sourceEditUrl'] ) ) {
			printf(
				' <a class="mclogiora-editor__source-link" href="%1$s">%2$s</a>',
				esc_url( $model['sourceEditUrl'] ),
				esc_html__( 'Edit source', 'mclogiora' )
			);
		}

		echo '</p></div>';
	}

	/**
	 * Renders one language row.
	 *
	 * @param array<string,mixed> $model Translation model.
	 * @param array<string,mixed> $row Language row.
	 * @return void
	 */
	private function render_row( array $model, array $row ) {
		printf(
			'<li class="mclogiora-editor__row%1$s">',
			! empty( $row['isCurrent'] ) ? ' is-current' : ''
		);

		printf(
			'<div class="mclogiora-editor__row-head"><span class="mclogiora-editor__language" lang="%1$s" dir="%2$s">%3$s</span><span class="mclogiora-editor__status is-%4$s" title="%5$s">%6$s</span></div>',
			esc_attr( $row['code'] ),
			esc_attr( $row['direction'] ),
			esc_html( $row['name'] ),
			esc_attr( $row['status']['tone'] ),
			esc_attr( $row['status']['description'] ),
			esc_html( $row['status']['label'] )
		);

		printf(
			'<span class="screen-reader-text">%s</span>',
			esc_html( $row['accessibleLabel'] )
		);

		if ( ! empty( $row['needsUpdate'] ) && ! empty( $row['sourceChange'] ) ) {
			$this->render_source_change( $row['sourceChange'] );
		}

		$this->render_actions( $model, $row );

		echo '</li>';
	}

	/**
	 * Renders the outdated-translation notice.
	 *
	 * @param array<string,mixed> $change Source change payload.
	 * @return void
	 */
	private function render_source_change( array $change ) {
		echo '<div class="mclogiora-editor__notice">';

		printf( '<p>%s</p>', esc_html( $change['message'] ) );

		if ( ! empty( $change['sourceModified'] ) ) {
			printf(
				'<p class="mclogiora-editor__meta">%s</p>',
				esc_html( sprintf( /* translators: %s: date and time. */ __( 'Source updated: %s', 'mclogiora' ), $change['sourceModified'] ) )
			);
		}

		if ( ! empty( $change['translationModified'] ) ) {
			printf(
				'<p class="mclogiora-editor__meta">%s</p>',
				esc_html( sprintf( /* translators: %s: date and time. */ __( 'Translation updated: %s', 'mclogiora' ), $change['translationModified'] ) )
			);
		}

		echo '</div>';
	}

	/**
	 * Renders the actions available for one language.
	 *
	 * @param array<string,mixed> $model Translation model.
	 * @param array<string,mixed> $row Language row.
	 * @return void
	 */
	private function render_actions( array $model, array $row ) {
		$links = array();

		if ( ! empty( $row['isCurrent'] ) ) {
			if ( ! empty( $row['viewUrl'] ) ) {
				$links[] = sprintf( '<a href="%1$s">%2$s</a>', esc_url( $row['viewUrl'] ), esc_html__( 'View', 'mclogiora' ) );
			}
		} elseif ( ! empty( $row['objectId'] ) && ! empty( $row['editUrl'] ) ) {
			$links[] = sprintf( '<a href="%1$s">%2$s</a>', esc_url( $row['editUrl'] ), esc_html__( 'Edit translation', 'mclogiora' ) );

			if ( ! empty( $row['viewUrl'] ) ) {
				$links[] = sprintf( '<a href="%1$s">%2$s</a>', esc_url( $row['viewUrl'] ), esc_html__( 'View', 'mclogiora' ) );
			}
		}

		$create = ! empty( $row['canCreate'] ) && ! empty( $model['createAction'] );

		if ( empty( $links ) && ! $create ) {
			return;
		}

		echo '<div class="mclogiora-editor__actions">';

		echo wp_kses_post( implode( ' ', $links ) );

		if ( $create ) {
			$this->render_create_form( $model['createAction'], $row );
		}

		echo '</div>';
	}

	/**
	 * Renders the create-translation submit button.
	 *
	 * The Classic Editor wraps the whole screen in `<form id="post">`, and
	 * HTML does not allow a nested form: the parser silently discards it, and
	 * the button is left submitting the post form instead. So the button is
	 * printed here and its form is printed after the post form closes, tied
	 * together by the HTML `form` attribute.
	 *
	 * This keeps the action a POST to the same `admin-post` endpoint every
	 * other translation action uses. Turning it into a nonced GET link would
	 * have been less code and a mutation over GET.
	 *
	 * @param array<string,string> $action Action payload.
	 * @param array<string,mixed>  $row Language row.
	 * @return void
	 */
	private function render_create_form( array $action, array $row ) {
		$form_id = 'mclogiora-create-' . sanitize_key( $row['code'] );

		$this->pending_forms[] = array(
			'id'         => $form_id,
			'url'        => $action['url'],
			'action'     => $action['action'],
			'sourceId'   => $action['sourceId'],
			'language'   => (string) $row['code'],
			'nonceField' => $action['nonceField'],
			'nonce'      => $action['nonce'],
		);

		printf(
			'<button type="submit" form="%1$s" class="button button-secondary">%2$s</button>',
			esc_attr( $form_id ),
			esc_html__( 'Create translation', 'mclogiora' )
		);
	}

	/**
	 * Prints the create-translation forms outside the post form.
	 *
	 * @return void
	 */
	public function print_pending_forms() {
		if ( empty( $this->pending_forms ) ) {
			return;
		}

		foreach ( $this->pending_forms as $form ) {
			printf(
				'<form id="%1$s" method="post" action="%2$s" class="mclogiora-editor__hidden-form">',
				esc_attr( $form['id'] ),
				esc_url( $form['url'] )
			);

			printf( '<input type="hidden" name="action" value="%s" />', esc_attr( $form['action'] ) );
			printf( '<input type="hidden" name="source_id" value="%s" />', esc_attr( $form['sourceId'] ) );
			printf( '<input type="hidden" name="target_language" value="%s" />', esc_attr( $form['language'] ) );
			printf(
				'<input type="hidden" name="%1$s" value="%2$s" />',
				esc_attr( $form['nonceField'] ),
				esc_attr( $form['nonce'] )
			);

			echo '</form>';
		}

		$this->pending_forms = array();
	}

	/**
	 * Returns whether a post type is edited with the Block Editor.
	 *
	 * @param string $post_type Post type name.
	 * @return bool
	 */
	private function uses_block_editor( $post_type ) {
		return function_exists( 'use_block_editor_for_post_type' )
			&& use_block_editor_for_post_type( (string) $post_type );
	}
}
