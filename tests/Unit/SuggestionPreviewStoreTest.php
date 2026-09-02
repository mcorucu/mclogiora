<?php
/**
 * Suggestion preview storage and binding tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Unit;

use McLogiora\Suggestions\SuggestionPreview;
use McLogiora\Suggestions\SuggestionPreviewStore;
use McLogiora\Suggestions\SuggestionResult;
use McLogiora\Suggestions\SuggestionSurface;
use PHPUnit\Framework\TestCase;

/**
 * Covers the rejection matrix a preview must enforce before Apply can run.
 *
 * Every test here describes a way an attacker with a legitimate-looking token
 * could write content they should not be able to write. A token proves someone
 * saw a suggestion; it never proves they may put it in a particular field of a
 * particular object. These tests are the difference between those two claims.
 */
final class SuggestionPreviewStoreTest extends TestCase {
	/**
	 * Store under test.
	 *
	 * @var SuggestionPreviewStore
	 */
	private $store;

	/**
	 * Resets the transient and clock doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['mclogiora_test_transients']   = array();
		$GLOBALS['mclogiora_test_clock_offset'] = 0;

		$this->store = new SuggestionPreviewStore();
	}

	/**
	 * Returns the binding a preview is created with.
	 *
	 * @return array<string,mixed>
	 */
	private function context() {
		return array(
			'user_id'         => 7,
			'object_type'     => 'post',
			'source_id'       => '10',
			'target_id'       => '11',
			'surface'         => SuggestionSurface::POST_TITLE,
			'source_language' => 'en',
			'target_language' => 'tr',
		);
	}

	/**
	 * Creates and stores a preview.
	 *
	 * @return SuggestionPreview
	 */
	private function create() {
		$preview = $this->store->create(
			new SuggestionResult( 'Merhaba dunya', 'wordpress-ai' ),
			$this->context()
		);

		$this->assertInstanceOf( SuggestionPreview::class, $preview );

		return $preview;
	}

	/**
	 * Asserts a stored preview can be read back intact.
	 *
	 * @return void
	 */
	public function test_a_stored_preview_round_trips() {
		$preview = $this->create();
		$found   = $this->store->find( $preview->token() );

		$this->assertInstanceOf( SuggestionPreview::class, $found );
		$this->assertSame( 'Merhaba dunya', $found->text() );
		$this->assertSame( 'wordpress-ai', $found->provider_id() );
		$this->assertSame( '', $found->model() );
		$this->assertSame( 'post', $found->object_type() );
		$this->assertSame( '11', $found->target_id() );
		$this->assertSame( 7, $found->user_id() );
	}

	/**
	 * Asserts the token is long, opaque and not reused.
	 *
	 * @return void
	 */
	public function test_tokens_are_opaque_and_unique() {
		$tokens = array();

		/*
		 * Every one of these previews has an identical binding, so a token
		 * derived from the user, object, field or language in any way -- a
		 * hash, a counter, a timestamp -- would repeat or run in sequence.
		 * Fifty distinct values is the property worth asserting.
		 *
		 * A substring check against the binding's own values would be the
		 * obvious test and is the wrong one: a random 32-character
		 * alphanumeric string contains the digit "7" roughly two times in
		 * five, so such a test fails on correct code by chance.
		 */
		for ( $i = 0; $i < 50; $i++ ) {
			$tokens[] = $this->create()->token();
		}

		$this->assertCount( 50, array_unique( $tokens ), 'Tokens must not repeat for an identical binding.' );

		foreach ( $tokens as $token ) {
			$this->assertMatchesRegularExpression( '/^[A-Za-z0-9]{32}$/', $token );
		}

		/*
		 * The distinctive, multi-character facts are still worth checking,
		 * because those cannot appear by accident.
		 */
		foreach ( array( 'post_title', 'wordpress-ai' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $tokens[0] );
		}
	}

	/**
	 * Asserts an unknown token finds nothing.
	 *
	 * @return void
	 */
	public function test_an_unknown_token_finds_nothing() {
		$this->assertNull( $this->store->find( 'not-a-real-token' ) );
		$this->assertNull( $this->store->find( '' ) );
	}

	/**
	 * Asserts a preview expires, and does so at the documented lifetime.
	 *
	 * @return void
	 */
	public function test_a_preview_expires_after_fifteen_minutes() {
		$preview = $this->create();

		$GLOBALS['mclogiora_test_clock_offset'] = SuggestionPreviewStore::LIFETIME - 5;

		$this->assertInstanceOf( SuggestionPreview::class, $this->store->find( $preview->token() ), 'A preview must survive its lifetime.' );

		$GLOBALS['mclogiora_test_clock_offset'] = SuggestionPreviewStore::LIFETIME + 5;

		$this->assertNull( $this->store->find( $preview->token() ), 'An expired preview must be unusable.' );
	}

	/**
	 * Asserts consuming a preview makes it unusable a second time.
	 *
	 * @return void
	 */
	public function test_a_consumed_preview_cannot_be_found_again() {
		$preview = $this->create();

		$this->assertTrue( $this->store->consume( $preview->token() ) );
		$this->assertNull( $this->store->find( $preview->token() ), 'A consumed preview must not be reusable.' );
	}

	/**
	 * Asserts discarding removes the preview.
	 *
	 * @return void
	 */
	public function test_a_discarded_preview_is_gone() {
		$preview = $this->create();

		$this->store->discard( $preview->token() );

		$this->assertNull( $this->store->find( $preview->token() ) );
	}

	/**
	 * Asserts a preview cannot be created without a signed-in user.
	 *
	 * @return void
	 */
	public function test_an_unauthenticated_generation_is_refused() {
		$context            = $this->context();
		$context['user_id'] = 0;

		$result = $this->store->create( new SuggestionResult( 'x', 'wordpress-ai' ), $context );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( array(), $GLOBALS['mclogiora_test_transients'], 'Nothing may be stored without an owner.' );
	}

	/**
	 * Asserts the correct context is accepted.
	 *
	 * @return void
	 */
	public function test_the_original_context_is_accepted() {
		$preview = $this->create();

		$this->assertTrue( $preview->belongs_to( 7, 'post', '11', SuggestionSurface::POST_TITLE, 'tr' ) );
	}

	/**
	 * Asserts every altered fact breaks the binding.
	 *
	 * @param int    $user_id User identifier.
	 * @param string $object_type Object kind.
	 * @param string $target_id Target identifier.
	 * @param string $surface Surface.
	 * @param string $language Target language.
	 * @return void
	 *
	 * @dataProvider provide_mismatched_contexts
	 */
	public function test_a_mismatched_context_is_rejected( $user_id, $object_type, $target_id, $surface, $language ) {
		$preview = $this->create();

		$this->assertFalse( $preview->belongs_to( $user_id, $object_type, $target_id, $surface, $language ) );
	}

	/**
	 * Supplies each single-fact deviation from the bound context.
	 *
	 * One fact changes per row, so a passing row cannot be explained by any
	 * other mismatch.
	 *
	 * @return array<string,array{0:int,1:string,2:string,3:string,4:string}>
	 */
	public function provide_mismatched_contexts() {
		return array(
			'another user'      => array( 8, 'post', '11', SuggestionSurface::POST_TITLE, 'tr' ),
			'another object'    => array( 7, 'post', '12', SuggestionSurface::POST_TITLE, 'tr' ),
			'another field'     => array( 7, 'post', '11', SuggestionSurface::POST_EXCERPT, 'tr' ),
			'another language'  => array( 7, 'post', '11', SuggestionSurface::POST_TITLE, 'de' ),

			/*
			 * The reason object_type is bound at all: without it, term 11 and
			 * post 11 are the same target, and a preview generated for one
			 * could be applied to the other.
			 */
			'another object kind' => array( 7, 'term', '11', SuggestionSurface::POST_TITLE, 'tr' ),

			'source id used as target' => array( 7, 'post', '10', SuggestionSurface::POST_TITLE, 'tr' ),
		);
	}

	/**
	 * Asserts the stored record holds no provider internals.
	 *
	 * The suggestion is kept because the server must be the authority on what
	 * gets written. Nothing else about the exchange is worth keeping, and a
	 * stored prompt or raw response would be a copy of the site's content
	 * sitting in the options table.
	 *
	 * @return void
	 */
	public function test_no_provider_internals_are_stored() {
		$preview = $this->create();
		$stored  = $preview->to_array();

		foreach ( array( 'prompt', 'instructions', 'raw', 'response', 'api_key', 'credential', 'headers' ) as $forbidden ) {
			$this->assertArrayNotHasKey( $forbidden, $stored );
		}

		$this->assertSame(
			array(
				'token',
				'text',
				'provider_id',
				'model',
				'surface',
				'object_type',
				'source_id',
				'target_id',
				'source_language',
				'target_language',
				'user_id',
				'created_at',
				'expires_at',
			),
			array_keys( $stored ),
			'The stored shape is a security surface and should not grow silently.'
		);
	}
}
