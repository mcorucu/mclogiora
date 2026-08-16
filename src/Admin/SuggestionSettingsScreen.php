<?php
/**
 * Translation Suggestions settings screen.
 *
 * @package McLogiora
 */

namespace McLogiora\Admin;

use McLogiora\Capabilities\CapabilityRegistry;
use McLogiora\Contracts\ModuleInterface;
use McLogiora\Contracts\TranslationProviderInterface;
use McLogiora\Core\Container;
use McLogiora\Suggestions\CredentialStore;
use McLogiora\Suggestions\ModelCache;
use McLogiora\Suggestions\ProviderReadiness;
use McLogiora\Suggestions\ProviderRegistry;
use McLogiora\Suggestions\SuggestionSettings;

defined( 'ABSPATH' ) || exit;

/**
 * Lets a site owner configure optional translation providers.
 *
 * Every outbound request this feature can make begins with a deliberate click
 * on this screen, so the screen's own restraint is part of the security story:
 * rendering it reaches no provider, reveals no credential, and chooses nothing
 * on the owner's behalf.
 *
 * ## Five actions, five nonces
 *
 * Saving settings, saving a credential, removing a credential, testing a
 * connection and refreshing a model list are separate submissions with
 * separate nonces. One shared nonce would let a form built for the harmless
 * action authorise the expensive one -- and two of these spend the owner's
 * money.
 *
 * ## Nothing is chosen for the owner
 *
 * There is no default provider and no default model. A fresh install shows the
 * feature switched off, and manual translation -- the whole rest of the plugin
 * -- works with none of this configured. The screen is written to inform
 * rather than to sell: it says what a provider would cost the owner in
 * privacy terms and leaves the decision alone.
 */
final class SuggestionSettingsScreen implements ModuleInterface {
	/**
	 * Admin page slug.
	 */
	const PAGE_SLUG = 'mclogiora-suggestions';

	/**
	 * Nonce action for the settings form.
	 */
	const NONCE_SETTINGS = 'mclogiora_suggestion_settings';

	/**
	 * Nonce action for storing a credential.
	 */
	const NONCE_CREDENTIAL = 'mclogiora_suggestion_credential';

	/**
	 * Nonce action for removing a credential.
	 */
	const NONCE_REMOVE = 'mclogiora_suggestion_remove_credential';

	/**
	 * Nonce action for a connection test.
	 */
	const NONCE_TEST = 'mclogiora_suggestion_test_connection';

	/**
	 * Nonce action for a model refresh.
	 */
	const NONCE_REFRESH = 'mclogiora_suggestion_refresh_models';

	/**
	 * Effective capability.
	 *
	 * @var string
	 */
	private $capability = 'manage_options';

	/**
	 * Settings reader.
	 *
	 * @var SuggestionSettings|null
	 */
	private $settings;

	/**
	 * Provider registry.
	 *
	 * @var ProviderRegistry|null
	 */
	private $providers;

	/**
	 * Credential storage.
	 *
	 * @var CredentialStore|null
	 */
	private $credentials;

	/**
	 * Readiness resolver.
	 *
	 * @var ProviderReadiness|null
	 */
	private $readiness;

	/**
	 * Model cache.
	 *
	 * @var ModelCache|null
	 */
	private $models;

	/**
	 * Registers the screen and its actions.
	 *
	 * @param Container $container Service container.
	 * @return void
	 */
	public function register( Container $container ) {
		$this->capability  = $container->get( CapabilityRegistry::class )->resolve( CapabilityRegistry::MANAGE_SETTINGS );
		$this->settings    = $container->get( SuggestionSettings::class );
		$this->providers   = $container->get( ProviderRegistry::class );
		$this->credentials = $container->get( CredentialStore::class );
		$this->readiness   = $container->get( ProviderReadiness::class );
		$this->models      = $container->get( ModelCache::class );

		$container->get( AdminScreenRegistry::class )->add(
			new AdminScreen(
				static function () {
					return __( 'mcLogiora Translation Suggestions', 'mclogiora' );
				},
				static function () {
					return __( 'Translation Suggestions', 'mclogiora' );
				},
				$this->capability,
				self::PAGE_SLUG,
				array( $this, 'render' )
			)
		);

		if ( is_admin() ) {
			add_action( 'admin_post_mclogiora_save_suggestion_settings', array( $this, 'handle_save_settings' ) );
			add_action( 'admin_post_mclogiora_save_suggestion_credential', array( $this, 'handle_save_credential' ) );
			add_action( 'admin_post_mclogiora_remove_suggestion_credential', array( $this, 'handle_remove_credential' ) );
			add_action( 'admin_post_mclogiora_test_suggestion_connection', array( $this, 'handle_test_connection' ) );
			add_action( 'admin_post_mclogiora_refresh_suggestion_models', array( $this, 'handle_refresh_models' ) );
		}
	}

	/**
	 * Renders the settings screen.
	 *
	 * Reaches no provider. Everything shown is local state.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( $this->capability ) ) {
			wp_die( esc_html__( 'You are not allowed to manage these settings.', 'mclogiora' ) );
		}

		$enabled  = $this->settings->is_enabled();
		$selected = $this->settings->provider_id();
		?>
		<div class="wrap mclogiora-admin">
			<section class="mclogiora-panel" aria-labelledby="mclogiora-suggestions-title">
				<p class="mclogiora-eyebrow"><?php esc_html_e( 'Optional', 'mclogiora' ); ?></p>
				<h1 id="mclogiora-suggestions-title"><?php esc_html_e( 'Translation Suggestions', 'mclogiora' ); ?></h1>
				<p class="mclogiora-lede">
					<?php esc_html_e( 'Ask a translation provider you already pay for to draft a translation, which you then review before anything changes.', 'mclogiora' ); ?>
				</p>

				<?php $this->render_notice(); ?>

				<article class="mclogiora-info-card">
					<h2><?php esc_html_e( 'What this sends, and when', 'mclogiora' ); ?></h2>
					<ul>
						<li><?php esc_html_e( 'These providers are optional external services, switched off until you turn them on.', 'mclogiora' ); ?></li>
						<li><?php esc_html_e( 'You supply your own credentials and the provider bills you directly. mcLogiora ships no keys and no credits.', 'mclogiora' ); ?></li>
						<li><?php esc_html_e( 'Text leaves your site only when you explicitly ask for a suggestion, and only the single field you asked about.', 'mclogiora' ); ?></li>
						<li><?php esc_html_e( 'Translating by hand needs none of this and is unaffected by anything on this page.', 'mclogiora' ); ?></li>
						<li><?php esc_html_e( 'mcLogiora itself receives nothing. Requests go from your site straight to the provider you chose.', 'mclogiora' ); ?></li>
					</ul>
				</article>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="mclogiora_save_suggestion_settings" />
					<?php wp_nonce_field( self::NONCE_SETTINGS, self::NONCE_SETTINGS . '_nonce' ); ?>

					<article class="mclogiora-info-card">
						<h2><?php esc_html_e( 'Translation suggestions', 'mclogiora' ); ?></h2>
						<p>
							<label>
								<input type="checkbox" name="mclogiora_suggestions_enabled" value="1" <?php checked( $enabled ); ?> />
								<?php esc_html_e( 'Allow translation suggestions on this site', 'mclogiora' ); ?>
							</label>
						</p>
						<p class="mclogiora-muted-line">
							<?php esc_html_e( 'While this is off, no provider is contacted for any reason.', 'mclogiora' ); ?>
						</p>

						<p>
							<label for="mclogiora-suggestion-provider"><?php esc_html_e( 'Provider used for suggestions', 'mclogiora' ); ?></label><br />
							<select id="mclogiora-suggestion-provider" name="mclogiora_suggestions_provider">
								<option value="" <?php selected( '', $selected ); ?>><?php esc_html_e( '— None chosen —', 'mclogiora' ); ?></option>
								<?php foreach ( $this->providers->all() as $provider ) : ?>
									<option value="<?php echo esc_attr( $provider->get_id() ); ?>" <?php selected( $provider->get_id(), $selected ); ?>>
										<?php echo esc_html( $provider->get_label() ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</p>
						<p class="mclogiora-muted-line">
							<?php esc_html_e( 'Nothing is chosen for you. Suggestions stay unavailable until you pick a provider and finish configuring it below.', 'mclogiora' ); ?>
						</p>

						<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save Settings', 'mclogiora' ); ?></button></p>
					</article>
				</form>

				<?php foreach ( $this->providers->all() as $provider ) : ?>
					<?php $this->render_provider( $provider ); ?>
				<?php endforeach; ?>
			</section>
		</div>
		<?php
	}

	/**
	 * Renders the result of the last action.
	 *
	 * Every handler on this screen finishes by redirecting with a short result
	 * code, so without this the owner clicks Test connection, Save key or
	 * Refresh model list and is told nothing at all. Only local wording is
	 * printed; no provider message travels in the URL.
	 *
	 * @return void
	 */
	private function render_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect feedback.
		$notice = isset( $_GET['mclogiora_notice'] ) ? sanitize_key( wp_unslash( $_GET['mclogiora_notice'] ) ) : '';

		if ( '' === $notice ) {
			return;
		}

		$successes = array(
			'saved'              => __( 'Settings saved.', 'mclogiora' ),
			'credential_saved'   => __( 'The key was saved.', 'mclogiora' ),
			'credential_removed' => __( 'The stored key was removed.', 'mclogiora' ),
			'test_passed'        => __( 'The provider accepted the key.', 'mclogiora' ),
			'models_refreshed'   => __( 'The model list was refreshed.', 'mclogiora' ),
		);

		if ( isset( $successes[ $notice ] ) ) {
			printf( '<div class="notice notice-success"><p>%s</p></div>', esc_html( $successes[ $notice ] ) );

			return;
		}

		$warnings = array(
			'credential_unchanged' => __( 'The key box was empty, so the stored key was left as it was.', 'mclogiora' ),
			'model_retired'        => __( 'The model you had chosen is no longer offered, so the choice was cleared. Choose one from the refreshed list.', 'mclogiora' ),
			'no_models'            => __( 'This provider offers no model choice.', 'mclogiora' ),
		);

		if ( isset( $warnings[ $notice ] ) ) {
			printf( '<div class="notice notice-warning"><p>%s</p></div>', esc_html( $warnings[ $notice ] ) );

			return;
		}

		$errors = array(
			'test_failed'      => __( 'The provider did not accept the key. Check it and try again.', 'mclogiora' ),
			'refresh_failed'   => __( 'The model list could not be refreshed. Check the key and try again.', 'mclogiora' ),
			'unknown_provider' => __( 'That provider is not available.', 'mclogiora' ),
			'unknown_model'    => __( 'That model is not in the current list for this provider. Refresh the list and choose again.', 'mclogiora' ),
		);

		if ( isset( $errors[ $notice ] ) ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $errors[ $notice ] ) );
		}
	}

	/**
	 * Renders one provider's configuration card.
	 *
	 * @param TranslationProviderInterface $provider Provider to render.
	 * @return void
	 */
	private function render_provider( TranslationProviderInterface $provider ) {
		$id          = $provider->get_id();
		$by_config   = $this->credentials->is_defined_by_constant( $id );
		$masked      = $this->credentials->masked( $id );
		$state       = $this->readiness->state( $provider );
		$next_step   = $this->readiness->next_step( $provider );
		$source      = $this->readiness->credential_source( $provider );
		$needs_model = $provider->requires_model_selection();
		?>
		<article class="mclogiora-info-card">
			<h2><?php echo esc_html( $provider->get_label() ); ?></h2>

			<p>
				<strong><?php esc_html_e( 'Status:', 'mclogiora' ); ?></strong>
				<?php echo esc_html( $this->readiness->label( $provider ) ); ?>
				<?php if ( '' !== $source ) : ?>
					<span class="mclogiora-muted-line">— <?php echo esc_html( $source ); ?></span>
				<?php endif; ?>
			</p>

			<?php if ( '' !== $next_step ) : ?>
				<p class="mclogiora-muted-line"><?php echo esc_html( $next_step ); ?></p>
			<?php endif; ?>

			<?php if ( $by_config ) : ?>
				<p>
					<?php
					printf(
						/* translators: %s: PHP constant name. */
						esc_html__( 'This key comes from the %s constant in wp-config.php. It is never copied into the database and cannot be viewed or changed from here.', 'mclogiora' ),
						'<code>' . esc_html( $this->credentials->constant_name( $id ) ) . '</code>'
					);
					?>
				</p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="mclogiora_save_suggestion_credential" />
					<input type="hidden" name="provider" value="<?php echo esc_attr( $id ); ?>" />
					<?php wp_nonce_field( self::NONCE_CREDENTIAL . '_' . $id, self::NONCE_CREDENTIAL . '_nonce' ); ?>

					<p>
						<label for="mclogiora-key-<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'API key', 'mclogiora' ); ?></label><br />
						<input
							type="password"
							id="mclogiora-key-<?php echo esc_attr( $id ); ?>"
							name="credential"
							value=""
							autocomplete="off"
							class="regular-text"
							placeholder="<?php echo '' === $masked ? esc_attr__( 'Not set', 'mclogiora' ) : esc_attr( $masked ); ?>"
						/>
					</p>
					<p class="mclogiora-muted-line">
						<?php esc_html_e( 'A saved key is never shown again. Leaving this empty changes nothing.', 'mclogiora' ); ?>
					</p>
					<p><button type="submit" class="button"><?php esc_html_e( 'Save key', 'mclogiora' ); ?></button></p>
				</form>

				<?php if ( '' !== $masked ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="mclogiora_remove_suggestion_credential" />
						<input type="hidden" name="provider" value="<?php echo esc_attr( $id ); ?>" />
						<?php wp_nonce_field( self::NONCE_REMOVE . '_' . $id, self::NONCE_REMOVE . '_nonce' ); ?>
						<p><button type="submit" class="button button-link-delete"><?php esc_html_e( 'Remove stored key', 'mclogiora' ); ?></button></p>
					</form>
				<?php endif; ?>
			<?php endif; ?>

			<?php if ( '' !== $masked ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="mclogiora_test_suggestion_connection" />
					<input type="hidden" name="provider" value="<?php echo esc_attr( $id ); ?>" />
					<?php wp_nonce_field( self::NONCE_TEST . '_' . $id, self::NONCE_TEST . '_nonce' ); ?>
					<p>
						<button type="submit" class="button"><?php esc_html_e( 'Test connection', 'mclogiora' ); ?></button>
						<span class="mclogiora-muted-line"><?php esc_html_e( 'Checks the key against the provider. Sends none of your content.', 'mclogiora' ); ?></span>
					</p>
				</form>
			<?php endif; ?>

			<?php if ( $needs_model && '' !== $masked ) : ?>
				<?php $this->render_model_controls( $provider ); ?>
			<?php elseif ( ! $needs_model ) : ?>
				<p class="mclogiora-muted-line"><?php esc_html_e( 'This provider is a dedicated translation service and offers no model choice.', 'mclogiora' ); ?></p>
			<?php endif; ?>
		</article>
		<?php
	}

	/**
	 * Renders the model refresh and selection controls.
	 *
	 * @param TranslationProviderInterface $provider Provider to render.
	 * @return void
	 */
	private function render_model_controls( TranslationProviderInterface $provider ) {
		$id       = $provider->get_id();
		$cached   = $this->models->get( $id );
		$selected = $provider->selected_model();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="mclogiora_refresh_suggestion_models" />
			<input type="hidden" name="provider" value="<?php echo esc_attr( $id ); ?>" />
			<?php wp_nonce_field( self::NONCE_REFRESH . '_' . $id, self::NONCE_REFRESH . '_nonce' ); ?>
			<p><button type="submit" class="button"><?php esc_html_e( 'Refresh model list', 'mclogiora' ); ?></button></p>
		</form>

		<?php if ( array() === $cached ) : ?>
			<p class="mclogiora-muted-line"><?php esc_html_e( 'No models have been fetched yet. Refresh the list to choose one.', 'mclogiora' ); ?></p>
		<?php else : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="mclogiora_save_suggestion_settings" />
				<input type="hidden" name="provider" value="<?php echo esc_attr( $id ); ?>" />
				<?php wp_nonce_field( self::NONCE_SETTINGS, self::NONCE_SETTINGS . '_nonce' ); ?>
				<p>
					<label for="mclogiora-model-<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Model', 'mclogiora' ); ?></label><br />
					<select id="mclogiora-model-<?php echo esc_attr( $id ); ?>" name="model">
						<option value="" <?php selected( '', $selected ); ?>><?php esc_html_e( '— None chosen —', 'mclogiora' ); ?></option>
						<?php foreach ( $cached as $model ) : ?>
							<option value="<?php echo esc_attr( $model['id'] ); ?>" <?php selected( $model['id'], $selected ); ?>>
								<?php
								echo esc_html(
									$model['recommended']
										? sprintf(
											/* translators: %s: model name. */
											__( '%s (recommended)', 'mclogiora' ),
											$model['label']
										)
										: $model['label']
								);
								?>
							</option>
						<?php endforeach; ?>
					</select>
				</p>
				<p><button type="submit" class="button"><?php esc_html_e( 'Save model', 'mclogiora' ); ?></button></p>
			</form>
		<?php endif; ?>
		<?php
	}

	/**
	 * Saves the master switch, provider choice and model selection.
	 *
	 * @return void
	 */
	public function handle_save_settings() {
		$this->guard( self::NONCE_SETTINGS, self::NONCE_SETTINGS . '_nonce' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in guard().
		$provider_id = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';

		if ( '' !== $provider_id ) {
			/*
			 * The model form posts a provider but not the site-wide settings,
			 * so a model save must not be read as "the owner cleared the
			 * master switch and the default provider".
			 */
			$this->save_model( $provider_id );

			$this->redirect( 'saved' );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in guard().
		$this->settings->set_enabled( ! empty( $_POST['mclogiora_suggestions_enabled'] ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in guard().
		$chosen = isset( $_POST['mclogiora_suggestions_provider'] ) ? sanitize_key( wp_unslash( $_POST['mclogiora_suggestions_provider'] ) ) : '';

		if ( '' !== $chosen && ! $this->providers->has( $chosen ) ) {
			$this->redirect( 'unknown_provider' );
		}

		$this->settings->set_provider( $chosen );

		$this->redirect( 'saved' );
	}

	/**
	 * Stores a provider credential.
	 *
	 * @return void
	 */
	public function handle_save_credential() {
		$provider = $this->guarded_provider( self::NONCE_CREDENTIAL, self::NONCE_CREDENTIAL . '_nonce' );

		/*
		 * A credential is an opaque secret and is kept byte-exact. Passing it
		 * through sanitize_text_field() would silently corrupt keys that legally
		 * contain characters the sanitiser strips, leaving the owner with a
		 * stored key that cannot work and no way to see why. It is unslashed,
		 * cast and trimmed here, and it is never echoed or interpolated.
		 */
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Verified in guarded_provider(); stored byte-exact by design.
		$value = isset( $_POST['credential'] ) ? trim( (string) wp_unslash( $_POST['credential'] ) ) : '';

		if ( '' === $value ) {
			/*
			 * An empty box is "I did not type anything", never "delete my
			 * key". Removal has its own button and its own nonce.
			 */
			$this->redirect( 'credential_unchanged', $provider->get_id() );
		}

		$this->credentials->save( $provider->get_id(), $value );

		$this->redirect( 'credential_saved', $provider->get_id() );
	}

	/**
	 * Removes a stored provider credential.
	 *
	 * @return void
	 */
	public function handle_remove_credential() {
		$provider = $this->guarded_provider( self::NONCE_REMOVE, self::NONCE_REMOVE . '_nonce' );

		$this->credentials->remove( $provider->get_id() );
		$this->models->forget( $provider->get_id() );

		$provider->clear_selected_model();

		$this->redirect( 'credential_removed', $provider->get_id() );
	}

	/**
	 * Checks a stored credential against the provider.
	 *
	 * @return void
	 */
	public function handle_test_connection() {
		$provider = $this->guarded_provider( self::NONCE_TEST, self::NONCE_TEST . '_nonce' );

		$result = $provider->test_connection();

		$this->redirect( is_wp_error( $result ) ? 'test_failed' : 'test_passed', $provider->get_id() );
	}

	/**
	 * Fetches the provider's current model list.
	 *
	 * @return void
	 */
	public function handle_refresh_models() {
		$provider = $this->guarded_provider( self::NONCE_REFRESH, self::NONCE_REFRESH . '_nonce' );

		if ( ! $provider->requires_model_selection() ) {
			$this->redirect( 'no_models', $provider->get_id() );
		}

		$models = $provider->available_models();

		if ( is_wp_error( $models ) ) {
			$this->redirect( 'refresh_failed', $provider->get_id() );
		}

		$this->models->put( $provider->get_id(), $models );

		$invalidated = $provider->reconcile_selected_model( $models );

		$this->redirect( $invalidated ? 'model_retired' : 'models_refreshed', $provider->get_id() );
	}

	/**
	 * Saves an explicitly chosen model for a provider.
	 *
	 * @param string $provider_id Provider identifier.
	 * @return void
	 */
	private function save_model( $provider_id ) {
		$provider = $this->providers->find( $provider_id );

		if ( null === $provider ) {
			$this->redirect( 'unknown_provider' );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in guard().
		$model = isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : '';

		if ( '' === $model ) {
			$provider->clear_selected_model();

			return;
		}

		/*
		 * A model identifier is interpolated into a request path, so it is
		 * accepted only if the provider itself offered it during an explicit
		 * refresh. A value the form happened to post is not a source of truth.
		 */
		if ( ! $this->models->offers( $provider_id, $model ) ) {
			$this->redirect( 'unknown_model', $provider_id );
		}

		$provider->set_selected_model( $model );
	}

	/**
	 * Verifies capability and nonce, or stops the request.
	 *
	 * @param string $action Nonce action.
	 * @param string $field Nonce field name.
	 * @return void
	 */
	private function guard( $action, $field ) {
		if ( ! current_user_can( $this->capability ) ) {
			wp_die( esc_html__( 'You are not allowed to manage these settings.', 'mclogiora' ) );
		}

		$nonce = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, $action ) ) {
			wp_die( esc_html__( 'That request could not be verified. Please try again.', 'mclogiora' ) );
		}
	}

	/**
	 * Verifies the request and resolves the named provider.
	 *
	 * The nonce action includes the provider identifier, so a form issued for
	 * one provider cannot be replayed against another.
	 *
	 * @param string $action_prefix Nonce action prefix.
	 * @param string $field Nonce field name.
	 * @return TranslationProviderInterface
	 */
	private function guarded_provider( $action_prefix, $field ) {
		if ( ! current_user_can( $this->capability ) ) {
			wp_die( esc_html__( 'You are not allowed to manage these settings.', 'mclogiora' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified immediately below.
		$provider_id = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';
		$provider    = $this->providers->find( $provider_id );

		if ( null === $provider ) {
			wp_die( esc_html__( 'That translation provider is not available.', 'mclogiora' ) );
		}

		$nonce = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, $action_prefix . '_' . $provider_id ) ) {
			wp_die( esc_html__( 'That request could not be verified. Please try again.', 'mclogiora' ) );
		}

		return $provider;
	}

	/**
	 * Returns to the settings screen with a result code.
	 *
	 * Only a short known code travels in the URL. Provider messages are not
	 * carried in a query string, where they would end up in server logs and
	 * browser history.
	 *
	 * @param string $notice Result code.
	 * @param string $provider_id Provider the result belongs to.
	 * @return void
	 */
	private function redirect( $notice, $provider_id = '' ) {
		$args = array(
			'page'             => self::PAGE_SLUG,
			'mclogiora_notice' => sanitize_key( $notice ),
		);

		if ( '' !== $provider_id ) {
			$args['mclogiora_provider'] = sanitize_key( $provider_id );
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );

		exit;
	}
}
