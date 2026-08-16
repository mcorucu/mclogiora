<?php
/**
 * Taxonomy suggestion surface tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Integration;

use McLogiora\Admin\SuggestionAdminController;
use McLogiora\Admin\SuggestionAdminState;
use McLogiora\Core\Application;
use McLogiora\Database\Installer;
use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageRepositoryInterface;
use McLogiora\Languages\LanguageStatus;
use McLogiora\Relations\ContentType;
use McLogiora\Relations\TranslationGroup;
use McLogiora\Relations\TranslationItem;
use McLogiora\Relations\TranslationRelationServiceInterface;
use McLogiora\Relations\TranslationStatus;
use McLogiora\Suggestions\CredentialStore;
use McLogiora\Suggestions\HttpTransport;
use McLogiora\Suggestions\ProviderRegistry;
use McLogiora\Suggestions\Providers\DeepLProvider;
use McLogiora\Suggestions\SuggestionSettings;
use McLogiora\Suggestions\SuggestionSurface;
use McLogiora\Suggestions\TranslationSuggestionService;
use McLogiora\Tests\Support\EchoTransport;
use McLogiora\Workflows\TranslationWorkflowService;
use WP_Ajax_UnitTestCase;

/**
 * Proves the taxonomy term suggestion surface against real term persistence.
 *
 * A term is relation-backed like a post, so the status semantics are the same.
 * What is different, and what most of this file is about, is everything a term
 * carries *besides* the two translatable fields: a slug that owns every URL the
 * term has, a parent that owns its place in the hierarchy, and a taxonomy.
 * `wp_update_term()` will happily rewrite all three, so the tests assert they
 * come out byte-identical rather than assuming the write was narrow.
 */
final class TaxonomySuggestionIntegrationTest extends WP_Ajax_UnitTestCase {
	/**
	 * A credential distinctive enough to find anywhere.
	 */
	const SECRET = 'deepl-live-TAXONOMYSTATE-MUST-NOT-LEAK-8815';

	/**
	 * Source term name.
	 */
	const SOURCE_NAME = 'Qualification Source Term';

	/**
	 * Source term description.
	 */
	const SOURCE_DESCRIPTION = 'Qualification source description for the term.';

	/**
	 * Service container.
	 *
	 * @var \McLogiora\Core\Container
	 */
	private $container;

	/**
	 * Recording transport that echoes the submitted text.
	 *
	 * @var EchoTransport
	 */
	private $transport;

	/**
	 * Source term identifier.
	 *
	 * @var int
	 */
	private $source_id = 0;

	/**
	 * Translated target term identifier.
	 *
	 * @var int
	 */
	private $target_id = 0;

	/**
	 * Parent term identifier of the target.
	 *
	 * @var int
	 */
	private $parent_id = 0;

	/**
	 * Sets up a ready site with a translated term.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		set_current_screen( 'toplevel_page_mclogiora' );

		$this->container = Application::instance( dirname( __DIR__, 2 ) . '/mclogiora.php' )->container();

		delete_option( 'mclogiora_db_version' );
		$this->container->get( Installer::class )->install();

		$languages = $this->container->get( LanguageRepositoryInterface::class );

		if ( ! $languages->find_by_code( 'en' ) instanceof Language ) {
			$languages->create( new Language( 'en', 'en_US', 'English', 'English', 'ltr', LanguageStatus::ACTIVE, 0, false ) );
		}

		$languages->set_default( 'en' );

		if ( ! $languages->find_by_code( 'tr' ) instanceof Language ) {
			$languages->create( new Language( 'tr', 'tr_TR', 'Turkce', 'Turkish', 'ltr', LanguageStatus::ACTIVE, 1, false ) );
		}

		$this->transport = new EchoTransport( 'TR::' );

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

		( new CredentialStore() )->save( 'deepl', self::SECRET );

		$settings = $this->container->get( SuggestionSettings::class );

		$settings->set_enabled( true );
		$settings->set_provider( 'deepl' );

		( new SuggestionAdminController() )->register( $this->container );

		$source = self::factory()->term->create_and_get(
			array(
				'taxonomy'    => 'category',
				'name'        => self::SOURCE_NAME,
				'description' => self::SOURCE_DESCRIPTION,
			)
		);

		$this->source_id = (int) $source->term_id;

		$created = $this->container->get( TranslationWorkflowService::class )
			->taxonomy()
			->create_translation( $this->source_id, 'category', 'tr', 'Hedef Terim', 'Hedef aciklama.' );

		$this->assertIsArray( $created, is_wp_error( $created ) ? $created->get_error_message() : '' );

		$this->target_id = (int) $created['term_id'];

		$this->parent_id = (int) self::factory()->term->create( array( 'taxonomy' => 'category', 'name' => 'Hedef Ebeveyn' ) );

		wp_update_term(
			$this->target_id,
			'category',
			array(
				'slug'   => 'hedef-terim-qualification-slug',
				'parent' => $this->parent_id,
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

		parent::tear_down();
	}

	/**
	 * Returns the state an admin screen would receive.
	 *
	 * @return array<string,mixed>
	 */
	private function state() {
		return $this->container->get( SuggestionAdminState::class )->current();
	}

	/**
	 * Returns a term as WordPress currently stores it.
	 *
	 * @param int $term_id Term identifier.
	 * @return \WP_Term
	 */
	private function term( $term_id ) {
		$term = get_term( (int) $term_id, 'category' );

		$this->assertInstanceOf( \WP_Term::class, $term );

		return $term;
	}

	/**
	 * Builds a request the way the admin script would.
	 *
	 * @param string              $surface Suggestion surface.
	 * @param int                 $term_id Target term identifier.
	 * @param string              $language Target language.
	 * @param array<string,mixed> $extra Extra fields.
	 * @return void
	 */
	private function admin_post( $surface, $term_id, $language = 'tr', array $extra = array() ) {
		$_POST = array_merge(
			array(
				'nonce'    => $this->state()['nonce'],
				'surface'  => $surface,
				'objectId' => $term_id,
				'language' => $language,
			),
			$extra
		);
	}

	/**
	 * Dispatches an action and returns the decoded response.
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
	 * Generates a suggestion for one term field.
	 *
	 * @param string $surface Suggestion surface.
	 * @return array<string,mixed>
	 */
	private function generate( $surface ) {
		$this->admin_post( $surface, $this->target_id );

		$response = $this->dispatch( $this->state()['actions']['generate'] );

		$this->assertIsArray( $response );
		$this->assertTrue( (bool) $response['success'], (string) wp_json_encode( $response ) );

		return $response['data'];
	}

	/**
	 * Applies a preview token for one term field.
	 *
	 * @param string $surface Suggestion surface.
	 * @param string $token Preview token.
	 * @return array<string,mixed>|null
	 */
	private function apply( $surface, $token ) {
		$this->admin_post( $surface, $this->target_id, 'tr', array( 'token' => $token ) );

		return $this->dispatch( $this->state()['actions']['apply'] );
	}

	/**
	 * Returns the body the provider was last sent.
	 *
	 * @return string
	 */
	private function sent_body() {
		$last = $this->transport->last_request();

		$this->assertNotNull( $last, 'Expected a provider request.' );

		return rawurldecode( is_string( $last['body'] ) ? $last['body'] : (string) wp_json_encode( $last['body'] ) );
	}

	/**
	 * Returns the relation status of one term.
	 *
	 * @param int $term_id Term identifier.
	 * @return string
	 */
	private function relation_status( $term_id ) {
		$group = $this->container->get( TranslationRelationServiceInterface::class )
			->get_translation_set_for_object( ContentType::TERM, (string) $term_id );

		if ( ! $group instanceof TranslationGroup ) {
			return '';
		}

		foreach ( $group->items() as $item ) {
			if ( $item instanceof TranslationItem && (int) $item->object_id() === (int) $term_id ) {
				return (string) $item->status();
			}
		}

		return '';
	}

	/**
	 * Asserts the screen state carries no credential.
	 *
	 * @return void
	 */
	public function test_the_admin_state_carries_no_credential() {
		$payload = (string) wp_json_encode( $this->state() );

		$this->assertStringNotContainsString( self::SECRET, $payload );
		$this->assertStringNotContainsString( 'MUST-NOT-LEAK', $payload );
		$this->assertStringNotContainsString( 'deepl-live', $payload );
		$this->assertStringNotContainsString( 'Authorization', $payload );
		$this->assertStringNotContainsString( 'DeepL-Auth-Key', $payload );
	}

	/**
	 * Asserts no term text is shipped to the browser.
	 *
	 * @return void
	 */
	public function test_the_admin_state_ships_no_term_text() {
		$payload = (string) wp_json_encode( $this->state() );

		$this->assertStringNotContainsString( self::SOURCE_NAME, $payload );
		$this->assertStringNotContainsString( self::SOURCE_DESCRIPTION, $payload );
	}

	/**
	 * Asserts building the state reaches no provider.
	 *
	 * @return void
	 */
	public function test_building_the_state_makes_no_provider_request() {
		$this->state();
		$this->state();

		$this->assertSame( array(), $this->transport->requests() );
	}

	/**
	 * Asserts Generate translates the authoritative source term name.
	 *
	 * @return void
	 */
	public function test_generate_sends_the_authoritative_source_name() {
		$data = $this->generate( SuggestionSurface::TERM_NAME );

		$this->assertSame( SuggestionSurface::TERM_NAME, $data['surface'] );

		$body = $this->sent_body();

		$this->assertStringContainsString( self::SOURCE_NAME, $body );
		$this->assertStringNotContainsString( 'Hedef Terim', $body, 'The target name is not what gets translated.' );
		$this->assertStringNotContainsString( self::SOURCE_DESCRIPTION, $body );
	}

	/**
	 * Asserts Generate translates the authoritative source description.
	 *
	 * @return void
	 */
	public function test_generate_sends_the_authoritative_source_description() {
		$this->generate( SuggestionSurface::TERM_DESCRIPTION );

		$body = $this->sent_body();

		$this->assertStringContainsString( self::SOURCE_DESCRIPTION, $body );
		$this->assertStringNotContainsString( self::SOURCE_NAME, $body );
	}

	/**
	 * Asserts Generate writes nothing at all.
	 *
	 * @return void
	 */
	public function test_generate_changes_nothing() {
		$before = $this->term( $this->target_id );

		$this->generate( SuggestionSurface::TERM_NAME );

		$after = $this->term( $this->target_id );

		$this->assertSame( $before->name, $after->name );
		$this->assertSame( $before->description, $after->description );
		$this->assertSame( $before->slug, $after->slug );
		$this->assertSame( $before->parent, $after->parent );
		$this->assertSame( TranslationStatus::DRAFT, $this->relation_status( $this->target_id ) );
	}

	/**
	 * Asserts the browser cannot choose what gets translated.
	 *
	 * @return void
	 */
	public function test_arbitrary_request_text_never_reaches_the_provider() {
		$this->admin_post(
			SuggestionSurface::TERM_NAME,
			$this->target_id,
			'tr',
			array(
				'text'       => 'ATTACKER CONTROLLED TEXT',
				'sourceText' => 'ATTACKER CONTROLLED TEXT',
				'source'     => 'ATTACKER CONTROLLED TEXT',
				'content'    => 'ATTACKER CONTROLLED TEXT',
			)
		);

		$this->dispatch( $this->state()['actions']['generate'] );

		$body = $this->sent_body();

		$this->assertStringNotContainsString( 'ATTACKER', $body, 'The endpoint must not be a translation proxy.' );
		$this->assertStringContainsString( self::SOURCE_NAME, $body );
	}

	/**
	 * Asserts Apply persists the name and nothing else.
	 *
	 * @return void
	 */
	public function test_apply_persists_the_name_and_preserves_everything_else() {
		$before  = $this->term( $this->target_id );
		$preview = $this->generate( SuggestionSurface::TERM_NAME );

		$response = $this->apply( SuggestionSurface::TERM_NAME, $preview['token'] );

		$this->assertIsArray( $response );
		$this->assertTrue( (bool) $response['success'], (string) wp_json_encode( $response ) );
		$this->assertSame( TranslationStatus::MACHINE_SUGGESTED, $response['data']['status'] );

		$after = $this->term( $this->target_id );

		$this->assertSame( 'TR::' . self::SOURCE_NAME, $after->name );
		$this->assertSame( $before->description, $after->description, 'Applying a name must not touch the description.' );
		$this->assertSame( $before->slug, $after->slug, 'A machine-translated slug would rewrite every URL the term owns.' );
		$this->assertSame( $before->parent, $after->parent );
		$this->assertSame( $before->taxonomy, $after->taxonomy );
		$this->assertSame( (int) $before->term_id, (int) $after->term_id );
	}

	/**
	 * Asserts Apply persists the description and nothing else.
	 *
	 * @return void
	 */
	public function test_apply_persists_the_description_and_preserves_everything_else() {
		$before  = $this->term( $this->target_id );
		$preview = $this->generate( SuggestionSurface::TERM_DESCRIPTION );

		$response = $this->apply( SuggestionSurface::TERM_DESCRIPTION, $preview['token'] );

		$this->assertIsArray( $response );
		$this->assertTrue( (bool) $response['success'], (string) wp_json_encode( $response ) );

		$after = $this->term( $this->target_id );

		$this->assertSame( 'TR::' . self::SOURCE_DESCRIPTION, $after->description );
		$this->assertSame( $before->name, $after->name, 'Applying a description must not touch the name.' );
		$this->assertSame( $before->slug, $after->slug );
		$this->assertSame( $before->parent, $after->parent );
	}

	/**
	 * Asserts the source term is never rewritten.
	 *
	 * @return void
	 */
	public function test_apply_leaves_the_source_term_untouched() {
		$before  = $this->term( $this->source_id );
		$preview = $this->generate( SuggestionSurface::TERM_NAME );

		$this->apply( SuggestionSurface::TERM_NAME, $preview['token'] );

		$after = $this->term( $this->source_id );

		$this->assertSame( self::SOURCE_NAME, $after->name );
		$this->assertSame( self::SOURCE_DESCRIPTION, $after->description );
		$this->assertSame( $before->slug, $after->slug );
		$this->assertSame( $before->parent, $after->parent );
		$this->assertSame( TranslationStatus::ORIGINAL, $this->relation_status( $this->source_id ) );
	}

	/**
	 * Asserts Regenerate is one more explicit call that writes nothing.
	 *
	 * @return void
	 */
	public function test_regenerate_is_one_more_explicit_call() {
		$first = $this->generate( SuggestionSurface::TERM_NAME );

		$this->assertCount( 1, $this->transport->requests() );

		$second = $this->generate( SuggestionSurface::TERM_NAME );

		$this->assertCount( 2, $this->transport->requests() );
		$this->assertNotSame( $first['token'], $second['token'] );
		$this->assertSame( 'Hedef Terim', $this->term( $this->target_id )->name );
		$this->assertSame( TranslationStatus::DRAFT, $this->relation_status( $this->target_id ) );
	}

	/**
	 * Asserts Discard writes nothing and invalidates the token.
	 *
	 * @return void
	 */
	public function test_discard_writes_nothing_and_invalidates_the_token() {
		$preview = $this->generate( SuggestionSurface::TERM_NAME );
		$before  = count( $this->transport->requests() );

		$this->admin_post( SuggestionSurface::TERM_NAME, $this->target_id, 'tr', array( 'token' => $preview['token'] ) );

		$discarded = $this->dispatch( $this->state()['actions']['discard'] );

		$this->assertIsArray( $discarded );
		$this->assertTrue( (bool) $discarded['success'] );
		$this->assertCount( $before, $this->transport->requests(), 'Discard must reach no provider.' );
		$this->assertSame( 'Hedef Terim', $this->term( $this->target_id )->name );

		$reapplied = $this->apply( SuggestionSurface::TERM_NAME, $preview['token'] );

		$this->assertIsArray( $reapplied );
		$this->assertFalse( (bool) $reapplied['success'], 'A discarded preview must not be applicable.' );
		$this->assertSame( 'Hedef Terim', $this->term( $this->target_id )->name );
	}

	/**
	 * Asserts a name preview cannot be applied to the description.
	 *
	 * @return void
	 */
	public function test_a_name_preview_cannot_be_applied_to_the_description() {
		$before  = $this->term( $this->target_id );
		$preview = $this->generate( SuggestionSurface::TERM_NAME );

		$response = $this->apply( SuggestionSurface::TERM_DESCRIPTION, $preview['token'] );

		$this->assertIsArray( $response );
		$this->assertFalse( (bool) $response['success'] );

		$after = $this->term( $this->target_id );

		$this->assertSame( $before->name, $after->name );
		$this->assertSame( $before->description, $after->description );
		$this->assertSame( TranslationStatus::DRAFT, $this->relation_status( $this->target_id ) );
	}

	/**
	 * Asserts a preview cannot be applied to a different term.
	 *
	 * @return void
	 */
	public function test_a_preview_cannot_be_applied_to_another_term() {
		$other = self::factory()->term->create_and_get( array( 'taxonomy' => 'category', 'name' => 'Unrelated' ) );

		$preview = $this->generate( SuggestionSurface::TERM_NAME );

		$this->admin_post(
			SuggestionSurface::TERM_NAME,
			(int) $other->term_id,
			'tr',
			array( 'token' => $preview['token'] )
		);

		$response = $this->dispatch( $this->state()['actions']['apply'] );

		$this->assertIsArray( $response );
		$this->assertFalse( (bool) $response['success'] );
		$this->assertSame( 'Unrelated', $this->term( (int) $other->term_id )->name );
		$this->assertSame( 'Hedef Terim', $this->term( $this->target_id )->name );
	}

	/**
	 * Asserts requests that cannot be honoured reach no provider.
	 *
	 * @return void
	 */
	public function test_refused_requests_never_reach_a_provider() {
		$unrelated = (int) self::factory()->term->create( array( 'taxonomy' => 'category', 'name' => 'Outside Any Group' ) );

		$refusals = array(
			'source term as target'  => array( SuggestionSurface::TERM_NAME, $this->source_id, 'tr' ),
			'term outside a group'   => array( SuggestionSurface::TERM_NAME, $unrelated, 'tr' ),
			'missing term'           => array( SuggestionSurface::TERM_NAME, 99999999, 'tr' ),
			'unconfigured language'  => array( SuggestionSurface::TERM_NAME, $this->target_id, 'de' ),
			'wrong target language'  => array( SuggestionSurface::TERM_NAME, $this->target_id, 'en' ),
			'slug is not a surface'  => array( 'term_slug', $this->target_id, 'tr' ),
			'parent is not a surface' => array( 'term_parent', $this->target_id, 'tr' ),
			'media surface here'     => array( SuggestionSurface::MEDIA_ALT, $this->target_id, 'tr' ),
			'post surface here'      => array( SuggestionSurface::POST_TITLE, $this->target_id, 'tr' ),
		);

		foreach ( $refusals as $label => $arguments ) {
			$this->admin_post( $arguments[0], $arguments[1], $arguments[2] );

			$response = $this->dispatch( $this->state()['actions']['generate'] );

			$this->assertIsArray( $response, $label );
			$this->assertFalse( (bool) $response['success'], $label );
		}

		$this->assertSame( array(), $this->transport->requests(), 'A refusal must cost the owner nothing.' );
		$this->assertSame( self::SOURCE_NAME, $this->term( $this->source_id )->name );
		$this->assertSame( 'Hedef Terim', $this->term( $this->target_id )->name );
	}

	/**
	 * Asserts an invalid nonce is refused before anything happens.
	 *
	 * @return void
	 */
	public function test_an_invalid_nonce_is_refused() {
		$_POST = array(
			'nonce'    => 'not-a-real-nonce',
			'surface'  => SuggestionSurface::TERM_NAME,
			'objectId' => $this->target_id,
			'language' => 'tr',
		);

		$this->dispatch( $this->state()['actions']['generate'] );

		$this->assertSame( array(), $this->transport->requests() );
		$this->assertSame( 'Hedef Terim', $this->term( $this->target_id )->name );
	}

	/**
	 * Asserts a user without the capability is refused.
	 *
	 * @return void
	 */
	public function test_a_user_without_the_capability_is_refused() {
		$nonce = $this->state()['nonce'];

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$_POST = array(
			'nonce'    => $nonce,
			'surface'  => SuggestionSurface::TERM_NAME,
			'objectId' => $this->target_id,
			'language' => 'tr',
		);

		$this->dispatch( $this->state()['actions']['generate'] );

		$this->assertSame( array(), $this->transport->requests() );
		$this->assertSame( 'Hedef Terim', $this->term( $this->target_id )->name );
	}

	/**
	 * Asserts a disabled site hands the screen no usable token.
	 *
	 * @return void
	 */
	public function test_a_disabled_site_hands_the_screen_no_usable_nonce() {
		$this->container->get( SuggestionSettings::class )->set_enabled( false );

		$state = $this->state();

		$this->assertFalse( (bool) $state['available'] );
		$this->assertSame( '', (string) $state['nonce'] );
	}
}
