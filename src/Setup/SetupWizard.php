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
use McLogiora\Languages\LanguageCatalog;
use McLogiora\Languages\LanguageDefinition;
use McLogiora\Languages\LanguageServiceInterface;
use McLogiora\Languages\LanguageStatus;
use McLogiora\Routing\RoutingModule;
use McLogiora\Routing\RoutingSettings;
use McLogiora\Routing\UrlStrategy;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the first-run setup journey.
 */
final class SetupWizard implements ModuleInterface {
	const NONCE_ACTION = 'mclogiora_setup_wizard';
	const NONCE_FIELD  = 'mclogiora_setup_nonce';
	const PAGE_SLUG    = 'mclogiora-setup';

	/** Effective capability for setup mutations.
	 *
	 * @var string
	 */
	private $capability = 'manage_options';

	/** Canonical language service.
	 *
	 * @var LanguageServiceInterface|null
	 */
	private $language_service = null;

	/** Canonical routing settings service.
	 *
	 * @var RoutingSettings|null
	 */
	private $routing_settings = null;

	/** Notice collected while handling an invalid mutation before output.
	 *
	 * @var array<string,string>|null
	 */
	private $request_notice = null;

	/** Ordered server-rendered steps.
	 *
	 * @var string[]
	 */
	private $steps = array(
		'welcome',
		'languages',
		'default_language',
		'url_format',
		'review',
		'complete',
	);

	/**
	 * Registers the setup wizard admin screen and activation hand-off.
	 *
	 * @param Container $container Service container.
	 * @return void
	 */
	public function register( Container $container ) {
		$capabilities           = $container->get( CapabilityRegistry::class );
		$this->capability       = $capabilities->resolve( CapabilityRegistry::MANAGE_SETTINGS );
		$this->language_service = $container->get( LanguageServiceInterface::class );
		$this->routing_settings = $container->get( RoutingSettings::class );

		$registry = $container->get( AdminScreenRegistry::class );
		$registry->add(
			new AdminScreen(
				static function () {
					return __( 'mcLogiora Setup Wizard', 'mclogiora' );
				},
				static function () {
					return __( 'Setup Wizard', 'mclogiora' );
				},
				$this->capability,
				self::PAGE_SLUG,
				array( $this, 'render' )
			)
		);

		if ( is_admin() ) {
			add_action( 'admin_init', array( $this, 'maybe_redirect_after_activation' ), 1 );
			add_action( 'admin_init', array( $this, 'handle_post' ), 2 );
		}
	}

	/**
	 * Redirects one eligible request after a fresh interactive activation.
	 *
	 * @return void
	 */
	public function maybe_redirect_after_activation() {
		if ( ! SetupState::has_pending_activation() || ! $this->eligible_activation_request() ) {
			return;
		}

		SetupState::consume_activation();

		if ( self::PAGE_SLUG === $this->requested_page() ) {
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&step=welcome' ) );
		exit;
	}

	/**
	 * Returns whether the current request is safe for the one-time redirect.
	 *
	 * @return bool
	 */
	public function eligible_activation_request() {
		if ( ! is_admin() || ! current_user_can( $this->capability ) ) {
			return false;
		}

		if ( ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() )
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() )
			|| ( defined( 'WP_CLI' ) && WP_CLI )
			|| ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
			return false;
		}

		if ( function_exists( 'is_network_admin' ) && is_network_admin() ) {
			return false;
		}

		$request_action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';

		if ( 'activate-selected' === $request_action || isset( $_REQUEST['activate-multi'] ) || isset( $_REQUEST['networkwide'] ) ) {
			return false;
		}

		return true;
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

		$notice  = $this->request_notice;
		$default = $this->default_language();
		$step    = $this->current_step( $default );

		if ( SetupState::COMPLETED !== SetupState::status() ) {
			SetupState::begin();
		}

		$is_review = SetupState::COMPLETED === SetupState::status();

		?>
		<div class="wrap mclogiora-admin mclogiora-setup-wizard">
			<section class="mclogiora-panel" aria-labelledby="mclogiora-setup-title" data-mclogiora-setup-wizard>
				<p class="mclogiora-eyebrow"><?php esc_html_e( 'Setup Wizard', 'mclogiora' ); ?></p>
				<h1 id="mclogiora-setup-title"><?php echo esc_html( $is_review ? __( 'Review your setup', 'mclogiora' ) : __( 'Set up mcLogiora', 'mclogiora' ) ); ?></h1>
				<p class="mclogiora-lede"><?php echo esc_html( $is_review ? __( 'Review the languages and URL choices currently used by your site. Nothing is reset by revisiting this screen.', 'mclogiora' ) : __( 'A few focused choices will prepare mcLogiora for your multilingual content. You can change them later in the dedicated settings screens.', 'mclogiora' ) ); ?></p>

				<?php $this->render_notice( $notice ); ?>
				<?php $this->render_steps( $step ); ?>

				<?php
				switch ( $step ) {
					case 'languages':
						$this->render_languages_step();
						break;
					case 'default_language':
						$this->render_default_language_step();
						break;
					case 'url_format':
						$this->render_url_format_step();
						break;
					case 'review':
						$this->render_review_step();
						break;
					case 'complete':
						$this->render_complete_step();
						break;
					default:
						$this->render_welcome_step( $is_review );
				}
				?>
			</section>
		</div>
		<?php
	}

	/**
	 * Handles posted setup actions.
	 *
	 * This runs on admin_init, before WordPress emits the admin header. Keeping
	 * mutations here preserves Post/Redirect/Get; the page callback only renders.
	 *
	 * @return void
	 */
	public function handle_post() {
		if ( ! is_admin() || self::PAGE_SLUG !== $this->requested_page() ) {
			return;
		}

		if ( empty( $_POST['mclogiora_setup_action'] ) ) {
			return;
		}

		if ( ! Security::current_user_can( $this->capability ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'mclogiora' ) );
		}

		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';

		if ( ! Security::verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			$this->request_notice = $this->notice( 'error', __( 'Security check failed. Please try again.', 'mclogiora' ) );
			return;
		}

		$action = sanitize_key( wp_unslash( $_POST['mclogiora_setup_action'] ) );

		if ( 'exit' === $action ) {
			SetupState::dismiss();
			$this->redirect_to( admin_url( 'admin.php?page=mclogiora' ) );
		}

		if ( ! $this->language_service instanceof LanguageServiceInterface || ! $this->routing_settings instanceof RoutingSettings ) {
			$this->request_notice = $this->notice( 'error', __( 'Setup services are not available. Please try again later.', 'mclogiora' ) );
			return;
		}

		switch ( $action ) {
			case 'continue_welcome':
				SetupState::begin();
				$this->redirect_to( $this->step_url( 'languages' ) );
				break;

			case 'add_language':
				$code     = isset( $_POST['language_code'] ) ? sanitize_key( wp_unslash( $_POST['language_code'] ) ) : '';
				$existing = $this->language_service->get_language_by_code( $code );

				if ( $existing instanceof Language ) {
					$this->request_notice = $this->notice( 'success', __( 'That language is already available. Continue when you are ready.', 'mclogiora' ) );
					return;
				}

				$result = $this->language_service->create_language(
					array(
						'code'         => $code,
						'locale'       => isset( $_POST['locale'] ) ? wp_unslash( $_POST['locale'] ) : '',
						'native_name'  => isset( $_POST['native_name'] ) ? wp_unslash( $_POST['native_name'] ) : '',
						'english_name' => isset( $_POST['english_name'] ) ? wp_unslash( $_POST['english_name'] ) : '',
						'direction'    => isset( $_POST['direction'] ) ? wp_unslash( $_POST['direction'] ) : '',
						'status'       => LanguageStatus::ACTIVE,
						'default'      => false,
					)
				);

				if ( is_wp_error( $result ) ) {
					$this->request_notice = $this->notice( 'error', $this->friendly_language_error( $result ) );
					return;
				}

				$this->redirect_to( $this->step_url( 'languages' ) );
				break;

			case 'save_catalog_languages':
				$primary    = isset( $_POST['primary_language'] ) ? sanitize_key( wp_unslash( $_POST['primary_language'] ) ) : '';
				$definition = LanguageCatalog::find( $primary );

				if ( ! $definition instanceof LanguageDefinition ) {
					$this->request_notice = $this->notice( 'error', __( 'Choose a primary language from the catalog before continuing.', 'mclogiora' ) );
					return;
				}

				$existing = $this->language_service->get_language_by_code( $definition->code() );
				$existing = $existing instanceof Language ? $existing : $this->language_service->get_language_by_locale( $definition->locale() );
				$result   = $existing instanceof Language
					? $this->language_service->set_default_language( $existing->code() )
					: $this->language_service->create_language( $definition->language_data( true, 1 ) );

				if ( is_wp_error( $result ) ) {
					$this->request_notice = $this->notice( 'error', $this->friendly_language_error( $result ) );
					return;
				}

				$targets      = isset( $_POST['translation_languages'] ) && is_array( $_POST['translation_languages'] ) ? wp_unslash( $_POST['translation_languages'] ) : array();
				$targets      = array_values( array_unique( array_map( 'sanitize_key', $targets ) ) );
				$order        = count( $this->language_service->get_languages() ) + 1;
				$primary_code = $existing instanceof Language ? $existing->code() : $definition->code();

				if ( SetupState::COMPLETED !== SetupState::status() ) {
					foreach ( $this->language_service->get_languages() as $configured ) {
						$configured_definition = LanguageCatalog::find( $configured->locale() );
						$is_selected_target    = in_array( $configured->code(), $targets, true ) || ( $configured_definition instanceof LanguageDefinition && in_array( $configured_definition->code(), $targets, true ) );

						if ( $configured->code() === $primary_code || $is_selected_target ) {
							continue;
						}

						$catalog_definition = LanguageCatalog::find( $configured->locale() );

						if ( $catalog_definition instanceof LanguageDefinition ) {
							$this->language_service->delete_language( $configured->code() );
						}
					}
				}

				foreach ( $targets as $target ) {
					if ( $target === $definition->code() || $this->language_service->get_language_by_code( $target ) instanceof Language ) {
						continue;
					}

					$target_definition = LanguageCatalog::find( $target );

					if ( ! $target_definition instanceof LanguageDefinition ) {
						continue;
					}

					if ( $this->language_service->get_language_by_locale( $target_definition->locale() ) instanceof Language ) {
						continue;
					}

					$created = $this->language_service->create_language( $target_definition->language_data( false, $order ) );

					if ( is_wp_error( $created ) ) {
						$this->request_notice = $this->notice( 'error', $this->friendly_language_error( $created ) );
						return;
					}

					++$order;
				}

				$this->redirect_to( $this->step_url( 'url_format' ) );
				break;

			case 'continue_languages':
				if ( empty( $this->language_service->get_languages() ) ) {
					$this->request_notice = $this->notice( 'error', __( 'Add at least one language before choosing the default.', 'mclogiora' ) );
					return;
				}

				SetupState::begin();
				$this->redirect_to( $this->step_url( 'default_language' ) );
				break;

			case 'set_existing_default':
				$result = $this->language_service->set_default_language( $this->posted_code() );

				if ( is_wp_error( $result ) ) {
					$this->request_notice = $this->notice( 'error', $this->friendly_language_error( $result ) );
					return;
				}

				$this->redirect_to( $this->step_url( 'url_format' ) );
				break;

			case 'create_default_language':
				$code     = $this->posted_code();
				$existing = $this->language_service->get_language_by_code( $code );
				$result   = $existing instanceof Language ? $this->language_service->set_default_language( $code ) : $this->language_service->create_language(
					array(
						'code'         => $code,
						'locale'       => isset( $_POST['locale'] ) ? wp_unslash( $_POST['locale'] ) : '',
						'native_name'  => isset( $_POST['native_name'] ) ? wp_unslash( $_POST['native_name'] ) : '',
						'english_name' => isset( $_POST['english_name'] ) ? wp_unslash( $_POST['english_name'] ) : '',
						'direction'    => isset( $_POST['direction'] ) ? wp_unslash( $_POST['direction'] ) : '',
						'status'       => LanguageStatus::ACTIVE,
						'default'      => true,
					)
				);

				if ( is_wp_error( $result ) ) {
					$this->request_notice = $this->notice( 'error', $this->friendly_language_error( $result ) );
					return;
				}

				$this->redirect_to( $this->step_url( 'url_format' ) );
				break;

			case 'save_routing':
				if ( ! $this->default_language() instanceof Language ) {
					$this->request_notice = $this->notice( 'error', __( 'Choose a default language before saving URL settings.', 'mclogiora' ) );
					return;
				}

				$input  = array(
					'url_strategy'            => UrlStrategy::DIRECTORY,
					'default_language_prefix' => isset( $_POST['default_language_prefix'] ),
				);
				$result = $this->routing_settings->save( $input );

				if ( ! empty( $result['routing_changed'] ) ) {
					RoutingModule::invalidate_rules();
				}

				$this->redirect_to( $this->step_url( 'review' ) );
				break;

			case 'finish_setup':
				if ( ! $this->default_language() instanceof Language ) {
					$this->request_notice = $this->notice( 'error', __( 'Choose a default language before finishing setup.', 'mclogiora' ) );
					return;
				}

				SetupState::complete();
				$this->redirect_to( $this->step_url( 'complete' ) );
				break;

			default:
				$this->request_notice = $this->notice( 'error', __( 'That setup action is not available. Please try again.', 'mclogiora' ) );
				return;
		}
	}

	/**
	 * Returns the current step constrained by actual prerequisites.
	 *
	 * @param Language|null $default_language Current default language.
	 * @return string Current step key.
	 */
	private function current_step( $default_language ) {
		$has_step = isset( $_GET['step'] );
		$step     = $has_step ? sanitize_key( wp_unslash( $_GET['step'] ) ) : ( $default_language instanceof Language && SetupState::COMPLETED === SetupState::status() ? 'review' : 'welcome' );

		if ( ! in_array( $step, $this->steps, true ) ) {
			$step = 'welcome';
		}

		if ( 'complete' === $step && ( ! $default_language instanceof Language || SetupState::COMPLETED !== SetupState::status() ) ) {
			$step = 'review';
		}

		if ( in_array( $step, array( 'url_format', 'review' ), true ) && ! $default_language instanceof Language ) {
			$step = empty( $this->languages() ) ? 'languages' : 'default_language';
		}

		return $step;
	}

	/**
	 * Renders the accessible step progress indicator.
	 *
	 * @param string $current_step Current step key.
	 * @return void
	 */
	private function render_steps( $current_step ) {
		$current_index = array_search( $current_step, $this->steps, true );

		?>
		<ol class="mclogiora-step-list" aria-label="<?php esc_attr_e( 'Setup progress', 'mclogiora' ); ?>">
			<?php foreach ( $this->steps as $index => $step ) : ?>
				<?php
				$is_current = $current_step === $step;
				$is_done    = $index < $current_index || ( 'complete' === $current_step && 'complete' !== $step );
				$class      = 'mclogiora-step';

				if ( $is_current ) {
					$class .= ' mclogiora-step--current';
				} elseif ( $is_done ) {
					$class .= ' mclogiora-step--done';
				}
				?>
				<?php
				/* translators: 1: current step number, 2: total step count, 3: step name. */
				$step_aria = sprintf( __( 'Step %1$d of %2$d: %3$s', 'mclogiora' ), $index + 1, count( $this->steps ), $this->label_for_step( $step ) );
				?>
				<li class="<?php echo esc_attr( $class ); ?>" <?php echo $is_current ? 'aria-current="step"' : ''; ?> aria-label="<?php echo esc_attr( $step_aria ); ?>">
					<span class="mclogiora-step__number" aria-hidden="true"><?php echo esc_html( (string) ( $index + 1 ) ); ?></span>
					<div><h2><?php echo esc_html( $this->label_for_step( $step ) ); ?></h2><p><?php echo esc_html( $this->description_for_step( $step ) ); ?></p></div>
				</li>
			<?php endforeach; ?>
		</ol>
		<?php
	}

	/**
	 * Renders the welcome or completed-site entry step.
	 *
	 * @param bool $is_review Whether this is a completed-site revisit.
	 * @return void
	 */
	private function render_welcome_step( $is_review ) {
		?>
		<div class="mclogiora-table-card mclogiora-setup-card">
			<h2><?php echo esc_html( $is_review ? __( 'Review setup', 'mclogiora' ) : __( 'Welcome to mcLogiora', 'mclogiora' ) ); ?></h2>
			<p><?php echo esc_html( $is_review ? __( 'Your current languages and URL choices are safe. Open the settings below whenever you want to review them.', 'mclogiora' ) : __( 'This short setup helps mcLogiora understand the languages your site uses and how visitors should reach them.', 'mclogiora' ) ); ?></p>
			<div class="mclogiora-setup-actions">
				<a class="button button-primary mclogiora-button" href="<?php echo esc_url( $this->step_url( $is_review ? 'review' : 'languages' ) ); ?>"><?php echo esc_html( $is_review ? __( 'Review current setup', 'mclogiora' ) : __( 'Start setup', 'mclogiora' ) ); ?></a>
				<?php
				if ( ! $is_review ) :
					?>
					<?php $this->render_exit_form(); ?><?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders the language selection and add form.
	 *
	 * @return void
	 */
	private function render_languages_step() {
		$languages = $this->languages();
		$default   = $this->default_language();
		$selected  = array();
		$suggested = LanguageCatalog::suggested_for_site();

		foreach ( $languages as $language ) {
			if ( $default instanceof Language && $language->code() === $default->code() ) {
				continue;
			}

			$selected[] = $language->code();
		}

		?>
		<div class="mclogiora-table-card mclogiora-setup-card">
			<h2><?php esc_html_e( 'Choose your site languages', 'mclogiora' ); ?></h2>
			<p><?php esc_html_e( 'Choose the languages your site uses. mcLogiora resolves the technical locale, language tag, and direction for you.', 'mclogiora' ); ?></p>
			<?php if ( ! empty( $languages ) ) : ?>
				<ul class="mclogiora-setup-language-list" aria-label="<?php esc_attr_e( 'Configured languages', 'mclogiora' ); ?>">
					<?php
					foreach ( $languages as $language ) :
						?>
						<li><strong><?php echo esc_html( $language->native_name() ); ?></strong> <span><?php echo esc_html( sprintf( '%s · %s · %s', $language->english_name(), $language->locale(), strtoupper( $language->direction() ) ) ); ?></span>
						<?php
						if ( $default instanceof Language && $language->code() === $default->code() ) :
							?>
							<em><?php esc_html_e( 'Primary', 'mclogiora' ); ?></em><?php endif; ?></li><?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<form class="mclogiora-language-form mclogiora-language-form--wide mclogiora-catalog-picker" method="post" data-mclogiora-setup-language-picker data-mclogiora-language-picker>
				<?php echo Security::nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<input type="hidden" name="mclogiora_setup_action" value="save_catalog_languages">
				<h3><?php esc_html_e( 'What is the primary language of this site?', 'mclogiora' ); ?></h3>
				<p class="mclogiora-setup-tip"><?php esc_html_e( 'Choose the language most of your existing content is written in. mcLogiora will use this as the starting point for translation relationships.', 'mclogiora' ); ?></p>
				<?php $this->render_catalog_picker( 'primary_language', false, $default instanceof Language ? $default->code() : ( $suggested instanceof LanguageDefinition ? $suggested->code() : '' ), array(), true ); ?>
				<h3><?php esc_html_e( 'Which languages would you like to translate into?', 'mclogiora' ); ?></h3>
				<p class="mclogiora-setup-tip"><?php esc_html_e( 'You can add or remove languages later. Choosing a language does not translate or publish any content automatically.', 'mclogiora' ); ?></p>
				<?php $this->render_catalog_picker( 'translation_languages', true, '', $selected, false ); ?>
				<div class="mclogiora-setup-actions"><button type="submit" class="button button-primary mclogiora-button"><?php esc_html_e( 'Save languages and continue', 'mclogiora' ); ?></button></div>
			</form>
			<div class="mclogiora-setup-actions">
				<form method="post"><?php echo Security::nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><input type="hidden" name="mclogiora_setup_action" value="continue_languages"><button type="submit" class="button mclogiora-button" <?php disabled( empty( $languages ) ); ?>><?php esc_html_e( 'Continue to default language', 'mclogiora' ); ?></button></form>
				<?php $this->render_exit_form(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders the default language step.
	 *
	 * @return void
	 */
	private function render_default_language_step() {
		$languages = $this->languages();
		$default   = $this->default_language();

		?>
		<div class="mclogiora-table-card mclogiora-setup-card">
			<h2><?php esc_html_e( 'Default language', 'mclogiora' ); ?></h2>
			<p><?php esc_html_e( 'This is the language used for your existing content and site-wide defaults. It must be chosen before setup can be finished.', 'mclogiora' ); ?></p>
			<?php
			if ( $default instanceof Language ) :
				?>
				<p class="mclogiora-status-line"><strong><?php esc_html_e( 'Current default:', 'mclogiora' ); ?></strong> <?php echo esc_html( $default->english_name() . ' (' . $default->locale() . ')' ); ?></p><?php endif; ?>
			<form class="mclogiora-language-form" method="post">
				<?php echo Security::nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<input type="hidden" name="mclogiora_setup_action" value="set_existing_default">
				<label><span><?php esc_html_e( 'Choose a configured language', 'mclogiora' ); ?></span><select name="language_code" required>
				<?php
				foreach ( $languages as $language ) :
					?>
					<option value="<?php echo esc_attr( $language->code() ); ?>" <?php selected( $language->is_default() ); ?>><?php echo esc_html( $language->english_name() . ' (' . $language->locale() . ')' ); ?></option><?php endforeach; ?></select></label>
				<button type="submit" class="button button-primary mclogiora-button" <?php disabled( empty( $languages ) ); ?>><?php esc_html_e( 'Save and continue', 'mclogiora' ); ?></button>
			</form>
			<p class="mclogiora-muted-line"><a href="<?php echo esc_url( $this->step_url( 'languages' ) ); ?>"><?php esc_html_e( 'Add or review languages', 'mclogiora' ); ?></a></p>
			<div class="mclogiora-setup-actions"><a class="button" href="<?php echo esc_url( $this->step_url( 'languages' ) ); ?>"><?php esc_html_e( 'Back', 'mclogiora' ); ?></a><?php $this->render_exit_form(); ?></div>
		</div>
		<?php
	}

	/**
	 * Renders the language URL step.
	 *
	 * @return void
	 */
	private function render_url_format_step() {
		$settings = $this->routing_settings instanceof RoutingSettings ? $this->routing_settings->all() : RoutingSettings::defaults();

		?>
		<div class="mclogiora-table-card mclogiora-setup-card">
			<h2><?php esc_html_e( 'Language URLs', 'mclogiora' ); ?></h2>
			<p><?php esc_html_e( 'mcLogiora uses language directories, such as /tr/about/. The default language can keep your existing root URLs, or you can include its directory too.', 'mclogiora' ); ?></p>
			<form class="mclogiora-language-form" method="post">
				<?php echo Security::nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<input type="hidden" name="mclogiora_setup_action" value="save_routing">
				<label><span><?php esc_html_e( 'URL structure', 'mclogiora' ); ?></span><select name="url_strategy"><option value="<?php echo esc_attr( UrlStrategy::DIRECTORY ); ?>" selected><?php esc_html_e( 'Language directory', 'mclogiora' ); ?></option></select></label>
				<label class="mclogiora-checkbox-label"><input type="checkbox" name="default_language_prefix" value="1" <?php checked( ! empty( $settings['default_language_prefix'] ) ); ?>> <span><?php esc_html_e( 'Also add a directory for the default language', 'mclogiora' ); ?></span></label>
				<p class="mclogiora-muted-line"><?php esc_html_e( 'Leaving this off keeps default-language URLs at the site root. If your site uses plain permalinks, review WordPress Permalinks after setup so rewrite rules can be refreshed.', 'mclogiora' ); ?></p>
				<button type="submit" class="button button-primary mclogiora-button"><?php esc_html_e( 'Save and review', 'mclogiora' ); ?></button>
			</form>
			<div class="mclogiora-setup-actions"><a class="button" href="<?php echo esc_url( $this->step_url( 'default_language' ) ); ?>"><?php esc_html_e( 'Back', 'mclogiora' ); ?></a><?php $this->render_exit_form(); ?></div>
		</div>
		<?php
	}

	/**
	 * Renders the review summary.
	 *
	 * @return void
	 */
	private function render_review_step() {
		$default  = $this->default_language();
		$settings = $this->routing_settings instanceof RoutingSettings ? $this->routing_settings->all() : RoutingSettings::defaults();

		?>
		<div class="mclogiora-table-card mclogiora-setup-card">
			<h2><?php esc_html_e( 'Review setup', 'mclogiora' ); ?></h2>
			<p><?php esc_html_e( 'Check the choices that mcLogiora will use. Translation Suggestions remain optional and are not part of core setup.', 'mclogiora' ); ?></p>
			<dl class="mclogiora-setup-summary"><dt><?php esc_html_e( 'Primary language', 'mclogiora' ); ?></dt><dd><?php echo esc_html( $default instanceof Language ? sprintf( '%s · %s · %s', $default->native_name(), $default->locale(), strtoupper( $default->direction() ) ) : __( 'Not selected', 'mclogiora' ) ); ?></dd><dt><?php esc_html_e( 'Translation languages', 'mclogiora' ); ?></dt><dd><?php echo esc_html( $this->language_names( $default ) ); ?></dd><dt><?php esc_html_e( 'Language URLs', 'mclogiora' ); ?></dt><dd><?php echo esc_html( ! empty( $settings['default_language_prefix'] ) ? __( 'Directories for every configured language, such as /en-us/ and /de/', 'mclogiora' ) : __( 'Translated languages use directories such as /de/; the primary language stays at the site root', 'mclogiora' ) ); ?></dd><dt><?php esc_html_e( 'Translation Suggestions', 'mclogiora' ); ?></dt><dd><?php esc_html_e( 'Not configured — optional', 'mclogiora' ); ?></dd></dl>
			<div class="mclogiora-setup-actions"><a class="button" href="<?php echo esc_url( $this->step_url( 'url_format' ) ); ?>"><?php esc_html_e( 'Back', 'mclogiora' ); ?></a><form method="post"><?php echo Security::nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><input type="hidden" name="mclogiora_setup_action" value="finish_setup"><button type="submit" class="button button-primary mclogiora-button" <?php disabled( ! $default instanceof Language ); ?>><?php esc_html_e( 'Finish setup', 'mclogiora' ); ?></button></form><?php $this->render_exit_form(); ?></div>
		</div>
		<?php
	}

	/**
	 * Renders the completion education and next actions.
	 *
	 * @return void
	 */
	private function render_complete_step() {
		?>
		<div class="mclogiora-table-card mclogiora-setup-card mclogiora-setup-complete" tabindex="-1" data-mclogiora-focus-heading="1">
			<h2><?php esc_html_e( 'Your multilingual foundation is ready.', 'mclogiora' ); ?></h2>
			<p><?php esc_html_e( 'mcLogiora now knows your site languages and default language. You can build translation relationships when you are ready.', 'mclogiora' ); ?></p>
			<ul class="mclogiora-check-list"><li><?php esc_html_e( 'Languages configured', 'mclogiora' ); ?></li><li><?php esc_html_e( 'Default language set', 'mclogiora' ); ?></li><li><?php esc_html_e( 'Language URL behavior saved', 'mclogiora' ); ?></li></ul>
			<div class="mclogiora-setup-next"><h3><?php esc_html_e( 'How mcLogiora works', 'mclogiora' ); ?></h3><ol><li><strong><?php esc_html_e( 'Discover existing content.', 'mclogiora' ); ?></strong> <?php esc_html_e( 'Translation Manager can show supported posts, pages, public custom post types, and taxonomies.', 'mclogiora' ); ?></li><li><strong><?php esc_html_e( 'Create or link a translation.', 'mclogiora' ); ?></strong> <?php esc_html_e( 'Use + Language to create a translated draft; each language version remains its own WordPress object.', 'mclogiora' ); ?></li><li><strong><?php esc_html_e( 'Translate and review.', 'mclogiora' ); ?></strong> <?php esc_html_e( 'Edit translations manually, or optionally request a suggestion that you review before using.', 'mclogiora' ); ?></li><li><strong><?php esc_html_e( 'Publish and navigate.', 'mclogiora' ); ?></strong> <?php esc_html_e( 'The relationship, language switcher, URLs, and multilingual metadata stay connected around your content.', 'mclogiora' ); ?></li></ol><div class="mclogiora-setup-example"><strong><?php esc_html_e( 'ABOUT PAGE', 'mclogiora' ); ?></strong><span><?php esc_html_e( 'Türkçe', 'mclogiora' ); ?> &nbsp; <button type="button" class="button button-small" disabled><?php esc_html_e( '+ EN', 'mclogiora' ); ?></button> &nbsp; <button type="button" class="button button-small" disabled><?php esc_html_e( '+ DE', 'mclogiora' ); ?></button><small><?php esc_html_e( '+ EN creates an English translation draft and opens it for editing.', 'mclogiora' ); ?></small></span></div></div>
			<details class="mclogiora-inline-details"><summary class="button"><?php esc_html_e( 'Good to know', 'mclogiora' ); ?></summary><ul><li><?php esc_html_e( 'Translations are independent WordPress objects; mcLogiora does not duplicate or publish content automatically.', 'mclogiora' ); ?></li><li><?php esc_html_e( 'Unlinking a relationship does not delete the content.', 'mclogiora' ); ?></li><li><?php esc_html_e( 'Translation Suggestions are optional and use providers you configure yourself.', 'mclogiora' ); ?></li></ul></details>
			<div class="mclogiora-setup-actions mclogiora-setup-actions--primary"><a class="button button-primary mclogiora-button" href="<?php echo esc_url( admin_url( 'admin.php?page=mclogiora-translation-manager' ) ); ?>"><?php esc_html_e( 'Open Translation Manager', 'mclogiora' ); ?></a><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=mclogiora-manual&article=quick-start' ) ); ?>"><?php esc_html_e( 'Read the 3-minute getting started guide', 'mclogiora' ); ?></a><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=mclogiora-languages' ) ); ?>"><?php esc_html_e( 'Manage Languages', 'mclogiora' ); ?></a><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=mclogiora-routing' ) ); ?>"><?php esc_html_e( 'Configure URLs', 'mclogiora' ); ?></a></div>
		</div>
		<?php
	}

	/**
	 * Renders the dismissible exit action.
	 *
	 * @return void
	 */
	private function render_exit_form() {
		?>
		<form method="post" class="mclogiora-setup-exit-form"><?php echo Security::nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><input type="hidden" name="mclogiora_setup_action" value="exit"><button type="submit" class="button-link"><?php esc_html_e( 'Exit for now', 'mclogiora' ); ?></button></form>
		<?php
	}

	/**
	 * Renders the shared catalog picker used by setup.
	 *
	 * @param string   $name Field name.
	 * @param bool     $multi Whether multiple choices are allowed.
	 * @param string   $checked_code Checked primary code.
	 * @param string[] $checked_codes Checked target codes.
	 * @param bool     $primary Whether this is the primary picker.
	 * @return void
	 */
	private function render_catalog_picker( $name, $multi, $checked_code = '', array $checked_codes = array(), $primary = false ) {
		$catalog    = LanguageCatalog::all();
		$suggestion = $primary ? LanguageCatalog::suggested_for_site() : null;
		?>
		<?php
		if ( $suggestion instanceof LanguageDefinition && ( '' === $checked_code || $suggestion->code() === $checked_code ) ) :
			?>
			<p class="mclogiora-language-suggestion" role="status"><strong><?php esc_html_e( 'Suggested from your WordPress site language', 'mclogiora' ); ?></strong> <?php echo esc_html( $suggestion->display_name() ); ?> (<?php echo esc_html( $suggestion->locale() ); ?>)</p><?php endif; ?>
		<label class="mclogiora-picker-search"><span><?php esc_html_e( 'Search languages', 'mclogiora' ); ?></span><input type="search" data-mclogiora-language-search placeholder="<?php esc_attr_e( 'Search by name, code, locale, or region', 'mclogiora' ); ?>"></label>
		<div class="mclogiora-language-options" role="<?php echo $multi ? 'group' : 'radiogroup'; ?>" aria-label="<?php esc_attr_e( 'Language catalog', 'mclogiora' ); ?>" data-mclogiora-language-group="<?php echo $primary ? 'primary' : 'target'; ?>">
			<?php foreach ( $catalog as $definition ) : ?>
				<?php $is_checked = $primary ? $definition->code() === $checked_code : in_array( $definition->code(), $checked_codes, true ); ?>
				<label class="mclogiora-language-option" data-mclogiora-language-option data-search="<?php echo esc_attr( strtolower( implode( ' ', array( $definition->code(), $definition->locale(), $definition->native_name(), $definition->english_name(), $definition->region() ) ) ) ); ?>">
					<input type="<?php echo $multi ? 'checkbox' : 'radio'; ?>" name="<?php echo esc_attr( $name . ( $multi ? '[]' : '' ) ); ?>" value="<?php echo esc_attr( $definition->code() ); ?>" data-mclogiora-language-choice="<?php echo $primary ? 'primary' : 'target'; ?>" <?php checked( $is_checked ); ?>>
					<span><strong><?php echo esc_html( $definition->native_name() ); ?></strong>
					<?php
					if ( $definition->english_name() !== $definition->native_name() ) :
						?>
						<span class="mclogiora-language-option__english"><?php echo esc_html( $definition->english_name() ); ?></span><?php endif; ?>
						<?php
						if ( '' !== $definition->region() ) :
							?>
						<span class="mclogiora-language-option__region"><?php echo esc_html( $definition->region() ); ?></span><?php endif; ?><small><?php echo esc_html( $definition->locale() ); ?> · <?php echo esc_html( strtoupper( $definition->direction() ) ); ?></small></span>
				</label>
			<?php endforeach; ?>
		</div>
		<p class="mclogiora-muted-line" data-mclogiora-language-empty hidden><?php esc_html_e( 'No catalog language matches that search.', 'mclogiora' ); ?></p>
		<?php
	}

	/**
	 * Converts known language errors to clear setup copy.
	 *
	 * @param \WP_Error $error Language error.
	 * @return string User-facing error message.
	 */
	private function friendly_language_error( $error ) {
		$known = array(
			'mclogiora_invalid_language_code'  => __( 'Enter a language code.', 'mclogiora' ),
			'mclogiora_invalid_locale'         => __( 'Enter a valid WordPress locale such as en_US or tr_TR.', 'mclogiora' ),
			'mclogiora_language_name_required' => __( 'Enter both the native and English language names.', 'mclogiora' ),
			'mclogiora_duplicate_locale'       => __( 'That WordPress locale is already in use.', 'mclogiora' ),
		);
		$code  = $error->get_error_code();

		return isset( $known[ $code ] ) ? $known[ $code ] : $error->get_error_message();
	}

	/**
	 * Returns all configured languages.
	 *
	 * @return Language[] Configured languages.
	 */
	private function languages() {
		return $this->language_service instanceof LanguageServiceInterface ? $this->language_service->get_languages() : array();
	}

	/**
	 * Returns the configured default language.
	 *
	 * @return Language|null Default language.
	 */
	private function default_language() {
		return $this->language_service instanceof LanguageServiceInterface ? $this->language_service->get_default_language() : null;
	}

	/**
	 * Returns a readable language summary.
	 *
	 * @param Language|null $exclude Language to omit.
	 * @return string Language names.
	 */
	private function language_names( $exclude = null ) {
		$names = array();

		foreach ( $this->languages() as $language ) {
			if ( $exclude instanceof Language && $exclude->code() === $language->code() ) {
				continue;
			}

			$names[] = sprintf( '%s · %s', $language->native_name(), $language->locale() );
		}

		return empty( $names ) ? __( 'No languages configured', 'mclogiora' ) : implode( ', ', $names );
	}

	/**
	 * Returns the posted language code.
	 *
	 * @return string Sanitized language code.
	 */
	private function posted_code() {
		return isset( $_POST['language_code'] ) ? sanitize_key( wp_unslash( $_POST['language_code'] ) ) : '';
	}

	/**
	 * Returns the current admin page slug.
	 *
	 * @return string Page slug.
	 */
	private function requested_page() {
		return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	}

	/**
	 * Builds a wizard step URL.
	 *
	 * @param string $step Step key.
	 * @return string Admin URL.
	 */
	private function step_url( $step ) {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&step=' . sanitize_key( $step ) );
	}

	/**
	 * Redirects after a successful mutation.
	 *
	 * @param string $url Destination URL.
	 * @return void
	 */
	private function redirect_to( $url ) {
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Builds a notice payload.
	 *
	 * @param string $type Notice type.
	 * @param string $message Notice message.
	 * @return array<string,string> Notice payload.
	 */
	private function notice( $type, $message ) {
		return array(
			'type'    => $type,
			'message' => $message,
		);
	}

	/**
	 * Renders a notice payload.
	 *
	 * @param array<string,string>|null $notice Notice payload.
	 * @return void
	 */
	private function render_notice( $notice ) {
		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return;
		}

		$class = 'success' === $notice['type'] ? 'mclogiora-notice mclogiora-notice--success' : 'mclogiora-notice mclogiora-notice--error';
		$role  = 'error' === $notice['type'] ? 'alert' : 'status';
		?>
		<div class="<?php echo esc_attr( $class ); ?>" role="<?php echo esc_attr( $role ); ?>"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
		<?php
	}

	/**
	 * Returns a translated step label.
	 *
	 * @param string $step Step key.
	 * @return string Step label.
	 */
	private function label_for_step( $step ) {
		$labels = array(
			'welcome'          => __( 'Welcome', 'mclogiora' ),
			'languages'        => __( 'Languages', 'mclogiora' ),
			'default_language' => __( 'Default language', 'mclogiora' ),
			'url_format'       => __( 'Language URLs', 'mclogiora' ),
			'review'           => __( 'Review', 'mclogiora' ),
			'complete'         => __( 'Complete', 'mclogiora' ),
		);

		return isset( $labels[ $step ] ) ? $labels[ $step ] : $step;
	}

	/**
	 * Returns a translated step description.
	 *
	 * @param string $step Step key.
	 * @return string Step description.
	 */
	private function description_for_step( $step ) {
		$descriptions = array(
			'welcome'          => __( 'A short introduction to the setup choices.', 'mclogiora' ),
			'languages'        => __( 'Add the languages your site will use.', 'mclogiora' ),
			'default_language' => __( 'Choose the language used for existing content.', 'mclogiora' ),
			'url_format'       => __( 'Choose how translated URLs are organized.', 'mclogiora' ),
			'review'           => __( 'Check the choices before finishing.', 'mclogiora' ),
			'complete'         => __( 'See what to do next.', 'mclogiora' ),
		);

		return isset( $descriptions[ $step ] ) ? $descriptions[ $step ] : '';
	}
}
