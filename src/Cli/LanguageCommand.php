<?php
/**
 * Language inspection commands.
 *
 * @package McLogiora
 */

namespace McLogiora\Cli;

use McLogiora\Api\PublicApi;

defined( 'ABSPATH' ) || exit;

/**
 * Inspects the languages a site has configured.
 */
final class LanguageCommand {
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
	 * Lists the languages configured on this site.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Which languages to list.
	 * ---
	 * default: all
	 * options:
	 *   - all
	 *   - active
	 * ---
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
	 *     # Show every configured language.
	 *     $ wp mclogiora language list
	 *
	 *     # Only the enabled ones, as JSON.
	 *     $ wp mclogiora language list --status=active --format=json
	 *
	 * @subcommand list
	 *
	 * @param array<int,string>   $args Positional arguments.
	 * @param array<string,mixed> $options Associative arguments.
	 * @return void
	 */
	public function list_( array $args, array $options ) {
		unset( $args );

		/*
		 * `all` by default, deliberately unlike REST. REST defaults to active
		 * and gates the rest behind a capability because anonymous callers
		 * exist; running `wp` means shell access, which is already more
		 * privileged than any role. Hiding configured-but-disabled languages
		 * from the person administering them would be the wrong default here.
		 */
		$status = isset( $options['status'] ) && 'active' === $options['status'] ? 'active' : 'all';
		$rows   = array();

		foreach ( $this->projection->api()->languages( array( 'status' => $status ) ) as $language ) {
			$rows[] = $this->projection->language_row( $language );
		}

		if ( empty( $rows ) ) {
			\WP_CLI::warning( __( 'No languages are configured yet.', 'mclogiora' ) );
		}

		$this->projection->render(
			$rows,
			$this->projection->fields( $options, CliProjection::LANGUAGE_FIELDS ),
			$options
		);
	}
}
