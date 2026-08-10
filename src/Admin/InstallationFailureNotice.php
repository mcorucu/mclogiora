<?php
/**
 * Installation failure admin notice.
 *
 * @package McLogiora
 */

namespace McLogiora\Admin;

use McLogiora\Contracts\ModuleInterface;
use McLogiora\Core\Container;
use McLogiora\Core\InstallationFailure;
use McLogiora\Database\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * Tells administrators when the plugin's own tables were not created.
 *
 * The notice is written for someone who has to decide what to do next, not for
 * someone reading a stack trace. It says what stopped working, offers the one
 * action that usually fixes it, and keeps the table-level detail for the same
 * screen that already reports schema state.
 */
final class InstallationFailureNotice implements ModuleInterface {
	const RETRY_ACTION = 'mclogiora_retry_installation';
	const NONCE_NAME   = 'mclogiora_retry_installation_nonce';

	/**
	 * Service container.
	 *
	 * @var Container|null
	 */
	private $container = null;

	/**
	 * Registers the notice and its retry handler.
	 *
	 * @param Container $container Service container.
	 * @return void
	 */
	public function register( Container $container ) {
		$this->container = $container;

		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_notices', array( $this, 'render' ) );
		add_action( 'admin_post_' . self::RETRY_ACTION, array( $this, 'handle_retry' ) );
	}

	/**
	 * Renders the notice when installation is recorded as failed.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		if ( ! InstallationFailure::exists() ) {
			return;
		}

		$retry = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::RETRY_ACTION ),
			self::RETRY_ACTION,
			self::NONCE_NAME
		);

		echo '<div class="notice notice-error">';
		echo '<p><strong>' . esc_html__( 'mcLogiora could not finish setting up its database.', 'mclogiora' ) . '</strong></p>';
		echo '<p>' . esc_html__( 'Translation features stay switched off until this is resolved, and your content is untouched. This is usually a database permissions problem.', 'mclogiora' ) . '</p>';
		echo '<p><a class="button button-primary" href="' . esc_url( $retry ) . '">' . esc_html__( 'Try setting up the database again', 'mclogiora' ) . '</a></p>';

		$detail = InstallationFailure::get();

		if ( current_user_can( 'manage_options' ) && ! empty( $detail['detail'] ) ) {
			echo '<p><em>' . esc_html( $detail['detail'] ) . '</em></p>';
		}

		echo '</div>';
	}

	/**
	 * Retries installation from the notice.
	 *
	 * @return void
	 */
	public function handle_retry() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'mclogiora' ) );
		}

		check_admin_referer( self::RETRY_ACTION, self::NONCE_NAME );

		if ( ! $this->container instanceof Container ) {
			wp_safe_redirect( admin_url() );
			exit;
		}

		$result = $this->container->get( Installer::class )->install();

		if ( is_wp_error( $result ) ) {
			InstallationFailure::record( $result );
		} else {
			InstallationFailure::clear();
		}

		wp_safe_redirect( admin_url() );
		exit;
	}
}
