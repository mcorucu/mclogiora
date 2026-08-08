<?php
/**
 * Translation action controller.
 *
 * @package McLogiora
 */

namespace McLogiora\Admin;

use McLogiora\Contracts\ModuleInterface;
use McLogiora\Core\Container;
use McLogiora\Relations\ContentType;
use McLogiora\Relations\TranslationStatus;
use McLogiora\Workflows\TranslationWorkflowService;

defined( 'ABSPATH' ) || exit;

/**
 * Handles secured admin-post requests for translation workflows.
 *
 * This class coordinates requests only. It reads and sanitizes input, checks
 * the nonce and capability, delegates to the workflow services, and redirects.
 * It contains no domain rules of its own.
 */
final class TranslationActionController implements ModuleInterface {
	const NONCE_ACTION = 'mclogiora_translation_action';
	const NONCE_NAME   = 'mclogiora_translation_nonce';

	/**
	 * Workflow service.
	 *
	 * @var TranslationWorkflowService|null
	 */
	private $workflows = null;

	/**
	 * Registers admin-post handlers.
	 *
	 * @param Container $container Service container.
	 * @return void
	 */
	public function register( Container $container ) {
		if ( ! is_admin() ) {
			return;
		}

		$this->workflows = $container->get( TranslationWorkflowService::class );

		add_action( 'admin_post_mclogiora_create_translation', array( $this, 'handle_create_translation' ) );
		add_action( 'admin_post_mclogiora_link_translation', array( $this, 'handle_link_translation' ) );
		add_action( 'admin_post_mclogiora_unlink_translation', array( $this, 'handle_unlink_translation' ) );
		add_action( 'admin_post_mclogiora_change_translation_status', array( $this, 'handle_change_status' ) );
		add_action( 'admin_post_mclogiora_create_term_translation', array( $this, 'handle_create_term_translation' ) );
		add_action( 'admin_post_mclogiora_link_term_translation', array( $this, 'handle_link_term_translation' ) );
		add_action( 'admin_post_mclogiora_unlink_term_translation', array( $this, 'handle_unlink_term_translation' ) );
	}

	/**
	 * Handles creating a post translation.
	 *
	 * @return void
	 */
	public function handle_create_translation() {
		$this->verify_request();

		$result = $this->workflows->content()->create_translation(
			$this->request_int( 'source_id' ),
			$this->request_key( 'target_language' )
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_error( $result );
		}

		$edit_link = isset( $result['edit_link'] ) ? (string) $result['edit_link'] : '';

		if ( '' !== $edit_link ) {
			wp_safe_redirect( $edit_link );
			exit;
		}

		$this->redirect_with_notice( 'created' );
	}

	/**
	 * Handles linking an existing post translation.
	 *
	 * @return void
	 */
	public function handle_link_translation() {
		$this->verify_request();

		$result = $this->workflows->content()->link_existing(
			$this->request_int( 'source_id' ),
			$this->request_int( 'target_id' ),
			$this->request_key( 'target_language' )
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_error( $result );
		}

		$this->redirect_with_notice( 'linked' );
	}

	/**
	 * Handles unlinking a post translation.
	 *
	 * @return void
	 */
	public function handle_unlink_translation() {
		$this->verify_request();

		$result = $this->workflows->content()->unlink(
			$this->request_int( 'object_id' ),
			$this->request_key( 'language' )
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_error( $result );
		}

		$this->redirect_with_notice( 'unlinked' );
	}

	/**
	 * Handles a translation status change.
	 *
	 * @return void
	 */
	public function handle_change_status() {
		$this->verify_request();

		$status = $this->request_key( 'status' );

		if ( ! TranslationStatus::is_valid( $status ) ) {
			$this->redirect_with_error(
				new \WP_Error( 'mclogiora_unknown_target_status', __( 'The requested translation status is not recognised.', 'mclogiora' ) )
			);
		}

		$object_type = ContentType::TERM === $this->request_key( 'object_type' ) ? ContentType::TERM : ContentType::POST;

		$result = $this->workflows->change_status(
			$object_type,
			$this->request_int( 'object_id' ),
			$this->request_key( 'language' ),
			$status
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_error( $result );
		}

		$this->redirect_with_notice( 'status_changed' );
	}

	/**
	 * Handles creating a term translation.
	 *
	 * @return void
	 */
	public function handle_create_term_translation() {
		$this->verify_request();

		$result = $this->workflows->taxonomy()->create_translation(
			$this->request_int( 'source_id' ),
			$this->request_key( 'taxonomy' ),
			$this->request_key( 'target_language' ),
			$this->request_text( 'translated_name' ),
			$this->request_textarea( 'translated_description' )
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_error( $result );
		}

		$this->redirect_with_notice( 'created' );
	}

	/**
	 * Handles linking an existing term translation.
	 *
	 * @return void
	 */
	public function handle_link_term_translation() {
		$this->verify_request();

		$result = $this->workflows->taxonomy()->link_existing(
			$this->request_int( 'source_id' ),
			$this->request_key( 'taxonomy' ),
			$this->request_int( 'target_id' ),
			$this->request_key( 'target_language' )
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_error( $result );
		}

		$this->redirect_with_notice( 'linked' );
	}

	/**
	 * Handles unlinking a term translation.
	 *
	 * @return void
	 */
	public function handle_unlink_term_translation() {
		$this->verify_request();

		$result = $this->workflows->taxonomy()->unlink(
			$this->request_int( 'object_id' ),
			$this->request_key( 'language' )
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_error( $result );
		}

		$this->redirect_with_notice( 'unlinked' );
	}

	/**
	 * Verifies the nonce for the current request.
	 *
	 * Capability is checked again inside every workflow method, so this is a
	 * request-integrity gate rather than the authorisation boundary.
	 *
	 * @return void
	 */
	private function verify_request() {
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			wp_die( esc_html__( 'The request could not be verified.', 'mclogiora' ), '', array( 'response' => 400 ) );
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) );

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'The request could not be verified.', 'mclogiora' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Returns a sanitized integer request value.
	 *
	 * The nonce is verified in verify_request() before any handler reads input.
	 *
	 * @param string $key Request key.
	 * @return int
	 */
	private function request_int( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_request() runs first in every handler.
		return isset( $_POST[ $key ] ) ? absint( wp_unslash( $_POST[ $key ] ) ) : 0;
	}

	/**
	 * Returns a sanitized key request value.
	 *
	 * @param string $key Request key.
	 * @return string
	 */
	private function request_key( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_request() runs first in every handler.
		return isset( $_POST[ $key ] ) ? sanitize_key( wp_unslash( $_POST[ $key ] ) ) : '';
	}

	/**
	 * Returns a sanitized text request value.
	 *
	 * @param string $key Request key.
	 * @return string
	 */
	private function request_text( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_request() runs first in every handler.
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
	}

	/**
	 * Returns a sanitized textarea request value.
	 *
	 * @param string $key Request key.
	 * @return string
	 */
	private function request_textarea( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_request() runs first in every handler.
		return isset( $_POST[ $key ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ) : '';
	}

	/**
	 * Returns the Translation Manager URL.
	 *
	 * @return string
	 */
	private function manager_url() {
		return admin_url( 'admin.php?page=mclogiora-translation-manager' );
	}

	/**
	 * Redirects back with an error notice.
	 *
	 * @param \WP_Error $error Error.
	 * @return void
	 */
	private function redirect_with_error( \WP_Error $error ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'mclogiora_notice'  => 'error',
					'mclogiora_message' => rawurlencode( $error->get_error_message() ),
				),
				$this->manager_url()
			)
		);
		exit;
	}

	/**
	 * Redirects back with a success notice.
	 *
	 * @param string $notice Notice key.
	 * @return void
	 */
	private function redirect_with_notice( $notice ) {
		wp_safe_redirect(
			add_query_arg( array( 'mclogiora_notice' => sanitize_key( $notice ) ), $this->manager_url() )
		);
		exit;
	}
}
