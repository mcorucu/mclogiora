<?php
/**
 * Classic Editor suggestion action contract tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Integration;

use McLogiora\Core\Application;
use McLogiora\Database\Installer;
use McLogiora\Editors\ClassicEditorMetabox;
use McLogiora\Editors\SuggestionEditorController;
use McLogiora\Editors\SuggestionEditorState;
use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageRepositoryInterface;
use McLogiora\Languages\LanguageStatus;
use McLogiora\Relations\ContentType;
use McLogiora\Relations\TranslationStatus;
use McLogiora\Suggestions\CredentialStore;
use McLogiora\Suggestions\HttpTransport;
use McLogiora\Suggestions\ProviderRegistry;
use McLogiora\Suggestions\Providers\DeepLProvider;
use McLogiora\Suggestions\SuggestionSettings;
use McLogiora\Suggestions\TranslationSuggestionService;
use McLogiora\Tests\Support\FakeTransport;
use McLogiora\Workflows\TranslationWorkflowService;
use WP_Ajax_UnitTestCase;

/**
 * Drives the shared endpoints with the payload the Classic surface ships.
 *
 * The controller's own security and semantics already have twenty-five tests
 * and are not repeated here. What is unproven is the join: that the state the
 * Classic metabox hands its script -- that object id, that nonce, those action
 * names -- actually completes a Generate, Apply and Discard for both fields.
 * A surface that ships a payload the controller rejects would look perfectly
 * correct in a render test.
 */
final class ClassicSuggestionActionTest extends WP_Ajax_UnitTestCase {
	/**
	 * Service container.
	 *
	 * @var \McLogiora\Core\Container
	 */
	private $container;

	/**
	 * Recording transport.
	 *
	 * @var FakeTransport
	 */
	private $transport;

	/**
	 * Source post identifier.
	 *
	 * @var int
	 */
	private $source_id = 0;

	/**
	 * Target post identifier.
	 *
	 * @var int
	 */
	private $target_id = 0;

	/**
	 * Sets up a ready site with a translated pair.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		set_current_screen( 'post' );

		add_filter( 'use_block_editor_for_post_type', '__return_false' );

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

		$this->transport = new FakeTransport();

		/*
		 * The transport, the registry and the service are all replaced. The
		 * container may already have resolved the registry and the service
		 * against the real transport during plugin boot, and swapping only the
		 * transport would leave a cached object pointing at the network.
		 */
		$this->container->set( HttpTransport::class, $this->transport );
		$this->container->set(
			ProviderRegistry::class,
			function () {
				$registry = new ProviderRegistry();

				$registry->add( new DeepLProvider( $this->transport, new CredentialStore() ) );

				return $registry;
			}
		);
		$this->container->set(
			TranslationSuggestionService::class,
			function () {
				return new TranslationSuggestionService(
					new SuggestionSettings(),
					$this->container->get( ProviderRegistry::class )
				);
			}
		);

		( new CredentialStore() )->save( 'deepl', 'classic-action-key-7712' );

		$settings = $this->container->get( SuggestionSettings::class );

		$settings->set_enabled( true );
		$settings->set_provider( 'deepl' );

		( new SuggestionEditorController() )->register( $this->container );

		$this->source_id = self::factory()->post->create(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => 'Classic action source title',
				'post_excerpt' => 'Classic action source excerpt',
				'post_content' => 'Classic action source body',
			)
		);

		$created = $this->container->get( TranslationWorkflowService::class )
			->content()
			->create_translation( $this->source_id, 'tr' );

		$this->assertIsArray( $created, is_wp_error( $created ) ? $created->get_error_message() : '' );

		$this->target_id = (int) $created['post_id'];

		wp_update_post(
			array(
				'ID'           => $this->target_id,
				'post_title'   => 'Classic target title before',
				'post_excerpt' => 'Classic target excerpt before',
			)
		);
	}

	/**
	 * Clears configuration between tests.
	 *
	 * @return void
	 */
	public function tear_down() {
		( new CredentialStore() )->remove( 'deepl' );

		delete_option( SuggestionSettings::OPTION_ENABLED );
		delete_option( SuggestionSettings::OPTION_PROVIDER );

		remove_filter( 'use_block_editor_for_post_type', '__return_false' );

		parent::tear_down();
	}

	/**
	 * Returns the state the Classic metabox hands its script.
	 *
	 * @return array<string,mixed>
	 */
	private function classic_state() {
		return $this->container->get( SuggestionEditorState::class )->for_post( $this->target_id );
	}

	/**
	 * Builds a request the way the Classic script would.
	 *
	 * @param string              $field Field name.
	 * @param array<string,mixed> $extra Extra fields.
	 * @return void
	 */
	private function classic_post( $field, array $extra = array() ) {
		$state = $this->classic_state();

		$this->assertTrue( (bool) $state['available'], 'The fixture must be ready to suggest.' );

		$_POST = array_merge(
			array(
				'nonce'    => $state['nonce'],
				'objectId' => $this->target_id,
				'field'    => $field,
			),
			$extra
		);
	}

	/**
	 * Dispatches an action and returns the decoded response, or null on die.
	 *
	 * @param string $action AJAX action name.
	 * @return array<string,mixed>|null
	 */
	private function dispatch( $action ) {
		$this->_last_response = '';

		try {
			$this->_handleAjax( $action );
		} catch ( \WPAjaxDieContinueException $e ) {
			unset( $e );
		} catch ( \WPAjaxDieStopException $e ) {
			unset( $e );
		}

		$decoded = json_decode( (string) $this->_last_response, true );

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Generates a suggestion for one field and returns the response payload.
	 *
	 * @param string $field Field name.
	 * @param string $text Text the provider should answer with.
	 * @return array<string,mixed>
	 */
	private function generate( $field, $text ) {
		$this->transport->will_return(
			array( 'translations' => array( array( 'text' => $text, 'detected_source_language' => 'EN' ) ) )
		);

		$state = $this->classic_state();

		$this->classic_post( $field );

		$response = $this->dispatch( $state['actions']['generate'] );

		$this->assertIsArray( $response );
		$this->assertTrue( (bool) $response['success'], (string) wp_json_encode( $response ) );

		return $response['data'];
	}

	/**
	 * Returns the current relation status of the target.
	 *
	 * @return string
	 */
	private function target_status() {
		$group = $this->container->get( \McLogiora\Relations\TranslationRelationServiceInterface::class )
			->get_translation_set_for_object( ContentType::POST, (string) $this->target_id );

		foreach ( $group->items() as $item ) {
			if ( (int) $item->object_id() === $this->target_id ) {
				return (string) $item->status();
			}
		}

		return '';
	}

	/**
	 * Asserts Generate translates the authoritative source title only.
	 *
	 * @return void
	 */
	public function test_classic_generate_sends_the_source_title() {
		$data = $this->generate( 'title', 'Onerilen baslik' );

		$this->assertSame( 'title', $data['field'] );
		$this->assertSame( 'Onerilen baslik', $data['text'] );
		$this->assertNotSame( '', (string) $data['token'] );

		$last = $this->transport->last_request();
		$body = is_string( $last['body'] ) ? $last['body'] : (string) wp_json_encode( $last['body'] );

		$this->assertStringContainsString( 'Classic%20action%20source%20title', $body );
		$this->assertStringNotContainsString( 'target%20title%20before', $body, 'The target title is not what gets translated.' );
		$this->assertStringNotContainsString( 'source%20excerpt', $body );
		$this->assertStringNotContainsString( 'source%20body', $body );

		$this->assertSame( 'Classic target title before', get_post( $this->target_id )->post_title, 'Generate must write nothing.' );
		$this->assertSame( TranslationStatus::DRAFT, $this->target_status() );
	}

	/**
	 * Asserts Generate translates the authoritative source excerpt only.
	 *
	 * @return void
	 */
	public function test_classic_generate_sends_the_source_excerpt() {
		$data = $this->generate( 'excerpt', 'Onerilen ozet' );

		$this->assertSame( 'excerpt', $data['field'] );

		$last = $this->transport->last_request();
		$body = is_string( $last['body'] ) ? $last['body'] : (string) wp_json_encode( $last['body'] );

		$this->assertStringContainsString( 'Classic%20action%20source%20excerpt', $body );
		$this->assertStringNotContainsString( 'source%20title', $body );

		$this->assertSame( 'Classic target excerpt before', get_post( $this->target_id )->post_excerpt );
	}

	/**
	 * Asserts Apply persists the title and moves the relation.
	 *
	 * @return void
	 */
	public function test_classic_apply_persists_the_title() {
		$preview = $this->generate( 'title', 'Onerilen baslik' );

		$this->classic_post( 'title', array( 'token' => $preview['token'] ) );

		$response = $this->dispatch( $this->classic_state()['actions']['apply'] );

		$this->assertIsArray( $response );
		$this->assertTrue( (bool) $response['success'], (string) wp_json_encode( $response ) );
		$this->assertSame( 'Onerilen baslik', $response['data']['text'], 'Apply must answer with what it stored.' );

		$target = get_post( $this->target_id );

		$this->assertSame( 'Onerilen baslik', $target->post_title );
		$this->assertSame( 'Classic target excerpt before', $target->post_excerpt, 'Applying a title must not touch the excerpt.' );
		$this->assertSame( 'Classic action source body', $target->post_content, 'Applying a title must not touch the body.' );
		$this->assertSame( 'Classic action source title', get_post( $this->source_id )->post_title, 'The source must never change.' );
		$this->assertSame( TranslationStatus::MACHINE_SUGGESTED, $this->target_status() );
	}

	/**
	 * Asserts Apply persists the excerpt.
	 *
	 * @return void
	 */
	public function test_classic_apply_persists_the_excerpt() {
		$preview = $this->generate( 'excerpt', 'Onerilen ozet' );

		$this->classic_post( 'excerpt', array( 'token' => $preview['token'] ) );

		$response = $this->dispatch( $this->classic_state()['actions']['apply'] );

		$this->assertIsArray( $response );
		$this->assertTrue( (bool) $response['success'], (string) wp_json_encode( $response ) );

		$target = get_post( $this->target_id );

		$this->assertSame( 'Onerilen ozet', $target->post_excerpt );
		$this->assertSame( 'Classic target title before', $target->post_title, 'Applying an excerpt must not touch the title.' );
		$this->assertSame( 'Classic action source excerpt', get_post( $this->source_id )->post_excerpt );
		$this->assertSame( TranslationStatus::MACHINE_SUGGESTED, $this->target_status() );
	}

	/**
	 * Asserts a token is bound to the field it was generated for.
	 *
	 * @return void
	 */
	public function test_a_title_token_cannot_be_applied_to_the_excerpt() {
		$preview = $this->generate( 'title', 'Onerilen baslik' );

		$this->classic_post( 'excerpt', array( 'token' => $preview['token'] ) );

		$response = $this->dispatch( $this->classic_state()['actions']['apply'] );

		$this->assertIsArray( $response );
		$this->assertFalse( (bool) $response['success'] );

		$target = get_post( $this->target_id );

		$this->assertSame( 'Classic target title before', $target->post_title );
		$this->assertSame( 'Classic target excerpt before', $target->post_excerpt );
		$this->assertSame( TranslationStatus::DRAFT, $this->target_status() );
	}

	/**
	 * Asserts Discard forgets the preview and writes nothing.
	 *
	 * @return void
	 */
	public function test_classic_discard_writes_nothing_and_invalidates_the_token() {
		$preview = $this->generate( 'title', 'Onerilen baslik' );
		$before  = count( $this->transport->requests() );

		$this->classic_post( 'title', array( 'token' => $preview['token'] ) );

		$response = $this->dispatch( $this->classic_state()['actions']['discard'] );

		$this->assertIsArray( $response );
		$this->assertTrue( (bool) $response['success'] );

		$this->assertCount( $before, $this->transport->requests(), 'Discard must reach no provider.' );

		$target = get_post( $this->target_id );

		$this->assertSame( 'Classic target title before', $target->post_title );
		$this->assertSame( TranslationStatus::DRAFT, $this->target_status() );

		$this->classic_post( 'title', array( 'token' => $preview['token'] ) );

		$reapplied = $this->dispatch( $this->classic_state()['actions']['apply'] );

		$this->assertIsArray( $reapplied );
		$this->assertFalse( (bool) $reapplied['success'], 'A discarded preview must not be applicable.' );
		$this->assertSame( 'Classic target title before', get_post( $this->target_id )->post_title );
	}

	/**
	 * Asserts Regenerate is one more explicit call that writes nothing.
	 *
	 * @return void
	 */
	public function test_classic_regenerate_is_one_more_explicit_call() {
		$first = $this->generate( 'title', 'Ilk oneri' );

		$this->assertCount( 1, $this->transport->requests() );

		$second = $this->generate( 'title', 'Ikinci oneri' );

		$this->assertCount( 2, $this->transport->requests(), 'Regenerate must be exactly one more call.' );
		$this->assertNotSame( $first['token'], $second['token'] );
		$this->assertSame( 'Ikinci oneri', $second['text'] );

		$this->assertSame( 'Classic target title before', get_post( $this->target_id )->post_title );
		$this->assertSame( TranslationStatus::DRAFT, $this->target_status() );
	}

	/**
	 * Asserts a disabled site hands Classic no usable token.
	 *
	 * A stale nonce left in a disabled payload would let the feature be driven
	 * from the console after the owner switched it off.
	 *
	 * @return void
	 */
	public function test_a_disabled_site_hands_classic_no_usable_nonce() {
		$this->container->get( SuggestionSettings::class )->set_enabled( false );

		$state = $this->classic_state();

		$this->assertFalse( (bool) $state['available'] );
		$this->assertSame( '', (string) $state['nonce'] );

		$this->assertStringNotContainsString(
			'data-mclogiora-generate',
			$this->rendered_metabox(),
			'A disabled site must render no generate control.'
		);
	}

	/**
	 * Renders the Classic metabox for the target post.
	 *
	 * @return string
	 */
	private function rendered_metabox() {
		$metabox = new ClassicEditorMetabox();

		$metabox->register( $this->container );

		ob_start();

		$metabox->render( get_post( $this->target_id ) );

		return (string) ob_get_clean();
	}
}
