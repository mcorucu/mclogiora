<?php
/**
 * Menu and widget translation admin screen.
 *
 * @package McLogiora
 */

namespace McLogiora\Admin;

use McLogiora\Capabilities\CapabilityRegistry;
use McLogiora\Contracts\ModuleInterface;
use McLogiora\Core\Container;
use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageServiceInterface;
use McLogiora\Widgets\WidgetAdapterInterface;
use McLogiora\Widgets\WidgetTranslation;
use McLogiora\Widgets\WidgetTranslationService;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the Menus & Widgets translation screen.
 *
 * Menus themselves stay in Appearance, which is where people expect them.
 * This screen only creates a translated copy and shows which widget types
 * mcLogiora can translate; everything else is left to native WordPress UI.
 */
final class WidgetTranslationManager implements ModuleInterface {
	const PAGE_SLUG = 'mclogiora-menus-widgets';

	/**
	 * Effective admin capability.
	 *
	 * @var string
	 */
	private $capability = 'manage_options';

	/**
	 * Language service.
	 *
	 * @var LanguageServiceInterface|null
	 */
	private $languages = null;

	/**
	 * Widget translation service.
	 *
	 * @var WidgetTranslationService|null
	 */
	private $widgets = null;

	/**
	 * Registers the screen.
	 *
	 * @param Container $container Service container.
	 * @return void
	 */
	public function register( Container $container ) {
		$capabilities     = $container->get( CapabilityRegistry::class );
		$this->capability = $capabilities->resolve( CapabilityRegistry::MANAGE_TRANSLATIONS );
		$this->languages  = $container->get( LanguageServiceInterface::class );
		$this->widgets    = $container->get( WidgetTranslationService::class );

		$registry = $container->get( AdminScreenRegistry::class );
		$registry->add(
			new AdminScreen(
				static function () {
					return __( 'mcLogiora Menus and Widgets', 'mclogiora' );
				},
				static function () {
					return __( 'Menus & Widgets', 'mclogiora' );
				},
				$this->capability,
				self::PAGE_SLUG,
				array( $this, 'render' )
			)
		);
	}

	/**
	 * Renders the screen.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( $this->capability ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mclogiora' ) );
		}

		$languages = $this->languages->get_active_languages();

		?>
		<div class="wrap mclogiora-admin">
			<section class="mclogiora-panel" aria-labelledby="mclogiora-menus-widgets-title">
				<p class="mclogiora-eyebrow"><?php esc_html_e( 'Translation Data', 'mclogiora' ); ?></p>
				<h1 id="mclogiora-menus-widgets-title"><?php esc_html_e( 'Menus & Widgets', 'mclogiora' ); ?></h1>
				<p class="mclogiora-lede"><?php esc_html_e( 'Create translated navigation menus and review which widget types mcLogiora can translate. Front-end display follows the menu and widget settings you choose in WordPress.', 'mclogiora' ); ?></p>

				<?php $this->render_notice(); ?>
				<?php $this->render_menu_panel( $languages ); ?>
				<?php $this->render_widget_support(); ?>
			</section>
		</div>
		<?php
	}

	/**
	 * Renders the menu translation panel.
	 *
	 * @param Language[] $languages Active languages.
	 * @return void
	 */
	private function render_menu_panel( array $languages ) {
		if ( empty( $languages ) ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'Add and activate at least one language before creating translations.', 'mclogiora' )
			);

			return;
		}

		$menus = function_exists( 'wp_get_nav_menus' ) ? wp_get_nav_menus() : array();

		?>
		<article class="mclogiora-info-card">
			<h2><?php esc_html_e( 'Create Menu Translation', 'mclogiora' ); ?></h2>
			<p><?php esc_html_e( 'Creates a separate menu in the target language, copying item labels, order, and hierarchy as a starting point. Theme locations are not changed.', 'mclogiora' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="mclogiora_create_menu_translation">
				<?php wp_nonce_field( StringActionController::NONCE_ACTION, StringActionController::NONCE_NAME ); ?>
				<label>
					<span><?php esc_html_e( 'Source menu', 'mclogiora' ); ?></span>
					<select name="menu_id" required>
						<?php foreach ( $menus as $menu ) : ?>
							<?php if ( $menu instanceof \WP_Term ) : ?>
								<option value="<?php echo esc_attr( (string) $menu->term_id ); ?>"><?php echo esc_html( (string) $menu->name ); ?></option>
							<?php endif; ?>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<span><?php esc_html_e( 'Target language', 'mclogiora' ); ?></span>
					<select name="language" required>
						<?php foreach ( $languages as $language ) : ?>
							<?php if ( $language instanceof Language ) : ?>
								<option value="<?php echo esc_attr( $language->code() ); ?>"><?php echo esc_html( $language->native_name() ); ?></option>
							<?php endif; ?>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<span><?php esc_html_e( 'Translated menu name (optional)', 'mclogiora' ); ?></span>
					<input type="text" name="translated_name">
				</label>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Create Menu Translation', 'mclogiora' ); ?></button>
			</form>
		</article>
		<?php
	}

	/**
	 * Renders the supported widget adapter overview.
	 *
	 * @return void
	 */
	private function render_widget_support() {
		$adapters = $this->widgets->adapters()->all();

		?>
		<article class="mclogiora-info-card">
			<h2><?php esc_html_e( 'Supported Widget Types', 'mclogiora' ); ?></h2>
			<p><?php esc_html_e( 'mcLogiora only translates widget fields it understands. Widgets without an adapter are listed as unsupported and are never modified.', 'mclogiora' ); ?></p>
			<table class="widefat striped mclogiora-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Widget type', 'mclogiora' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Translatable fields', 'mclogiora' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $adapters as $adapter ) : ?>
						<?php if ( $adapter instanceof WidgetAdapterInterface ) : ?>
							<tr>
								<td><?php echo esc_html( $adapter->label() ); ?> <code><?php echo esc_html( $adapter->id() ); ?></code></td>
								<td><?php echo esc_html( implode( ', ', $adapter->translatable_fields() ) ); ?></td>
							</tr>
						<?php endif; ?>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="mclogiora-muted-line"><?php esc_html_e( 'Other widget types can be supported by registering an adapter through the mclogiora_widget_adapters filter.', 'mclogiora' ); ?></p>
		</article>
		<?php
	}

	/**
	 * Renders the action notice.
	 *
	 * @return void
	 */
	private function render_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect feedback.
		$notice = isset( $_GET['mclogiora_notice'] ) ? sanitize_key( wp_unslash( $_GET['mclogiora_notice'] ) ) : '';

		if ( '' === $notice ) {
			return;
		}

		if ( 'error' === $notice ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect feedback.
			$message = isset( $_GET['mclogiora_message'] ) ? sanitize_text_field( wp_unslash( $_GET['mclogiora_message'] ) ) : '';

			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html( '' !== $message ? $message : __( 'The action could not be completed.', 'mclogiora' ) )
			);

			return;
		}

		$messages = array(
			'created' => __( 'The translated menu was created.', 'mclogiora' ),
			'saved'   => __( 'The translation was saved.', 'mclogiora' ),
		);

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		printf( '<div class="notice notice-success"><p>%s</p></div>', esc_html( $messages[ $notice ] ) );
	}
}
