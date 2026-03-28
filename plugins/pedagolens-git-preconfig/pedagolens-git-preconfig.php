<?php
/**
 * Plugin Name: PedagoLens Git Preconfig
 * Plugin URI:  https://pedagolens.local
 * Description: Synchronisation Git preconfiguree + configuration automatique PedagoLens (pages, permalink, homepage, mode n8n).
 * Version:     1.0.0
 * Author:      PedagoLens Team
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Text Domain: pedagolens-git-preconfig
 */

defined( 'ABSPATH' ) || exit;

define( 'PL_GIT_PRECONFIG_VERSION', '1.0.0' );
define( 'PL_GIT_PRECONFIG_DIR', plugin_dir_path( __FILE__ ) );

spl_autoload_register( function ( string $class ): void {
    $map = [
        'PedagoLens_Git_Preconfig' => PL_GIT_PRECONFIG_DIR . 'includes/class-git-preconfig.php',
    ];
    if ( isset( $map[ $class ] ) && file_exists( $map[ $class ] ) ) {
        require_once $map[ $class ];
    }
} );

add_action( 'plugins_loaded', [ 'PedagoLens_Git_Preconfig', 'init' ] );
