<?php
/**
 * Locator resolver.
 *
 * @package McLogiora
 */

namespace McLogiora\ImportExport;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a package locator into whatever this site actually has.
 *
 * Resolution is memoised for the lifetime of one resolver. A package routinely
 * names the same source page from several groups, and repeating the query would
 * cost more and, worse, could return two different answers within a single plan
 * if content changed underneath it. One plan gets one view of the destination.
 *
 * Matches are capped rather than counted exhaustively. The planner needs to
 * know whether a locator names none, one, or several objects, and listing five
 * of the several is enough to put in a message; loading all of them would only
 * make a pathological site expensive to inspect.
 */
final class LocatorResolver {
	/**
	 * Matches loaded before a locator is called ambiguous.
	 */
	const MATCH_LIMIT = 5;

	/**
	 * Object locator gateway.
	 *
	 * @var ObjectLocatorGatewayInterface
	 */
	private $objects;

	/**
	 * Memoised resolutions keyed by locator signature.
	 *
	 * @var array<string,LocatorResolution>
	 */
	private $cache = array();

	/**
	 * Constructor.
	 *
	 * @param ObjectLocatorGatewayInterface $objects Object locator gateway.
	 */
	public function __construct( ObjectLocatorGatewayInterface $objects ) {
		$this->objects = $objects;
	}

	/**
	 * Resolves a locator against this site.
	 *
	 * @param ObjectLocator|null $locator Locator, or null when the package had none.
	 * @return LocatorResolution
	 */
	public function resolve( ?ObjectLocator $locator = null ) {
		if ( null === $locator ) {
			return LocatorResolution::of( LocatorResolution::ABSENT );
		}

		if ( ! $locator->is_complete() ) {
			return LocatorResolution::of( LocatorResolution::INCOMPLETE );
		}

		$signature = $this->signature( $locator );

		if ( isset( $this->cache[ $signature ] ) ) {
			return $this->cache[ $signature ];
		}

		$resolution = ObjectLocator::KIND_TERM === $locator->kind()
			? $this->resolve_term( $locator )
			: $this->resolve_post( $locator );

		$this->cache[ $signature ] = $resolution;

		return $resolution;
	}

	/**
	 * Resolves a post locator.
	 *
	 * @param ObjectLocator $locator Locator.
	 * @return LocatorResolution
	 */
	private function resolve_post( ObjectLocator $locator ) {
		if ( ! $this->objects->post_type_exists( $locator->post_type() ) ) {
			return LocatorResolution::of( LocatorResolution::TYPE_UNKNOWN );
		}

		$ids       = $this->objects->find_posts( $locator->post_type(), $locator->slug(), self::MATCH_LIMIT );
		$ancestors = $locator->ancestors();

		/*
		 * WordPress keeps a slug unique per parent inside a hierarchical type,
		 * not per type: `team` may exist once under `about` and again under
		 * `company`. Comparing the ancestor path is what tells those two apart,
		 * and it is compared exactly -- a page that has been moved to a
		 * different parent is a different address, and adopting it because the
		 * last segment still matches would silently relink content.
		 */
		if ( null !== $ancestors ) {
			$ids = array_values(
				array_filter(
					$ids,
					function ( $id ) use ( $ancestors ) {
						$post = $this->objects->describe_post( (int) $id );

						return null !== $post && $ancestors === $post['ancestors'];
					}
				)
			);
		}

		return LocatorResolution::from_ids( $ids );
	}

	/**
	 * Resolves a term locator.
	 *
	 * @param ObjectLocator $locator Locator.
	 * @return LocatorResolution
	 */
	private function resolve_term( ObjectLocator $locator ) {
		if ( ! $this->objects->taxonomy_exists( $locator->taxonomy() ) ) {
			return LocatorResolution::of( LocatorResolution::TYPE_UNKNOWN );
		}

		return LocatorResolution::from_ids(
			$this->objects->find_terms( $locator->taxonomy(), $locator->slug(), self::MATCH_LIMIT )
		);
	}

	/**
	 * Returns the memoisation key for a locator.
	 *
	 * @param ObjectLocator $locator Locator.
	 * @return string
	 */
	private function signature( ObjectLocator $locator ) {
		$ancestors = $locator->ancestors();

		return implode(
			"\x1f",
			array(
				$locator->kind(),
				$locator->post_type(),
				$locator->taxonomy(),
				$locator->slug(),
				null === $ancestors ? '-' : implode( '/', $ancestors ),
			)
		);
	}
}
