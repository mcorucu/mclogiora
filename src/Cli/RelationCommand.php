<?php
/**
 * Translation relation inspection commands.
 *
 * @package McLogiora
 */

namespace McLogiora\Cli;

use McLogiora\Api\PublicApi;

defined( 'ABSPATH' ) || exit;

/**
 * Inspects the translation group an object belongs to.
 *
 * Unlike the REST equivalent this returns object IDs whatever state those
 * objects are in, drafts and private posts included. That difference is
 * deliberate: REST is gated because anonymous HTTP callers exist, and running
 * `wp` means shell access to the server, which is already more privileged than
 * any WordPress role. Withholding an ID from someone who can read the database
 * directly would be theatre. Secrets and internals stay out regardless.
 */
final class RelationCommand {
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
	 * Shows the translation group an object belongs to.
	 *
	 * ## OPTIONS
	 *
	 * <object-type>
	 * : Type of the object, such as post or term.
	 *
	 * <object-id>
	 * : Identifier of the object.
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
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Every language a post is related in.
	 *     $ wp mclogiora relation get post 42
	 *
	 *     # A term's group, with resolvable URLs.
	 *     $ wp mclogiora relation get term 7 --taxonomy=category
	 *
	 * @param array<int,string>   $args Positional arguments.
	 * @param array<string,mixed> $options Associative arguments.
	 * @return void
	 */
	public function get( array $args, array $options ) {
		$object_type = $this->projection->object_type( isset( $args[0] ) ? $args[0] : '' );
		$object_id   = $this->projection->object_id( isset( $args[1] ) ? $args[1] : '' );
		$taxonomy    = isset( $options['taxonomy'] ) ? sanitize_key( (string) $options['taxonomy'] ) : '';

		$group = $this->projection->api()->translation_group( $object_id, $object_type );

		if ( null === $group ) {
			$this->projection->fail( __( 'That object does not belong to a translation group.', 'mclogiora' ) );
		}

		$rows = array();

		foreach ( $group['translations'] as $item ) {
			$rows[] = $this->projection->item_row( $item, $taxonomy );
		}

		$this->projection->render(
			$rows,
			$this->projection->fields( $options, CliProjection::ITEM_FIELDS ),
			$options
		);
	}
}
