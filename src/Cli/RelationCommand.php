<?php
/**
 * Translation relation inspection commands.
 *
 * @package McLogiora
 */

namespace McLogiora\Cli;

use McLogiora\Api\PublicApi;
use McLogiora\Relations\ContentType;
use McLogiora\Workflows\TranslationWorkflowService;

defined( 'ABSPATH' ) || exit;

/**
 * Inspects and edits the membership of a translation group.
 *
 * Membership is what these commands change. `unlink` removes a translation
 * relation; it never deletes the WordPress post or term, which keeps every
 * field it had. Deleting content is WordPress's own job and is not reachable
 * from this namespace.
 *
 * Posts and terms share the transport but not a code path: the commands branch
 * once on the object type and call the content or the taxonomy workflow, the
 * same ones REST calls. The checks that differ between them -- post type
 * against post type, taxonomy against taxonomy -- live there.
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
	 * Translation workflow service, or null when unavailable.
	 *
	 * @var TranslationWorkflowService|null
	 */
	private $workflows;

	/**
	 * Constructor.
	 *
	 * @param PublicApi                       $api Public read API.
	 * @param TranslationWorkflowService|null $workflows Workflow service.
	 */
	public function __construct( PublicApi $api, $workflows = null ) {
		$this->projection = new CliProjection( $api );
		$this->workflows  = $workflows instanceof TranslationWorkflowService ? $workflows : null;
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

	/**
	 * Links an object that already exists into a translation group.
	 *
	 * ## OPTIONS
	 *
	 * <object-type>
	 * : Type of the objects. Only post and term can hold membership.
	 *
	 * <source-id>
	 * : Identifier of the object whose group the target joins.
	 *
	 * <target-id>
	 * : Identifier of the existing object to link as a translation.
	 *
	 * <language>
	 * : Language the target represents.
	 *
	 * [--taxonomy=<taxonomy>]
	 * : Taxonomy name. Required when linking terms.
	 *
	 * ## EXAMPLES
	 *
	 *     # Link an existing page as the Turkish translation of another.
	 *     $ wp mclogiora relation link post 42 77 tr --user=admin
	 *
	 *     # Link an existing category term.
	 *     $ wp mclogiora relation link term 5 9 tr --taxonomy=category --user=admin
	 *
	 * Creates nothing: both objects must already exist. Requires a WordPress
	 * user allowed to manage translations, passed with the global --user flag.
	 *
	 * @param array<int,string>   $args Positional arguments.
	 * @param array<string,mixed> $options Associative arguments.
	 * @return void
	 */
	public function link( array $args, array $options ) {
		$workflows   = $this->workflow_service();
		$object_type = $this->membership_type( isset( $args[0] ) ? $args[0] : '' );
		$source_id   = $this->projection->object_id( isset( $args[1] ) ? $args[1] : '' );
		$target_id   = $this->projection->object_id( isset( $args[2] ) ? $args[2] : '' );
		$language    = $this->projection->language( isset( $args[3] ) ? $args[3] : '' );
		$taxonomy    = isset( $options['taxonomy'] ) ? sanitize_key( (string) $options['taxonomy'] ) : '';

		if ( ContentType::TERM === $object_type ) {
			if ( '' === $taxonomy ) {
				$this->projection->fail( __( 'A --taxonomy is required when linking terms.', 'mclogiora' ) );
			}

			$result = $workflows->taxonomy()->link_existing( $source_id, $taxonomy, $target_id, $language );
		} else {
			$result = $workflows->content()->link_existing( $source_id, $target_id, $language );
		}

		if ( is_wp_error( $result ) ) {
			$this->projection->fail_from_workflow( $result );
		}

		\WP_CLI::success(
			sprintf(
				/* translators: 1: object type, 2: target identifier, 3: language code, 4: source identifier. */
				__( 'Linked %1$s %2$d as the %3$s translation of %1$s %4$d.', 'mclogiora' ),
				$object_type,
				$target_id,
				$language,
				$source_id
			)
		);
	}

	/**
	 * Detaches an object from its translation group.
	 *
	 * ## OPTIONS
	 *
	 * <object-type>
	 * : Type of the object. Only post and term can hold membership.
	 *
	 * <object-id>
	 * : Identifier of the object to detach.
	 *
	 * <language>
	 * : Language slot the object occupies.
	 *
	 * ## EXAMPLES
	 *
	 *     # Detach a translation, leaving the page itself alone.
	 *     $ wp mclogiora relation unlink post 77 tr --user=admin
	 *
	 * This removes translation relation membership only. The WordPress post or
	 * term is not deleted and keeps every field it had. Requires a WordPress
	 * user allowed to manage translations, passed with the global --user flag.
	 *
	 * @param array<int,string>   $args Positional arguments.
	 * @param array<string,mixed> $options Associative arguments.
	 * @return void
	 */
	public function unlink( array $args, array $options ) {
		unset( $options );

		$workflows   = $this->workflow_service();
		$object_type = $this->membership_type( isset( $args[0] ) ? $args[0] : '' );
		$object_id   = $this->projection->object_id( isset( $args[1] ) ? $args[1] : '' );
		$language    = $this->projection->language( isset( $args[2] ) ? $args[2] : '' );

		$result = ContentType::TERM === $object_type
			? $workflows->taxonomy()->unlink( $object_id, $language )
			: $workflows->content()->unlink( $object_id, $language );

		if ( is_wp_error( $result ) ) {
			$this->projection->fail_from_workflow( $result );
		}

		\WP_CLI::success(
			sprintf(
				/* translators: 1: object type, 2: object identifier, 3: language code. */
				__( 'Detached %1$s %2$d from its %3$s translation slot. The %1$s itself was not deleted.', 'mclogiora' ),
				$object_type,
				$object_id,
				$language
			)
		);
	}

	/**
	 * Returns the workflow service, or exits when it is unavailable.
	 *
	 * @return TranslationWorkflowService
	 */
	private function workflow_service() {
		if ( ! $this->workflows instanceof TranslationWorkflowService ) {
			$this->projection->fail( __( 'Translation workflows are unavailable.', 'mclogiora' ) );
		}

		return $this->workflows;
	}

	/**
	 * Returns an object type that can hold membership, or exits.
	 *
	 * Only posts and terms have link and unlink workflows. This is not the CLI
	 * narrowing the domain vocabulary; it is the CLI declaring which of the
	 * domain's operations exist.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private function membership_type( $value ) {
		$value   = $this->projection->object_type( $value );
		$allowed = array( ContentType::POST, ContentType::TERM );

		if ( ! in_array( $value, $allowed, true ) ) {
			$this->projection->fail(
				sprintf(
					/* translators: 1: supplied object type, 2: comma-separated list of valid types. */
					__( 'Membership is not supported for "%1$s". Expected one of: %2$s.', 'mclogiora' ),
					$value,
					implode( ', ', $allowed )
				)
			);
		}

		return $value;
	}
}
