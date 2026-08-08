<?php
/**
 * Gettext string scanner.
 *
 * @package McLogiora
 */

namespace McLogiora\Strings;

defined( 'ABSPATH' ) || exit;

/**
 * Finds translatable strings in PHP source using token parsing.
 *
 * This scanner only ever runs from an explicit, authorised admin action. It
 * never runs on a frontend request, never runs on a schedule, and never
 * writes strings as a side effect of ordinary traffic. Walking a plugin or
 * theme tree is far too expensive to do per request, and doing it implicitly
 * would mean normal visitors could grow the database.
 *
 * Source is tokenised with PHP's own lexer rather than matched with regular
 * expressions, and it is never executed or included. Tokenising is both safer
 * and more accurate: it will not match a call inside a comment or a string
 * literal, and it understands PHP quoting rules.
 *
 * Only statically resolvable literal arguments are recorded. A call such as
 * `__( $label, 'domain' )` cannot be known without running the code, so it is
 * counted as unresolvable and reported honestly rather than guessed at.
 */
final class StringScanner {
	/**
	 * Gettext functions and the argument positions that carry text.
	 *
	 * Positions are zero-based. 'text' lists argument indexes holding
	 * translatable text, 'domain' is the text domain index, and 'context'
	 * is the gettext context index when the function takes one.
	 *
	 * @var array<string,array{text:int[],domain:int,context:int}>
	 */
	private static $functions = array(
		'__'         => array(
			'text'    => array( 0 ),
			'domain'  => 1,
			'context' => -1,
		),
		'_e'         => array(
			'text'    => array( 0 ),
			'domain'  => 1,
			'context' => -1,
		),
		'esc_html__' => array(
			'text'    => array( 0 ),
			'domain'  => 1,
			'context' => -1,
		),
		'esc_html_e' => array(
			'text'    => array( 0 ),
			'domain'  => 1,
			'context' => -1,
		),
		'esc_attr__' => array(
			'text'    => array( 0 ),
			'domain'  => 1,
			'context' => -1,
		),
		'esc_attr_e' => array(
			'text'    => array( 0 ),
			'domain'  => 1,
			'context' => -1,
		),
		'_x'         => array(
			'text'    => array( 0 ),
			'domain'  => 2,
			'context' => 1,
		),
		'_ex'        => array(
			'text'    => array( 0 ),
			'domain'  => 2,
			'context' => 1,
		),
		'esc_html_x' => array(
			'text'    => array( 0 ),
			'domain'  => 2,
			'context' => 1,
		),
		'esc_attr_x' => array(
			'text'    => array( 0 ),
			'domain'  => 2,
			'context' => 1,
		),
		'_n'         => array(
			'text'    => array( 0, 1 ),
			'domain'  => 3,
			'context' => -1,
		),
		'_nx'        => array(
			'text'    => array( 0, 1 ),
			'domain'  => 4,
			'context' => 3,
		),
		'_n_noop'    => array(
			'text'    => array( 0, 1 ),
			'domain'  => 2,
			'context' => -1,
		),
		'_nx_noop'   => array(
			'text'    => array( 0, 1 ),
			'domain'  => 3,
			'context' => 2,
		),
	);

	/**
	 * Directory names never descended into.
	 *
	 * @var string[]
	 */
	private static $skip_directories = array(
		'vendor',
		'node_modules',
		'.git',
		'.svn',
		'cache',
		'caches',
		'dist',
		'build',
		'tests',
		'test',
	);

	/**
	 * Maximum file size to read, in bytes.
	 *
	 * @var int
	 */
	private $max_file_bytes;

	/**
	 * Maximum number of files to read in one scan.
	 *
	 * @var int
	 */
	private $max_files;

	/**
	 * Constructor.
	 *
	 * @param int $max_file_bytes Maximum file size in bytes.
	 * @param int $max_files Maximum files per scan.
	 */
	public function __construct( $max_file_bytes = 1048576, $max_files = 3000 ) {
		$this->max_file_bytes = (int) $max_file_bytes;
		$this->max_files      = (int) $max_files;
	}

	/**
	 * Scans a confined directory and returns discovered strings.
	 *
	 * @param string $directory Confined absolute directory.
	 * @param string $source_type Source type to record.
	 * @param string $reference_prefix Reference prefix recorded on each string.
	 * @return array{strings:StringSource[],files:int,skipped:int,unresolvable:int}
	 */
	public function scan_directory( $directory, $source_type, $reference_prefix = '' ) {
		$result = array(
			'strings'      => array(),
			'files'        => 0,
			'skipped'      => 0,
			'unresolvable' => 0,
		);

		if ( ! is_dir( $directory ) || ! is_readable( $directory ) ) {
			return $result;
		}

		$found = array();

		foreach ( $this->php_files( $directory ) as $file ) {
			if ( $result['files'] >= $this->max_files ) {
				break;
			}

			$size = @filesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unreadable file must not abort the scan.

			if ( false === $size || $size > $this->max_file_bytes ) {
				++$result['skipped'];
				continue;
			}

			$contents = @file_get_contents( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local source read; an unreadable file must not abort the scan.

			if ( false === $contents ) {
				++$result['skipped'];
				continue;
			}

			++$result['files'];

			$relative = ltrim( str_replace( $directory, '', $file ), DIRECTORY_SEPARATOR );
			$scanned  = $this->scan_source( $contents, $source_type, $this->reference( $reference_prefix, $relative ) );

			$result['unresolvable'] += $scanned['unresolvable'];

			foreach ( $scanned['strings'] as $string ) {
				$found[ $string->hash() ] = $string;
			}
		}

		$result['strings'] = array_values( $found );

		return $result;
	}

	/**
	 * Scans PHP source text and returns discovered strings.
	 *
	 * @param string $source PHP source.
	 * @param string $source_type Source type to record.
	 * @param string $reference Source reference.
	 * @return array{strings:StringSource[],unresolvable:int}
	 */
	public function scan_source( $source, $source_type, $reference = '' ) {
		$strings      = array();
		$unresolvable = 0;
		$tokens       = @token_get_all( (string) $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- malformed third-party source must not abort the scan.
		$count        = count( $tokens );

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];

			if ( ! is_array( $token ) || T_STRING !== $token[0] ) {
				continue;
			}

			$name = $token[1];

			if ( ! isset( self::$functions[ $name ] ) ) {
				continue;
			}

			if ( $this->is_method_or_declaration( $tokens, $i ) ) {
				continue;
			}

			$arguments = $this->read_arguments( $tokens, $i, $count );

			if ( null === $arguments ) {
				++$unresolvable;
				continue;
			}

			$spec    = self::$functions[ $name ];
			$domain  = $this->argument_value( $arguments, $spec['domain'] );
			$context = $spec['context'] >= 0 ? $this->argument_value( $arguments, $spec['context'] ) : '';

			if ( null === $domain || null === $context ) {
				++$unresolvable;
				continue;
			}

			foreach ( $spec['text'] as $position ) {
				$text = $this->argument_value( $arguments, $position );

				if ( null === $text || '' === $text ) {
					++$unresolvable;
					continue;
				}

				$string                     = new StringSource(
					0,
					$text,
					$domain,
					$context,
					$source_type,
					$reference,
					(int) $token[2]
				);
				$strings[ $string->hash() ] = $string;
			}
		}

		return array(
			'strings'      => array_values( $strings ),
			'unresolvable' => $unresolvable,
		);
	}

	/**
	 * Returns whether a token is a method call or a function declaration.
	 *
	 * @param array<int,mixed> $tokens Token list.
	 * @param int              $index Current index.
	 * @return bool
	 */
	private function is_method_or_declaration( array $tokens, $index ) {
		for ( $i = $index - 1; $i >= 0; $i-- ) {
			$previous = $tokens[ $i ];

			if ( is_array( $previous ) && in_array( $previous[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}

			if ( is_array( $previous ) ) {
				return in_array( $previous[0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ), true );
			}

			return false;
		}

		return false;
	}

	/**
	 * Reads the literal arguments of a call, or null when not resolvable.
	 *
	 * Returns null as soon as anything other than a plain literal appears at
	 * the top level of the argument list, because the value then depends on
	 * runtime state this scanner deliberately never evaluates.
	 *
	 * @param array<int,mixed> $tokens Token list.
	 * @param int              $index Function name index.
	 * @param int              $count Token count.
	 * @return array<int,string>|null
	 */
	private function read_arguments( array $tokens, $index, $count ) {
		$i = $index + 1;

		while ( $i < $count && is_array( $tokens[ $i ] ) && T_WHITESPACE === $tokens[ $i ][0] ) {
			++$i;
		}

		if ( $i >= $count || '(' !== $tokens[ $i ] ) {
			return null;
		}

		++$i;
		$depth     = 1;
		$arguments = array();
		$current   = null;
		$dirty     = false;

		for ( ; $i < $count; $i++ ) {
			$token = $tokens[ $i ];

			if ( ! is_array( $token ) ) {
				if ( '(' === $token || '[' === $token ) {
					++$depth;
					$dirty = true;
					continue;
				}

				if ( ')' === $token || ']' === $token ) {
					--$depth;

					if ( 0 === $depth ) {
						$arguments[] = $dirty ? null : $current;

						return $arguments;
					}

					continue;
				}

				if ( ',' === $token && 1 === $depth ) {
					$arguments[] = $dirty ? null : $current;
					$current     = null;
					$dirty       = false;
					continue;
				}

				$dirty = true;
				continue;
			}

			if ( in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}

			if ( T_CONSTANT_ENCAPSED_STRING === $token[0] && 1 === $depth && null === $current && ! $dirty ) {
				$current = $this->unquote( $token[1] );
				continue;
			}

			$dirty = true;
		}

		return null;
	}

	/**
	 * Returns a literal argument value, or null when unresolvable.
	 *
	 * @param array<int,string|null> $arguments Argument values.
	 * @param int                    $position Argument index.
	 * @return string|null
	 */
	private function argument_value( array $arguments, $position ) {
		if ( $position < 0 ) {
			return '';
		}

		if ( ! array_key_exists( $position, $arguments ) ) {
			return '';
		}

		return $arguments[ $position ];
	}

	/**
	 * Removes quoting from a PHP string literal.
	 *
	 * @param string $literal Quoted literal.
	 * @return string
	 */
	private function unquote( $literal ) {
		$literal = (string) $literal;
		$quote   = substr( $literal, 0, 1 );
		$inner   = substr( $literal, 1, -1 );

		if ( "'" === $quote ) {
			return str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $inner );
		}

		return str_replace(
			array( '\\"', '\\n', '\\t', '\\r', '\\\\' ),
			array( '"', "\n", "\t", "\r", '\\' ),
			$inner
		);
	}

	/**
	 * Builds a source reference string.
	 *
	 * @param string $prefix Reference prefix.
	 * @param string $relative Relative file path.
	 * @return string
	 */
	private function reference( $prefix, $relative ) {
		$prefix = trim( (string) $prefix, '/' );

		return '' === $prefix ? (string) $relative : $prefix . '/' . (string) $relative;
	}

	/**
	 * Yields readable PHP files beneath a directory.
	 *
	 * @param string $directory Directory.
	 * @return string[]
	 */
	private function php_files( $directory ) {
		$files = array();
		$stack = array( $directory );

		while ( ! empty( $stack ) ) {
			$current = array_pop( $stack );
			$handle  = @opendir( $current ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unreadable directory must not abort the scan.

			if ( false === $handle ) {
				continue;
			}

			$entry = readdir( $handle );

			while ( false !== $entry ) {
				$name  = $entry;
				$entry = readdir( $handle );

				if ( '.' === $name || '..' === $name ) {
					continue;
				}

				$path = $current . DIRECTORY_SEPARATOR . $name;

				if ( is_dir( $path ) ) {
					if ( in_array( strtolower( $name ), self::$skip_directories, true ) ) {
						continue;
					}

					$stack[] = $path;
					continue;
				}

				if ( 'php' === strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
					$files[] = $path;
				}
			}

			closedir( $handle );
		}

		sort( $files );

		return $files;
	}
}
