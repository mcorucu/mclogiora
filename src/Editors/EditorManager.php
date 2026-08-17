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

		/*
		 * Not yet a public contract, and it carries no @since for that reason.
		 *
		 * The filter works, but promoting it would freeze `EditorInterface`,
		 * which still carries `get_placeholder_areas()` from the Phase 09
		 * foundation and takes an internal `EditorContext`. Publishing an
		 * interface with a method named "placeholder" in it commits to keeping
		 * the placeholder. Deferred until that interface is reviewed. See
		 * docs/architecture/developer-api.md.
		 */
		$editors = apply_filters( 'mclogiora_register_editors', $factory->create_defaults(), $registry );

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
