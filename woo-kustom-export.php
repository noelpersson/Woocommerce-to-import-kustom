<?php
/**
 * Plugin Name: WooCommerce Product Export (Kustom & Sello)
 * Plugin URI:  https://linthor.com
 * Description: Exporterar alla WooCommerce-produkter (enkla och variabla) för bulkimport i Kustom POS och Sello (Tradera).
 * Version:     1.5.0
 * Author:      Linthor Webbyrå
 * Author URI:  https://linthor.com
 * License:     GPL-2.0-or-later
 * Text Domain: woo-kustom-export
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 */

defined( 'ABSPATH' ) || exit;

define( 'WOO_KUSTOM_EXPORT_VERSION', '1.5.0' );
define( 'WOO_KUSTOM_EXPORT_PLUGIN_FILE', __FILE__ );
define( 'WOO_KUSTOM_EXPORT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WOO_KUSTOM_EXPORT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Kontrollera att WooCommerce är aktivt innan pluginet laddas.
 */
function woo_kustom_export_missing_woocommerce_notice() {
	echo '<div class="error"><p>';
	esc_html_e( 'WooCommerce Product Export (Kustom & Sello) kräver att WooCommerce är installerat och aktiverat.', 'woo-kustom-export' );
	echo '</p></div>';
}

function woo_kustom_export_bootstrap() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'woo_kustom_export_missing_woocommerce_notice' );
		return;
	}

	require_once WOO_KUSTOM_EXPORT_PLUGIN_DIR . 'includes/class-woo-kustom-csv-generator-base.php';
	require_once WOO_KUSTOM_EXPORT_PLUGIN_DIR . 'includes/class-woo-kustom-csv-generator.php';
	require_once WOO_KUSTOM_EXPORT_PLUGIN_DIR . 'includes/class-woo-sello-csv-generator.php';
	require_once WOO_KUSTOM_EXPORT_PLUGIN_DIR . 'includes/lib/xlsxwriter.class.php';
	require_once WOO_KUSTOM_EXPORT_PLUGIN_DIR . 'includes/class-woo-sello-xlsx-generator.php';
	require_once WOO_KUSTOM_EXPORT_PLUGIN_DIR . 'includes/class-woo-kustom-export.php';

	WOO_Kustom_Export::instance();
}
add_action( 'plugins_loaded', 'woo_kustom_export_bootstrap', 20 );

register_activation_hook(
	__FILE__,
	static function () {
		if ( false === get_option( 'woo_kustom_export_settings', false ) ) {
			add_option(
				'woo_kustom_export_settings',
				array(
					'include_drafts'     => 0,
					'include_private'    => 0,
					'include_outofstock' => 0,
				)
			);
		}

		if ( ! wp_next_scheduled( 'woo_kustom_export_cleanup_archive' ) ) {
			wp_schedule_event( time(), 'daily', 'woo_kustom_export_cleanup_archive' );
		}
	}
);

register_deactivation_hook(
	__FILE__,
	static function () {
		wp_clear_scheduled_hook( 'woo_kustom_export_cleanup_archive' );
	}
);
