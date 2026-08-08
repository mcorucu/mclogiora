<?php
/**
 * Compatibility dashboard module.
 *
 * @package McLogiora
 */

namespace McLogiora\Compatibility;

use McLogiora\Admin\AdminScreen;
use McLogiora\Admin\AdminScreenRegistry;
use McLogiora\Capabilities\CapabilityRegistry;
use McLogiora\Contracts\ModuleInterface;
use McLogiora\Core\Container;
use McLogiora\Editors\EditorInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Registers a read-only compatibility and editor foundation screen.
 */
final class CompatibilityDashboard implements ModuleInterface {
	/**
	 * Compatibility service.
	 *
	 * @var CompatibilityService|null
	 */
	private $service = null;

	/**
	 * Effective capability.
	 *
	 * @var string
	 */
	private $capability = 'manage_options';

	/**
	 * Registers the dashboard screen.
	 *
	 * @param Container $container Service container.
	 * @return void
	 */
	public function register( Container $container ) {
		$this->service   = $container->get( CompatibilityService::class );
		$this->capability = $container->get( CapabilityRegistry::class )->resolve( CapabilityRegistry::MANAGE );

		$container->get( AdminScreenRegistry::class )->add(
			new AdminScreen(
				__( 'mcLogiora Compatibility', 'mclogiora' ),
				__( 'Compatibility', 'mclogiora' ),
				$this->capability,
				'mclogiora-compatibility',
				array( $this, 'render' )
			)
		);
	}

	/**
	 * Renders the compatibility dashboard.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( $this->capability ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mclogiora' ) );
		}

		$snapshot = $this->service instanceof CompatibilityService ? $this->service->snapshot() : array();
		$editors  = isset( $snapshot['editors'] ) && is_array( $snapshot['editors'] ) ? $snapshot['editors'] : array();
		$builders = isset( $snapshot['builders'] ) && is_array( $snapshot['builders'] ) ? $snapshot['builders'] : array();
		$plugins  = isset( $snapshot['plugins'] ) && is_array( $snapshot['plugins'] ) ? $snapshot['plugins'] : array();
		$theme    = isset( $snapshot['theme'] ) && is_array( $snapshot['theme'] ) ? $snapshot['theme'] : array();
		?>
		<div class="wrap mclogiora-admin">
			<section class="mclogiora-panel" aria-labelledby="mclogiora-compatibility-title">
				<p class="mclogiora-eyebrow"><?php esc_html_e( 'Compatibility foundation', 'mclogiora' ); ?></p>
				<h1 id="mclogiora-compatibility-title"><?php esc_html_e( 'Editors and compatibility', 'mclogiora' ); ?></h1>
				<p class="mclogiora-lede"><?php esc_html_e( 'This read-only overview identifies the current environment. Editor integrations remain dormant until their dedicated workflow phases.', 'mclogiora' ); ?></p>

				<div class="mclogiora-card-grid mclogiora-card-grid--four">
					<?php $this->render_list_card( __( 'Detected Editors', 'mclogiora' ), $editors, 'editor' ); ?>
					<?php $this->render_list_card( __( 'Detected Builders', 'mclogiora' ), $builders, 'builder' ); ?>
					<?php $this->render_list_card( __( 'Detected Plugins', 'mclogiora' ), $plugins, 'plugin' ); ?>
					<article class="mclogiora-info-card">
						<h2><?php esc_html_e( 'Detected Theme', 'mclogiora' ); ?></h2>
						<p class="mclogiora-card-value mclogiora-card-value--text"><?php echo esc_html( isset( $theme['name'] ) ? $theme['name'] : __( 'Unavailable', 'mclogiora' ) ); ?></p>
						<p><?php echo esc_html( ! empty( $theme['version'] ) ? sprintf( __( 'Version %s. Theme detection is read-only.', 'mclogiora' ), $theme['version'] ) : __( 'Theme detection is read-only.', 'mclogiora' ) ); ?></p>
					</article>
				</div>

				<div class="mclogiora-status-card mclogiora-status-card--notice">
					<span class="mclogiora-status-card__icon" aria-hidden="true">i</span>
					<div>
						<h2><?php esc_html_e( 'Editor integrations are intentionally dormant', 'mclogiora' ); ?></h2>
						<p><?php esc_html_e( 'No editor scripts, metaboxes, sidebars, panels, content mutations, REST routes, or AJAX handlers are registered in this phase.', 'mclogiora' ); ?></p>
					</div>
				</div>

				<div class="mclogiora-card-grid">
					<?php $this->render_placeholder_card( __( 'Classic Editor metabox', 'mclogiora' ) ); ?>
					<?php $this->render_placeholder_card( __( 'Block Editor sidebar', 'mclogiora' ) ); ?>
					<?php $this->render_placeholder_card( __( 'Elementor panel', 'mclogiora' ) ); ?>
				</div>
			</section>
		</div>
		<?php
	}

	/**
	 * Renders a detection card.
	 *
	 * @param string  $title Card title.
	 * @param array[] $items Detected items.
	 * @param string  $kind Item kind.
	 * @return void
	 */
	private function render_list_card( $title, array $items, $kind ) {
		?>
		<article class="mclogiora-info-card">
			<h2><?php echo esc_html( $title ); ?></h2>
			<p class="mclogiora-card-value"><?php echo esc_html( (string) count( $items ) ); ?></p>
			<?php if ( empty( $items ) ) : ?>
				<p><?php esc_html_e( 'Nothing detected in the current environment.', 'mclogiora' ); ?></p>
			<?php else : ?>
				<ul class="mclogiora-inline-list" aria-label="<?php echo esc_attr( $title ); ?>">
					<?php foreach ( $items as $item ) : ?>
						<li class="mclogiora-pill<?php echo 'editor' === $kind ? ' mclogiora-pill--active' : ''; ?>">
							<?php
							if ( $item instanceof EditorInterface ) {
								echo esc_html( $item->get_label() );
							} else {
								echo esc_html( isset( $item['label'] ) ? $item['label'] : '' );
							}
							?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</article>
		<?php
	}

	/**
	 * Renders a future editor surface placeholder.
	 *
	 * @param string $title Placeholder title.
	 * @return void
	 */
	private function render_placeholder_card( $title ) {
		?>
		<article class="mclogiora-info-card">
			<h2><?php echo esc_html( $title ); ?></h2>
			<p><span class="mclogiora-pill"><?php esc_html_e( 'Architecture ready', 'mclogiora' ); ?></span></p>
			<p><?php esc_html_e( 'The future surface will use the shared editor context and relation services. No editor UI is registered yet.', 'mclogiora' ); ?></p>
		</article>
		<?php
	}
}
