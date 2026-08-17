<?php
/**
 * Translation lookup commands.
 *
 * @package McLogiora
 */

namespace McLogiora\Cli;

use McLogiora\Api\PublicApi;
use McLogiora\Relations\ContentType;
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
 *
 * `create` makes a new WordPress post or term and relates it. It is the only
 * command in the namespace that brings an object into existence, and it accepts
 * no WordPress field of its own: the workflows own every default. Attaching an
 * object that already exists is `relation link`, a different operation with a
 * different command.
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

	/**
	 * Creates a translation of an existing post or term.
	 *
	 * ## OPTIONS
	 *
	 * <object-type>
	 * : Type of the object to translate. Either post or term.
	 *
	 * <source-id>
	 * : Identifier of the object to translate.
	 *
	 * <language>
	 * : Language the new translation represents.
	 *
	 * [--taxonomy=<taxonomy>]
	 * : Taxonomy name. Required when translating a term.
	 *
	 * [--name=<name>]
	 * : Name for the new term. Required when translating a term.
	 *
	 * [--description=<description>]
	 * : Description for the new term. Empty by default, never copied from the
	 * source.
	 *
	 * ## EXAMPLES
	 *
	 *     # Create a Turkish draft of a page.
	 *     $ wp mclogiora translation create post 42 tr --user=admin
	 *
	 *     # Create a Turkish category term.
	 *     $ wp mclogiora translation create term 5 tr --taxonomy=category --name=Haberler --user=admin
	 *
	 * A created post is always a draft, carrying the source's type, title,
	 * content, excerpt, menu order and author. No title, status, slug, parent or
	 * meta flag exists: the workflow owns those, and a flag for any of them would
	 * make this a clone command wearing a translation label.
	 *
	 * Nothing is machine-translated. A created post starts as a copy of the
	 * source's text and a created term takes the name given here.
	 *
	 * To attach an object that already exists, use `wp mclogiora relation link`.
	 * This command always creates a new one.
	 *
	 * Requires a WordPress user allowed to manage translations. Pass one with the
	 * global --user flag; without it there is no current user and the workflow
	 * refuses.
	 *
	 * @param array<int,string>   $args Positional arguments.
	 * @param array<string,mixed> $options Associative arguments.
	 * @return void
	 */
	public function create( array $args, array $options ) {
		if ( ! $this->workflows instanceof TranslationWorkflowService ) {
			$this->projection->fail( __( 'Translation workflows are unavailable.', 'mclogiora' ) );
		}

		$object_type = $this->creatable_type( isset( $args[0] ) ? $args[0] : '' );
		$source_id   = $this->projection->object_id( isset( $args[1] ) ? $args[1] : '' );
		$language    = $this->projection->language( isset( $args[2] ) ? $args[2] : '' );

		if ( ContentType::TERM === $object_type ) {
			$taxonomy = isset( $options['taxonomy'] ) ? sanitize_key( (string) $options['taxonomy'] ) : '';

			if ( '' === $taxonomy ) {
				$this->projection->fail( __( 'A --taxonomy is required when creating a term translation.', 'mclogiora' ) );
			}

			/*
			 * The name is handed over as given, sanitized the way the admin
			 * screens and REST sanitize it. Whether a blank one is acceptable is
			 * the workflow's answer and it already has a specific error for it;
			 * checking here would replace that answer with a vaguer one.
			 */
			$result = $this->workflows->taxonomy()->create_translation(
				$source_id,
				$taxonomy,
				$language,
				isset( $options['name'] ) ? sanitize_text_field( (string) $options['name'] ) : '',
				isset( $options['description'] ) ? sanitize_textarea_field( (string) $options['description'] ) : ''
			);

			$created = is_wp_error( $result ) ? 0 : (int) $result['term_id'];
		} else {
			$result  = $this->workflows->content()->create_translation( $source_id, $language );
			$created = is_wp_error( $result ) ? 0 : (int) $result['post_id'];
		}

		if ( is_wp_error( $result ) ) {
			$this->projection->fail_from_workflow( $result );
		}

		/*
		 * The workflow also returns an edit link. REST drops it and so does
		 * this: one published vocabulary across transports is worth more than a
		 * convenience field on one of them.
		 */
		\WP_CLI::success(
			sprintf(
				/* translators: 1: object type, 2: created identifier, 3: language code, 4: translation group key. */
				__( 'Created %1$s %2$d as the %3$s translation, in group %4$s.', 'mclogiora' ),
				$object_type,
				$created,
				$language,
				(string) $result['group_key']
			)
		);
	}

	/**
	 * Returns an object type that can be created, or exits.
	 *
	 * Only posts and terms have creation workflows. This is not the CLI
	 * narrowing the domain vocabulary; it is the CLI declaring which of the
	 * domain's operations exist.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private function creatable_type( $value ) {
		$value   = $this->projection->object_type( $value );
		$allowed = array( ContentType::POST, ContentType::TERM );

		if ( ! in_array( $value, $allowed, true ) ) {
			$this->projection->fail(
				sprintf(
					/* translators: 1: supplied object type, 2: comma-separated list of valid types. */
					__( 'Translations cannot be created for "%1$s". Expected one of: %2$s.', 'mclogiora' ),
					$value,
					implode( ', ', $allowed )
				)
			);
		}

		return $value;
	}
}
