<?php
/**
 * Routing and switcher settings screen.
 *
 * @package McLogiora
 */

namespace McLogiora\Admin;

use McLogiora\Capabilities\CapabilityRegistry;
use McLogiora\Contracts\ModuleInterface;
use McLogiora\Core\Container;
use McLogiora\Routing\MissingTranslationPolicy;
use McLogiora\Routing\RoutingModule;
use McLogiora\Routing\RoutingSettings;
use McLogiora\Routing\UrlStrategy;
use McLogiora\Switcher\SwitcherStyle;

defined( 'ABSPATH' ) || exit;

/**
 * Renders and saves the Phase 12 settings.
 *
 * Only settings that actually do something are shown. Domain and subdomain
 * routing are not offered, because a control that silently does nothing is
 * worse than an absent one.
 */
final class RoutingSettingsScreen implements ModuleInterface {
	const PAGE_SLUG    = 'mclogiora-routing';
	const NONCE_ACTION = 'mclogiora_routing_settings';
	const NONCE_NAME   = 'mclogiora_routing_nonce';

	/**
	 * Effective capability.
	 *
	 * @var string
	 */
	private $capability = 'manage_options';

	/**
	 * Routing settings.
	 *
	 * @var RoutingSettings|null
	 */
	private $settings = null;

	/**
	 * Registers the screen and its save handler.
	 *
	 * @param Container $container Service container.
	 * @return void
	 */
	public function register( Container $container ) {
		$capabilities     = $container->get( CapabilityRegistry::class );
		$this->capability = $capabilities->resolve( CapabilityRegistry::MANAGE_SETTINGS );
		$this->settings   = $container->get( RoutingSettings::class );

		$registry = $container->get( AdminScreenRegistry::class );
		$registry->add(
			new AdminScreen(
				static function () {
					return __( 'mcLogiora Languages and URLs', 'mclogiora' );
				},
				static function () {
					return __( 'Languages & URLs', 'mclogiora' );
				},
				$this->capability,
				self::PAGE_SLUG,
				array( $this, 'render' )
			)
		);

		if ( is_admin() ) {
			add_action( 'admin_post_mclogiora_save_routing_settings', array( $this, 'handle_save' ) );
		}
	}

	/**
	 * Renders the settings screen.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( $this->capability ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mclogiora' ) );
		}

		$settings = $this->settings->all();

		?>
		<div class="wrap mclogiora-admin">
			<section class="mclogiora-panel" aria-labelledby="mclogiora-routing-title">
				<p class="mclogiora-eyebrow"><?php esc_html_e( 'Multilingual URLs', 'mclogiora' ); ?></p>
				<h1 id="mclogiora-routing-title"><?php esc_html_e( 'Languages & URLs', 'mclogiora' ); ?></h1>
				<p class="mclogiora-lede"><?php esc_html_e( 'Controls how languages appear in your URLs and how the language switcher is presented.', 'mclogiora' ); ?></p>

				<?php $this->render_notice(); ?>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="mclogiora_save_routing_settings">
					<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>

					<article class="mclogiora-info-card">
						<h2><?php esc_html_e( 'URL Structure', 'mclogiora' ); ?></h2>
						<p><?php esc_html_e( 'Languages are added as a directory, for example /tr/about/. Other URL strategies are not available yet.', 'mclogiora' ); ?></p>
						<p>
							<label>
								<span><?php esc_html_e( 'Strategy', 'mclogiora' ); ?></span>
								<select name="url_strategy">
									<option value="<?php echo esc_attr( UrlStrategy::DIRECTORY ); ?>" selected><?php esc_html_e( 'Language directory', 'mclogiora' ); ?></option>
								</select>
							</label>
						</p>
						<p>
							<label>
								<input type="checkbox" name="default_language_prefix" value="1" <?php checked( ! empty( $settings['default_language_prefix'] ) ); ?>>
								<?php esc_html_e( 'Also add a directory for the default language', 'mclogiora' ); ?>
							</label>
						</p>
						<p class="mclogiora-muted-line"><?php esc_html_e( 'Leaving this off keeps your existing default-language URLs unchanged. Turning it on changes every published permalink at once.', 'mclogiora' ); ?></p>
					</article>

					<article class="mclogiora-info-card">
						<h2><?php esc_html_e( 'Language Switcher', 'mclogiora' ); ?></h2>
						<p>
							<label>
								<span><?php esc_html_e( 'Default style', 'mclogiora' ); ?></span>
								<select name="switcher_style">
									<?php foreach ( SwitcherStyle::labels() as $key => $label ) : ?>
										<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $settings['switcher_style'], $key ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</label>
						</p>
						<p>
							<label>
								<input type="checkbox" name="switcher_show_name" value="1" <?php checked( ! empty( $settings['switcher_show_name'] ) ); ?>>
								<?php esc_html_e( 'Show the native language name', 'mclogiora' ); ?>
							</label>
						</p>
						<p>
							<label>
								<input type="checkbox" name="switcher_show_code" value="1" <?php checked( ! empty( $settings['switcher_show_code'] ) ); ?>>
								<?php esc_html_e( 'Show the language code', 'mclogiora' ); ?>
							</label>
						</p>
						<p>
							<label>
								<input type="checkbox" name="switcher_show_flag" value="1" <?php checked( ! empty( $settings['switcher_show_flag'] ) ); ?>>
								<?php esc_html_e( 'Show an optional flag', 'mclogiora' ); ?>
							</label>
						</p>
						<p class="mclogiora-muted-line"><?php esc_html_e( 'A language is not a country, so no flag is assumed for any language. Flags appear only where your site supplies one, and the readable label never depends on them.', 'mclogiora' ); ?></p>
						<p>
							<label>
								<input type="checkbox" name="switcher_show_current" value="1" <?php checked( ! empty( $settings['switcher_show_current'] ) ); ?>>
								<?php esc_html_e( 'Include the current language', 'mclogiora' ); ?>
							</label>
						</p>
						<p>
							<label>
								<span><?php esc_html_e( 'When a translation is missing', 'mclogiora' ); ?></span>
								<select name="switcher_missing">
									<option value="<?php echo esc_attr( MissingTranslationPolicy::HIDE ); ?>" <?php selected( $settings['switcher_missing'], MissingTranslationPolicy::HIDE ); ?>><?php esc_html_e( 'Hide the language', 'mclogiora' ); ?></option>
									<option value="<?php echo esc_attr( MissingTranslationPolicy::HOME ); ?>" <?php selected( $settings['switcher_missing'], MissingTranslationPolicy::HOME ); ?>><?php esc_html_e( 'Link to that language home page', 'mclogiora' ); ?></option>
									<option value="<?php echo esc_attr( MissingTranslationPolicy::DISABLE ); ?>" <?php selected( $settings['switcher_missing'], MissingTranslationPolicy::DISABLE ); ?>><?php esc_html_e( 'Show it as unavailable', 'mclogiora' ); ?></option>
								</select>
							</label>
						</p>
						<p class="mclogiora-muted-line"><?php esc_html_e( 'mcLogiora never links a language to content in a different language. A missing translation is reported honestly rather than disguised.', 'mclogiora' ); ?></p>
					</article>

					<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save Settings', 'mclogiora' ); ?></button></p>
				</form>

				<article class="mclogiora-info-card">
					<h2><?php esc_html_e( 'Using the Switcher', 'mclogiora' ); ?></h2>
					<p><?php esc_html_e( 'Add the switcher with the shortcode, the block, the widget, or a template tag:', 'mclogiora' ); ?></p>
					<p><code>[mclogiora_switcher]</code></p>
					<p><code>[mclogiora_switcher style="dropdown" show_code="1"]</code></p>
					<p><code>&lt;?php mclogiora_the_language_switcher(); ?&gt;</code></p>
				</article>
			</section>
		</div>
		<?php
	}

	/**
	 * Saves the settings.
	 *
	 * @return void
	 */
	public function handle_save() {
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			wp_die( esc_html__( 'The request could not be verified.', 'mclogiora' ), '', array( 'response' => 400 ) );
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) );

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'The request could not be verified.', 'mclogiora' ), '', array( 'response' => 403 ) );
		}

		if ( ! current_user_can( $this->capability ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'mclogiora' ), '', array( 'response' => 403 ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified immediately above.
		$input = array(
			'url_strategy'            => isset( $_POST['url_strategy'] ) ? sanitize_key( wp_unslash( $_POST['url_strategy'] ) ) : '',
			'default_language_prefix' => isset( $_POST['default_language_prefix'] ),
			'switcher_style'          => isset( $_POST['switcher_style'] ) ? sanitize_key( wp_unslash( $_POST['switcher_style'] ) ) : '',
			'switcher_show_name'      => isset( $_POST['switcher_show_name'] ),
			'switcher_show_code'      => isset( $_POST['switcher_show_code'] ),
			'switcher_show_flag'      => isset( $_POST['switcher_show_flag'] ),
			'switcher_show_current'   => isset( $_POST['switcher_show_current'] ),
			'switcher_missing'        => isset( $_POST['switcher_missing'] ) ? sanitize_key( wp_unslash( $_POST['switcher_missing'] ) ) : '',
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$result = $this->settings->save( $input );

		/*
		 * Rewrite rules are only rebuilt when the URL shape changed. Changing
		 * a switcher colour must never trigger a rewrite flush.
		 */
		if ( $result['routing_changed'] ) {
			RoutingModule::invalidate_rules();
		}

		wp_safe_redirect(
			add_query_arg(
				array( 'mclogiora_notice' => 'saved' ),
				admin_url( 'admin.php?page=' . self::PAGE_SLUG )
			)
		);
		exit;
	}

	/**
	 * Renders the saved notice.
	 *
	 * @return void
	 */
	private function render_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect feedback.
		$notice = isset( $_GET['mclogiora_notice'] ) ? sanitize_key( wp_unslash( $_GET['mclogiora_notice'] ) ) : '';

		if ( 'saved' !== $notice ) {
			return;
		}

		printf(
			'<div class="notice notice-success"><p>%s</p></div>',
			esc_html__( 'Settings saved.', 'mclogiora' )
		);
	}
}
