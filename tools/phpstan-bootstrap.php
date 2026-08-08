<?php
/**
 * PHPStan bootstrap.
 *
 * Declares the plugin constants that mclogiora.php defines at runtime inside
 * `if ( ! defined( ... ) )` guards. PHPStan cannot resolve conditionally
 * defined constants, so it reports them as unknown without this file.
 *
 * This file is analysis-only. It is never loaded by WordPress and has no
 * effect on plugin behavior.
 *
 * @package McLogiora
 */

define( 'MCLOGIORA_VERSION', '0.0.0' );
define( 'MCLOGIORA_FILE', '' );
define( 'MCLOGIORA_PATH', '' );
define( 'MCLOGIORA_URL', '' );
define( 'MCLOGIORA_BASENAME', '' );
define( 'MCLOGIORA_TEXT_DOMAIN', 'mclogiora' );
define( 'MCLOGIORA_MINIMUM_PHP', '7.4' );
define( 'MCLOGIORA_MINIMUM_WP', '6.5' );
