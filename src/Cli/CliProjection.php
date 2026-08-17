<?php
/**
 * Shared WP-CLI argument parsing and output.
 *
 * @package McLogiora
 */

namespace McLogiora\Cli;

use McLogiora\Api\PublicApi;
use McLogiora\Relations\ContentType;
use McLogiora\Relations\TranslationStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Turns command arguments into domain input, and domain output into rows.
 *
 * Collected here so the three commands cannot drift on what an object type is,
 * what counts as an identifier, or which fields a relation row publishes. The
 * field names match the REST and `PublicApi` vocabulary exactly: an operator
 * who has read one interface should not have to relearn `native_name` as
 * `label` because a table looked nicer that way.
 */
final class CliProjection {
	const ITEM_FIELDS = array( 'language', 'object_id', 'object_type', 'status', 'is_source', 'url' );

	const LANGUAGE_FIELDS = array(
		'code',
		'locale',
		'tag',
		'native_name',
		'english_name',
		'direction',
		'is_active',
		'is_default',
		'order',
		'home_url',
	);

	/**
	 * Public read API.
	 *
	 * @var PublicApi
	 */
	private $api;

	/**
	 * Constructor.
	 *
	 * @param PublicApi $api Public read API.
	 */
	public function __construct( PublicApi $api ) {
		$this->api = $api;
	}

	/**
	 * Returns the read API.
	 *
	 * @return PublicApi
	 */
	public function api() {
		return $this->api;
	}

	/**
	 * Returns a validated object type, or exits with an error.
	 *
	 * Checked against the enumerated set rather than `ContentType::is_valid()`,
	 * which only tests the shape of an identifier and accepts any lowercase
	 * token.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public function object_type( $value ) {
		$value = sanitize_key( (string) $value );

		if ( ! in_array( $value, ContentType::all(), true ) ) {
			$this->fail(
				sprintf(
					/* translators: 1: supplied object type, 2: comma-separated list of valid types. */
					__( 'Unknown object type "%1$s". Expected one of: %2$s.', 'mclogiora' ),
					$value,
					implode( ', ', ContentType::all() )
				)
			);
		}

		return $value;
	}

	/**
	 * Returns a validated positive identifier, or exits with an error.
	 *
	 * Rejected rather than cast. `absint( 'nonsense' )` is zero, and zero
	 * becomes a lookup for whatever the storage layer makes of it.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public function object_id( $value ) {
		if ( ! is_scalar( $value ) || ! is_numeric( $value ) ) {
			$this->fail( __( 'The object identifier must be a positive integer.', 'mclogiora' ) );
		}

		$number = (float) $value;

		if ( $number <= 0 || floor( $number ) !== $number ) {
			$this->fail( __( 'The object identifier must be a positive integer.', 'mclogiora' ) );
		}

		return (int) $number;
	}

	/**
	 * Returns a validated configured language code, or exits with an error.
	 *
	 * Checked against what the site actually has rather than a CLI-only
	 * pattern, so the command cannot accept a language the domain does not.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public function language( $value ) {
		$value = sanitize_key( (string) $value );
		$known = false;

		foreach ( $this->api->languages( array( 'status' => 'all' ) ) as $language ) {
			if ( (string) $language['code'] === $value ) {
				$known = true;
				break;
			}
		}

		if ( ! $known ) {
			$this->fail(
				sprintf(
					/* translators: %s: supplied language code. */
					__( 'The language "%s" is not configured on this site.', 'mclogiora' ),
					$value
				)
			);
		}

		return $value;
	}

	/**
	 * Returns a validated translation status, or exits with an error.
	 *
	 * Only the canonical vocabulary is accepted. Friendlier aliases such as
	 * "done" or "approved" are deliberately absent: a status that exists on one
	 * transport and not another is how an operator learns two vocabularies for
	 * one concept.
	 *
	 * Being a real status does not make a transition legal. That is the
	 * workflow's answer, and this only checks the word.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public function status( $value ) {
		$value = sanitize_key( (string) $value );

		if ( ! TranslationStatus::is_valid( $value ) ) {
			$this->fail(
				sprintf(
					/* translators: 1: supplied status, 2: comma-separated list of valid statuses. */
					__( 'Unknown status "%1$s". Expected one of: %2$s.', 'mclogiora' ),
					$value,
					implode( ', ', TranslationStatus::all() )
				)
			);
		}

		return $value;
	}

	/**
	 * Aborts with a workflow refusal, keeping the domain code visible.
	 *
	 * The message is written for a person, but the code is appended so the same
	 * refusal stays identifiable whether it arrives from CLI, REST or an admin
	 * screen. Workflow messages name no table, class, query or path, so they are
	 * safe to print as they are.
	 *
	 * @param \WP_Error $error Workflow error.
	 * @return void
	 */
	public function fail_from_workflow( \WP_Error $error ) {
		$this->fail( $error->get_error_message() . ' (' . $error->get_error_code() . ')' );
	}

	/**
	 * Projects a relation item onto a CLI row.
	 *
	 * @param array<string,mixed> $item Relation item projection.
	 * @param string              $taxonomy Taxonomy name, for term URLs.
	 * @return array<string,mixed>
	 */
	public function item_row( array $item, $taxonomy ) {
		$type = (string) $item['object_type'];
		$url  = null;

		if ( ContentType::POST === $type || ( ContentType::TERM === $type && '' !== $taxonomy ) ) {
			$url = $this->api->language_url( (string) $item['language'], (int) $item['object_id'], $type, $taxonomy );
		}

		return array(
			'language'    => (string) $item['language'],
			'object_id'   => (int) $item['object_id'],
			'object_type' => $type,
			'status'      => (string) $item['status'],
			'is_source'   => $item['is_source'] ? 'yes' : 'no',
			'url'         => null === $url ? '' : $url,
		);
	}

	/**
	 * Projects a language onto a CLI row.
	 *
	 * @param array<string,mixed> $language Language projection.
	 * @return array<string,mixed>
	 */
	public function language_row( array $language ) {
		$home = $this->api->language_url( (string) $language['code'] );

		return array(
			'code'         => (string) $language['code'],
			'locale'       => (string) $language['locale'],
			'tag'          => (string) $language['tag'],
			'native_name'  => (string) $language['native_name'],
			'english_name' => (string) $language['english_name'],
			'direction'    => (string) $language['direction'],
			'is_active'    => $language['is_active'] ? 'yes' : 'no',
			'is_default'   => $language['is_default'] ? 'yes' : 'no',
			'order'        => (int) $language['order'],
			'home_url'     => null === $home ? '' : $home,
		);
	}

	/**
	 * Returns the requested field list, restricted to the published set.
	 *
	 * @param array<string,mixed> $options Command options.
	 * @param string[]            $available Publishable fields.
	 * @return string[]
	 */
	public function fields( array $options, array $available ) {
		if ( empty( $options['fields'] ) ) {
			return $available;
		}

		$requested = array_filter( array_map( 'trim', explode( ',', (string) $options['fields'] ) ) );
		$fields    = array_values( array_intersect( $requested, $available ) );

		if ( empty( $fields ) ) {
			$this->fail(
				sprintf(
					/* translators: %s: comma-separated list of valid field names. */
					__( 'No known fields requested. Available fields: %s.', 'mclogiora' ),
					implode( ', ', $available )
				)
			);
		}

		return $fields;
	}

	/**
	 * Prints rows through WP-CLI's own formatter.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows.
	 * @param string[]                       $fields Field names.
	 * @param array<string,mixed>            $options Command options.
	 * @return void
	 */
	public function render( array $rows, array $fields, array $options ) {
		/*
		 * WP-CLI's own formatter, so `--format` behaves exactly as it does for
		 * every core command and mcLogiora ships no table renderer of its own.
		 *
		 * `fields` is deliberately not passed through from the options: the
		 * caller has already validated it against the published set, and
		 * leaving the raw value in place would let the formatter print a column
		 * for a field that does not exist.
		 */
		$args = array( 'format' => isset( $options['format'] ) ? (string) $options['format'] : 'table' );

		$formatter = new \WP_CLI\Formatter( $args, $fields );

		$formatter->display_items( $rows );
	}

	/**
	 * Aborts the command with a message and a non-zero exit status.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public function fail( $message ) {
		\WP_CLI::error( $message );
	}
}
