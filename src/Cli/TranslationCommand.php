<?php
/**
 * Translation lookup commands.
 *
 * @package McLogiora
 */

namespace McLogiora\Cli;

use McLogiora\Api\PublicApi;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves one translation of an object.
 *
 * Strictly a lookup. A missing translation is an error with a non-zero exit
 * status, never an invitation to create one: `wp mclogiora translation get`
 * that quietly created a draft would be a surprising thing for a `get` to do,
 * and creation belongs to a later slice with its own argument for why it is
 * safe.
 */
final class TranslationCommand {
	/**
	 * Shared parsing and output.
	 *
	 * @var CliProjection
	 */
	private $projection;

	/**
	 * Constructor.
	 *
	 * @param PublicApi $api Public read API.
	 */
	public function __construct( PublicApi $api ) {
		$this->projection = new CliProjection( $api );
	}

	/**
	 * Resolves an object's translation in one language.
	 *
	 * ## OPTIONS
	 *
	 * <object-type>
	 * : Type of the object, such as post or term.
	 *
	 * <object-id>
	 * : Identifier of the object.
	 *
	 * <language>
	 * : Language code of the translation to resolve.
	 *
	 * [--taxonomy=<taxonomy>]
	 * : Taxonomy name. Needed to resolve URLs for terms.
	 *
	 * [--fields=<fields>]
	 * : Comma-separated list of fields to show.
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # The Turkish translation of a post.
	 *     $ wp mclogiora translation get post 42 tr
	 *
	 *     # Just the identifier, for scripting.
	 *     $ wp mclogiora translation get post 42 tr --fields=object_id --format=csv
	 *
	 * @param array<int,string>   $args Positional arguments.
	 * @param array<string,mixed> $options Associative arguments.
	 * @return void
	 */
	public function get( array $args, array $options ) {
		$object_type = $this->projection->object_type( isset( $args[0] ) ? $args[0] : '' );
		$object_id   = $this->projection->object_id( isset( $args[1] ) ? $args[1] : '' );
		$language    = $this->projection->language( isset( $args[2] ) ? $args[2] : '' );
		$taxonomy    = isset( $options['taxonomy'] ) ? sanitize_key( (string) $options['taxonomy'] ) : '';

		$group = $this->projection->api()->translation_group( $object_id, $object_type );

		if ( null === $group ) {
			$this->projection->fail( __( 'That object does not belong to a translation group.', 'mclogiora' ) );
		}

		if ( ! isset( $group['translations'][ $language ] ) ) {
			$this->projection->fail(
				sprintf(
					/* translators: %s: language code. */
					__( 'That object has no translation in "%s".', 'mclogiora' ),
					$language
				)
			);
		}

		$this->projection->render(
			array( $this->projection->item_row( $group['translations'][ $language ], $taxonomy ) ),
			$this->projection->fields( $options, CliProjection::ITEM_FIELDS ),
			$options
		);
	}
}
