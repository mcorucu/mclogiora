<?php
/**
 * Placeholder protection tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Unit;

use McLogiora\Suggestions\PlaceholderShield;
use PHPUnit\Framework\TestCase;

/**
 * Covers the constructs that must survive translation byte-for-byte.
 *
 * This file is an acceptance gate rather than ordinary coverage. Every case
 * here describes a way a translation provider can silently corrupt a site:
 * a lost `%s` is a PHP 8 `ValueError` on the next render, a translated
 * shortcode name renders as literal text, and a mangled URL is a dead link.
 * The shield's job is to make all of that detectable before it can be applied,
 * so a regression in this file is a shipping blocker, not a test failure.
 */
final class PlaceholderShieldTest extends TestCase {
	/**
	 * Returns a shield with a fixed nonce so tokens are predictable.
	 *
	 * @return PlaceholderShield
	 */
	private function shield() {
		return new PlaceholderShield( 'testng' );
	}

	/**
	 * Supplies constructs that must never reach a translation provider.
	 *
	 * @return array<string,array{0:string,1:int}>
	 */
	public function provide_protected_constructs() {
		return array(
			'simple printf'          => array( 'Showing %s results', 1 ),
			'positional printf'      => array( 'Showing %1$s of %2$d results', 2 ),
			'padded positional'      => array( 'Price: %1$04.2f', 1 ),
			'escaped percent'        => array( 'Battery at 80%% capacity', 1 ),
			'repeated placeholder'   => array( 'Hello %s, meet %s', 2 ),
			'named placeholder'      => array( 'Hello {name}, welcome', 1 ),
			'template placeholder'   => array( 'Hello {{ user.name }}, welcome', 1 ),
			'html entity named'      => array( 'Tom &amp; Jerry&nbsp;show', 2 ),
			'html entity numeric'    => array( 'Curly &#8217;quote&#x2019;', 2 ),
			'absolute url'           => array( 'See https://example.com/a?b=1&c=2 for more', 1 ),
			'self closing shortcode' => array( 'Gallery: [gallery id="4" columns="3"]', 1 ),
			'wrapping shortcode'     => array( '[caption]A photo[/caption]', 2 ),
		);
	}

	/**
	 * Asserts every protected construct is removed before a provider sees it.
	 *
	 * @param string $text Source text.
	 * @param int    $expected How many fragments should be protected.
	 * @return void
	 *
	 * @dataProvider provide_protected_constructs
	 */
	public function test_protected_constructs_are_masked( $text, $expected ) {
		$shield = $this->shield();
		$masked = $shield->protect( $text );

		$this->assertCount( $expected, $shield->tokens(), "Wrong number of protected fragments in: {$text}" );

		foreach ( $shield->map() as $token => $original ) {
			$this->assertStringContainsString( $token, $masked );
			$this->assertStringNotContainsString( $original, $masked, "{$original} was left exposed to the provider." );
		}
	}

	/**
	 * Asserts a faithful provider round-trips the text exactly.
	 *
	 * @param string $text Source text.
	 * @return void
	 *
	 * @dataProvider provide_protected_constructs
	 */
	public function test_untouched_tokens_restore_the_original( $text ) {
		$shield = $this->shield();
		$masked = $shield->protect( $text );

		$this->assertSame( array(), $shield->verify( $masked ) );
		$this->assertSame( $text, $shield->restore( $masked ) );
	}

	/**
	 * Asserts a dropped placeholder is refused.
	 *
	 * @return void
	 */
	public function test_a_dropped_placeholder_is_rejected() {
		$shield = $this->shield();
		$masked = $shield->protect( 'Showing %1$s of %2$d results' );
		$tokens = $shield->tokens();

		$broken = str_replace( $tokens[1], '', $masked );

		$problems = $shield->verify( $broken );

		$this->assertNotEmpty( $problems, 'A lost placeholder must never verify clean.' );
		$this->assertStringContainsString( 'removed', strtolower( implode( ' ', $problems ) ) );
	}

	/**
	 * Asserts a duplicated placeholder is refused.
	 *
	 * A duplicate breaks sprintf() exactly as hard as a missing one, so
	 * "at least one is present" is not a sufficient check.
	 *
	 * @return void
	 */
	public function test_a_duplicated_placeholder_is_rejected() {
		$shield = $this->shield();
		$masked = $shield->protect( 'Hello %s' );
		$tokens = $shield->tokens();

		$problems = $shield->verify( $masked . ' ' . $tokens[0] );

		$this->assertNotEmpty( $problems, 'A duplicated placeholder must never verify clean.' );
	}

	/**
	 * Asserts a placeholder the provider invented is refused.
	 *
	 * @return void
	 */
	public function test_an_invented_placeholder_is_rejected() {
		$shield = $this->shield();
		$masked = $shield->protect( 'Hello %s' );

		$problems = $shield->verify( $masked . ' [[MCQ_testng_99]]' );

		$this->assertNotEmpty( $problems, 'An invented placeholder must never verify clean.' );
	}

	/**
	 * Asserts a mutated token is treated as a loss rather than restored.
	 *
	 * A model that "corrects" a token's spelling must not be able to smuggle
	 * scaffolding into published content.
	 *
	 * @return void
	 */
	public function test_a_mutated_token_is_rejected_and_never_restored() {
		$shield = $this->shield();
		$masked = $shield->protect( 'Showing %s results' );
		$tokens = $shield->tokens();

		$mutated = str_replace( $tokens[0], strtolower( $tokens[0] ), $masked );

		$this->assertNotEmpty( $shield->verify( $mutated ), 'A mutated token must never verify clean.' );
		$this->assertStringNotContainsString( '%s', $shield->restore( $mutated ), 'A mutated token must not restore.' );
	}

	/**
	 * Asserts the words around a placeholder are still free to change.
	 *
	 * The shield must protect the slots without freezing the sentence, or it
	 * would defeat the feature it exists to make safe.
	 *
	 * @return void
	 */
	public function test_surrounding_words_remain_translatable() {
		$shield = $this->shield();
		$masked = $shield->protect( 'Showing %1$s of %2$d results' );
		$tokens = $shield->tokens();

		$translated = str_replace(
			array( 'Showing', 'of', 'results' ),
			array( 'Gosterilen', 've', 'sonuc' ),
			$masked
		);

		$this->assertSame( array(), $shield->verify( $translated ) );
		$this->assertSame( 'Gosterilen %1$s ve %2$d sonuc', $shield->restore( $translated ) );
	}

	/**
	 * Asserts tokens cannot collide with text that already looks like one.
	 *
	 * @return void
	 */
	public function test_source_text_resembling_a_token_cannot_confuse_verification() {
		$shield = new PlaceholderShield( 'aaaaaa' );
		$masked = $shield->protect( 'Literal [[MCQ_bbbbbb_0]] and a real %s' );

		$this->assertSame( array(), $shield->verify( $masked ) );
		$this->assertStringContainsString( '[[MCQ_bbbbbb_0]]', $shield->restore( $masked ) );
	}

	/**
	 * Asserts text with nothing to protect is passed through untouched.
	 *
	 * @return void
	 */
	public function test_plain_text_is_left_alone() {
		$shield = $this->shield();

		$this->assertSame( 'Just a sentence.', $shield->protect( 'Just a sentence.' ) );
		$this->assertFalse( $shield->has_placeholders() );
		$this->assertSame( array(), $shield->verify( 'Sadece bir cumle.' ) );
	}

	/**
	 * Asserts a URL is protected whole rather than in pieces.
	 *
	 * A query string can contain sequences the printf pattern would otherwise
	 * match, so the URL has to be taken first or it comes back shredded.
	 *
	 * @return void
	 */
	public function test_a_url_containing_percent_sequences_is_protected_whole() {
		$shield = $this->shield();
		$text   = 'Open https://example.com/search?q=100%25%20off&page=2 now';

		$masked = $shield->protect( $text );

		$this->assertCount( 1, $shield->tokens(), 'The URL must be one fragment, not several.' );
		$this->assertSame( array(), $shield->verify( $masked ) );
		$this->assertSame( $text, $shield->restore( $masked ) );
	}
}
