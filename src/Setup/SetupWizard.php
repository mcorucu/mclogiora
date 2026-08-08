<?php
/**
 * Setup wizard module.
 *
 * @package McLogiora
 */

namespace McLogiora\Setup;

use McLogiora\Admin\AdminScreen;
use McLogiora\Admin\AdminScreenRegistry;
use McLogiora\Capabilities\CapabilityRegistry;
use McLogiora\Contracts\ModuleInterface;
use McLogiora\Core\Container;
use McLogiora\Helpers\Security;
use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageServiceInterface;
use McLogiora\Languages\LanguageStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the setup wizard.
 */
final class SetupWizard implements ModuleInterface {
	const NONCE_ACTION = 'mclogiora_setup_default_language';
	const NONCE_FIELD  = 'mclogiora_setup_nonce';

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
	private $language_service = null;

	/**
	 * Wizard step keys.
	 *
	 * @var string[]
	 */
	private $steps = array(
		'welcome',
		'default_language',
		'additional_languages',
		'url_format',
		'switcher',
		'finish',
	);

	/**
	 * Registers the setup wizard admin screen.
	 *
	 * @param Container $container Service container.
	 * @return void
	 */
	public function register( Container $container ) {
		$capabilities           = $container->get( CapabilityRegistry::class );
		$this->capability      = $capabilities->resolve( CapabilityRegistry::MANAGE_SETTINGS );
		$this->language_service = $container->get( LanguageServiceInterface::class );

		$registry = $container->get( AdminScreenRegistry::class );
		$registry->add(
			new AdminScreen(
				__( 'mcLogiora Setup Wizard', 'mclogiora' ),
				__( 'Setup Wizard', 'mclogiora' ),
				$this->capability,
				'mclogiora-setup',
				array( $this, 'render' )
			)
		);
	}

	/**
	 * Renders the setup wizard.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( $this->capability ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mclogiora' ) );
		}

		$notice = $this->maybe_handle_post();
		$step   = $this->current_step();

		?>
		<div class="wrap mclogiora-admin">
			<section class="mclogiora-panel" aria-labelledby="mclogiora-setup-title">
				<p class="mclogiora-eyebrow"><?php esc_html_e( 'Setup Wizard', 'mclogiora' ); ?></p>
				<h1 id="mclogiora-setup-title"><?php esc_html_e( 'mcLogiora Setup', 'mclogiora' ); ?></h1>
				<p class="mclogiora-lede"><?php esc_html_e( 'The first setup steps now persist the default language. Later wizard steps remain placeholders until their phases arrive.', 'mclogiora' ); ?></p>

				<?php $this->render_notice( $notice ); ?>
				<?php $this->render_steps( $step ); ?>

				<?php if ( 'default_language' === $step ) : ?>
					<?php $this->render_default_language_step(); ?>
				<?php else : ?>
					<?php $this->render_welcome_step(); ?>
				<?php endif; ?>
			</section>
		</div>
		<?php
	}

	/**
	 * Handles posted setup actions.
	 *
	 * @return array<string, string>|null
	 */
	private function maybe_handle_post() {
		if ( empty( $_POST['mclogiora_setup_action'] ) ) {
			return null;
		}

		if ( ! Security::current_user_can( $this->capability ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'mclogiora' ) );
		}

		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';

		if ( ! Security::verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return $this->notice( 'error', __( 'Security check failed. Please try again.', 'mclogiora' ) );
		}

		if ( ! $this->language_service instanceof LanguageServiceInterface ) {
			return $this->notice( 'error', __( 'Language services are not available.', 'mclogiora' ) );
		}

		$action = sanitize_key( wp_unslash( $_POST['mclogiora_setup_action'] ) );

		if ( 'set_existing_default' === $action ) {
			$code   = isset( $_POST['language_code'] ) ? sanitize_key( wp_unslash( $_POST['language_code'] ) ) : '';
			$result = $this->language_service->set_default_language( $code );
		} elseif ( 'create_default_language' === $action ) {
			$result = $this->language_service->create_language(
				array(
					'code'         => isset( $_POST['language_code'] ) ? wp_unslash( $_POST['language_code'] ) : '',
					'locale'       => isset( $_POST['locale'] ) ? wp_unslash( $_POST['locale'] ) : '',
					'native_name'  => isset( $_POST['native_name'] ) ? wp_unslash( $_POST['native_name'] ) : '',
					'english_name' => isset( $_POST['english_name'] ) ? wp_unslash( $_POST['english_name'] ) : '',
					'direction'    => isset( $_POST['direction'] ) ? wp_unslash( $_POST['direction'] ) : '',
					'status'       => LanguageStatus::ACTIVE,
					'default'      => true,
				)
			);
		} else {
			return $this->notice( 'error', __( 'Unknown setup action.', 'mclogiora' ) );
		}

		if ( is_wp_error( $result ) ) {
			return $this->notice( 'error', $result->get_error_message() );
		}

		return $this->notice( 'success', __( 'Default language saved.', 'mclogiora' ) );
	}

	/**
	 * Returns the current setup step.
	 *
	 * @return string
	 */
	private function current_step() {
		$step = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : 'welcome';

		return in_array( $step, array( 'welcome', 'default_language' ), true ) ? $step : 'welcome';
	}

	/**
	 * Renders setup step navigation.
	 *
	 * @param string $current_step Current step.
	 * @return void
	 */
	private function render_steps( $current_step ) {
		?>
		<ol class="mclogiora-step-list mclogiora-step-list--compact">
			<?php foreach ( $this->steps as $index => $step ) : ?>
				<?php
				$is_available = in_array( $step, array( 'welcome', 'default_language' ), true );
				$is_current   = $current_step === $step;
				$class        = $is_current ? 'mclogiora-step mclogiora-step--current' : 'mclogiora-step';
				?>
				<li class="<?php echo esc_attr( $class ); ?>">
					<span class="mclogiora-step__number"><?php echo esc_html( (string) ( $index + 1 ) ); ?></span>
					<div>
						<h2><?php echo esc_html( $this->label_for_step( $step ) ); ?></h2>
						<p><?php echo esc_html( $this->description_for_step( $step, $is_available ) ); ?></p>
					</div>
				</li>
			<?php endforeach; ?>
		</ol>
		<?php
	}

	/**
	 * Renders the welcome step.
	 *
	 * @return void
	 */
	private function render_welcome_step() {
		?>
		<div class="mclogiora-table-card">
			<h2><?php esc_html_e( 'Welcome', 'mclogiora' ); ?></h2>
			<p><?php esc_html_e( 'Start by choosing the default language for this site. This is the only setup decision saved in Phase 07.', 'mclogiora' ); ?></p>
			<a class="button button-primary mclogiora-button" href="<?php echo esc_url( admin_url( 'admin.php?page=mclogiora-setup&step=default_language' ) ); ?>"><?php esc_html_e( 'Choose Default Language', 'mclogiora' ); ?></a>
		</div>
		<?php
	}

	/**
	 * Renders the default language step.
	 *
	 * @return void
	 */
	private function render_default_language_step() {
		$languages = $this->language_service instanceof LanguageServiceInterface ? $this->language_service->get_languages() : array();
		$default   = $this->language_service instanceof LanguageServiceInterface ? $this->language_service->get_default_language() : null;

		?>
		<div class="mclogiora-card-grid mclogiora-card-grid--two">
			<article class="mclogiora-info-card">
				<h2><?php esc_html_e( 'Current Default', 'mclogiora' ); ?></h2>
				<?php if ( $default instanceof Language ) : ?>
					<p class="mclogiora-card-value"><?php echo esc_html( $default->english_name() ); ?></p>
					<p><?php echo esc_html( sprintf( '%s / %s', $default->code(), $default->locale() ) ); ?></p>
				<?php else : ?>
					<p><?php esc_html_e( 'No default language has been saved yet.', 'mclogiora' ); ?></p>
				<?php endif; ?>
			</article>

			<article class="mclogiora-info-card">
				<h2><?php esc_html_e( 'Use Existing Language', 'mclogiora' ); ?></h2>
				<form class="mclogiora-language-form" method="post">
					<?php echo Security::nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<input type="hidden" name="mclogiora_setup_action" value="set_existing_default">
					<label>
						<span><?php esc_html_e( 'Language', 'mclogiora' ); ?></span>
						<select name="language_code" <?php disabled( empty( $languages ) ); ?>>
							<?php foreach ( $languages as $language ) : ?>
								<option value="<?php echo esc_attr( $language->code() ); ?>" <?php selected( $language->is_default() ); ?>><?php echo esc_html( $language->english_name() . ' (' . $language->locale() . ')' ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<button type="submit" class="button button-primary mclogiora-button" <?php disabled( empty( $languages ) ); ?>><?php esc_html_e( 'Save Default', 'mclogiora' ); ?></button>
				</form>
			</article>
		</div>

		<div class="mclogiora-table-card">
			<h2><?php esc_html_e( 'Create Default Language', 'mclogiora' ); ?></h2>
			<p><?php esc_html_e( 'Create a new active language and mark it as the site default.', 'mclogiora' ); ?></p>
			<form class="mclogiora-language-form mclogiora-language-form--wide" method="post">
				<?php echo Security::nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<input type="hidden" name="mclogiora_setup_action" value="create_default_language">
				<label>
					<span><?php esc_html_e( 'Language code', 'mclogiora' ); ?></span>
					<input type="text" name="language_code" placeholder="<?php esc_attr_e( 'en', 'mclogiora' ); ?>" required>
				</label>
				<label>
					<span><?php esc_html_e( 'Locale', 'mclogiora' ); ?></span>
					<input type="text" name="locale" placeholder="<?php esc_attr_e( 'en_US', 'mclogiora' ); ?>" required>
				</label>
				<label>
					<span><?php esc_html_e( 'Native name', 'mclogiora' ); ?></span>
					<input type="text" name="native_name" placeholder="<?php esc_attr_e( 'English', 'mclogiora' ); ?>" required>
				</label>
				<label>
					<span><?php esc_html_e( 'English name', 'mclogiora' ); ?></span>
					<input type="text" name="english_name" placeholder="<?php esc_attr_e( 'English', 'mclogiora' ); ?>" required>
				</label>
				<label>
					<span><?php esc_html_e( 'Direction', 'mclogiora' ); ?></span>
					<select name="direction">
						<option value="ltr"><?php esc_html_e( 'Left to right', 'mclogiora' ); ?></option>
						<option value="rtl"><?php esc_html_e( 'Right to left', 'mclogiora' ); ?></option>
					</select>
				</label>
				<button type="submit" class="button button-primary mclogiora-button"><?php esc_html_e( 'Create and Save Default', 'mclogiora' ); ?></button>
			</form>
		</div>
		<?php
	}

	/**
	 * Creates a notice payload.
	 *
	 * @param string $type Notice type.
	 * @param string $message Notice message.
	 * @return array<string, string>
	 */
	private function notice( $type, $message ) {
		return array(
			'type'    => $type,
			'message' => $message,
		);
	}

	/**
	 * Renders a screen notice.
	 *
	 * @param array<string, string>|null $notice Notice payload.
	 * @return void
	 */
	private function render_notice( $notice ) {
		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return;
		}

		$class = 'success' === $notice['type'] ? 'mclogiora-notice mclogiora-notice--success' : 'mclogiora-notice mclogiora-notice--error';
		?>
		<div class="<?php echo esc_attr( $class ); ?>" role="status">
			<p><?php echo esc_html( $notice['message'] ); ?></p>
		</div>
		<?php
	}

	/**
	 * Returns the translated label for a step.
	 *
	 * @param string $step Step key.
	 * @return string
	 */
	private function label_for_step( $step ) {
		$labels = array(
			'welcome'              => __( 'Welcome', 'mclogiora' ),
			'default_language'     => __( 'Default Language', 'mclogiora' ),
			'additional_languages' => __( 'Additional Languages', 'mclogiora' ),
			'url_format'           => __( 'URL Format', 'mclogiora' ),
			'switcher'             => __( 'Switcher', 'mclogiora' ),
			'finish'               => __( 'Finish', 'mclogiora' ),
		);

		return isset( $labels[ $step ] ) ? $labels[ $step ] : $step;
	}

	/**
	 * Returns the translated description for a step.
	 *
	 * @param string $step Step key.
	 * @param bool   $available Whether this step is available.
	 * @return string
	 */
	private function description_for_step( $step, $available ) {
		if ( ! $available ) {
			return __( 'Placeholder for a future phase.', 'mclogiora' );
		}

		$descriptions = array(
			'welcome'          => __( 'Introduce mcLogiora and confirm the setup path.', 'mclogiora' ),
			'default_language' => __( 'Persist the default language in the language table.', 'mclogiora' ),
		);

		return isset( $descriptions[ $step ] ) ? $descriptions[ $step ] : '';
	}
}
