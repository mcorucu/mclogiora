<?php
/**
 * Source change hook integration.
 *
 * @package McLogiora
 */

namespace McLogiora\Workflows;

use McLogiora\Content\ContentTypeRegistryInterface;
use McLogiora\Contracts\ModuleInterface;
use McLogiora\Core\Container;
use McLogiora\Relations\ContentType;

defined( 'ABSPATH' ) || exit;

/**
 * Connects WordPress save events to the source change tracker.
 *
 * The guards here are the reason this is safe to run on every save: autosaves,
 * revisions, auto-drafts, and trashed posts are discarded before any relation
 * is read, and the tracker itself ignores objects that are not the source of
 * their group, which is what prevents a translation edit from cascading.
 */
final class SourceChangeSubscriber implements ModuleInterface {
	/**
	 * Source change tracker.
	 *
	 * @var SourceChangeTracker|null
	 */
	private $tracker = null;

	/**
	 * Content type registry.
	 *
	 * @var ContentTypeRegistryInterface|null
	 */
	private $content_types = null;

	/**
	 * Registers the save hook.
	 *
	 * @param Container $container Service container.
	 * @return void
	 */
	public function register( Container $container ) {
		$this->tracker       = $container->get( SourceChangeTracker::class );
		$this->content_types = $container->get( ContentTypeRegistryInterface::class );

		add_action( 'save_post', array( $this, 'handle_save_post' ), 20, 3 );
	}

	/**
	 * Marks translations as needing an update when a source post changes.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post Post object.
	 * @param bool     $update Whether this is an update.
	 * @return void
	 */
	public function handle_save_post( $post_id, $post, $update ) {
		unset( $update );

		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		if ( ! $this->content_types->is_translatable( (string) $post->post_type ) ) {
			return;
		}

		$context = array(
			'is_autosave'            => defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE,
			'is_revision'            => wp_is_post_revision( (int) $post_id ) !== false,
			'post_status'            => (string) $post->post_status,
			'modified_gmt_timestamp' => (int) strtotime( (string) $post->post_modified_gmt . ' UTC' ),
		);

		$this->tracker->handle_source_saved(
			ContentType::POST,
			(int) $post_id,
			array(
				'post_title'   => (string) $post->post_title,
				'post_content' => (string) $post->post_content,
				'post_excerpt' => (string) $post->post_excerpt,
			),
			$context
		);
	}
}
