<?php
/**
 * String scanner tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Unit;

use McLogiora\Strings\StringScanner;
use McLogiora\Strings\StringSourceType;
use PHPUnit\Framework\TestCase;

/**
 * Covers gettext discovery from PHP source.
 */
final class StringScannerTest extends TestCase {
	/**
	 * Scanner under test.
	 *
	 * @var StringScanner
	 */
	private $scanner;

	/**
	 * Sets up the test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->scanner = new StringScanner();
	}

	/**
	 * Scans a source snippet and returns the discovered texts.
	 *
	 * @param string $source PHP source.
	 * @return array{strings:array<int,array{text:string,domain:string,context:string}>,unresolvable:int}
	 */
	private function scan( $source ) {
		$result = $this->scanner->scan_source( $source, StringSourceType::PLUGIN, 'fixture.php' );
		$found  = array();

		foreach ( $result['strings'] as $string ) {
			$found[] = array(
				'text'    => $string->text(),
				'domain'  => $string->text_domain(),
				'context' => $string->context(),
			);
		}

		return array(
			'strings'      => $found,
			'unresolvable' => $result['unresolvable'],
		);
	}

	/**
	 * Asserts the common gettext functions are recognised.
	 *
	 * @return void
	 */
	public function test_recognises_common_gettext_functions() {
		$source = '<?php
			__( "Simple", "mclogiora" );
			_e( "Echoed", "mclogiora" );
			esc_html__( "Escaped", "mclogiora" );
			esc_attr_e( "Attribute", "mclogiora" );
		';

		$result = $this->scan( $source );
		$texts  = array_column( $result['strings'], 'text' );

		$this->assertContains( 'Simple', $texts );
		$this->assertContains( 'Echoed', $texts );
		$this->assertContains( 'Escaped', $texts );
		$this->assertContains( 'Attribute', $texts );
	}

	/**
	 * Asserts context-aware functions record their context.
	 *
	 * @return void
	 */
	public function test_records_context_for_context_functions() {
		$result = $this->scan( '<?php _x( "Post", "noun", "mclogiora" );' );

		$this->assertCount( 1, $result['strings'] );
		$this->assertSame( 'Post', $result['strings'][0]['text'] );
		$this->assertSame( 'noun', $result['strings'][0]['context'] );
		$this->assertSame( 'mclogiora', $result['strings'][0]['domain'] );
	}

	/**
	 * Asserts plural functions record both forms.
	 *
	 * @return void
	 */
	public function test_records_both_plural_forms() {
		$result = $this->scan( '<?php _n( "One item", "Many items", $count, "mclogiora" );' );
		$texts  = array_column( $result['strings'], 'text' );

		$this->assertContains( 'One item', $texts );
		$this->assertContains( 'Many items', $texts );
	}

	/**
	 * Asserts identical text in different contexts stays separate.
	 *
	 * @return void
	 */
	public function test_same_text_in_different_contexts_is_not_collapsed() {
		$source = '<?php
			_x( "Order", "noun", "mclogiora" );
			_x( "Order", "verb", "mclogiora" );
		';

		$result   = $this->scan( $source );
		$contexts = array_column( $result['strings'], 'context' );

		$this->assertCount( 2, $result['strings'], 'Context changes meaning, so the strings must stay separate.' );
		$this->assertContains( 'noun', $contexts );
		$this->assertContains( 'verb', $contexts );
	}

	/**
	 * Asserts dynamic arguments are reported rather than guessed.
	 *
	 * @return void
	 */
	public function test_dynamic_arguments_are_reported_as_unresolvable() {
		$result = $this->scan( '<?php __( $label, "mclogiora" );' );

		$this->assertSame( array(), $result['strings'], 'A runtime value must never be recorded as a source string.' );
		$this->assertGreaterThan( 0, $result['unresolvable'] );
	}

	/**
	 * Asserts concatenated arguments are not recorded.
	 *
	 * @return void
	 */
	public function test_concatenated_arguments_are_not_recorded() {
		$result = $this->scan( '<?php __( "Hello " . $name, "mclogiora" );' );

		$this->assertSame( array(), $result['strings'] );
		$this->assertGreaterThan( 0, $result['unresolvable'] );
	}

	/**
	 * Asserts calls inside comments and strings are ignored.
	 *
	 * @return void
	 */
	public function test_ignores_calls_inside_comments_and_strings() {
		$source = '<?php
			// __( "Commented", "mclogiora" );
			/* __( "Block commented", "mclogiora" ); */
			$sql = \'__( "Inside a string", "mclogiora" )\';
			__( "Real", "mclogiora" );
		';

		$result = $this->scan( $source );
		$texts  = array_column( $result['strings'], 'text' );

		$this->assertSame( array( 'Real' ), $texts, 'Token parsing must not match commented or quoted code.' );
	}

	/**
	 * Asserts method calls with matching names are ignored.
	 *
	 * @return void
	 */
	public function test_ignores_method_calls_with_matching_names() {
		$result = $this->scan( '<?php $translator->__( "Not gettext", "mclogiora" ); Helper::__( "Also not", "x" );' );

		$this->assertSame( array(), $result['strings'] );
	}

	/**
	 * Asserts single-quoted escapes are decoded.
	 *
	 * @return void
	 */
	public function test_decodes_single_quoted_escapes() {
		$result = $this->scan( "<?php __( 'It\\'s here', 'mclogiora' );" );

		$this->assertCount( 1, $result['strings'] );
		$this->assertSame( "It's here", $result['strings'][0]['text'] );
	}

	/**
	 * Asserts an identical call twice yields one string.
	 *
	 * @return void
	 */
	public function test_duplicate_calls_are_deduplicated() {
		$result = $this->scan( '<?php __( "Same", "mclogiora" ); __( "Same", "mclogiora" );' );

		$this->assertCount( 1, $result['strings'] );
	}

	/**
	 * Asserts a missing text domain is still recorded.
	 *
	 * @return void
	 */
	public function test_records_strings_without_a_text_domain() {
		$result = $this->scan( '<?php __( "No domain" );' );

		$this->assertCount( 1, $result['strings'] );
		$this->assertSame( '', $result['strings'][0]['domain'] );
	}

	/**
	 * Asserts malformed source does not throw.
	 *
	 * @return void
	 */
	public function test_malformed_source_is_handled_safely() {
		$result = $this->scan( '<?php __( "Unclosed", ' );

		$this->assertIsArray( $result['strings'] );
	}
}
