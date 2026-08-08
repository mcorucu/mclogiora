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
use McLogiora\Relations\ContentType;
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

		/*
		 * The WordPress test suite wraps each test in a transaction and rolls
		 * it back afterwards. Table creation is DDL and survives that, but the
		 * stored schema version does not, so the recorded version and the real
		 * schema can disagree between tests. Clearing the option makes the
		 * runner authoritative every time, which is what a fresh install does.
		 */
		delete_option( 'mclogiora_db_version' );

		$this->container->get( MigrationRunner::class )->run();

		global $wpdb;

		$this->assertSame(
			'',
			(string) $wpdb->last_error,
			'The migration reported a database error.'
		);

		$this->assertTrue(
			$this->container->get( MigrationRunner::class )->is_current(),
			sprintf(
				'Migrations did not complete. Stored version: "%s". dbDelta result: %s',
				(string) get_option( 'mclogiora_db_version', '(unset)' ),
				wp_json_encode(
					$this->container->get( SchemaBuilder::class )->apply(
						array( 'CREATE TABLE ' . $this->container->get( TableNames::class )->languages() . ' ( id bigint(20) unsigned NOT NULL AUTO_INCREMENT, PRIMARY KEY  (id) );' )
					)
				)
			)
		);

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
}
