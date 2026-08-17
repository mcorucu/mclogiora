<?php
/**
 * WordPress integration tests for the translation workflows.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Integration;

use McLogiora\Core\Application;
use McLogiora\Database\MigrationRunner;
use McLogiora\Database\SchemaBuilder;
use McLogiora\Database\TableNames;
use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageRepositoryInterface;
use McLogiora\Languages\LanguageStatus;
use McLogiora\Media\MediaTranslationService;
use McLogiora\Menus\MenuTranslationWorkflow;
use McLogiora\Editors\Payload\PayloadAdapterRegistry;
use McLogiora\Editors\Payload\TranslationPayloadAdapterInterface;
use McLogiora\Languages\LanguageServiceInterface;
use McLogiora\Relations\ContentType;
use McLogiora\Relations\TranslationRelationRepositoryInterface;
use McLogiora\Relations\TranslationRelationServiceInterface;
use McLogiora\Relations\TranslationStatus;
use McLogiora\Workflows\ContentTranslationWorkflow;
use McLogiora\Workflows\TranslationWorkflowValidator;
use McLogiora\WordPress\ContentGatewayInterface;
use McLogiora\Widgets\WidgetTranslationService;
use McLogiora\Workflows\TranslationWorkflowService;
use WP_UnitTestCase;

/**
 * Exercises the behaviour that doubles cannot prove.
 *
 * These tests run against real WordPress APIs and a real database, so they
 * cover what unit tests deliberately stub: that wp_insert_post and
 * wp_insert_term actually accept what the workflows send them, that slug
 * collisions behave as assumed, that menu hierarchy survives round-tripping
 * through the menu API, and that the migration produces usable tables.
 */
final class TranslationWorkflowIntegrationTest extends WP_UnitTestCase {
	/**
	 * Service container.
	 *
	 * @var \McLogiora\Core\Container
	 */
	private $container;

	/**
	 * Sets up the plugin services and languages.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->container = Application::instance( dirname( __DIR__, 2 ) . '/mclogiora.php' )->container();

		delete_option( 'mclogiora_db_version' );

		$migrated = $this->container->get( MigrationRunner::class )->run();

		$this->assertTrue( $migrated, is_wp_error( $migrated ) ? $migrated->get_error_message() : '' );

		$languages = $this->container->get( LanguageRepositoryInterface::class );

		if ( ! $languages->find_by_code( 'en' ) instanceof Language ) {
			$languages->create( new Language( 'en', 'en_US', 'English', 'English', 'ltr', LanguageStatus::ACTIVE, 0, false ) );
			$languages->set_default( 'en' );
		}

		if ( ! $languages->find_by_code( 'tr' ) instanceof Language ) {
			$languages->create( new Language( 'tr', 'tr_TR', 'Turkce', 'Turkish', 'ltr', LanguageStatus::ACTIVE, 1, false ) );
		}
	}

	/**
	 * Asserts the migration created every managed table.
	 *
	 * @return void
	 */
	public function test_migration_creates_all_managed_tables() {
		$schema = $this->container->get( SchemaBuilder::class );
		$tables = $this->container->get( TableNames::class );

		foreach ( $tables->all() as $table ) {
			$this->assertTrue( $schema->table_exists( $table ), "Missing table: {$table}" );
		}
	}

	/**
	 * Asserts running the migration twice is safe.
	 *
	 * @return void
	 */
	public function test_migration_is_idempotent() {
		$this->container->get( MigrationRunner::class )->run();
		$this->container->get( MigrationRunner::class )->run();

		$schema = $this->container->get( SchemaBuilder::class );

		$this->assertTrue( $schema->table_exists( $this->container->get( TableNames::class )->strings() ) );
	}

	/**
	 * Asserts a real translation draft is created by wp_insert_post().
	 *
	 * @return void
	 */
	public function test_creates_a_real_translation_draft() {
		$source_id = self::factory()->post->create(
			array(
				'post_type'    => 'post',
				'post_title'   => 'Hello world',
				'post_content' => 'Body text',
				'post_status'  => 'publish',
			)
		);

		$result = $this->container->get( TranslationWorkflowService::class )->content()->create_translation( $source_id, 'tr' );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		$created = get_post( $result['post_id'] );

		$this->assertInstanceOf( \WP_Post::class, $created );
		$this->assertSame( 'draft', $created->post_status );
		$this->assertSame( 'post', $created->post_type );
		$this->assertSame( 'Hello world', $created->post_title );
		$this->assertNotSame( $source_id, $created->ID );
	}

	/**
	 * Asserts a real translated term is created by wp_insert_term().
	 *
	 * @return void
	 */
	public function test_creates_a_real_translated_term() {
		$source = self::factory()->term->create_and_get( array( 'taxonomy' => 'category', 'name' => 'News' ) );

		$result = $this->container->get( TranslationWorkflowService::class )
			->taxonomy()
			->create_translation( $source->term_id, 'category', 'tr', 'Haberler' );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		$term = get_term( $result['term_id'], 'category' );

		$this->assertInstanceOf( \WP_Term::class, $term );
		$this->assertSame( 'Haberler', $term->name );
	}

	/**
	 * Asserts the provisional slug avoids collision with the source term.
	 *
	 * @return void
	 */
	public function test_provisional_slug_avoids_collision_with_source() {
		$source = self::factory()->term->create_and_get( array( 'taxonomy' => 'category', 'name' => 'Sports' ) );

		$result = $this->container->get( TranslationWorkflowService::class )
			->taxonomy()
			->create_translation( $source->term_id, 'category', 'tr', 'Sports' );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		$term = get_term( $result['term_id'], 'category' );

		$this->assertNotSame( $source->slug, $term->slug, 'An identical name must not collide with the source slug.' );
		$this->assertStringContainsString( 'tr', $term->slug );
	}

	/**
	 * Asserts attachment metadata translations persist and read back.
	 *
	 * @return void
	 */
	public function test_attachment_metadata_persists() {
		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => 'sunset.jpg',
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Sunset',
				'post_excerpt'   => 'A caption',
				'post_content'   => 'A description',
			)
		);

		$service = $this->container->get( MediaTranslationService::class );

		$saved = $service->save(
			$attachment_id,
			'tr',
			array(
				'title'    => 'Gun batimi',
				'alt_text' => 'Deniz uzerinde gun batimi',
			)
		);

		$this->assertNotWPError( $saved );

		$metadata = $service->metadata_for_language( $attachment_id, 'tr' );

		$this->assertSame( 'Gun batimi', $metadata['title'] );
		$this->assertSame( 'A caption', $metadata['caption'], 'Untranslated fields fall back to the attachment.' );

		$attachment = get_post( $attachment_id );

		$this->assertSame( 'Sunset', $attachment->post_title, 'The attachment itself must be unchanged.' );
	}

	/**
	 * Asserts a translated post references the source featured attachment.
	 *
	 * @return void
	 */
	public function test_featured_image_is_referenced_not_duplicated() {
		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => 'hero.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);

		$source_id = self::factory()->post->create( array( 'post_type' => 'post', 'post_status' => 'publish' ) );
		set_post_thumbnail( $source_id, $attachment_id );

		$before = count( get_posts( array( 'post_type' => 'attachment', 'posts_per_page' => -1, 'fields' => 'ids' ) ) );

		$result = $this->container->get( TranslationWorkflowService::class )->content()->create_translation( $source_id, 'tr' );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		$after = count( get_posts( array( 'post_type' => 'attachment', 'posts_per_page' => -1, 'fields' => 'ids' ) ) );

		$this->assertSame( $before, $after, 'Creating a translation must not duplicate the media library.' );

		$resolved = $this->container->get( MediaTranslationService::class )->resolve_featured_attachment(
			(int) get_post_thumbnail_id( $result['post_id'] ),
			$attachment_id
		);

		$this->assertSame( $attachment_id, $resolved );
	}

	/**
	 * Asserts a translated menu is created with real WordPress menu APIs.
	 *
	 * @return void
	 */
	public function test_creates_a_real_translated_menu() {
		$menu_id = wp_create_nav_menu( 'Primary ' . uniqid( '', false ) );

		$this->assertIsInt( $menu_id );

		wp_update_nav_menu_item(
			$menu_id,
			0,
			array(
				'menu-item-title'  => 'Home',
				'menu-item-url'    => home_url( '/' ),
				'menu-item-status' => 'publish',
			)
		);

		$result = $this->container->get( MenuTranslationWorkflow::class )->create_translation( $menu_id, 'tr', 'Ana Menu' );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertNotSame( $menu_id, $result['menu_id'] );

		$items = wp_get_nav_menu_items( $result['menu_id'] );

		$this->assertCount( 1, $items );
		$this->assertSame( 'Home', $items[0]->title );
	}

	/**
	 * Asserts nested menu items are rebuilt against the new item ids.
	 *
	 * @return void
	 */
	public function test_nested_menu_items_are_remapped() {
		$menu_id = wp_create_nav_menu( 'Nested ' . uniqid( '', false ) );

		$parent_item = wp_update_nav_menu_item(
			$menu_id,
			0,
			array( 'menu-item-title' => 'Parent', 'menu-item-url' => home_url( '/p' ), 'menu-item-status' => 'publish' )
		);

		$child_item = wp_update_nav_menu_item(
			$menu_id,
			0,
			array(
				'menu-item-title'     => 'Child',
				'menu-item-url'       => home_url( '/c' ),
				'menu-item-status'    => 'publish',
				'menu-item-parent-id' => $parent_item,
			)
		);

		$this->assertIsInt( $child_item );

		$result = $this->container->get( MenuTranslationWorkflow::class )->create_translation( $menu_id, 'tr' );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		$items    = wp_get_nav_menu_items( $result['menu_id'] );
		$by_title = array();

		foreach ( $items as $item ) {
			$by_title[ $item->title ] = $item;
		}

		$this->assertArrayHasKey( 'Parent', $by_title );
		$this->assertArrayHasKey( 'Child', $by_title );
		$this->assertSame(
			(int) $by_title['Parent']->db_id,
			(int) $by_title['Child']->menu_item_parent,
			'The translated child must point at the translated parent.'
		);
		$this->assertNotSame(
			$parent_item,
			(int) $by_title['Child']->menu_item_parent,
			'Translated items must never reference source menu-item ids.'
		);
	}

	/**
	 * Asserts widget translations persist without touching the widget option.
	 *
	 * @return void
	 */
	public function test_widget_translation_persists_without_touching_the_source() {
		update_option(
			'widget_text',
			array(
				2 => array( 'title' => 'Hello', 'text' => 'Body' ),
			)
		);

		$service = $this->container->get( WidgetTranslationService::class );

		$saved = $service->save( 'text', '2', 'tr', array( 'title' => 'Merhaba', 'text' => 'Govde' ) );

		$this->assertNotWPError( $saved );

		$option = get_option( 'widget_text' );

		$this->assertSame( 'Hello', $option[2]['title'], 'The source widget option must never be rewritten.' );

		$applied = $service->apply_for_language( 'text', '2', 'tr', $option[2] );

		$this->assertSame( 'Merhaba', $applied['title'] );
	}

	/**
	 * Asserts relation records survive a real database round trip.
	 *
	 * @return void
	 */
	public function test_relations_persist_in_the_database() {
		$source_id = self::factory()->post->create( array( 'post_type' => 'post', 'post_status' => 'publish' ) );

		$result = $this->container->get( TranslationWorkflowService::class )->content()->create_translation( $source_id, 'tr' );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		$group = $this->container->get( \McLogiora\Relations\TranslationRelationServiceInterface::class )
			->get_translation_set_for_object( ContentType::POST, (string) $source_id );

		$this->assertNotNull( $group );
		$this->assertCount( 2, $group->items(), 'The group should hold the source and its translation.' );
	}

	/**
	 * Asserts detaching refreshes a group read in the same request.
	 *
	 * @return void
	 */
	public function test_detaching_refreshes_a_cached_group_in_the_same_request() {
		$workflow = $this->container->get( TranslationWorkflowService::class )->content();
		$languages = $this->container->get( LanguageRepositoryInterface::class );

		if ( ! $languages->find_by_code( 'de' ) instanceof Language ) {
			$languages->create( new Language( 'de', 'de_DE', 'Deutsch', 'German', 'ltr', LanguageStatus::ACTIVE, 2, false ) );
		}

		$source = self::factory()->post->create( array( 'post_type' => 'post', 'post_status' => 'publish' ) );
		$first  = $workflow->create_translation( $source, 'tr' );
		$second = self::factory()->post->create( array( 'post_type' => 'post', 'post_status' => 'publish' ) );
		$link   = $workflow->link_existing( $source, $second, 'de' );

		$this->assertIsArray( $first, is_wp_error( $first ) ? $first->get_error_message() : '' );
		$this->assertIsArray( $link, is_wp_error( $link ) ? $link->get_error_message() : '' );

		$repository = $this->container->get( TranslationRelationRepositoryInterface::class );
		$group_key  = $first['group_key'];
		$cached     = $repository->find_group( $group_key );

		$this->assertInstanceOf( \McLogiora\Relations\TranslationGroup::class, $cached );
		$this->assertCount( 3, $cached->items() );
		$this->assertTrue( $repository->detach_item( ContentType::POST, (string) $first['post_id'], 'tr' ) );

		$refreshed = $repository->find_group( $group_key );
		$ids       = array_map(
			static function ( $item ) {
				return $item->object_id();
			},
			$refreshed->items()
		);

		$this->assertNotContains( (string) $first['post_id'], $ids );
		$this->assertContains( (string) $source, $ids );
		$this->assertContains( (string) $second, $ids );
		$this->assertNull( $repository->find_item( ContentType::POST, (string) $first['post_id'], 'tr' ) );

		$this->assertNotWPError( $workflow->link_existing( $source, $first['post_id'], 'tr' ) );
		$reattached = $repository->find_group( $group_key );
		$reattached_ids = array_map(
			static function ( $item ) {
				return $item->object_id();
			},
			$reattached->items()
		);

		$this->assertContains( (string) $first['post_id'], $reattached_ids );
	}

	/**
	 * Asserts unlinking frees the language slot for a different object.
	 *
	 * Unlink used to park the item in `disabled` and leave the row in place.
	 * The slot check matches on language alone and cannot see a status, so an
	 * unlinked language could never be filled again without editing the
	 * database by hand. ADR-0010 says unlink removes the relation record; this
	 * asserts it now does.
	 *
	 * @return void
	 */
	public function test_unlink_frees_the_language_slot_for_another_object() {
		$workflow = $this->container->get( TranslationWorkflowService::class )->content();

		$source_id = self::factory()->post->create( array( 'post_type' => 'post', 'post_status' => 'publish' ) );
		$created   = $workflow->create_translation( $source_id, 'tr' );

		$this->assertIsArray( $created, is_wp_error( $created ) ? $created->get_error_message() : '' );

		$this->assertTrue( $workflow->unlink( (int) $created['post_id'], 'tr' ) );

		$replacement = self::factory()->post->create( array( 'post_type' => 'post', 'post_status' => 'publish' ) );

		$this->assertNotWPError(
			$workflow->link_existing( $source_id, $replacement, 'tr' ),
			'The Turkish slot must be free once its translation is unlinked.'
		);
	}

	/**
	 * Asserts a fresh translation can be created after an unlink.
	 *
	 * @return void
	 */
	public function test_unlink_allows_creating_a_new_translation() {
		$workflow = $this->container->get( TranslationWorkflowService::class )->content();

		$source_id = self::factory()->post->create( array( 'post_type' => 'post', 'post_status' => 'publish' ) );
		$created   = $workflow->create_translation( $source_id, 'tr' );

		$this->assertIsArray( $created, is_wp_error( $created ) ? $created->get_error_message() : '' );
		$this->assertTrue( $workflow->unlink( (int) $created['post_id'], 'tr' ) );

		$this->assertIsArray(
			$workflow->create_translation( $source_id, 'tr' ),
			'A new Turkish translation must be creatable once the old one is unlinked.'
		);
	}

	/**
	 * Asserts unlinking keeps the WordPress content untouched.
	 *
	 * @return void
	 */
	public function test_unlink_preserves_the_content_and_its_meta() {
		$workflow = $this->container->get( TranslationWorkflowService::class )->content();

		$source_id = self::factory()->post->create( array( 'post_type' => 'post', 'post_status' => 'publish' ) );
		$created   = $workflow->create_translation( $source_id, 'tr' );

		$this->assertIsArray( $created, is_wp_error( $created ) ? $created->get_error_message() : '' );

		$target_id = (int) $created['post_id'];

		update_post_meta( $target_id, 'mclogiora_test_keepsake', 'intact' );

		$this->assertTrue( $workflow->unlink( $target_id, 'tr' ) );

		$this->assertInstanceOf( \WP_Post::class, get_post( $target_id ), 'Unlink must never delete the content.' );
		$this->assertInstanceOf( \WP_Post::class, get_post( $source_id ), 'Unlink must never delete the source.' );
		$this->assertSame( 'intact', get_post_meta( $target_id, 'mclogiora_test_keepsake', true ) );
	}

	/**
	 * Asserts the source item cannot be unlinked while translations remain.
	 *
	 * @return void
	 */
	public function test_source_item_cannot_be_unlinked() {
		$workflow = $this->container->get( TranslationWorkflowService::class )->content();

		$source_id = self::factory()->post->create( array( 'post_type' => 'post', 'post_status' => 'publish' ) );

		$this->assertIsArray( $workflow->create_translation( $source_id, 'tr' ) );

		$this->assertWPError( $workflow->unlink( $source_id, 'en' ), 'Unlinking the source would orphan its translations.' );
		$this->assertInstanceOf( \WP_Post::class, get_post( $source_id ) );
	}

	/**
	 * Asserts an unlinked item leaves no row behind in its group.
	 *
	 * @return void
	 */
	public function test_unlink_removes_the_relation_row() {
		$workflow = $this->container->get( TranslationWorkflowService::class )->content();

		$source_id = self::factory()->post->create( array( 'post_type' => 'post', 'post_status' => 'publish' ) );
		$created   = $workflow->create_translation( $source_id, 'tr' );

		$this->assertIsArray( $created, is_wp_error( $created ) ? $created->get_error_message() : '' );
		$this->assertTrue( $workflow->unlink( (int) $created['post_id'], 'tr' ) );

		$group = $this->container->get( \McLogiora\Relations\TranslationRelationServiceInterface::class )
			->get_translation_set_for_object( ContentType::POST, (string) $source_id );

		$this->assertNotNull( $group );
		$this->assertCount( 1, $group->items(), 'Only the source should remain in the group.' );
	}

	/**
	 * Asserts a failure after the draft exists leaves no orphan behind.
	 *
	 * `create_translation()` inserts a real post and only then writes the
	 * relation and lets builder adapters prepare the payload. Both of those can
	 * fail, and the workflow compensates by deleting the post it just made. The
	 * compensation has been in the code since Phase 14 with a comment
	 * explaining itself and no test proving it, which is the state in which a
	 * guarantee quietly stops being true.
	 *
	 * The failure is injected through `mclogiora_register_payload_adapters`,
	 * the plugin's own supported extension point, so nothing is stubbed and
	 * nothing in production is altered to make the test possible. A site whose
	 * builder adapter fails is exactly the situation being modelled.
	 *
	 * @return void
	 */
	public function test_a_failed_payload_step_leaves_no_orphan_draft() {
		global $wpdb;

		$source = self::factory()->post->create(
			array(
				'post_title'  => 'Rollback source',
				'post_status' => 'publish',
			)
		);

		$failing = new class() implements TranslationPayloadAdapterInterface {
			/**
			 * {@inheritDoc}
			 *
			 * @return string
			 */
			public function id() {
				return 'mclogiora_failing_payload';
			}

			/**
			 * {@inheritDoc}
			 *
			 * @return bool
			 */
			public function is_available() {
				return true;
			}

			/**
			 * {@inheritDoc}
			 *
			 * @param int $source_id Source post identifier.
			 * @return bool
			 */
			public function applies_to( $source_id ) {
				unset( $source_id );

				return true;
			}

			/**
			 * {@inheritDoc}
			 *
			 * @param int $source_id Source post identifier.
			 * @param int $target_id Newly created translation identifier.
			 * @return true|\WP_Error
			 */
			public function copy( $source_id, $target_id ) {
				unset( $source_id, $target_id );

				return new \WP_Error( 'mclogiora_test_payload_failed', 'Injected builder failure.' );
			}
		};

		$callback = static function ( $adapters ) use ( $failing ) {
			$adapters[] = $failing;

			return $adapters;
		};

		add_filter( 'mclogiora_register_payload_adapters', $callback );

		$workflow = new ContentTranslationWorkflow(
			$this->container->get( ContentGatewayInterface::class ),
			$this->container->get( TranslationRelationServiceInterface::class ),
			$this->container->get( LanguageServiceInterface::class ),
			$this->container->get( TranslationWorkflowValidator::class ),
			PayloadAdapterRegistry::with_core_adapters()
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- counting rows is the assertion.
		$posts_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" );

		$result = $workflow->create_translation( $source, 'tr' );

		remove_filter( 'mclogiora_register_payload_adapters', $callback );

		$this->assertWPError( $result, 'A failing payload adapter must fail the whole operation.' );
		$this->assertSame( 'mclogiora_test_payload_failed', $result->get_error_code() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- counting rows is the assertion.
		$posts_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" );

		$this->assertSame( $posts_before, $posts_after, 'The draft created before the failure must be removed.' );
		$this->assertSame( 'publish', get_post_status( $source ), 'The source must be untouched.' );

		$relations = $this->container->get( TranslationRelationServiceInterface::class );
		$group     = $relations->get_translation_set_for_object( ContentType::POST, (string) $source );

		/*
		 * The group itself survives, holding only its source. That is the same
		 * state a group reaches when its last translation is unlinked, and it
		 * is created before the post is inserted because the slot-free check
		 * needs it. What must not survive is a record pointing at a post that
		 * no longer exists, so every remaining item is resolved back to a real
		 * object rather than merely counted.
		 */
		$this->assertNotNull( $group );

		$languages = array();

		foreach ( $group->items() as $item ) {
			$languages[] = $item->language_code();

			$this->assertInstanceOf(
				'WP_Post',
				get_post( (int) $item->object_id() ),
				'No relation item may outlive the object it points at.'
			);
		}

		$this->assertSame( array( 'en' ), $languages, 'The target language slot must be free again.' );
	}

	/**
	 * Asserts a failed relation write removes the term it just created.
	 *
	 * The unit suite already proves the workflow *calls* delete_term on this
	 * path, against a fake gateway. What it cannot show is that WordPress then
	 * actually removes the term, which is the part a site cares about.
	 *
	 * The failure is injected through `created_term`, a core hook that fires
	 * inside wp_insert_term -- after the term exists, before the relation is
	 * written. Occupying the target language slot at that instant is the real
	 * race the compensation was written for: the slot was free when the
	 * workflow checked, and taken by the time it wrote.
	 *
	 * @return void
	 */
	public function test_a_failed_relation_write_removes_the_created_term() {
		$workflows = $this->container->get( TranslationWorkflowService::class );
		$relations = $this->container->get( TranslationRelationServiceInterface::class );

		$languages = $this->container->get( LanguageRepositoryInterface::class );

		if ( ! $languages->find_by_code( 'de' ) instanceof Language ) {
			$languages->create( new Language( 'de', 'de_DE', 'Deutsch', 'German', 'ltr', LanguageStatus::ACTIVE, 2, false ) );
		}

		$source = self::factory()->term->create( array( 'taxonomy' => 'category', 'name' => 'Rollback source' ) );
		$spare  = self::factory()->term->create( array( 'taxonomy' => 'category', 'name' => 'Squatter' ) );

		/*
		 * The group is established with a first translation so the injected
		 * failure lands on the relation write rather than on group creation.
		 */
		$established = $workflows->taxonomy()->create_translation( $source, 'category', 'de', 'Nachrichten' );

		$this->assertIsArray( $established, is_wp_error( $established ) ? $established->get_error_message() : '' );

		$group_key = $established['group_key'];
		$before    = $this->term_count();

		$squat = static function () use ( &$squat, $relations, $group_key, $spare ) {
			remove_action( 'created_term', $squat, 10 );

			$relations->attach_existing_object_as_translation(
				$group_key,
				ContentType::TERM,
				(string) $spare,
				'tr',
				TranslationStatus::DRAFT
			);
		};

		add_action( 'created_term', $squat, 10 );

		$result = $workflows->taxonomy()->create_translation( $source, 'category', 'tr', 'Haberler' );

		remove_action( 'created_term', $squat, 10 );

		$this->assertWPError( $result, 'A relation write that loses the race must fail the whole operation.' );

		$this->assertSame( $before, $this->term_count(), 'The term created before the failure must be removed.' );
		$this->assertFalse( get_term_by( 'name', 'Haberler', 'category' ), 'No orphan term may survive.' );

		$source_term = get_term( $source, 'category' );

		$this->assertInstanceOf( 'WP_Term', $source_term, 'The source term must survive.' );
		$this->assertSame( 'Rollback source', $source_term->name );
		$this->assertInstanceOf( 'WP_Term', get_term( $spare, 'category' ), 'Unrelated terms must survive.' );

		$group = $relations->get_translation_set_for_object( ContentType::TERM, (string) $source );

		$this->assertNotNull( $group );

		foreach ( $group->items() as $item ) {
			$this->assertInstanceOf(
				'WP_Term',
				get_term( (int) $item->object_id(), 'category' ),
				'No relation item may point at a term that no longer exists.'
			);
		}
	}

	/**
	 * Returns the number of terms.
	 *
	 * @return int
	 */
	private function term_count() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- counting rows is the assertion.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->terms}" );
	}
}
