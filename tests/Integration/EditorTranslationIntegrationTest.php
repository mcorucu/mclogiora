<?php
/**
 * Editor translation UX integration tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Integration;

use McLogiora\Core\Application;
use McLogiora\Database\Installer;
use McLogiora\Editors\BlockEditorPanel;
use McLogiora\Editors\ClassicEditorMetabox;
use McLogiora\Editors\EditorTranslationModel;
use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageRepositoryInterface;
use McLogiora\Languages\LanguageStatus;
use McLogiora\Relations\TranslationStatus;
use McLogiora\Routing\LanguageContextInterface;
use McLogiora\Routing\RoutingSettings;
use McLogiora\Workflows\TranslationWorkflowService;
use WP_UnitTestCase;

/**
 * Covers the editor translation model against real WordPress objects.
 *
 * The model is what every editor surface renders, so its correctness is the
 * correctness of all of them. Testing it directly is worth more than driving
 * three sets of markup, and it does not depend on editor DOM internals that
 * change with every WordPress release.
 */
final class EditorTranslationIntegrationTest extends WP_UnitTestCase {
	/**
	 * Service container.
	 *
	 * @var \McLogiora\Core\Container
	 */
	private $container;

	/**
	 * Sets up schema, languages and an administrator.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->container = Application::instance( dirname( __DIR__, 2 ) . '/mclogiora.php' )->container();

		delete_option( 'mclogiora_db_version' );
		$this->container->get( Installer::class )->install();

		$languages = $this->container->get( LanguageRepositoryInterface::class );

		if ( ! $languages->find_by_code( 'en' ) instanceof Language ) {
			$languages->create( new Language( 'en', 'en_US', 'English', 'English', 'ltr', LanguageStatus::ACTIVE, 0, false ) );
			$languages->set_default( 'en' );
		}

		if ( ! $languages->find_by_code( 'tr' ) instanceof Language ) {
			$languages->create( new Language( 'tr', 'tr_TR', 'Turkce', 'Turkish', 'ltr', LanguageStatus::ACTIVE, 1, false ) );
		}

		delete_option( RoutingSettings::OPTION_NAME );

		$context = $this->container->get( LanguageContextInterface::class );
		$context->reset();
		$context->set_requested_code( '' );
	}

	/**
	 * Returns the editor model service.
	 *
	 * @return EditorTranslationModel
	 */
	private function model() {
		return $this->container->get( EditorTranslationModel::class );
	}

	/**
	 * Creates a source post and its Turkish translation.
	 *
	 * @return array{source:int,target:int}
	 */
	private function translated_pair() {
		$source = self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_title'  => 'Editor source',
				'post_status' => 'publish',
			)
		);

		$created = $this->container->get( TranslationWorkflowService::class )
			->content()
			->create_translation( $source, 'tr' );

		$this->assertIsArray( $created, is_wp_error( $created ) ? $created->get_error_message() : '' );

		return array(
			'source' => (int) $source,
			'target' => (int) $created['post_id'],
		);
	}

	/**
	 * Returns the row for a language code.
	 *
	 * @param array<string,mixed> $model Editor model.
	 * @param string              $code Language code.
	 * @return array<string,mixed>
	 */
	private function row( array $model, $code ) {
		foreach ( $model['languages'] as $row ) {
			if ( $row['code'] === $code ) {
				return $row;
			}
		}

		$this->fail( "No row for language {$code}." );
	}

	/**
	 * Asserts an untranslated post reads as its own source.
	 *
	 * @return void
	 */
	public function test_untranslated_post_is_its_own_source() {
		$post  = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$model = $this->model()->for_post( $post );

		$this->assertIsArray( $model );
		$this->assertTrue( $model['isSource'] );
		$this->assertSame( 'en', $model['currentLanguage']['code'] );
		$this->assertSame( 'en', $model['sourceLanguage']['code'] );
		$this->assertSame( TranslationStatus::ORIGINAL, $this->row( $model, 'en' )['status']['status'] );
		$this->assertSame( TranslationStatus::MISSING, $this->row( $model, 'tr' )['status']['status'] );
		$this->assertTrue( $this->row( $model, 'tr' )['isMissing'] );
	}

	/**
	 * Asserts the model reports the object's own language, not the request's.
	 *
	 * Phase 13.1 established that an object's language belongs to the object.
	 * The editor must not undo that by reading the request.
	 *
	 * @return void
	 */
	public function test_translation_reports_its_own_language() {
		$pair = $this->translated_pair();

		$context = $this->container->get( LanguageContextInterface::class );
		$context->reset();
		$context->set_requested_code( 'en' );

		$model = $this->model()->for_post( $pair['target'] );

		$this->assertIsArray( $model );
		$this->assertFalse( $model['isSource'] );
		$this->assertSame( 'tr', $model['currentLanguage']['code'], 'A translation is Turkish regardless of the request.' );
		$this->assertSame( 'en', $model['sourceLanguage']['code'] );
		$this->assertSame( $pair['source'], $model['sourceObjectId'] );
	}

	/**
	 * Asserts an existing translation is offered edit rather than create.
	 *
	 * @return void
	 */
	public function test_existing_translation_offers_edit_not_create() {
		$pair  = $this->translated_pair();
		$model = $this->model()->for_post( $pair['source'] );
		$row   = $this->row( $model, 'tr' );

		$this->assertSame( $pair['target'], $row['objectId'] );
		$this->assertFalse( $row['canCreate'], 'An existing translation must not offer creation.' );
		$this->assertNotSame( '', $row['editUrl'] );
	}

	/**
	 * Asserts edit URLs come from WordPress rather than a hardcoded path.
	 *
	 * @return void
	 */
	public function test_edit_urls_use_the_wordpress_api() {
		$pair  = $this->translated_pair();
		$model = $this->model()->for_post( $pair['source'] );

		$this->assertSame(
			get_edit_post_link( $pair['target'], 'raw' ),
			$this->row( $model, 'tr' )['editUrl']
		);
	}

	/**
	 * Asserts a draft translation offers no front-end link.
	 *
	 * @return void
	 */
	public function test_draft_translation_has_no_view_url() {
		$pair = $this->translated_pair();

		$this->assertSame( 'draft', get_post_status( $pair['target'] ) );

		$model = $this->model()->for_post( $pair['source'] );

		$this->assertSame( '', $this->row( $model, 'tr' )['viewUrl'], 'A draft has nothing to view.' );
	}

	/**
	 * Asserts a source change surfaces in the editor model.
	 *
	 * @return void
	 */
	public function test_source_change_is_reported_as_needs_update() {
		$pair = $this->translated_pair();

		$workflows = $this->container->get( TranslationWorkflowService::class );
		$workflows->change_status( 'post', $pair['target'], 'tr', TranslationStatus::TRANSLATED );

		wp_update_post(
			array(
				'ID'           => $pair['source'],
				'post_content' => 'Changed after the translation was made.',
			)
		);

		$row = $this->row( $this->model()->for_post( $pair['source'] ), 'tr' );

		$this->assertSame( TranslationStatus::NEEDS_UPDATE, $row['status']['status'] );
		$this->assertTrue( $row['needsUpdate'] );
		$this->assertIsArray( $row['sourceChange'] );
		$this->assertStringContainsString( 'source content changed', strtolower( $row['sourceChange']['message'] ) );
	}

	/**
	 * Asserts a user without the capability is offered no actions.
	 *
	 * @return void
	 */
	public function test_user_without_capability_gets_no_actions() {
		$pair = $this->translated_pair();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$model = $this->model()->for_post( $pair['source'] );

		$this->assertIsArray( $model );
		$this->assertFalse( $model['canManage'] );
		$this->assertNull( $model['createAction'], 'No action payload without the capability.' );

		foreach ( $model['languages'] as $row ) {
			$this->assertFalse( $row['canCreate'], "Creation offered for {$row['code']} without capability." );
		}
	}

	/**
	 * Asserts the create action carries a nonce for the shared endpoint.
	 *
	 * @return void
	 */
	public function test_create_action_targets_the_shared_admin_post_endpoint() {
		$post   = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$model  = $this->model()->for_post( $post );
		$action = $model['createAction'];

		$this->assertIsArray( $action );
		$this->assertSame( admin_url( 'admin-post.php' ), $action['url'] );
		$this->assertSame( 'mclogiora_create_translation', $action['action'] );
		$this->assertSame( 'mclogiora_translation_nonce', $action['nonceField'] );
		$this->assertNotFalse( wp_verify_nonce( $action['nonce'], 'mclogiora_translation_action' ) );
	}

	/**
	 * Asserts a configured site always keeps a default language.
	 *
	 * The panel refuses to render without one, and this is why that state is
	 * unreachable once setup has run: the repository will not delete the
	 * default language. Asserted here so the panel's precondition is a fact
	 * about the data model rather than an assumption it makes.
	 *
	 * @return void
	 */
	public function test_default_language_cannot_be_removed_from_under_the_panel() {
		$languages = $this->container->get( LanguageRepositoryInterface::class );

		$this->assertWPError(
			$languages->delete( 'en' ),
			'Deleting the default language would leave the editor with nothing to describe.'
		);

		$post = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->assertIsArray( $this->model()->for_post( $post ) );
	}

	/**
	 * Asserts a missing post produces no model.
	 *
	 * @return void
	 */
	public function test_missing_post_produces_no_model() {
		$this->assertNull( $this->model()->for_post( 0 ) );
		$this->assertNull( $this->model()->for_post( 999999 ) );
	}

	/**
	 * Asserts editor assets never load on the front end.
	 *
	 * @return void
	 */
	public function test_editor_assets_are_not_registered_on_the_front_end() {
		$panel = new BlockEditorPanel();
		$panel->register( $this->container );

		$this->assertFalse(
			(bool) has_action( 'wp_enqueue_scripts', array( $panel, 'enqueue' ) ),
			'The editor panel must never enqueue on front-end requests.'
		);

		$this->assertNotFalse(
			has_action( 'enqueue_block_editor_assets', array( $panel, 'enqueue' ) ),
			'The panel should hook the editor asset action.'
		);
	}

	/**
	 * Asserts the Classic metabox registers its own hooks in the admin.
	 *
	 * @return void
	 */
	public function test_classic_metabox_registers_admin_hooks() {
		set_current_screen( 'edit-post' );

		$metabox = new ClassicEditorMetabox();
		$metabox->register( $this->container );

		$this->assertNotFalse( has_action( 'add_meta_boxes', array( $metabox, 'add_meta_box' ) ) );
		$this->assertNotFalse(
			has_action( 'admin_footer', array( $metabox, 'print_pending_forms' ) ),
			'Create forms must be printed outside the post form.'
		);

		set_current_screen( 'front' );
	}

	/**
	 * Asserts the status vocabulary matches the list-table column.
	 *
	 * Two screens describing one state differently is the confusion this
	 * phase set out to remove.
	 *
	 * @return void
	 */
	public function test_status_labels_match_the_list_table_vocabulary() {
		$pair   = $this->translated_pair();
		$model  = $this->model()->for_post( $pair['source'] );
		$labels = array();

		foreach ( $model['languages'] as $row ) {
			$labels[ $row['status']['status'] ] = $row['status']['label'];
		}

		$this->assertSame( 'Original', $labels[ TranslationStatus::ORIGINAL ] );
		$this->assertSame( 'Draft', $labels[ TranslationStatus::DRAFT ] );
	}
}
