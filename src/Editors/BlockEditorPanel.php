<?php
/**
 * Block Editor translation panel.
 *
 * @package McLogiora
 */

namespace McLogiora\Editors;

use McLogiora\Contracts\ModuleInterface;
use McLogiora\Core\Container;
use McLogiora\Core\RuntimeReadiness;

defined( 'ABSPATH' ) || exit;

/**
 * Puts the translation panel in the Block Editor's document sidebar.
 *
 * WordPress 7.1 renders the editing canvas in an iframe. Nothing here reaches
 * into it. The panel is registered through `@wordpress/plugins` and rendered
 * by `@wordpress/editor`, both of which live in stable editor chrome outside
 * the canvas, so there is no cross-frame DOM to break and no selector into
 * editor internals to go stale.
 *
 * The panel is read-mostly on purpose. It renders state and links, and its one
 * write action posts a form to the same `admin-post` endpoint the Translation
 * Manager uses. It never touches the block store, never marks the post dirty,
 * and holds no authority of its own.
 */
final class BlockEditorPanel implements ModuleInterface {
	const HANDLE = 'mclogiora-editor-panel';

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
	 * Suggestion state provider.
	 *
	 * @var SuggestionEditorState|null
	 */
	private $suggestions = null;

	/**
	 * Registers the editor assets.
	 *
	 * @param Container $container Service container.
	 * @return void
	 */
	public function register( Container $container ) {
		$this->readiness = $container->get( RuntimeReadiness::class );

		if ( $this->readiness->is_installing() ) {
			return;
		}

		$this->model       = $container->get( EditorTranslationModel::class );
		$this->suggestions = $container->get( SuggestionEditorState::class );

		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueues the panel on post editing screens only.
	 *
	 * `enqueue_block_editor_assets` also fires for the site editor and the
	 * widgets screen, neither of which edits a post that could have a
	 * translation. The screen is checked rather than assumed.
	 *
	 * @return void
	 */
	public function enqueue() {
		if ( ! $this->is_post_editor() ) {
			return;
		}

		$model = $this->model instanceof EditorTranslationModel
			? $this->model->for_post( $this->current_post_id() )
			: null;

		if ( null === $model ) {
			return;
		}

		$path = MCLOGIORA_PATH . 'assets/js/editor-panel.js';

		wp_enqueue_script(
			self::HANDLE,
			MCLOGIORA_URL . 'assets/js/editor-panel.js',
			array( 'wp-plugins', 'wp-editor', 'wp-element', 'wp-components', 'wp-i18n', 'wp-data' ),
			file_exists( $path ) ? (string) filemtime( $path ) : MCLOGIORA_VERSION,
			true
		);

		wp_set_script_translations( self::HANDLE, 'mclogiora', MCLOGIORA_PATH . 'languages' );

		if ( $this->suggestions instanceof SuggestionEditorState ) {
			$model['suggestions'] = $this->suggestions->for_post( $this->current_post_id() );
		}

		wp_add_inline_script(
			self::HANDLE,
			'window.mcLogioraEditor = ' . wp_json_encode( $model ) . ';',
			'before'
		);

		wp_enqueue_style(
			self::HANDLE,
			MCLOGIORA_URL . 'assets/css/editor-panel.css',
			array(),
			MCLOGIORA_VERSION
		);
	}

	/**
	 * Returns whether the current screen edits a single post.
	 *
	 * @return bool
	 */
	private function is_post_editor() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();

		return $screen instanceof \WP_Screen && 'post' === $screen->base;
	}

	/**
	 * Returns the post currently being edited.
	 *
	 * @return int
	 */
	private function current_post_id() {
		$post = get_post();

		return $post instanceof \WP_Post ? (int) $post->ID : 0;
	}
}
