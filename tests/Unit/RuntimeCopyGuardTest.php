<?php
/**
 * Runtime copy guard.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Prevents internal delivery language from returning to shipped UI strings.
 */
final class RuntimeCopyGuardTest extends TestCase {
	/**
	 * Prohibited development-language patterns.
	 *
	 * These are intentionally specific. The guard does not ban legitimate
	 * technical words such as "prompt" in provider protocol fields or "phase"
	 * in internal documentation.
	 *
	 * @var string[]
	 */
	private $patterns = array(
		'/ready\s+for\s+prompt\s+\d+/i',
		'/phase\s+\d+\s+is\s+active/i',
		'/no\s+settings\s+are\s+registered\s+in\s+phase/i',
		'/future\s+editor\s+support/i',
		'/placeholder\s+for\s+a\s+future\s+phase/i',
		'/in\s+a\s+later\s+phase/i',
		'/planned\s+as\s+a\s+future/i',
		'/foundation\s+screen/i',
	);

	/**
	 * Scans runtime/admin sources while ignoring internal PHP comments.
	 *
	 * @return void
	 */
	public function test_prohibited_development_copy_is_absent_from_runtime_sources() {
		$root  = dirname( __DIR__, 2 );
		$files = array_merge(
			$this->files_under( $root . '/src', '*.php' ),
			$this->files_under( $root . '/assets', '*.js' ),
			$this->files_under( $root . '/assets', '*.css' ),
			array( $root . '/mclogiora.php' )
		);
		$violations = array();

		foreach ( $files as $file ) {
			$source = $this->runtime_text( $file );

			foreach ( $this->patterns as $pattern ) {
				if ( preg_match( $pattern, $source ) ) {
					$violations[] = $file . ' matches ' . $pattern;
				}
			}
		}

		$this->assertSame( array(), $violations, implode( "\n", $violations ) );
	}

	/**
	 * Returns files matching a simple extension pattern.
	 *
	 * @param string $directory Directory.
	 * @param string $pattern File pattern.
	 * @return string[]
	 */
	private function files_under( $directory, $pattern ) {
		$extension = strtolower( ltrim( $pattern, '*.' ) );
		$files    = array();

		if ( ! is_dir( $directory ) ) {
			return $files;
		}

		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $directory ) );

		foreach ( $iterator as $file ) {
			if ( $file->isFile() && strtolower( $file->getExtension() ) === $extension ) {
				$files[] = $file->getPathname();
			}
		}

		sort( $files );

		return $files;
	}

	/**
	 * Removes PHP comments before scanning visible PHP literals and markup.
	 *
	 * @param string $file File path.
	 * @return string
	 */
	private function runtime_text( $file ) {
		$source = (string) file_get_contents( $file );

		if ( '.php' !== strtolower( (string) pathinfo( $file, PATHINFO_EXTENSION ) ) ) {
			return $source;
		}

		$text = '';

		foreach ( token_get_all( $source ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}

			$text .= is_array( $token ) ? $token[1] : $token;
		}

		return $text;
	}
}
