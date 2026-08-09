<?php
/**
 * In-memory string repository for tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Support;

use McLogiora\Strings\StringRepositoryInterface;
use McLogiora\Strings\StringSource;
use McLogiora\Strings\StringTranslation;

/**
 * Stores strings and translations in memory with the same identity rules as
 * the database repository.
 */
final class FakeStringRepository implements StringRepositoryInterface {
	/**
	 * Strings keyed by hash.
	 *
	 * @var array<string,StringSource>
	 */
	private $strings = array();

	/**
	 * Translations keyed by "string_id:language".
	 *
	 * @var array<string,StringTranslation>
	 */
	private $translations = array();

	/**
	 * Next identifier.
	 *
	 * @var int
	 */
	private $sequence = 0;

	/**
	 * Number of read lookups performed.
	 *
	 * @var int
	 */
	private $lookups = 0;

	/**
	 * Returns how many read lookups this repository has been asked for.
	 *
	 * Lets a test assert that a code path never reached the storage layer at
	 * all, which is stronger than asserting it returned the right value.
	 *
	 * @return int
	 */
	public function lookup_count() {
		return $this->lookups;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param StringSource $source Source string.
	 * @return StringSource|\WP_Error
	 */
	public function register( StringSource $source ) {
		$existing = $this->find_by_hash( $source->hash() );

		if ( $existing instanceof StringSource ) {
			$refreshed = new StringSource(
				$existing->id(),
				$existing->text(),
				$existing->text_domain(),
				$existing->context(),
				$source->source_type(),
				$source->source_reference(),
				$source->source_line(),
				false
			);

			$this->strings[ $source->hash() ] = $refreshed;

			return $refreshed;
		}

		++$this->sequence;

		$stored = new StringSource(
			$this->sequence,
			$source->text(),
			$source->text_domain(),
			$source->context(),
			$source->source_type(),
			$source->source_reference(),
			$source->source_line(),
			false
		);

		$this->strings[ $source->hash() ] = $stored;

		return $stored;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $hash Identity hash.
	 * @return StringSource|null
	 */
	public function find_by_hash( $hash ) {
		++$this->lookups;

		return isset( $this->strings[ (string) $hash ] ) ? $this->strings[ (string) $hash ] : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $id Internal identifier.
	 * @return StringSource|null
	 */
	public function find( $id ) {
		foreach ( $this->strings as $string ) {
			if ( $string->id() === (int) $id ) {
				return $string;
			}
		}

		return null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string,mixed> $filters Filters.
	 * @return StringSource[]
	 */
	public function query( array $filters = array() ) {
		$found = array();

		foreach ( $this->strings as $string ) {
			if ( ! empty( $filters['text_domain'] ) && $string->text_domain() !== $filters['text_domain'] ) {
				continue;
			}

			if ( ! empty( $filters['source_type'] ) && $string->source_type() !== $filters['source_type'] ) {
				continue;
			}

			if ( ! empty( $filters['search'] ) && false === strpos( $string->text(), (string) $filters['search'] ) ) {
				continue;
			}

			$found[] = $string;
		}

		return $found;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return int
	 */
	public function count_strings() {
		return count( $this->strings );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $source_type Source type.
	 * @param string $source_reference Reference prefix.
	 * @return int
	 */
	public function mark_scope_stale( $source_type, $source_reference = '' ) {
		$marked = 0;

		foreach ( $this->strings as $hash => $string ) {
			if ( $string->source_type() !== (string) $source_type ) {
				continue;
			}

			if ( '' !== (string) $source_reference && 0 !== strpos( $string->source_reference(), (string) $source_reference ) ) {
				continue;
			}

			$this->strings[ $hash ] = new StringSource(
				$string->id(),
				$string->text(),
				$string->text_domain(),
				$string->context(),
				$string->source_type(),
				$string->source_reference(),
				$string->source_line(),
				true
			);

			++$marked;
		}

		return $marked;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param StringTranslation $translation Translation.
	 * @return StringTranslation|\WP_Error
	 */
	public function save_translation( StringTranslation $translation ) {
		$this->translations[ $translation->string_id() . ':' . $translation->language_code() ] = $translation;

		return $translation;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $string_id Source string identifier.
	 * @param string $language_code Language code.
	 * @return StringTranslation|null
	 */
	public function find_translation( $string_id, $language_code ) {
		++$this->lookups;

		$key = (int) $string_id . ':' . (string) $language_code;

		return isset( $this->translations[ $key ] ) ? $this->translations[ $key ] : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $string_id Source string identifier.
	 * @return StringTranslation[]
	 */
	public function translations_for( $string_id ) {
		$found = array();

		foreach ( $this->translations as $translation ) {
			if ( $translation->string_id() === (int) $string_id ) {
				$found[] = $translation;
			}
		}

		return $found;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $string_id Source string identifier.
	 * @param string $language_code Language code.
	 * @return bool|\WP_Error
	 */
	public function delete_translation( $string_id, $language_code ) {
		unset( $this->translations[ (int) $string_id . ':' . (string) $language_code ] );

		return true;
	}
}
