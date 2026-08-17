<?php
/**
 * WP-CLI module.
 *
 * @package McLogiora
 */

namespace McLogiora\Cli;

use McLogiora\Api\PublicApi;
use McLogiora\Contracts\ModuleInterface;
use McLogiora\Core\Container;
use McLogiora\Core\RuntimeReadiness;

defined( 'ABSPATH' ) || exit;

/**
 * Registers mcLogiora's WP-CLI commands, and only under WP-CLI.
 *
 * Everything here is gated on `RuntimeReadiness::is_cli()`, the same authority
 * every other module asks about the request. A web request never constructs a
 * command class, never touches a WP-CLI symbol, and pays for one boolean.
 *
 * The command classes deliberately do not extend `WP_CLI_Command`. WP-CLI
 * accepts any class, and not extending it means no file in `src/` names a
 * WP-CLI class at all, so the plugin cannot fatal on a site where WP-CLI is
 * absent. For the same reason mcLogiora takes no production Composer
 * dependency on WP-CLI: the runtime provides it when it is the runtime.
 *
 * Commands read through `PublicApi`, the same reader a theme and the REST
 * controllers use. CLI has direct access to every service in the container and
 * that is exactly why it must not use them: a command reaching into a
 * repository would become a third definition of what a translation is.
 */
final class CliModule implements ModuleInterface {
	const ROOT = 'mclogiora';

	/**
	 * Registers the commands when running under WP-CLI.
	 *
	 * @param Container $container Service container.
	 * @return void
	 */
	public function register( Container $container ) {
		$readiness = $container->has( RuntimeReadiness::class )
			? $container->get( RuntimeReadiness::class )
			: new RuntimeReadiness();

		if ( ! $readiness->is_cli() || ! class_exists( '\WP_CLI' ) ) {
			return;
		}

		$api = new PublicApi( $container );

		$this->add( 'language', new LanguageCommand( $api ) );
		$this->add( 'relation', new RelationCommand( $api ) );
		$this->add( 'translation', new TranslationCommand( $api ) );
	}

	/**
	 * Registers one subcommand under the root command.
	 *
	 * @param string $name Subcommand name.
	 * @param object $command Command instance.
	 * @return void
	 */
	private function add( $name, $command ) {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- WP-CLI's own API.
		\WP_CLI::add_command( self::ROOT . ' ' . $name, $command );
	}
}
