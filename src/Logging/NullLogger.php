<?php
/**
 * Null logger.
 *
 * @package McLogiora
 */

namespace McLogiora\Logging;

use McLogiora\Contracts\LoggerInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Default logger that intentionally stores nothing.
 */
final class NullLogger implements LoggerInterface {
	/**
	 * Logs debug information.
	 *
	 * @param string $message Log message.
	 * @param array  $context Optional context.
	 * @return void
	 */
	public function debug( $message, array $context = array() ) {}

	/**
	 * Logs informational messages.
	 *
	 * @param string $message Log message.
	 * @param array  $context Optional context.
	 * @return void
	 */
	public function info( $message, array $context = array() ) {}

	/**
	 * Logs notices.
	 *
	 * @param string $message Log message.
	 * @param array  $context Optional context.
	 * @return void
	 */
	public function notice( $message, array $context = array() ) {}

	/**
	 * Logs warnings.
	 *
	 * @param string $message Log message.
	 * @param array  $context Optional context.
	 * @return void
	 */
	public function warning( $message, array $context = array() ) {}

	/**
	 * Logs errors.
	 *
	 * @param string $message Log message.
	 * @param array  $context Optional context.
	 * @return void
	 */
	public function error( $message, array $context = array() ) {}
}
