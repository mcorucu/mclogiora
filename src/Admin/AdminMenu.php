<?php
/**
 * Admin menu framework.
 *
 * @package McLogiora
 */

namespace McLogiora\Admin;

use McLogiora\Assets\AssetLoader;
use McLogiora\Capabilities\CapabilityRegistry;
use McLogiora\Content\ContentTranslationServiceInterface;
use McLogiora\Contracts\ModuleInterface;
use McLogiora\Core\Container;
use McLogiora\Taxonomies\TaxonomyTranslationServiceInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Registers mcLogiora admin pages with placeholder content.
 */
final class AdminMenu implements ModuleInterface {
	/**
	 * Menu capability.
	 *
	 * @var string
	 */
	private $capability = 'manage_options';

	/**
	 * Planned capability identifier.
	 *
	 * @var string
	 */
	private $planned_capability = CapabilityRegistry::MANAGE;

	/**
	 * Asset loader.
	 *
	 * @var AssetLoader|null
	 */
	private $asset_loader = null;

	/**
	 * Admin screen registry.
	 *
	 * @var AdminScreenRegistry|null
	 */
	private $screen_registry = null;

	/**
	 * Capability registry.
	 *
	 * @var CapabilityRegistry|null
	 */
	private $capability_registry = null;

	/**
	 * Content translation foundation service.
	 *
	 * @var ContentTranslationServiceInterface|null
	 */
	private $content_service = null;

	/**
	 * Taxonomy translation foundation service.
	 *
	 * @var TaxonomyTranslationServiceInterface|null
	 */
	private $taxonomy_service = null;

	/**
	 * Registers admin menu hooks.
	 *
	 * @param Container $container Service container.
	 * @return void
	 */
	public function register( Container $container ) {
		$this->asset_loader        = $container->get( AssetLoader::class );
		$this->screen_registry     = $container->get( AdminScreenRegistry::class );
		$this->capability_registry = $container->get( CapabilityRegistry::class );
		$this->content_service     = $container->get( ContentTranslationServiceInterface::class );
		$this->taxonomy_service    = $container->get( TaxonomyTranslationServiceInterface::class );
		$this->capability          = $this->capability_registry->resolve( $this->planned_capability );

		add_action( 'admin_menu', array( $this, 'register_pages' ) );
	}

	/**
	 * Registers placeholder admin pages.
	 *
	 * @return void
	 */
	public function register_pages() {
		$dashboard_hook = add_menu_page(
			__( 'mcLogiora', 'mclogiora' ),
			__( 'mcLogiora', 'mclogiora' ),
			$this->capability,
			'mclogiora',
			array( $this, 'render_dashboard' ),
			'dashicons-translation',
			58
		);

		$settings_hook = add_submenu_page(
			'mclogiora',
			__( 'mcLogiora Settings', 'mclogiora' ),
			__( 'Settings', 'mclogiora' ),
			$this->capability,
			'mclogiora-settings',
			array( $this, 'render_settings' )
		);

		if ( $this->asset_loader instanceof AssetLoader ) {
			$this->asset_loader->add_admin_screen( $dashboard_hook );
			$this->asset_loader->add_admin_screen( $settings_hook );
		}

		if ( $this->screen_registry instanceof AdminScreenRegistry ) {
			foreach ( $this->screen_registry->all() as $screen ) {
				$hook = add_submenu_page(
					'mclogiora',
					$screen->page_title(),
					$screen->menu_title(),
					$screen->capability(),
					$screen->slug(),
					$screen->callback()
				);

				if ( $this->asset_loader instanceof AssetLoader ) {
					$this->asset_loader->add_admin_screen( $hook );
				}
			}
		}
	}

	/**
	 * Renders the foundation dashboard placeholder.
	 *
	 * @return void
	 */
	public function render_dashboard() {
		if ( ! current_user_can( $this->capability ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mclogiora' ) );
		}

		$this->render_page(
			__( 'mcLogiora Core Kernel', 'mclogiora' ),
			__( 'Phase 05 is active. Content and taxonomy translation foundations are available as read-only registries without persistence.', 'mclogiora' ),
			true
		);
	}

	/**
	 * Renders the settings placeholder.
	 *
	 * @return void
	 */
	public function render_settings() {
		if ( ! current_user_can( $this->capability ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mclogiora' ) );
		}

		$this->render_page(
			__( 'Settings Framework', 'mclogiora' ),
			__( 'No settings are registered in Phase 05. This screen exists only as the future settings framework entry point.', 'mclogiora' )
		);
	}

	/**
	 * Renders a Skylearn-aligned placeholder page.
	 *
	 * @param string $title Page title.
	 * @param string $message Page message.
	 * @param bool   $show_dashboard_cards Whether to show dashboard cards.
	 * @return void
	 */
	private function render_page( $title, $message, $show_dashboard_cards = false ) {
		?>
		<div class="wrap mclogiora-admin">
			<section class="mclogiora-panel" aria-labelledby="mclogiora-page-title">
				<p class="mclogiora-eyebrow"><?php esc_html_e( 'Foundation', 'mclogiora' ); ?></p>
				<h1 id="mclogiora-page-title"><?php echo esc_html( $title ); ?></h1>
				<p class="mclogiora-lede"><?php echo esc_html( $message ); ?></p>
				<div class="mclogiora-status-card">
					<span class="mclogiora-status-card__icon" aria-hidden="true">OK</span>
					<div>
						<h2><?php esc_html_e( 'Ready for Prompt 06', 'mclogiora' ); ?></h2>
						<p><?php esc_html_e( 'Content and taxonomy support foundations are registered. No translations or content changes are created yet.', 'mclogiora' ); ?></p>
					</div>
				</div>

				<?php if ( $show_dashboard_cards ) : ?>
					<?php $this->render_dashboard_cards(); ?>
				<?php endif; ?>
			</section>
		</div>
		<?php
	}

	/**
	 * Renders Phase 05 dashboard cards.
	 *
	 * @return void
	 */
	private function render_dashboard_cards() {
		$content_overview  = $this->content_service instanceof ContentTranslationServiceInterface ? $this->content_service->get_support_overview() : array();
		$taxonomy_overview = $this->taxonomy_service instanceof TaxonomyTranslationServiceInterface ? $this->taxonomy_service->get_support_overview() : array();
		$content_count     = isset( $content_overview['translatable'] ) ? absint( $content_overview['translatable'] ) : 0;
		$taxonomy_count    = isset( $taxonomy_overview['translatable'] ) ? absint( $taxonomy_overview['translatable'] ) : 0;
		$excluded_count    = ( isset( $content_overview['excluded'] ) ? absint( $content_overview['excluded'] ) : 0 ) + ( isset( $taxonomy_overview['excluded'] ) ? absint( $taxonomy_overview['excluded'] ) : 0 );
		?>
		<div class="mclogiora-card-grid mclogiora-card-grid--four">
			<article class="mclogiora-info-card">
				<h2><?php esc_html_e( 'Translatable Content Types', 'mclogiora' ); ?></h2>
				<p class="mclogiora-card-value"><?php echo esc_html( (string) $content_count ); ?></p>
				<p><?php esc_html_e( 'Posts, pages, and eligible public custom post types are prepared for future workflows.', 'mclogiora' ); ?></p>
			</article>
			<article class="mclogiora-info-card">
				<h2><?php esc_html_e( 'Translatable Taxonomies', 'mclogiora' ); ?></h2>
				<p class="mclogiora-card-value"><?php echo esc_html( (string) $taxonomy_count ); ?></p>
				<p><?php esc_html_e( 'Categories, tags, and eligible public custom taxonomies are prepared for future workflows.', 'mclogiora' ); ?></p>
			</article>
			<article class="mclogiora-info-card">
				<h2><?php esc_html_e( 'Excluded Integrations', 'mclogiora' ); ?></h2>
				<p class="mclogiora-card-value"><?php echo esc_html( (string) $excluded_count ); ?></p>
				<p><?php esc_html_e( 'WooCommerce and LMS support are planned as future free compatibility modules and are not part of this foundation yet.', 'mclogiora' ); ?></p>
			</article>
			<article class="mclogiora-info-card">
				<h2><?php esc_html_e( 'Future Editor Support', 'mclogiora' ); ?></h2>
				<p class="mclogiora-card-value"><?php echo esc_html( '4' ); ?></p>
				<p><?php esc_html_e( 'Gutenberg, Classic Editor, Elementor, and ACF remain planned adapter foundations.', 'mclogiora' ); ?></p>
			</article>
		</div>
		<?php
	}
}
