<?php
/**
 * Editor module manager.
 *
 * @package McLogiora
 */

namespace McLogiora\Editors;

use McLogiora\Contracts\ModuleInterface;
use McLogiora\Core\Container;

defined( 'ABSPATH' ) || exit;

/**
 * Registers editor adapters while keeping integrations dormant.
 */
final class EditorManager implements ModuleInterface {
	/**
	 * Registers the core adapter set.
	 *
	 * @param Container $container Service container.
	 * @return void
	 */
	public function register( Container $container ) {
		$registry = $container->get( EditorRegistry::class );
		$factory  = $container->get( EditorFactory::class );
		$editors  = apply_filters( 'mclogiora_register_editors', $factory->create_defaults(), $registry );

		if ( ! is_array( $editors ) ) {
			$editors = $factory->create_defaults();
		}

		foreach ( $editors as $editor ) {
			if ( $editor instanceof EditorInterface ) {
				$registry->add( $editor );
			}
		}

		$container->set( self::class, $this );
	}
}
