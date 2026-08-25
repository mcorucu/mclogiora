<?php
/**
 * Admin menu framework.
 *
 * @package McLogiora
 */

namespace McLogiora\Admin;

use McLogiora\Assets\AssetLoader;
use McLogiora\Capabilities\CapabilityRegistry;
use McLogiora\Contracts\ModuleInterface;
use McLogiora\Core\Container;
use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageServiceInterface;
use McLogiora\Relations\TranslationRelationServiceInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the main mcLogiora admin pages.
 */
final class AdminMenu implements ModuleInterface {
	/**
	 * Menu capability.
	 *
	 * @var string
	 */
	private $capability = 'manage_options';

	/**
	 * Capability identifier.
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
	 * Language service.
	 *
	 * @var LanguageServiceInterface|null
	 */
	private $language_service = null;

	/**
	 * Translation relation service.
	 *
	 * @var TranslationRelationServiceInterface|null
	 */
	private $relation_service = null;

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
		$this->language_service    = $container->get( LanguageServiceInterface::class );
		$this->relation_service    = $container->get( TranslationRelationServiceInterface::class );
		$this->capability          = $this->capability_registry->resolve( $this->planned_capability );

		add_action( 'admin_menu', array( $this, 'register_pages' ) );
	}

	/**
	 * Registers admin pages.
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
	 * Renders the product overview dashboard.
	 *
	 * @return void
	 */
	public function render_dashboard() {
		if ( ! current_user_can( $this->capability ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mclogiora' ) );
		}

		$languages = $this->language_service instanceof LanguageServiceInterface ? $this->language_service->get_active_languages() : array();
		$default   = $this->language_service instanceof LanguageServiceInterface ? $this->language_service->get_default_language() : null;
		$groups    = $this->relation_service instanceof TranslationRelationServiceInterface ? $this->relation_service->get_placeholder_groups() : array();

		?>
		<div class="wrap mclogiora-admin">
			<section class="mclogiora-panel mclogiora-dashboard" aria-labelledby="mclogiora-page-title">
				<p class="mclogiora-eyebrow"><?php esc_html_e( 'Overview', 'mclogiora' ); ?></p>
				<h1 id="mclogiora-page-title"><?php esc_html_e( 'mcLogiora', 'mclogiora' ); ?></h1>
				<p class="mclogiora-lede"><?php esc_html_e( 'Manage your site languages, translation relationships, multilingual URLs, strings, and optional translation suggestions from one place.', 'mclogiora' ); ?></p>

				<div class="mclogiora-dashboard-summary" aria-label="<?php esc_attr_e( 'Site translation summary', 'mclogiora' ); ?>">
					<article class="mclogiora-summary-card">
						<span class="mclogiora-summary-card__label"><?php esc_html_e( 'Languages', 'mclogiora' ); ?></span>
						<strong><?php echo esc_html( (string) count( $languages ) ); ?></strong>
						<?php if ( $default instanceof Language ) : ?>
							<span><?php echo esc_html( sprintf( '%s (%s)', $default->english_name(), $default->code() ) ); ?></span>
						<?php else : ?>
							<span><?php esc_html_e( 'No default language configured', 'mclogiora' ); ?></span>
						<?php endif; ?>
					</article>
					<article class="mclogiora-summary-card">
						<span class="mclogiora-summary-card__label"><?php esc_html_e( 'Translation groups', 'mclogiora' ); ?></span>
						<strong><?php echo esc_html( (string) count( $groups ) ); ?></strong>
						<span><?php esc_html_e( 'Explicit relationships managed by the plugin', 'mclogiora' ); ?></span>
					</article>
				</div>

				<?php if ( ! $default instanceof Language ) : ?>
					<div class="mclogiora-onboarding" role="status">
						<div>
							<strong><?php esc_html_e( 'Start with your site language', 'mclogiora' ); ?></strong>
							<p><?php esc_html_e( 'Choose a default language before configuring translated URLs or creating translation relationships.', 'mclogiora' ); ?></p>
						</div>
						<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=mclogiora-setup' ) ); ?>"><?php esc_html_e( 'Run Setup Wizard', 'mclogiora' ); ?></a>
					</div>
				<?php endif; ?>

				<div class="mclogiora-quick-actions">
					<h2><?php esc_html_e( 'Quick actions', 'mclogiora' ); ?></h2>
					<nav aria-label="<?php esc_attr_e( 'mcLogiora quick actions', 'mclogiora' ); ?>">
						<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=mclogiora-languages' ) ); ?>"><?php esc_html_e( 'Manage Languages', 'mclogiora' ); ?></a>
						<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=mclogiora-translation-manager' ) ); ?>"><?php esc_html_e( 'Translation Manager', 'mclogiora' ); ?></a>
						<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=mclogiora-string-translation' ) ); ?>"><?php esc_html_e( 'String Translation', 'mclogiora' ); ?></a>
						<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=mclogiora-routing' ) ); ?>"><?php esc_html_e( 'Configure URLs', 'mclogiora' ); ?></a>
						<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=mclogiora-suggestions' ) ); ?>"><?php esc_html_e( 'Translation Suggestions', 'mclogiora' ); ?></a>
						<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=mclogiora-system-status' ) ); ?>"><?php esc_html_e( 'System Status', 'mclogiora' ); ?></a>
					</nav>
				</div>
			</section>
		</div>
		<?php
	}

	/**
	 * Renders the settings overview.
	 *
	 * @return void
	 */
	public function render_settings() {
		if ( ! current_user_can( $this->capability ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mclogiora' ) );
		}

		?>
		<div class="wrap mclogiora-admin">
			<section class="mclogiora-panel" aria-labelledby="mclogiora-settings-title">
				<p class="mclogiora-eyebrow"><?php esc_html_e( 'Configuration', 'mclogiora' ); ?></p>
				<h1 id="mclogiora-settings-title"><?php esc_html_e( 'Settings', 'mclogiora' ); ?></h1>
				<p class="mclogiora-lede"><?php esc_html_e( 'Choose a mcLogiora area to configure. Each screen saves only the settings it owns and explains the effect before you change it.', 'mclogiora' ); ?></p>
				<div class="mclogiora-card-grid mclogiora-card-grid--two">
					<?php $this->render_settings_link_card( 'mclogiora-languages', __( 'Languages', 'mclogiora' ), __( 'Add languages, choose the default, and control the active language list.', 'mclogiora' ) ); ?>
					<?php $this->render_settings_link_card( 'mclogiora-routing', __( 'Languages & URLs', 'mclogiora' ), __( 'Configure language directories and the language switcher used on the front end.', 'mclogiora' ) ); ?>
					<?php $this->render_settings_link_card( 'mclogiora-suggestions', __( 'Translation Suggestions', 'mclogiora' ), __( 'Keep optional provider suggestions disabled or configure your own provider credentials.', 'mclogiora' ) ); ?>
					<?php $this->render_settings_link_card( 'mclogiora-compatibility', __( 'Compatibility', 'mclogiora' ), __( 'Review the editors, builders, plugins, and theme detected on this site.', 'mclogiora' ) ); ?>
				</div>
			</section>
		</div>
		<?php
	}

	/**
	 * Renders one settings navigation card.
	 *
	 * @param string $slug Destination page slug.
	 * @param string $title Card title.
	 * @param string $description Card description.
	 * @return void
	 */
	private function render_settings_link_card( $slug, $title, $description ) {
		?>
		<article class="mclogiora-info-card mclogiora-link-card">
			<h2><?php echo esc_html( $title ); ?></h2>
			<p><?php echo esc_html( $description ); ?></p>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $slug ) ); ?>"><?php esc_html_e( 'Open settings', 'mclogiora' ); ?></a>
		</article>
		<?php
	}
}
