<?php
/**
 * Menu translation workflow tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Unit;

use McLogiora\Capabilities\CapabilityRegistry;
use McLogiora\Menus\MenuTranslationWorkflow;
use McLogiora\Relations\ContentType;
use McLogiora\Relations\MetadataNeedsUpdateDetector;
use McLogiora\Relations\TranslationRelationService;
use McLogiora\Tests\Support\FakeContentGateway;
use McLogiora\Tests\Support\FakeMenuGateway;
use McLogiora\Tests\Support\FakeRelationRepository;
use McLogiora\Tests\Support\WorkflowTestFactory;
use PHPUnit\Framework\TestCase;

/**
 * Covers menu duplication, hierarchy remapping, and link resolution.
 */
final class MenuTranslationWorkflowTest extends TestCase {
	/**
	 * Menu gateway.
	 *
	 * @var FakeMenuGateway
	 */
	private $menus;

	/**
	 * Relation repository.
	 *
	 * @var FakeRelationRepository
	 */
	private $repository;

	/**
	 * Content gateway.
	 *
	 * @var FakeContentGateway
	 */
	private $gateway;

	/**
	 * Workflow under test.
	 *
	 * @var MenuTranslationWorkflow
	 */
	private $workflow;

	/**
	 * Sets up the test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$factory          = new WorkflowTestFactory();
		$this->gateway    = $factory->gateway;
		$this->repository = $factory->repository;
		$this->menus      = new FakeMenuGateway();

		$this->workflow = new MenuTranslationWorkflow(
			$this->menus,
			$this->gateway,
			new TranslationRelationService(
				$this->repository,
				new MetadataNeedsUpdateDetector(),
				$factory->languages
			),
			$factory->languages,
			new CapabilityRegistry()
		);

		$this->menus->add_menu( 5, 'Primary' );
	}

	/**
	 * Asserts a translated menu is created as a separate menu.
	 *
	 * @return void
	 */
	public function test_creates_a_separate_translated_menu() {
		$this->menus->add_item( 5, array( 'db_id' => 1, 'title' => 'Home', 'url' => '/' ) );

		$result = $this->workflow->create_translation( 5, 'tr', 'Ana Menu' );

		$this->assertIsArray( $result );
		$this->assertNotSame( 5, $result['menu_id'], 'The translation must be its own menu.' );
		$this->assertSame( 'Ana Menu', $this->menus->get_menu( $result['menu_id'] )['name'] );
		$this->assertSame( 1, $result['items'] );

		$item = $this->repository->find_item( ContentType::TERM, (string) $result['menu_id'], 'tr' );

		$this->assertNotNull( $item, 'The translated menu must be related to the source.' );
	}

	/**
	 * Asserts the source menu is left untouched.
	 *
	 * @return void
	 */
	public function test_source_menu_is_not_modified() {
		$this->menus->add_item( 5, array( 'db_id' => 1, 'title' => 'Home' ) );

		$this->workflow->create_translation( 5, 'tr' );

		$source_items = $this->menus->get_menu_items( 5 );

		$this->assertCount( 1, $source_items );
		$this->assertSame( 'Home', $source_items[0]['title'] );
		$this->assertSame( array(), $this->menus->deleted_menus );
	}

	/**
	 * Asserts nested hierarchy is rebuilt against the new item ids.
	 *
	 * @return void
	 */
	public function test_rebuilds_nested_hierarchy_with_new_item_ids() {
		$this->menus->add_item( 5, array( 'db_id' => 1, 'title' => 'Parent', 'menu_order' => 1 ) );
		$this->menus->add_item( 5, array( 'db_id' => 2, 'title' => 'Child', 'menu_item_parent' => 1, 'menu_order' => 2 ) );
		$this->menus->add_item( 5, array( 'db_id' => 3, 'title' => 'Grandchild', 'menu_item_parent' => 2, 'menu_order' => 3 ) );

		$result = $this->workflow->create_translation( 5, 'tr' );
		$items  = $this->menus->get_menu_items( $result['menu_id'] );

		$this->assertCount( 3, $items );

		$by_title = array();

		foreach ( $items as $item ) {
			$by_title[ $item['title'] ] = $item;
		}

		$source_ids = array( 1, 2, 3 );

		$this->assertSame(
			$by_title['Parent']['db_id'],
			$by_title['Child']['menu_item_parent'],
			'The child must point at the new parent id.'
		);
		$this->assertSame(
			$by_title['Child']['db_id'],
			$by_title['Grandchild']['menu_item_parent'],
			'Nesting must survive to the third level.'
		);
		$this->assertNotContains(
			$by_title['Child']['menu_item_parent'],
			$source_ids,
			'Translated items must never reference source menu-item ids.'
		);
		$this->assertSame( 0, $by_title['Parent']['menu_item_parent'] );
	}

	/**
	 * Asserts item order is preserved.
	 *
	 * @return void
	 */
	public function test_preserves_item_order() {
		$this->menus->add_item( 5, array( 'db_id' => 1, 'title' => 'First', 'menu_order' => 1 ) );
		$this->menus->add_item( 5, array( 'db_id' => 2, 'title' => 'Second', 'menu_order' => 2 ) );

		$result = $this->workflow->create_translation( 5, 'tr' );
		$items  = $this->menus->get_menu_items( $result['menu_id'] );

		$this->assertSame( 1, $items[0]['menu_order'] );
		$this->assertSame( 2, $items[1]['menu_order'] );
	}

	/**
	 * Asserts an object link is retargeted when a translation exists.
	 *
	 * @return void
	 */
	public function test_links_to_translated_object_when_available() {
		$this->gateway->add_post( 10, array( 'post_type' => 'post' ) );

		$created = ( new WorkflowTestFactory() )->content;
		unset( $created );

		$factory = new WorkflowTestFactory();
		$factory->gateway->add_post( 10, array( 'post_type' => 'post' ) );
		$translation = $factory->content->create_translation( 10, 'tr' );

		$menus = new FakeMenuGateway();
		$menus->add_menu( 5, 'Primary' );
		$menus->add_item( 5, array( 'db_id' => 1, 'title' => 'Post', 'type' => 'post_type', 'object' => 'post', 'object_id' => 10 ) );

		$workflow = new MenuTranslationWorkflow(
			$menus,
			$factory->gateway,
			new TranslationRelationService( $factory->repository, new MetadataNeedsUpdateDetector(), $factory->languages ),
			$factory->languages,
			new CapabilityRegistry()
		);

		$result = $workflow->create_translation( 5, 'tr' );
		$items  = $menus->get_menu_items( $result['menu_id'] );

		$this->assertSame(
			$translation['post_id'],
			$items[0]['object_id'],
			'A menu item should point at the translated post when one exists.'
		);
	}

	/**
	 * Asserts an untranslated object link falls back to the source.
	 *
	 * @return void
	 */
	public function test_falls_back_to_source_object_when_untranslated() {
		$this->gateway->add_post( 20, array( 'post_type' => 'post' ) );
		$this->menus->add_item( 5, array( 'db_id' => 1, 'title' => 'Post', 'type' => 'post_type', 'object' => 'post', 'object_id' => 20 ) );

		$result = $this->workflow->create_translation( 5, 'tr' );
		$items  = $this->menus->get_menu_items( $result['menu_id'] );

		$this->assertSame( 20, $items[0]['object_id'], 'An untranslated link must stay usable rather than being invented.' );
	}

	/**
	 * Asserts custom URLs are copied unchanged.
	 *
	 * @return void
	 */
	public function test_custom_urls_are_copied_unchanged() {
		$this->menus->add_item( 5, array( 'db_id' => 1, 'title' => 'External', 'type' => 'custom', 'url' => 'https://example.test/page' ) );

		$result = $this->workflow->create_translation( 5, 'tr' );
		$items  = $this->menus->get_menu_items( $result['menu_id'] );

		$this->assertSame( 'https://example.test/page', $items[0]['url'] );
	}

	/**
	 * Asserts a duplicate language is rejected.
	 *
	 * @return void
	 */
	public function test_rejects_duplicate_language() {
		$this->menus->add_item( 5, array( 'db_id' => 1, 'title' => 'Home' ) );
		$this->assertIsArray( $this->workflow->create_translation( 5, 'tr' ) );

		$second = $this->workflow->create_translation( 5, 'tr' );

		$this->assertInstanceOf( \WP_Error::class, $second );
		$this->assertSame( 'mclogiora_translation_exists', $second->get_error_code() );
	}

	/**
	 * Asserts an inactive language is rejected.
	 *
	 * @return void
	 */
	public function test_rejects_inactive_language() {
		$result = $this->workflow->create_translation( 5, 'de' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mclogiora_inactive_target_language', $result->get_error_code() );
	}

	/**
	 * Asserts a failed item copy rolls back the created menu.
	 *
	 * @return void
	 */
	public function test_failed_item_copy_rolls_back_the_new_menu() {
		$this->menus->add_item( 5, array( 'db_id' => 1, 'title' => 'Home' ) );
		$this->menus->add_item_error = new \WP_Error( 'mclogiora_forced_failure', 'Item creation failed.' );

		$result = $this->workflow->create_translation( 5, 'tr' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertCount( 1, $this->menus->deleted_menus );
		$this->assertNotContains( 5, $this->menus->deleted_menus, 'The source menu must never be deleted.' );
		$this->assertNotNull( $this->menus->get_menu( 5 ) );
	}

	/**
	 * Asserts only whitelisted menu item fields are copied.
	 *
	 * @return void
	 */
	public function test_only_whitelisted_fields_are_copied() {
		$fields = MenuTranslationWorkflow::copied_fields();

		$this->assertContains( 'menu-item-title', $fields );
		$this->assertContains( 'menu-item-object-id', $fields );
		$this->assertNotContains( 'menu-item-meta', $fields, 'Arbitrary menu item meta must not be duplicated.' );
	}
}
