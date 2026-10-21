<?php
/**
 * Huvudklass för WooCommerce Kustom Product Export.
 *
 * Registrerar adminsida under WooCommerce-menyn, hanterar inställningar,
 * nonces, capability-kontroll och exportens nedladdning.
 *
 * @package Woo_Kustom_Export
 */

defined( 'ABSPATH' ) || exit;

/**
 * Klass WOO_Kustom_Export
 */
class WOO_Kustom_Export {

	/**
	 * Instans (singleton).
	 *
	 * @var WOO_Kustom_Export|null
	 */
	protected static $instance = null;

	/**
	 * Capability som krävs för export och inställningar.
	 *
	 * @var string
	 */
	const CAPABILITY = 'manage_woocommerce';

	/**
	 * Namn på inställningsalternativet.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'woo_kustom_export_settings';

	/**
	 * Hämta instans.
	 *
	 * @return WOO_Kustom_Export
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Konstruktor: koppla hooks.
	 */
	protected function __construct() {
		add_action( 'admin_menu', array( $this, 'register_admin_page' ), 99 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_post_woo_kustom_export_download', array( $this, 'handle_export' ) );
		add_action( 'admin_post_woo_kustom_export_save_settings', array( $this, 'handle_save_settings' ) );
	}

	/**
	 * Hämta inställningar med standardvärden.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$defaults = array(
			'include_drafts'     => 0,
			'include_private'     => 0,
			'include_outofstock'  => 0,
			'optional_columns'   => array(),
		);

		return wp_parse_args( (array) get_option( self::OPTION_KEY, array() ), $defaults );
	}

	/**
	 * Registrera adminsida under WooCommerce-menyn.
	 */
	public function register_admin_page() {
		add_submenu_page(
			'woocommerce',
			__( 'Exportera produkter till Kustom', 'woo-kustom-export' ),
			__( 'Kustom-export', 'woo-kustom-export' ),
			self::CAPABILITY,
			'woo-kustom-export',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Ladda pluginets admin-stilar endast på pluginets egen sida.
	 *
	 * @param string $hook Admin-hook-suffix.
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( false === strpos( $hook, 'woo-kustom-export' ) ) {
			return;
		}

		wp_enqueue_style(
			'woo-kustom-export-admin',
			WOO_KUSTOM_EXPORT_PLUGIN_URL . 'assets/admin.css',
			array(),
			WOO_KUSTOM_EXPORT_VERSION
		);
	}

	/**
	 * Rendera adminsida med modernt, användarvänligt gränssnitt.
	 */
	public function render_admin_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Du har inte behörighet att använda denna sida.', 'woo-kustom-export' ) );
		}

		$settings  = self::get_settings();
		$generator = new WOO_Kustom_CSV_Generator( $settings );
		$stats     = $generator->get_stats();
		$columns   = $generator->collect_columns();

		$download_url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'woo_kustom_export_download',
				),
				admin_url( 'admin-post.php' )
			),
			'woo_kustom_export_download'
		);

		$total_rows = (int) $stats['products'] + (int) $stats['variations'];
		?>
		<div class="wrap woo-kustom-export-wrap">
			<div class="woo-kustom-header">
				<div class="woo-kustom-header__content">
					<h1 class="woo-kustom-header__title">
						<span class="woo-kustom-header__icon" aria-hidden="true">&#8681;</span>
						<?php esc_html_e( 'Exportera produkter till Kustom', 'woo-kustom-export' ); ?>
					</h1>
					<p class="woo-kustom-header__subtitle">
						<?php esc_html_e( 'Skapa en CSV-fil redo för bulkimport av produkter i Kustom POS.', 'woo-kustom-export' ); ?>
					</p>
				</div>
			</div>

			<div class="woo-kustom-grid">
				<section class="woo-kustom-card woo-kustom-card--main">
					<h2 class="woo-kustom-card__title"><?php esc_html_e( 'Export', 'woo-kustom-export' ); ?></h2>
					<p class="woo-kustom-card__text">
						<?php esc_html_e( 'Filen innehåller alla produkter (enkla produkter och varianter) i Kustoms produktimportformat. Filnamnet blir kustom-products-YYYY-MM-DD-HHmm.csv.', 'woo-kustom-export' ); ?>
					</p>

					<div class="woo-kustom-stats">
						<div class="woo-kustom-stat">
							<span class="woo-kustom-stat__value"><?php echo esc_html( number_format_i18n( (int) $stats['products'] ) ); ?></span>
							<span class="woo-kustom-stat__label"><?php esc_html_e( 'Enkla produkter', 'woo-kustom-export' ); ?></span>
						</div>
						<div class="woo-kustom-stat">
							<span class="woo-kustom-stat__value"><?php echo esc_html( number_format_i18n( (int) $stats['variations'] ) ); ?></span>
							<span class="woo-kustom-stat__label"><?php esc_html_e( 'Varianter', 'woo-kustom-export' ); ?></span>
						</div>
						<div class="woo-kustom-stat">
							<span class="woo-kustom-stat__value"><?php echo esc_html( number_format_i18n( $total_rows ) ); ?></span>
							<span class="woo-kustom-stat__label"><?php esc_html_e( 'CSV-rader totalt', 'woo-kustom-export' ); ?></span>
						</div>
					</div>

					<?php if ( 0 === $total_rows ) : ?>
						<div class="woo-kustom-notice woo-kustom-notice--info">
							<p><?php esc_html_e( 'Inga produkter matchar de aktuella inställningarna. Justera filtren nedan eller lägg till produkter i butiken.', 'woo-kustom-export' ); ?></p>
						</div>
					<?php endif; ?>

					<a href="<?php echo esc_url( $download_url ); ?>" class="button button-primary woo-kustom-button<?php echo 0 === $total_rows ? ' woo-kustom-button--disabled' : ''; ?>" <?php echo 0 === $total_rows ? 'aria-disabled="true"' : ''; ?>>
						<?php esc_html_e( 'Exportera produkter till Kustom CSV', 'woo-kustom-export' ); ?>
					</a>

					<div class="woo-kustom-notice woo-kustom-notice--warning">
						<p><strong><?php esc_html_e( 'Obs!', 'woo-kustom-export' ); ?></strong>
						<?php esc_html_e( 'När CSV-filen importeras till Kustom ersätts befintliga produkter i samma Kustom-butik. Dubbelkolla filen innan du importerar.', 'woo-kustom-export' ); ?></p>
					</div>
				</section>

				<section class="woo-kustom-card">
					<h2 class="woo-kustom-card__title"><?php esc_html_e( 'Exportfilter', 'woo-kustom-export' ); ?></h2>
					<p class="woo-kustom-card__text">
						<?php esc_html_e( 'Standard är att endast publicerade produkter i lager exporteras.', 'woo-kustom-export' ); ?>
					</p>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="woo-kustom-form">
						<input type="hidden" name="action" value="woo_kustom_export_save_settings">
						<?php wp_nonce_field( 'woo_kustom_export_save_settings' ); ?>

						<label class="woo-kustom-toggle">
							<input type="checkbox" name="woo_kustom_export[include_drafts]" value="1" <?php checked( ! empty( $settings['include_drafts'] ) ); ?>>
							<span class="woo-kustom-toggle__label"><?php esc_html_e( 'Inkludera utkast', 'woo-kustom-export' ); ?></span>
						</label>

						<label class="woo-kustom-toggle">
							<input type="checkbox" name="woo_kustom_export[include_private]" value="1" <?php checked( ! empty( $settings['include_private'] ) ); ?>>
							<span class="woo-kustom-toggle__label"><?php esc_html_e( 'Inkludera privata produkter', 'woo-kustom-export' ); ?></span>
						</label>

						<label class="woo-kustom-toggle">
							<input type="checkbox" name="woo_kustom_export[include_outofstock]" value="1" <?php checked( ! empty( $settings['include_outofstock'] ) ); ?>>
							<span class="woo-kustom-toggle__label"><?php esc_html_e( 'Inkludera produkter ur lager', 'woo-kustom-export' ); ?></span>
						</label>

						<button type="submit" class="button"><?php esc_html_e( 'Spara filter', 'woo-kustom-export' ); ?></button>
					</form>
				</section>
			</div>

			<section class="woo-kustom-card">
				<h2 class="woo-kustom-card__title"><?php esc_html_e( 'Kolumner i exporten', 'woo-kustom-export' ); ?></h2>
				<p class="woo-kustom-card__text">
					<?php esc_html_e( 'Fyra obligatoriska kolumner exporteras som standard. Attributkolumner (exempelvis size och color) läggs till automatiskt för varianter.', 'woo-kustom-export' ); ?>
				</p>
				<div class="woo-kustom-columns">
					<?php foreach ( $columns as $column ) : ?>
						<span class="woo-kustom-column-chip"><code><?php echo esc_html( $column ); ?></code></span>
					<?php endforeach; ?>
				</div>
				<p class="woo-kustom-card__text woo-kustom-card__text--muted">
					<?php esc_html_e( 'Valfria kolumner (sku, category, ean, image_url, stock_status) aktiveras först när formatet verifierats mot Kustoms officiella exempel-CSV-filer, eftersom Kustom tolkar extra kolumner som variantattribut.', 'woo-kustom-export' ); ?>
				</p>
			</section>

			<footer class="woo-kustom-footer">
				<p class="woo-kustom-footer__text">
					<?php
					printf(
						// translators: 1: Linthor Webbyrå, 2: URL.
						esc_html__( 'WooCommerce Kustom Product Export är utvecklat av %1$s.', 'woo-kustom-export' ),
						'<a href="https://linthor.com" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Linthor Webbyrå', 'woo-kustom-export' ) . '</a>'
					);
					?>
				</p>
			</footer>
		</div>
		<?php
	}

	/**
	 * Hantera CSV-exporten: nonce- och capability-kontroll, sedan strömning.
	 */
	public function handle_export() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Du har inte behörighet att exportera produkter.', 'woo-kustom-export' ) );
		}

		check_admin_referer( 'woo_kustom_export_download' );

		$generator = new WOO_Kustom_CSV_Generator( self::get_settings() );
		$generator->stream_csv();
	}

	/**
	 * Spara exportfilter-inställningar.
	 */
	public function handle_save_settings() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Du har inte behörighet att ändra inställningarna.', 'woo-kustom-export' ) );
		}

		check_admin_referer( 'woo_kustom_export_save_settings' );

		$raw = isset( $_POST['woo_kustom_export'] ) ? (array) wp_unslash( $_POST['woo_kustom_export'] ) : array();

		$settings = array(
			'include_drafts'     => empty( $raw['include_drafts'] ) ? 0 : 1,
			'include_private'     => empty( $raw['include_private'] ) ? 0 : 1,
			'include_outofstock'  => empty( $raw['include_outofstock'] ) ? 0 : 1,
			'optional_columns'   => isset( $raw['optional_columns'] ) ? array_map( 'sanitize_key', (array) $raw['optional_columns'] ) : array(),
		);

		update_option( self::OPTION_KEY, $settings, false );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'woo-kustom-export',
					'updated' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
