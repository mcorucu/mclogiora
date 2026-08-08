<?php
/**
 * String scanner scope resolution.
 *
 * @package McLogiora
 */

namespace McLogiora\Strings;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves a named scope to a confined directory.
 *
 * Request data never becomes a filesystem path. The admin submits a scope
 * kind and a slug; this class maps those to a directory under a fixed root
 * and refuses anything that escapes it. A caller cannot ask to scan an
 * arbitrary location even by supplying traversal sequences, because the
 * resolved real path must still be inside the allowed root.
 */
final class ScanScope {
	/**
	 * Allowed roots keyed by scope kind.
	 *
	 * @var array<string,string>
	 */
	private $roots;

	/**
	 * Constructor.
	 *
	 * @param array<string,string> $roots Allowed roots keyed by scope kind.
	 */
	public function __construct( array $roots ) {
		$this->roots = $roots;
	}

	/**
	 * Builds the default scope map from WordPress paths.
	 *
	 * @return self
	 */
	public static function from_wordpress() {
		return new self(
			array(
				StringSourceType::THEME  => defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/themes' : '',
				StringSourceType::PLUGIN => defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : '',
			)
		);
	}

	/**
	 * Resolves a scope to a confined absolute directory.
	 *
	 * @param string $kind Scope kind.
	 * @param string $slug Directory slug within the root.
	 * @return string|\WP_Error
	 */
	public function resolve( $kind, $slug ) {
		$kind = (string) $kind;

		if ( ! isset( $this->roots[ $kind ] ) || '' === $this->roots[ $kind ] ) {
			return new \WP_Error( 'mclogiora_unknown_scan_scope', __( 'That scan scope is not supported.', 'mclogiora' ) );
		}

		$slug = (string) $slug;

		/*
		 * The slug is a single directory name, never a path. Rejecting
		 * separators outright is stricter than normalising them and leaves
		 * no room for encoding tricks.
		 */
		if ( '' === $slug || preg_match( '/[^A-Za-z0-9_.-]/', $slug ) || false !== strpos( $slug, '..' ) ) {
			return new \WP_Error( 'mclogiora_invalid_scan_target', __( 'That scan target name is not valid.', 'mclogiora' ) );
		}

		$root = realpath( $this->roots[ $kind ] );

		if ( false === $root ) {
			return new \WP_Error( 'mclogiora_scan_root_missing', __( 'The scan location could not be found.', 'mclogiora' ) );
		}

		$target = realpath( $root . DIRECTORY_SEPARATOR . $slug );

		if ( false === $target || ! is_dir( $target ) ) {
			return new \WP_Error( 'mclogiora_scan_target_missing', __( 'The scan target could not be found.', 'mclogiora' ) );
		}

		if ( ! $this->is_inside( $root, $target ) ) {
			return new \WP_Error( 'mclogiora_scan_target_outside_root', __( 'The scan target is outside the allowed location.', 'mclogiora' ) );
		}

		return $target;
	}

	/**
	 * Returns whether a resolved path sits inside a root.
	 *
	 * @param string $root Allowed root.
	 * @param string $path Resolved path.
	 * @return bool
	 */
	public function is_inside( $root, $path ) {
		$root = rtrim( (string) $root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
		$path = (string) $path;

		return 0 === strpos( $path . DIRECTORY_SEPARATOR, $root );
	}
}
