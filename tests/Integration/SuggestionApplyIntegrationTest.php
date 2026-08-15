<?php
/**
 * Translation suggestion apply integration tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Integration;

use McLogiora\Core\Application;
use McLogiora\Database\Installer;
use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageRepositoryInterface;
use McLogiora\Languages\LanguageStatus;
use McLogiora\Relations\ContentType;
use McLogiora\Relations\TranslationRelationRepositoryInterface;
use McLogiora\Relations\TranslationStatus;
use McLogiora\Routing\LanguageContextInterface;
use McLogiora\Routing\RoutingSettings;
use McLogiora\Suggestions\SuggestionPreview;
use McLogiora\Suggestions\SuggestionPreviewStore;
use McLogiora\Suggestions\SuggestionResult;
use McLogiora\Suggestions\SuggestionSurface;
use McLogiora\Suggestions\TranslationSuggestionApplyService;
use McLogiora\Workflows\TranslationWorkflowService;
use WP_UnitTestCase;

/**
 * Proves the apply path against real WordPress persistence.
 *
 * Everything here writes to a real database through real WordPress APIs.
 * `wp_update_post()` is not mocked, because the whole question this file
 * answers is what actually lands in the database -- and a mock would answer a
 * different, easier question.
 *
 * The rollback test deserves a note. It manufactures its failure using the
 * transition policy itself: `translated` is deliberately not a state a machine
 * suggestion may overwrite, so applying to a translated item makes the field
 * write succeed and the status change fail, which is exactly the sequence
 * rollback exists for. No production code is modified to create the failure
 * and no double is injected -- the seam was already there, because the policy
 * that forbids the transition is the same policy that makes the rollback
 * reachable.
 */
final class SuggestionApplyIntegrationTest extends WP_UnitTestCase {
	/**
	 * Service container.
	 *
	 * @var \McLogiora\Core\Container
	 */
	private $container;

	/**
	 * Preview storage.
	 *
	 * @var SuggestionPreviewStore
	 */
	private $previews;

	/**
	 * Apply service under test.
	 *
	 * @var TranslationSuggestionApplyService
	 */
	private $apply;

	/**
	 * Administrator used for most cases.
	 *
	 * @var int
	 */
	private $editor_id;

	/**
	 * Source post identifier.
	 *
	 * @var int
	 */
	private $source_id;

	/**
	 * Translation post identifier.
	 *
	 * @var int
	 */
	private $target_id;

	/**
	 * Sets up schema, languages, a user and a translated post.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->editor_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->editor_id );

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

		$this->previews = new SuggestionPreviewStore();
		$this->apply    = new TranslationSuggestionApplyService(
			$this->previews,
			$this->container->get( TranslationWorkflowService::class )
		);

		$this->source_id = self::factory()->post->create(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => 'English source title',
				'post_excerpt' => 'English source excerpt',
				'post_content' => '<!-- wp:paragraph --><p>Body</p><!-- /wp:paragraph -->',
			)
		);

		$created = $this->container->get( TranslationWorkflowService::class )
			->content()
			->create_translation( $this->source_id, 'tr' );

		$this->assertIsArray( $created, is_wp_error( $created ) ? $created->get_error_message() : '' );

		$this->target_id = (int) $created['post_id'];
	}

	/**
	 * Returns the binding for the translation under test.
	 *
	 * @param string $surface Surface identifier.
	 * @return array<string,mixed>
	 */
	private function context( $surface = SuggestionSurface::POST_TITLE ) {
		return array(
			'user_id'         => $this->editor_id,
			'object_type'     => 'post',
			'source_id'       => (string) $this->source_id,
			'target_id'       => (string) $this->target_id,
			'surface'         => $surface,
			'source_language' => 'en',
			'target_language' => 'tr',
		);
	}

	/**
	 * Stores a preview for the translation under test.
	 *
	 * @param string $text Suggested text.
	 * @param string $surface Surface identifier.
	 * @return SuggestionPreview
	 */
	private function preview( $text, $surface = SuggestionSurface::POST_TITLE ) {
		$preview = $this->previews->create(
			new SuggestionResult( $text, 'openai', 'gpt-5.4-mini' ),
			$this->context( $surface )
		);

		$this->assertInstanceOf( SuggestionPreview::class, $preview );

		return $preview;
	}

	/**
	 * Returns the stored relation status of the translation.
	 *
	 * @return string
	 */
	private function status() {
		$item = $this->container->get( TranslationRelationRepositoryInterface::class )
			->find_item( ContentType::POST, (string) $this->target_id, 'tr' );

		return null === $item ? '' : $item->status();
	}

	/**
	 * Asserts a title suggestion lands exactly, and nothing else moves.
	 *
	 * @return void
	 */
	public function test_applying_a_title_updates_only_that_field() {
		$before_status  = $this->status();
		$before_excerpt = get_post_field( 'post_excerpt', $this->target_id );
		$before_content = get_post_field( 'post_content', $this->target_id );
		$before_slug    = get_post_field( 'post_name', $this->target_id );
		$before_state   = get_post_field( 'post_status', $this->target_id );

		$preview = $this->preview( 'Turkce baslik' );

		$result = $this->apply->apply( $preview->token(), $this->context() );

		$this->assertInstanceOf( SuggestionPreview::class, $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		$this->assertSame( 'Turkce baslik', get_post_field( 'post_title', $this->target_id ) );
		$this->assertSame( $before_excerpt, get_post_field( 'post_excerpt', $this->target_id ), 'The excerpt must not move.' );
		$this->assertSame( $before_content, get_post_field( 'post_content', $this->target_id ), 'Post content must never be touched.' );
		$this->assertSame( $before_slug, get_post_field( 'post_name', $this->target_id ), 'The slug must not be regenerated.' );
		$this->assertSame( $before_state, get_post_field( 'post_status', $this->target_id ), 'Publication state must not change.' );

		$this->assertSame( 'English source title', get_post_field( 'post_title', $this->source_id ), 'The source must never change.' );
		$this->assertNotSame( $before_status, $this->status() );
		$this->assertSame( TranslationStatus::MACHINE_SUGGESTED, $this->status() );
	}

	/**
	 * Asserts an excerpt suggestion lands exactly.
	 *
	 * @return void
	 */
	public function test_applying_an_excerpt_updates_only_that_field() {
		$before_title   = get_post_field( 'post_title', $this->target_id );
		$before_content = get_post_field( 'post_content', $this->target_id );

		$preview = $this->preview( 'Turkce ozet', SuggestionSurface::POST_EXCERPT );

		$result = $this->apply->apply( $preview->token(), $this->context( SuggestionSurface::POST_EXCERPT ) );

		$this->assertInstanceOf( SuggestionPreview::class, $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		$this->assertSame( 'Turkce ozet', get_post_field( 'post_excerpt', $this->target_id ) );
		$this->assertSame( $before_title, get_post_field( 'post_title', $this->target_id ) );
		$this->assertSame( $before_content, get_post_field( 'post_content', $this->target_id ) );
		$this->assertSame( 'English source excerpt', get_post_field( 'post_excerpt', $this->source_id ) );
		$this->assertSame( TranslationStatus::MACHINE_SUGGESTED, $this->status() );
	}

	/**
	 * Asserts a preview cannot be applied twice.
	 *
	 * @return void
	 */
	public function test_a_preview_cannot_be_applied_twice() {
		$preview = $this->preview( 'Turkce baslik' );

		$this->assertInstanceOf( SuggestionPreview::class, $this->apply->apply( $preview->token(), $this->context() ) );

		$title_after_first = get_post_field( 'post_title', $this->target_id );

		$second = $this->apply->apply( $preview->token(), $this->context() );

		$this->assertTrue( is_wp_error( $second ), 'A consumed preview must not apply again.' );
		$this->assertSame( $title_after_first, get_post_field( 'post_title', $this->target_id ), 'A second apply must not write.' );
	}

	/**
	 * Asserts every mis-bound apply writes nothing at all.
	 *
	 * @param string $mutate Which binding fact to corrupt.
	 * @return void
	 *
	 * @dataProvider provide_binding_mutations
	 */
	public function test_a_mis_bound_apply_changes_nothing( $mutate ) {
		$preview = $this->preview( 'Turkce baslik' );

		$before_title  = get_post_field( 'post_title', $this->target_id );
		$before_source = get_post_field( 'post_title', $this->source_id );
		$before_status = $this->status();

		$context = $this->context();

		switch ( $mutate ) {
			case 'user':
				$context['user_id'] = self::factory()->user->create( array( 'role' => 'administrator' ) );
				break;

			case 'object':
				$context['target_id'] = (string) ( $this->target_id + 1000 );
				break;

			case 'object_type':
				$context['object_type'] = 'term';
				break;

			case 'field':
				$context['surface'] = SuggestionSurface::POST_EXCERPT;
				break;

			case 'language':
				$context['target_language'] = 'de';
				break;
		}

		$result = $this->apply->apply( $preview->token(), $context );

		$this->assertTrue( is_wp_error( $result ), "A preview applied with a wrong {$mutate} must be refused." );
		$this->assertSame( $before_title, get_post_field( 'post_title', $this->target_id ) );
		$this->assertSame( $before_source, get_post_field( 'post_title', $this->source_id ) );
		$this->assertSame( $before_status, $this->status() );
	}

	/**
	 * Supplies each binding fact that must be revalidated.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function provide_binding_mutations() {
		return array(
			'user'        => array( 'user' ),
			'object'      => array( 'object' ),
			'object type' => array( 'object_type' ),
			'field'       => array( 'field' ),
			'language'    => array( 'language' ),
		);
	}

	/**
	 * Asserts an unknown token writes nothing.
	 *
	 * @return void
	 */
	public function test_an_unknown_token_changes_nothing() {
		$before = get_post_field( 'post_title', $this->target_id );

		$result = $this->apply->apply( 'nope', $this->context() );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( $before, get_post_field( 'post_title', $this->target_id ) );
		$this->assertSame( TranslationStatus::DRAFT, $this->status() );
	}

	/**
	 * Asserts a discarded preview writes nothing afterwards.
	 *
	 * @return void
	 */
	public function test_a_discarded_preview_cannot_be_applied() {
		$preview = $this->preview( 'Turkce baslik' );
		$before  = get_post_field( 'post_title', $this->target_id );

		$this->assertTrue( $this->apply->discard( $preview->token(), $this->context() ) );

		$result = $this->apply->apply( $preview->token(), $this->context() );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( $before, get_post_field( 'post_title', $this->target_id ) );
	}

	/**
	 * Asserts a deleted target is refused rather than recreated.
	 *
	 * @return void
	 */
	public function test_a_deleted_target_is_refused() {
		$preview = $this->preview( 'Turkce baslik' );

		wp_delete_post( $this->target_id, true );

		$result = $this->apply->apply( $preview->token(), $this->context() );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertNull( get_post( $this->target_id ) );
	}

	/**
	 * Asserts a failed status change puts the field back exactly.
	 *
	 * The failure is genuine rather than injected: a translation already marked
	 * `translated` may not be moved to `machine_suggested`, because overwriting
	 * human-approved text with a machine's guess is precisely what the policy
	 * forbids. So the field write succeeds, the status change is refused, and
	 * the rollback path runs -- with real persistence on both sides of it.
	 *
	 * @return void
	 */
	public function test_a_failed_status_change_restores_the_field() {
		$workflows = $this->container->get( TranslationWorkflowService::class );

		$promoted = $workflows->change_status( ContentType::POST, $this->target_id, 'tr', TranslationStatus::TRANSLATED );

		$this->assertNotWPError( $promoted );
		$this->assertSame( TranslationStatus::TRANSLATED, $this->status() );

		$original_title = get_post_field( 'post_title', $this->target_id );

		$preview = $this->preview( 'Makine cevirisi' );

		$result = $this->apply->apply( $preview->token(), $this->context() );

		$this->assertTrue( is_wp_error( $result ), 'Applying over a translated item must fail.' );

		$this->assertSame(
			$original_title,
			get_post_field( 'post_title', $this->target_id ),
			'A failed status change must restore the previous title exactly.'
		);

		$this->assertSame( TranslationStatus::TRANSLATED, $this->status(), 'The status must be left as it was.' );
		$this->assertSame( 'English source title', get_post_field( 'post_title', $this->source_id ) );

		/*
		 * The preview survives a completed rollback. Nothing was written, so
		 * the suggestion is still valid and the user should not have to pay a
		 * provider again to retry after fixing the status.
		 */
		$this->assertInstanceOf( SuggestionPreview::class, $this->previews->find( $preview->token() ) );
	}

	/**
	 * Asserts the human review step is what reaches `translated`.
	 *
	 * @return void
	 */
	public function test_human_review_moves_a_machine_suggestion_to_translated() {
		$preview = $this->preview( 'Turkce baslik' );

		$this->assertInstanceOf( SuggestionPreview::class, $this->apply->apply( $preview->token(), $this->context() ) );
		$this->assertSame( TranslationStatus::MACHINE_SUGGESTED, $this->status() );

		$reviewed = $this->container->get( TranslationWorkflowService::class )
			->change_status( ContentType::POST, $this->target_id, 'tr', TranslationStatus::TRANSLATED );

		$this->assertNotWPError( $reviewed );
		$this->assertSame( TranslationStatus::TRANSLATED, $this->status() );
	}
}
