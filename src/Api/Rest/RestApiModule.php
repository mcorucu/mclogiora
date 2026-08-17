<?php
/**
 * REST API module.
 *
 * @package McLogiora
 */

namespace McLogiora\Api\Rest;

use McLogiora\Api\PublicApi;
use McLogiora\Capabilities\CapabilityRegistry;
use McLogiora\Contracts\ModuleInterface;
use McLogiora\Core\Container;
use McLogiora\Workflows\TranslationWorkflowService;

defined( 'ABSPATH' ) || exit;

/**
 * Registers mcLogiora's own REST routes.
 *
 * The namespace and route vocabulary come from the plan rather than from
 * whatever felt natural per controller, so that later slices extend a shape
 * that was decided once.
 *
 * Nothing here re-reads languages or relations from a repository. Every
 * response is projected from `PublicApi`, the same reader a theme calls, which
 * is what keeps HTTP from becoming a second definition of the public read
 * model. A controller that queried repositories directly would eventually
 * disagree with the functions about what a translation is.
 *
 * Registration is deliberately cheap: this module adds one `rest_api_init`
 * callback and nothing else, so an ordinary front-end or admin request pays for
 * a closure it never runs.
 */
final class RestApiModule implements ModuleInterface {
	const NAMESPACE_V1 = 'mclogiora/v1';

	/**
	 * Service container.
	 *
	 * @var Container|null
	 */
	private $container = null;

	/**
	 * Registers the REST bootstrap.
	 *
	 * @param Container $container Service container.
	 * @return void
	 */
	public function register( Container $container ) {
		$this->container = $container;

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers every route in the namespace.
	 *
	 * Controllers are built here rather than in `register()` so that an
	 * ordinary front-end or admin request constructs nothing at all. Routes
	 * existing must not cost a request that will never call one.
	 *
	 * @return void
	 */
	public function register_routes() {
		if ( ! $this->container instanceof Container ) {
			return;
		}

		$api        = new PublicApi( $this->container );
		$capability = $this->container->has( CapabilityRegistry::class )
			? $this->container->get( CapabilityRegistry::class )
			: new CapabilityRegistry();

		$workflows = $this->container->has( TranslationWorkflowService::class )
			? $this->container->get( TranslationWorkflowService::class )
			: null;

		$languages = new LanguagesController( $api, $capability );
		$relations = new RelationsController( $api, $capability, $workflows );

		$languages->register_routes( self::NAMESPACE_V1 );
		$relations->register_routes( self::NAMESPACE_V1 );
	}
}
