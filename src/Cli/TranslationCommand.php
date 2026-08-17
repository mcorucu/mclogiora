<?php
/**
 * Translation lookup commands.
 *
 * @package McLogiora
 */

namespace McLogiora\Cli;

use McLogiora\Api\PublicApi;
use McLogiora\Workflows\TranslationWorkflowService;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves one translation of an object.
 *
 * `get` is strictly a lookup. A missing translation is an error with a non-zero
 * exit status, never an invitation to create one: a `get` that quietly created
 * a draft would be a surprising thing for a `get` to do, and creation belongs
 * to a later slice with its own argument for why it is safe.
 *
 * `status` moves an existing translation through the lifecycle by calling
 * `TranslationWorkflowService::change_status()` — the same method REST and the
 * admin screens call. Which transitions are legal is that service's answer;
 * this command only reports it.
 */
final class TranslationCommand {
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

	/**
	 * Moves a translation to a new status.
	 *
	 * ## OPTIONS
	 *
	 * <object-type>
	 * : Type of the object, such as post or term.
	 *
	 * <object-id>
	 * : Identifier of the translated object.
	 *
	 * <language>
	 * : Language code of the translation.
	 *
	 * <status>
	 * : Status to move to.
	 *
	 * ## EXAMPLES
	 *
	 *     # Send a draft translation for review.
	 *     $ wp mclogiora translation status post 77 tr needs_review --user=admin
	 *
	 * Requires a WordPress user allowed to manage translations. Pass one with
	 * the global --user flag; without it there is no current user and the
	 * workflow refuses.
	 *
	 * @param array<int,string>   $args Positional arguments.
	 * @param array<string,mixed> $options Associative arguments.
	 * @return void
	 */
	public function status( array $args, array $options ) {
		unset( $options );

		if ( ! $this->workflows instanceof TranslationWorkflowService ) {
			$this->projection->fail( __( 'Translation workflows are unavailable.', 'mclogiora' ) );
		}

		$object_type = $this->projection->object_type( isset( $args[0] ) ? $args[0] : '' );
		$object_id   = $this->projection->object_id( isset( $args[1] ) ? $args[1] : '' );
		$language    = $this->projection->language( isset( $args[2] ) ? $args[2] : '' );
		$status      = $this->projection->status( isset( $args[3] ) ? $args[3] : '' );

		$result = $this->workflows->change_status( $object_type, $object_id, $language, $status );

		if ( is_wp_error( $result ) ) {
			$this->projection->fail_from_workflow( $result );
		}

		\WP_CLI::success(
			sprintf(
				/* translators: 1: object type, 2: object identifier, 3: language code, 4: new status. */
				__( '%1$s %2$d in %3$s is now %4$s.', 'mclogiora' ),
				$object_type,
				$object_id,
				$language,
				$status
			)
		);
	}
}
