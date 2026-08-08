<?php
/**
 * Editor adapter registry.
 *
 * @package McLogiora
 */

namespace McLogiora\Editors;

defined( 'ABSPATH' ) || exit;

/**
 * Stores editor adapters without attaching editor-specific hooks.
 */
final class EditorRegistry {
	/**
	 * Registered editors.
	 *
	 * @var EditorInterface[]
	 */
	private $editors = array();

	/**
	 * Registers an editor adapter.
	 *
	 * @param EditorInterface $editor Editor adapter.
	 * @return void
	 */
	public function add( EditorInterface $editor ) {
		$this->editors[ $editor->get_id() ] = $editor;
	}

	/**
	 * Returns all registered editor adapters.
	 *
	 * @return EditorInterface[]
	 */
	public function all() {
		return array_values( $this->editors );
	}

	/**
	 * Returns available editor adapters.
	 *
	 * @return EditorInterface[]
	 */
	public function available() {
		return array_values(
			array_filter(
				$this->all(),
				static function ( EditorInterface $editor ) {
					return $editor->is_available();
				}
			)
		);
	}

	/**
	 * Finds an adapter by identifier.
	 *
	 * @param string $id Adapter identifier.
	 * @return EditorInterface|null
	 */
	public function find( $id ) {
		$id = sanitize_key( $id );

		return isset( $this->editors[ $id ] ) ? $this->editors[ $id ] : null;
	}
}
