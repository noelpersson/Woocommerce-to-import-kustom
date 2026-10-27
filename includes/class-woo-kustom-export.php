<?php
/**
 * Huvudklass för WooCommerce Product Export (Kustom & Sello).
 *
 * Registrerar adminsidor under WooCommerce-menyn med fliknavigering,
 * hanterar inställningar, nonces, capability-kontroll och exportens nedladdning.
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
	 * Namn på inställningen för filarkivet.
	 *
	 * @var string
	 */
	const ARCHIVE_OPTION_KEY = 'woo_kustom_export_archive_log';

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
		add_action( 'admin_post_woo_kustom_export_download_archive', array( $this, 'handle_archive_download' ) );
		add_action( 'admin_post_woo_kustom_export_delete_archive', array( $this, 'handle_archive_delete' ) );
		add_action( 'admin_post_woo_kustom_export_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'woo_kustom_export_cleanup_archive', array( __CLASS__, 'cleanup_archive' ) );

		if ( ! wp_next_scheduled( 'woo_kustom_export_cleanup_archive' ) ) {
			wp_schedule_event( time(), 'daily', 'woo_kustom_export_cleanup_archive' );
		}
	}

	/**
	 * Tillgängliga exportplattformar och deras generatorer.
	 *
	 * @return array<string,array{label:string,generator:string}>
	 */
	public static function get_platforms() {
		return array(
			'kustom'     => array(
				'label'     => __( 'Kustom POS', 'woo-kustom-export' ),
				'generator' => 'WOO_Kustom_CSV_Generator',
			),
			'sello'      => array(
				'label'     => __( 'Sello CSV', 'woo-kustom-export' ),
				'generator' => 'WOO_Sello_CSV_Generator',
			),
			'sello-xlsx' => array(
				'label'     => __( 'Sello Excel', 'woo-kustom-export' ),
				'generator' => 'WOO_Sello_XLSX_Generator',
			),
		);
	}

	/**
	 * Normalisera begärd plattform mot listan över tillgängliga plattformar.
	 *
	 * @param string $platform Efterfrågad plattform.
	 * @return string
	 */
	public static function sanitize_platform( $platform ) {
		$platform = sanitize_key( (string) $platform );
		return isset( self::get_platforms()[ $platform ] ) ? $platform : 'kustom';
	}

	/**
	 * Hämta etikett för en plattform.
	 *
	 * @param string $platform Plattform.
	 * @return string
	 */
	public static function get_platform_label( $platform ) {
		$platforms = self::get_platforms();
		return isset( $platforms[ $platform ]['label'] ) ? $platforms[ $platform ]['label'] : ucfirst( (string) $platform );
	}

	/**
	 * Skapa CSV/XLSX-generator för en plattform.
	 *
	 * @param string $platform Plattform.
	 * @return WOO_Kustom_CSV_Generator_Base
	 */
	public static function create_generator( $platform ) {
		$platforms = self::get_platforms();
		$platform  = self::sanitize_platform( $platform );
		$class     = $platforms[ $platform ]['generator'];

		return new $class( self::get_settings() );
	}

	/**
	 * Hämta inställningar med standardvärden.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$defaults = array(
			'include_drafts'     => 0,
			'include_private'    => 0,
			'include_outofstock' => 0,
			'optional_columns'   => array(),
			'custom_attribute'   => '',
			'custom_attributes'  => array(),
			'sello_columns'      => array(),
		);

		return wp_parse_args( (array) get_option( self::OPTION_KEY, array() ), $defaults );
	}

	/**
	 * Registrera adminsida under WooCommerce-menyn.
	 */
	public function register_admin_page() {
		add_submenu_page(
			'woocommerce',
			__( 'Produkt-export (Kustom & Sello)', 'woo-kustom-export' ),
			__( 'Produkt-export', 'woo-kustom-export' ),
			self::CAPABILITY,
			'woo-product-export',
			array( $this, 'render_admin_page' )
		);

		// Bakåtkompatibilitet för gamla sluggen woo-kustom-export.
		add_submenu_page(
			null,
			__( 'Produkt-export', 'woo-kustom-export' ),
			__( 'Produkt-export', 'woo-kustom-export' ),
			self::CAPABILITY,
			'woo-kustom-export',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Ladda pluginets admin-stilar och skript endast på pluginets egna sidor.
	 *
	 * @param string $hook Admin-hook-suffix.
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( false === strpos( $hook, 'woo-product-export' ) && false === strpos( $hook, 'woo-kustom-export' ) ) {
			return;
		}

		$css_path = WOO_KUSTOM_EXPORT_PLUGIN_DIR . 'assets/admin.css';
		$ver      = file_exists( $css_path ) ? (string) filemtime( $css_path ) : WOO_KUSTOM_EXPORT_VERSION;

		// Google Fonts: Inter, JetBrains Mono och Material Symbols Outlined.
		wp_enqueue_style(
			'woo-kustom-export-fonts',
			'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500&family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=swap',
			array(),
			null
		);

		wp_enqueue_style(
			'woo-kustom-export-admin',
			WOO_KUSTOM_EXPORT_PLUGIN_URL . 'assets/admin.css',
			array(),
			$ver
		);

		wp_enqueue_script(
			'woo-kustom-export-admin',
			WOO_KUSTOM_EXPORT_PLUGIN_URL . 'assets/admin.js',
			array( 'jquery' ),
			$ver,
			true
		);
	}

	/**
	 * Hämta nedladdnings-URL för en plattform med giltig nonce.
	 *
	 * @param string $platform Plattform.
	 * @return string
	 */
	public function get_download_url( $platform ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'   => 'woo_kustom_export_download',
					'platform' => $platform,
				),
				admin_url( 'admin-post.php' )
			),
			'woo_kustom_export_download'
		);
	}

	/**
	 * Hämta begriplig svensk kolumnetikett för en specifik exportkolumn.
	 *
	 * @param string $column Kolumnnamn.
	 * @param string $platform Plattform ('kustom' eller 'sello').
	 * @param array  $supported_columns Valfria kolumner från generatorn.
	 * @return string
	 */
	public static function get_column_label( $column, $platform = 'kustom', $supported_columns = array() ) {
		if ( 'sello' === $platform || 'sello-xlsx' === $platform ) {
			$sello_labels = array(
				'Sku'                     => __( 'Artikelnummer (SKU)', 'woo-kustom-export' ),
				'Standard name sv'        => __( 'Produktnamn (svenska)', 'woo-kustom-export' ),
				'Standard description sv' => __( 'Beskrivning (svenska, ren text)', 'woo-kustom-export' ),
				'Tradera price store'     => __( 'Tradera butikspris (fast pris)', 'woo-kustom-export' ),
				'Purchase price'          => __( 'Inköpspris (exkl. moms)', 'woo-kustom-export' ),
				'Quantity'                => __( 'Lagersaldo', 'woo-kustom-export' ),
				'Tax'                     => __( 'Momssats (%)', 'woo-kustom-export' ),
				'Weight'                  => __( 'Vikt (gram)', 'woo-kustom-export' ),
				'Images'                  => __( 'Produktbilder (https URL)', 'woo-kustom-export' ),
				'Brand'                   => __( 'Varumärke', 'woo-kustom-export' ),
				'Group sku'               => __( 'Grupp-SKU (huvudprodukt)', 'woo-kustom-export' ),
				'Group type'              => __( 'Grupptyp (color, size, model)', 'woo-kustom-export' ),
			);
			return isset( $sello_labels[ $column ] ) ? $sello_labels[ $column ] : $column;
		}

		$kustom_mandatory = array(
			'product_name'        => __( 'Produktnamn', 'woo-kustom-export' ),
			'price_including_vat' => __( 'Pris inkl. moms', 'woo-kustom-export' ),
			'vat_rate'            => __( 'Momssats (%)', 'woo-kustom-export' ),
			'currency'            => __( 'Valuta', 'woo-kustom-export' ),
		);

		if ( isset( $kustom_mandatory[ $column ] ) ) {
			return $kustom_mandatory[ $column ];
		}

		if ( isset( $supported_columns[ $column ]['label'] ) ) {
			return $supported_columns[ $column ]['label'];
		}

		return sprintf( __( 'Attribut: %s', 'woo-kustom-export' ), $column );
	}

	/**
	 * Rendera modern Linthor Webbyrå header och fliknavigering.
	 *
	 * @param string $active_tab Aktuell flik ('dashboard', 'kustom', 'sello', 'doc').
	 */
	protected function render_header( $active_tab ) {
		$doc_url = admin_url( 'admin.php?page=woo-product-export&tab=doc' );
		?>
		<header class="bg-white border border-slate-200 rounded-2xl shadow-sm p-6 mb-6">
			<!-- Topprad: Varumärke, Rubrik, Badges & Åtgärder -->
			<div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
				<div>
					<div class="flex items-center flex-wrap gap-2.5">
						<h1 class="text-2xl font-bold text-slate-900 tracking-tight" style="margin: 0; padding: 0;">Produkt-export för WooCommerce</h1>
						<span class="bg-slate-100 text-slate-600 text-xs font-semibold px-2 py-0.5 rounded border border-slate-200">v<?php echo esc_html( WOO_KUSTOM_EXPORT_VERSION ); ?></span>
					</div>
				</div>

				<!-- Åtgärdsknappar -->
				<div class="flex items-center gap-2 self-start md:self-auto">
					<a href="<?php echo esc_url( $this->get_download_url( 'kustom' ) ); ?>" onclick="triggerQuickDownload('kustom');" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 bg-blue-700 hover:bg-blue-800 text-white text-xs font-medium rounded-lg shadow-sm transition-colors text-white no-underline">
						<svg class="w-3.5 h-3.5" width="14" height="14" style="width: 14px; height: 14px; flex-shrink: 0;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
						<?php esc_html_e( 'Tvinga full export (Kustom)', 'woo-kustom-export' ); ?>
					</a>
					<a href="<?php echo esc_url( $doc_url ); ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 text-xs font-medium rounded-lg transition-colors text-slate-700 no-underline">
						<svg class="w-3.5 h-3.5 text-slate-500" width="14" height="14" style="width: 14px; height: 14px; flex-shrink: 0;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path></svg>
						<?php esc_html_e( 'Dokumentation', 'woo-kustom-export' ); ?>
					</a>
				</div>
			</div>

			<!-- Fliknavigering -->
			<nav class="flex items-center gap-1 border-t border-slate-100 mt-5 pt-3" aria-label="<?php esc_attr_e( 'Produkt-export flikar', 'woo-kustom-export' ); ?>">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=woo-product-export' ) ); ?>" class="px-3 py-1.5 text-xs rounded-lg transition-colors no-underline <?php echo 'dashboard' === $active_tab ? 'font-semibold text-blue-700 bg-blue-50/80 border border-blue-100/50' : 'font-medium text-slate-600 hover:text-slate-900 hover:bg-slate-100/70'; ?>">
					<?php esc_html_e( 'Översikt', 'woo-kustom-export' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=woo-product-export&tab=kustom' ) ); ?>" class="px-3 py-1.5 text-xs rounded-lg transition-colors no-underline <?php echo 'kustom' === $active_tab ? 'font-semibold text-blue-700 bg-blue-50/80 border border-blue-100/50' : 'font-medium text-slate-600 hover:text-slate-900 hover:bg-slate-100/70'; ?>">
					<?php esc_html_e( 'Kustom POS', 'woo-kustom-export' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=woo-product-export&tab=sello' ) ); ?>" class="px-3 py-1.5 text-xs rounded-lg transition-colors no-underline <?php echo 'sello' === $active_tab ? 'font-semibold text-blue-700 bg-blue-50/80 border border-blue-100/50' : 'font-medium text-slate-600 hover:text-slate-900 hover:bg-slate-100/70'; ?>">
					<?php esc_html_e( 'Sello', 'woo-kustom-export' ); ?>
				</a>
				<a href="<?php echo esc_url( $doc_url ); ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs rounded-lg transition-colors no-underline <?php echo 'doc' === $active_tab ? 'font-semibold text-blue-700 bg-blue-50/80 border border-blue-100/50' : 'font-medium text-slate-600 hover:text-slate-900 hover:bg-slate-100/70'; ?>">
					<?php esc_html_e( 'Hjälp & Dokumentation', 'woo-kustom-export' ); ?>
				</a>
			</nav>
		</header>
		<?php
	}

	/**
	 * Rendera adminsida baserat på aktiv flik.
	 */
	public function render_admin_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Du har inte behörighet att använda denna sida.', 'woo-kustom-export' ) );
		}

		$current_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard';
		if ( ! in_array( $current_tab, array( 'dashboard', 'kustom', 'sello', 'doc' ), true ) ) {
			$current_tab = 'dashboard';
		}

		?>
		<div id="woo-kustom-export-root" class="wrap woo-kustom-wrap">
			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible" role="status"><p><?php esc_html_e( 'Inställningarna har sparats.', 'woo-kustom-export' ); ?></p></div>
			<?php endif; ?>

			<?php
			$this->render_header( $current_tab );

			switch ( $current_tab ) {
				case 'kustom':
					$this->render_kustom_tab();
					break;
				case 'sello':
					$this->render_sello_tab();
					break;
				case 'doc':
					$this->render_doc_tab();
					break;
				default:
					$this->render_dashboard_tab();
					break;
			}
			?>

			<footer class="box-border bg-surface-container-lowest border border-slate-200 rounded-xl py-space-md px-gutter-lg mt-8 shadow-sm">
				<div class="flex flex-col sm:flex-row items-center justify-between gap-space-sm text-on-surface-variant font-body-sm text-body-sm">
					<div class="flex items-center gap-space-xs">
						<span class="font-semibold text-on-surface"><?php esc_html_e( 'Linthor Webbyrå', 'woo-kustom-export' ); ?></span>
						<span>•</span>
						<span><?php esc_html_e( 'Skräddarsydd e-handelsintegration för WooCommerce', 'woo-kustom-export' ); ?></span>
					</div>
					<div class="flex items-center gap-space-md font-label-sm text-label-sm">
						<a class="text-secondary hover:text-primary transition-colors text-inherit" href="https://www.linthor.com" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'linthor.com', 'woo-kustom-export' ); ?></a>
					</div>
				</div>
			</footer>
		</div>
		<?php
	}

	/**
	 * Rendera Dashboard (Översiktssidan) med modern designskiss, KPI-kort, plattformskort, filter och logg.
	 */
	protected function render_dashboard_tab() {
		$generator  = self::create_generator( 'kustom' );
		$stats      = $generator->get_stats();
		$total_rows = (int) $stats['products'] + (int) $stats['variations'];
		$settings   = self::get_settings();

		$kustom_download_url = $this->get_download_url( 'kustom' );
		$sello_download_url  = $this->get_download_url( 'sello-xlsx' );

		$wc_version = defined( 'WC_VERSION' ) ? WC_VERSION : '8.6+';
		?>
		<div class="flex flex-col w-full gap-space-lg">
			<!-- Studio Hero Section -->
			<div class="bg-surface-container-lowest shadow-sm rounded-xl p-space-xl flex flex-col gap-space-lg border border-slate-200">
				<!-- Metric KPI Cards (3 columns) -->
				<div class="grid grid-cols-1 md:grid-cols-3 gap-gutter">
					<!-- Metric 1 -->
					<div class="bg-surface-container-low/70 hover:bg-surface-container-low p-space-lg rounded-lg transition-colors flex flex-col justify-between border border-transparent hover:border-slate-200">
						<div class="flex items-center justify-between mb-space-sm">
							<span class="font-label-md text-label-md text-on-surface-variant"><?php esc_html_e( 'Enkla produkter', 'woo-kustom-export' ); ?></span>
							<span class="material-symbols-outlined text-[20px] text-outline text-slate-400">inventory_2</span>
						</div>
						<div class="flex items-baseline gap-space-xs">
							<span class="font-headline-xl text-headline-xl font-bold text-on-surface tracking-tight" id="kpi-simple"><?php echo esc_html( number_format_i18n( (int) $stats['products'] ) ); ?></span>
							<span class="font-body-sm text-body-sm text-on-surface-variant"><?php esc_html_e( 'st', 'woo-kustom-export' ); ?></span>
						</div>
						<div class="mt-space-sm flex items-center gap-space-xs">
							<span class="material-symbols-outlined text-[14px] text-tertiary">verified</span>
							<span class="font-label-sm text-label-sm text-tertiary"><?php esc_html_e( 'Automatisk SKU-validering & fallback', 'woo-kustom-export' ); ?></span>
						</div>
					</div>

					<!-- Metric 2 -->
					<div class="bg-surface-container-low/70 hover:bg-surface-container-low p-space-lg rounded-lg transition-colors flex flex-col justify-between border border-transparent hover:border-slate-200">
						<div class="flex items-center justify-between mb-space-sm">
							<span class="font-label-md text-label-md text-on-surface-variant"><?php esc_html_e( 'Varianter', 'woo-kustom-export' ); ?></span>
							<span class="material-symbols-outlined text-[20px] text-outline text-slate-400">tune</span>
						</div>
						<div class="flex items-baseline gap-space-xs">
							<span class="font-headline-xl text-headline-xl font-bold text-on-surface tracking-tight" id="kpi-variants"><?php echo esc_html( number_format_i18n( (int) $stats['variations'] ) ); ?></span>
							<span class="font-body-sm text-body-sm text-on-surface-variant"><?php esc_html_e( 'st', 'woo-kustom-export' ); ?></span>
						</div>
						<div class="mt-space-sm flex items-center gap-space-xs">
							<span class="material-symbols-outlined text-[14px] text-secondary">style</span>
							<span class="font-label-sm text-label-sm text-secondary"><?php esc_html_e( 'Färg, storlek & modell aktiva', 'woo-kustom-export' ); ?></span>
						</div>
					</div>

					<!-- Metric 3 -->
					<div class="bg-surface-container-low/70 hover:bg-surface-container-low p-space-lg rounded-lg transition-colors flex flex-col justify-between relative overflow-hidden border border-transparent hover:border-slate-200">
						<div class="flex items-center justify-between mb-space-sm">
							<span class="font-label-md text-label-md text-on-surface-variant"><?php esc_html_e( 'Totalt rader i exporten', 'woo-kustom-export' ); ?></span>
							<span class="inline-flex items-center gap-1 px-space-xs py-0.5 rounded bg-tertiary-container text-on-tertiary font-label-sm text-label-sm font-semibold tracking-wide">
								<?php esc_html_e( 'Katalogredo', 'woo-kustom-export' ); ?>
							</span>
						</div>
						<div class="flex items-baseline gap-space-xs">
							<span class="font-headline-xl text-headline-xl font-bold text-primary tracking-tight" id="kpi-total"><?php echo esc_html( number_format_i18n( $total_rows ) ); ?></span>
							<span class="font-body-sm text-body-sm text-on-surface-variant"><?php esc_html_e( 'rader', 'woo-kustom-export' ); ?></span>
						</div>
						<div class="mt-space-sm flex items-center gap-space-xs">
							<div class="w-full bg-surface-container rounded-full h-1.5 overflow-hidden">
								<div class="bg-primary h-full rounded-full" style="width: 100%"></div>
							</div>
						</div>
					</div>
				</div>
			</div>

			<!-- Platform Selection Cards (2-Column Grid) -->
			<div class="grid grid-cols-1 lg:grid-cols-2 gap-gutter-lg">
				<!-- Platform Card 1: Kustom POS -->
				<div class="bg-surface-container-lowest shadow-sm rounded-xl p-space-xl flex flex-col justify-between hover:shadow-md transition-shadow border border-slate-200">
					<div>
						<!-- Card Header Badge & Identity -->
						<div class="flex items-center justify-between gap-space-sm mb-space-md">
							<span class="inline-flex items-center gap-1.5 px-space-md py-0.5 rounded-full font-label-sm text-label-sm font-semibold bg-surface-container-low text-secondary border border-surface-container">
								<span class="w-1.5 h-1.5 rounded-full bg-secondary"></span>
								<?php esc_html_e( 'KASSA & BUTIK', 'woo-kustom-export' ); ?>
							</span>
							<span class="font-code-sm text-code-sm text-on-surface-variant font-mono"><?php esc_html_e( 'Format: CSV (UTF-8 med BOM)', 'woo-kustom-export' ); ?></span>
						</div>
						<h3 class="font-headline-lg text-headline-lg text-on-surface font-semibold m-0 tracking-tight text-slate-900"><?php esc_html_e( 'Kustom POS Export', 'woo-kustom-export' ); ?></h3>
						<p class="font-body-md text-body-md text-on-surface-variant mt-space-sm mb-space-lg leading-relaxed text-slate-600">
							<?php esc_html_e( 'Skapa CSV-fil för produktimport till Kustom POS. Inkluderar priser inklusive moms, valuta och stöd för egna variantattribut optimerade för fysisk kassa.', 'woo-kustom-export' ); ?>
						</p>
						<!-- Feature Points -->
						<div class="flex flex-col gap-space-sm mb-space-xl bg-surface-container-lowest p-space-md rounded-lg border border-slate-100">
							<div class="flex items-start gap-space-sm">
								<span class="material-symbols-outlined text-[18px] text-tertiary mt-0.5">check_circle</span>
								<span class="font-body-sm text-body-sm text-on-surface"><?php esc_html_e( 'Anpassat CSV bulkformat för Kustom kassasystem', 'woo-kustom-export' ); ?></span>
							</div>
							<div class="flex items-start gap-space-sm">
								<span class="material-symbols-outlined text-[18px] text-tertiary mt-0.5">check_circle</span>
								<span class="font-body-sm text-body-sm text-on-surface"><?php esc_html_e( 'Automatisk momsberäkning (inkl. moms & momssats)', 'woo-kustom-export' ); ?></span>
							</div>
							<div class="flex items-start gap-space-sm">
								<span class="material-symbols-outlined text-[18px] text-tertiary mt-0.5">check_circle</span>
								<span class="font-body-sm text-body-sm text-on-surface"><?php esc_html_e( 'Butiksattribut och variantkopplingar med batchstöd', 'woo-kustom-export' ); ?></span>
							</div>
						</div>
					</div>
					<!-- Actions -->
					<div class="flex flex-col sm:flex-row items-center gap-space-sm pt-space-md border-t border-surface-container-high">
						<a class="w-full sm:w-auto inline-flex items-center justify-center gap-space-xs font-label-md text-label-md text-on-primary bg-primary hover:bg-primary-container px-space-lg py-2 rounded transition-colors shadow-sm text-white no-underline" href="<?php echo esc_url( admin_url( 'admin.php?page=woo-product-export&tab=kustom' ) ); ?>">
							<span><?php esc_html_e( 'Öppna Kustom-export', 'woo-kustom-export' ); ?></span>
							<span class="material-symbols-outlined text-[16px]">arrow_forward</span>
						</a>
						<a class="w-full sm:w-auto inline-flex items-center justify-center gap-space-xs font-label-md text-label-md text-on-surface bg-surface-container-lowest border border-outline-variant hover:bg-surface-container-low px-space-md py-2 rounded transition-colors text-slate-700 no-underline cursor-pointer" href="<?php echo esc_url( $kustom_download_url ); ?>" onclick="triggerQuickDownload('kustom')">
							<span class="material-symbols-outlined text-[16px]">download</span>
							<span><?php esc_html_e( 'Snabbexport (CSV)', 'woo-kustom-export' ); ?></span>
						</a>
					</div>
				</div>

				<!-- Platform Card 2: Sello -->
				<div class="bg-surface-container-lowest shadow-sm rounded-xl p-space-xl flex flex-col justify-between hover:shadow-md transition-shadow border border-slate-200">
					<div>
						<!-- Card Header Badge & Identity -->
						<div class="flex items-center justify-between gap-space-sm mb-space-md">
							<span class="inline-flex items-center gap-1.5 px-space-md py-0.5 rounded-full font-label-sm text-label-sm font-semibold bg-tertiary-container text-on-tertiary">
								<span class="w-1.5 h-1.5 rounded-full bg-on-tertiary"></span>
								<?php esc_html_e( 'MARKNADSPLATSER', 'woo-kustom-export' ); ?>
							</span>
							<span class="font-code-sm text-code-sm text-on-surface-variant font-mono"><?php esc_html_e( 'Format: Excel (.xlsx) / CSV', 'woo-kustom-export' ); ?></span>
						</div>
						<h3 class="font-headline-lg text-headline-lg text-on-surface font-semibold m-0 tracking-tight text-slate-900"><?php esc_html_e( 'Sello & Tradera Butik', 'woo-kustom-export' ); ?></h3>
						<p class="font-body-md text-body-md text-on-surface-variant mt-space-sm mb-space-lg leading-relaxed text-slate-600">
							<?php esc_html_e( 'Skapa Excel- (.xlsx) eller CSV-fil för Sello anpassad för Tradera som butiksobjekt med fast pris (Tradera price store), unika SKU:er och automatisk variantgruppering.', 'woo-kustom-export' ); ?>
						</p>
						<!-- Feature Points -->
						<div class="flex flex-col gap-space-sm mb-space-xl bg-surface-container-lowest p-space-md rounded-lg border border-slate-100">
							<div class="flex items-start gap-space-sm">
								<span class="material-symbols-outlined text-[18px] text-tertiary mt-0.5">check_circle</span>
								<span class="font-body-sm text-body-sm text-on-surface"><?php esc_html_e( 'Färdig .xlsx/.csv-fil med Tradera price store som fast pris', 'woo-kustom-export' ); ?></span>
							</div>
							<div class="flex items-start gap-space-sm">
								<span class="material-symbols-outlined text-[18px] text-tertiary mt-0.5">check_circle</span>
								<span class="font-body-sm text-body-sm text-on-surface"><?php esc_html_e( 'Unik rad och SKU för varje enkel produkt och variation', 'woo-kustom-export' ); ?></span>
							</div>
							<div class="flex items-start gap-space-sm">
								<span class="material-symbols-outlined text-[18px] text-tertiary mt-0.5">check_circle</span>
								<span class="font-body-sm text-body-sm text-on-surface"><?php esc_html_e( 'Automatisk variantgruppering (Group sku & Group type)', 'woo-kustom-export' ); ?></span>
							</div>
						</div>
					</div>
					<!-- Actions -->
					<div class="flex flex-col sm:flex-row items-center gap-space-sm pt-space-md border-t border-surface-container-high">
						<a class="w-full sm:w-auto inline-flex items-center justify-center gap-space-xs font-label-md text-label-md text-on-tertiary bg-tertiary-container hover:opacity-90 px-space-lg py-2 rounded transition-opacity shadow-sm text-white no-underline" href="<?php echo esc_url( admin_url( 'admin.php?page=woo-product-export&tab=sello' ) ); ?>">
							<span><?php esc_html_e( 'Öppna Sello-export', 'woo-kustom-export' ); ?></span>
							<span class="material-symbols-outlined text-[16px]">arrow_forward</span>
						</a>
						<a class="w-full sm:w-auto inline-flex items-center justify-center gap-space-xs font-label-md text-label-md text-on-surface bg-surface-container-lowest border border-outline-variant hover:bg-surface-container-low px-space-md py-2 rounded transition-colors text-slate-700 no-underline cursor-pointer" href="<?php echo esc_url( $sello_download_url ); ?>" onclick="triggerQuickDownload('sello')">
							<span class="material-symbols-outlined text-[16px]">table_view</span>
							<span><?php esc_html_e( 'Snabbexport (Excel)', 'woo-kustom-export' ); ?></span>
						</a>
					</div>
				</div>
			</div>

			<!-- Shared Filter Panel (Gemensamma exportfilter) -->
			<div class="bg-surface-container-lowest shadow-sm rounded-xl p-space-xl border border-slate-200">
				<div class="flex flex-col md:flex-row md:items-center justify-between gap-space-md pb-space-md border-b border-surface-container-high">
					<div>
						<h4 class="font-headline-sm text-headline-sm text-on-surface font-semibold m-0 text-slate-900"><?php esc_html_e( 'Gemensamma exportfilter för katalogen', 'woo-kustom-export' ); ?></h4>
						<p class="font-body-sm text-body-sm text-on-surface-variant m-0 mt-0.5 text-slate-600"><?php esc_html_e( 'Dessa regler tillämpas globalt på både Kustom POS och Sello-flöden innan filgenerering.', 'woo-kustom-export' ); ?></p>
					</div>
					<div class="flex items-center gap-space-xs text-on-surface-variant font-code-sm text-code-sm text-slate-500 font-mono">
						<span class="material-symbols-outlined text-[15px] text-tertiary">tune</span>
						<span><?php esc_html_e( 'Filterscope: Global Catalog', 'woo-kustom-export' ); ?></span>
					</div>
				</div>
				<!-- Form Controls -->
				<form class="pt-space-lg flex flex-col gap-space-lg" id="shared-filters-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="woo_kustom_export_save_settings">
					<input type="hidden" name="return_tab" value="dashboard">
					<?php wp_nonce_field( 'woo_kustom_export_save_settings' ); ?>

					<div class="grid grid-cols-1 md:grid-cols-3 gap-space-md">
						<!-- Toggle 1 -->
						<label class="flex items-start gap-space-sm p-space-md rounded-lg bg-surface-container-low/50 hover:bg-surface-container-low cursor-pointer transition-colors border border-transparent hover:border-surface-container">
							<input class="mt-1 w-4 h-4 rounded text-primary focus:ring-secondary accent-primary cursor-pointer" id="filter-drafts" name="woo_kustom_export[include_drafts]" value="1" type="checkbox" <?php checked( ! empty( $settings['include_drafts'] ) ); ?>>
							<div class="flex flex-col">
								<span class="font-title-md text-title-md text-on-surface font-semibold text-slate-900"><?php esc_html_e( 'Inkludera utkast', 'woo-kustom-export' ); ?></span>
								<span class="font-body-sm text-body-sm text-on-surface-variant text-slate-500"><?php esc_html_e( 'Produkter som ännu inte har publicerats', 'woo-kustom-export' ); ?></span>
							</div>
						</label>
						<!-- Toggle 2 -->
						<label class="flex items-start gap-space-sm p-space-md rounded-lg bg-surface-container-low/50 hover:bg-surface-container-low cursor-pointer transition-colors border border-transparent hover:border-surface-container">
							<input class="mt-1 w-4 h-4 rounded text-primary focus:ring-secondary accent-primary cursor-pointer" id="filter-private" name="woo_kustom_export[include_private]" value="1" type="checkbox" <?php checked( ! empty( $settings['include_private'] ) ); ?>>
							<div class="flex flex-col">
								<span class="font-title-md text-title-md text-on-surface font-semibold text-slate-900"><?php esc_html_e( 'Inkludera privata produkter', 'woo-kustom-export' ); ?></span>
								<span class="font-body-sm text-body-sm text-on-surface-variant text-slate-500"><?php esc_html_e( 'Synliga enbart för administratörer', 'woo-kustom-export' ); ?></span>
							</div>
						</label>
						<!-- Toggle 3 -->
						<label class="flex items-start gap-space-sm p-space-md rounded-lg bg-surface-container-low/50 hover:bg-surface-container-low cursor-pointer transition-colors border border-transparent hover:border-surface-container">
							<input class="mt-1 w-4 h-4 rounded text-primary focus:ring-secondary accent-primary cursor-pointer" id="filter-outofstock" name="woo_kustom_export[include_outofstock]" value="1" type="checkbox" <?php checked( ! empty( $settings['include_outofstock'] ) ); ?>>
							<div class="flex flex-col">
								<span class="font-title-md text-title-md text-on-surface font-semibold text-slate-900"><?php esc_html_e( 'Inkludera slut i lager', 'woo-kustom-export' ); ?></span>
								<span class="font-body-sm text-body-sm text-on-surface-variant text-slate-500"><?php esc_html_e( 'Behåll produkter med 0 i lagersaldo', 'woo-kustom-export' ); ?></span>
							</div>
						</label>
					</div>

					<!-- Action Footer with Feedback -->
					<div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-space-md pt-space-md border-t border-surface-container-high">
						<div class="flex items-center gap-space-sm">
							<button class="inline-flex items-center gap-space-xs font-label-md text-label-md text-on-primary bg-primary hover:bg-primary-container px-space-lg py-2 rounded transition-colors shadow-sm cursor-pointer border-0 text-white" id="save-btn" type="submit">
								<span class="material-symbols-outlined text-[16px]">save</span>
								<span><?php esc_html_e( 'Spara filter', 'woo-kustom-export' ); ?></span>
							</button>
							<span class="hidden items-center gap-1 font-label-sm text-label-sm text-tertiary" id="save-indicator">
								<span class="material-symbols-outlined text-[14px]">done</span>
								<?php esc_html_e( 'Sparat i databasen!', 'woo-kustom-export' ); ?>
							</span>
						</div>
						<div class="flex items-center gap-space-xs font-code-sm text-code-sm text-on-surface-variant font-mono text-slate-500">
							<span class="material-symbols-outlined text-[15px] text-outline text-slate-400">history</span>
							<span id="last-saved-timestamp"><?php printf( esc_html__( 'Senast sparat: Idag kl %s', 'woo-kustom-export' ), esc_html( date_i18n( 'H:i' ) ) ); ?></span>
						</div>
					</div>
				</form>
			</div>

			<!-- Senaste genereringar & filarkiv -->
			<div class="w-full bg-surface-container-lowest shadow-sm rounded-xl p-space-lg border border-slate-200">
				<div class="flex items-center justify-between mb-space-md">
					<div class="flex items-center gap-space-xs">
						<span class="material-symbols-outlined text-[18px] text-secondary">database</span>
						<h4 class="font-title-md text-title-md text-on-surface m-0 font-semibold text-slate-900"><?php esc_html_e( 'Senaste genereringar & filarkiv', 'woo-kustom-export' ); ?></h4>
					</div>
					<span class="font-code-sm text-code-sm text-on-surface-variant font-mono text-slate-500"><?php esc_html_e( 'Auto-rensning: 7 dagar', 'woo-kustom-export' ); ?></span>
				</div>

				<?php if ( isset( $_GET['archive_deleted'] ) ) : ?>
					<div class="mb-space-md p-3 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm flex items-center gap-2">
						<span class="material-symbols-outlined text-[18px] text-emerald-600">check_circle</span>
						<span><?php esc_html_e( 'Exportfilen har tagits bort från arkivet.', 'woo-kustom-export' ); ?></span>
					</div>
				<?php endif; ?>

				<div class="overflow-x-auto">
					<table class="w-full text-left" style="border-collapse: collapse;">
						<thead>
							<tr class="border-b border-surface-container-high font-label-sm text-label-sm text-on-surface-variant text-slate-500 uppercase tracking-wider">
								<th class="pb-space-xs font-semibold"><?php esc_html_e( 'MÅLSYSTEM', 'woo-kustom-export' ); ?></th>
								<th class="pb-space-xs font-semibold"><?php esc_html_e( 'FILNAMN', 'woo-kustom-export' ); ?></th>
								<th class="pb-space-xs font-semibold"><?php esc_html_e( 'RADER', 'woo-kustom-export' ); ?></th>
								<th class="pb-space-xs font-semibold"><?php esc_html_e( 'TIDPUNKT', 'woo-kustom-export' ); ?></th>
								<th class="pb-space-xs font-semibold text-right"><?php esc_html_e( 'STATUS & ÅTGÄRD', 'woo-kustom-export' ); ?></th>
							</tr>
						</thead>
						<tbody class="divide-y divide-surface-container font-body-sm text-body-sm text-slate-700">
							<?php
							$archive_entries = self::get_archive_entries();
							if ( empty( $archive_entries ) ) :
							?>
								<tr>
									<td colspan="5" class="py-8 text-center text-slate-500">
										<span class="material-symbols-outlined text-3xl text-slate-300 block mb-2">folder_off</span>
										<span class="font-medium text-slate-700 block"><?php esc_html_e( 'Inga sparade exportfiler i arkivet ännu.', 'woo-kustom-export' ); ?></span>
										<span class="text-xs text-slate-400 mt-1 block"><?php esc_html_e( 'När du genomför en export sparas filen här automatiskt i 7 dagar med direkt nedladdningslänk.', 'woo-kustom-export' ); ?></span>
									</td>
								</tr>
							<?php else : ?>
								<?php
								foreach ( $archive_entries as $entry ) :
									$entry_id     = isset( $entry['id'] ) ? $entry['id'] : '';
									$download_url = self::get_archive_download_url( $entry_id );
									$delete_url   = self::get_archive_delete_url( $entry_id );
									$platform     = isset( $entry['platform'] ) ? $entry['platform'] : 'kustom';
									$filename     = isset( $entry['filename'] ) ? $entry['filename'] : '';
									$is_kustom    = ( 'kustom' === $platform );
									$is_xlsx      = ( 'sello-xlsx' === $platform );
									$file_ext     = pathinfo( $filename, PATHINFO_EXTENSION );
								?>
									<tr class="hover:bg-surface-container-low/50">
										<td class="py-2.5">
											<?php if ( $is_kustom ) : ?>
												<span class="font-label-sm text-label-sm px-1.5 py-0.5 rounded bg-surface-container-low text-secondary font-semibold">Kustom POS</span>
											<?php elseif ( $is_xlsx ) : ?>
												<span class="font-label-sm text-label-sm px-1.5 py-0.5 rounded bg-tertiary-container text-on-tertiary font-semibold">Sello Excel</span>
											<?php else : ?>
												<span class="font-label-sm text-label-sm px-1.5 py-0.5 rounded bg-tertiary-container text-on-tertiary font-semibold">Sello CSV</span>
											<?php endif; ?>
										</td>
										<td class="py-2.5 font-code-sm text-code-sm text-on-surface font-mono font-medium">
											<a class="inline-flex items-center gap-1.5 text-primary hover:underline text-slate-900 font-mono no-underline" href="<?php echo esc_url( $download_url ); ?>" title="<?php esc_attr_e( 'Klicka för att ladda ner filen', 'woo-kustom-export' ); ?>">
												<span class="material-symbols-outlined text-[16px] text-slate-400"><?php echo esc_html( 'xlsx' === $file_ext ? 'table_view' : 'description' ); ?></span>
												<span><?php echo esc_html( $filename ); ?></span>
											</a>
										</td>
										<td class="py-2.5 text-on-surface-variant text-slate-500 font-mono">
											<div><?php echo esc_html( number_format_i18n( isset( $entry['rows'] ) ? (int) $entry['rows'] : 0 ) ); ?></div>
											<?php if ( ! empty( $entry['size'] ) ) : ?>
												<div class="text-[11px] text-slate-400"><?php echo esc_html( size_format( (int) $entry['size'] ) ); ?></div>
											<?php endif; ?>
										</td>
										<td class="py-2.5 text-on-surface-variant text-slate-500 whitespace-nowrap">
											<?php echo esc_html( self::format_archive_date( isset( $entry['timestamp'] ) ? (int) $entry['timestamp'] : time() ) ); ?>
										</td>
										<td class="py-2.5 text-right whitespace-nowrap">
											<div class="inline-flex items-center justify-end gap-2">
												<span class="inline-flex items-center gap-1 font-label-sm text-label-sm text-tertiary">
													<span class="w-1.5 h-1.5 rounded-full bg-tertiary"></span> <?php esc_html_e( 'Klar', 'woo-kustom-export' ); ?>
												</span>
												<a class="p-1.5 text-slate-500 hover:text-primary hover:bg-slate-100 rounded transition-colors inline-flex items-center no-underline" href="<?php echo esc_url( $download_url ); ?>" title="<?php esc_attr_e( 'Ladda ner', 'woo-kustom-export' ); ?>">
													<span class="material-symbols-outlined text-[18px]">download</span>
												</a>
												<a class="p-1.5 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded transition-colors inline-flex items-center no-underline" href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Är du säker på att du vill ta bort denna exportfil från arkivet?', 'woo-kustom-export' ) ); ?>');" title="<?php esc_attr_e( 'Ta bort från arkivet', 'woo-kustom-export' ); ?>">
													<span class="material-symbols-outlined text-[18px]">delete</span>
												</a>
											</div>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Rendera fliken Hjälp & Dokumentation.
	 */
	protected function render_doc_tab() {
		$kustom_tab_url      = admin_url( 'admin.php?page=woo-product-export&tab=kustom' );
		$sello_tab_url       = admin_url( 'admin.php?page=woo-product-export&tab=sello' );
		$kustom_download_url = $this->get_download_url( 'kustom' );
		$sello_download_url  = $this->get_download_url( 'sello-xlsx' );
		?>
		<div class="flex flex-col w-full space-y-6">

			<!-- Huvudrubrik för dokumentationen -->
			<div class="bg-white border border-slate-200 rounded-xl p-6 shadow-sm flex flex-col md:flex-row md:items-center justify-between gap-6">
				<div class="space-y-1">
					<div class="inline-flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wider text-blue-700 bg-blue-50 px-2.5 py-1 rounded-md border border-blue-100">
						<span><?php esc_html_e( 'Teknisk handbok & Guide', 'woo-kustom-export' ); ?></span>
					</div>
					<h2 class="text-2xl font-bold text-slate-900 m-0"><?php esc_html_e( 'Dokumentation & Användarguide', 'woo-kustom-export' ); ?></h2>
					<p class="text-sm text-slate-600 max-w-2xl m-0">
						<?php esc_html_e( 'Här hittar du snabbstartsguider, tekniska kolumnspecifikationer samt instruktioner för både Kustom POS och Sellos marknadsplatser.', 'woo-kustom-export' ); ?>
					</p>
				</div>

				<!-- Snabbsök / Hoppa till -->
				<div class="flex items-center flex-wrap gap-2 shrink-0">
					<a href="#kustom-pos-guide" class="px-3 py-2 text-xs font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 rounded-lg border border-slate-200 transition-colors no-underline">
						↓ Kustom POS
					</a>
					<a href="#sello-guide" class="px-3 py-2 text-xs font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 rounded-lg border border-slate-200 transition-colors no-underline">
						↓ Sello Export
					</a>
					<a href="#faq-section" class="px-3 py-2 text-xs font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 rounded-lg border border-slate-200 transition-colors no-underline">
						↓ <?php esc_html_e( 'Vanliga frågor', 'woo-kustom-export' ); ?>
					</a>
				</div>
			</div>

			<!-- 3 Snabbstartskort (Tydlig överblick) -->
			<div class="grid grid-cols-1 md:grid-cols-3 gap-5">
				<!-- Kort 1: On-demand & Minnesoptimering -->
				<div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm space-y-3 flex flex-col justify-between">
					<div class="space-y-3">
						<div class="w-9 h-9 rounded-lg bg-blue-50 border border-blue-100 flex items-center justify-center text-blue-700">
							<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
							</svg>
						</div>
						<h3 class="text-base font-bold text-slate-900 m-0"><?php esc_html_e( '1. Minnesoptimerad export', 'woo-kustom-export' ); ?></h3>
						<p class="text-xs text-slate-600 leading-relaxed m-0">
							<?php esc_html_e( 'Genererar filer i realtid med batchvis inläsning (100 produkter i taget). Strömmar direkt till webbläsaren utan att belasta serverns minne.', 'woo-kustom-export' ); ?>
						</p>
					</div>
					<div>
						<span class="inline-block text-[11px] font-semibold text-slate-600 bg-slate-50 px-2 py-0.5 rounded border border-slate-200">
							<?php esc_html_e( 'Batch-generatorer & strömning', 'woo-kustom-export' ); ?>
						</span>
					</div>
				</div>

				<!-- Kort 2: Filformat & Validering -->
				<div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm space-y-3 flex flex-col justify-between">
					<div class="space-y-3">
						<div class="w-9 h-9 rounded-lg bg-emerald-50 border border-emerald-100 flex items-center justify-center text-emerald-700">
							<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
							</svg>
						</div>
						<h3 class="text-base font-bold text-slate-900 m-0"><?php esc_html_e( '2. Anpassade format & validering', 'woo-kustom-export' ); ?></h3>
						<p class="text-xs text-slate-600 leading-relaxed m-0">
							<?php esc_html_e( 'Äkta Excel (.xlsx) med variantkopplingar för Sello samt RFC 4180 CSV med inbyggt formelskydd mot CSV-injection.', 'woo-kustom-export' ); ?>
						</p>
					</div>
					<div>
						<span class="inline-block text-[11px] font-semibold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded border border-emerald-200">
							<?php esc_html_e( '.xlsx (Sello) • .csv (Kustom)', 'woo-kustom-export' ); ?>
						</span>
					</div>
				</div>

				<!-- Kort 3: Behörighet & Säkerhet -->
				<div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm space-y-3 flex flex-col justify-between">
					<div class="space-y-3">
						<div class="w-9 h-9 rounded-lg bg-indigo-50 border border-indigo-100 flex items-center justify-center text-indigo-700">
							<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
							</svg>
						</div>
						<h3 class="text-base font-bold text-slate-900 m-0"><?php esc_html_e( '3. Behörighetsstyrd åtkomst', 'woo-kustom-export' ); ?></h3>
						<p class="text-xs text-slate-600 leading-relaxed m-0">
							<?php esc_html_e( 'Alla exporter kräver administratörsbehörighet (manage_woocommerce) och verifieras med WordPress säkerhets-nonces.', 'woo-kustom-export' ); ?>
						</p>
					</div>
					<div>
						<span class="inline-block text-[11px] font-semibold text-indigo-700 bg-indigo-50 px-2 py-0.5 rounded border border-indigo-200">
							<?php esc_html_e( 'Nonce & rollkontroll', 'woo-kustom-export' ); ?>
						</span>
					</div>
				</div>
			</div>

			<!-- SEKTION 1: KUSTOM POS GUIDE -->
			<section id="kustom-pos-guide" class="bg-white border border-slate-200 rounded-xl p-6 shadow-sm space-y-6">
				<div class="flex flex-col sm:flex-row sm:items-center justify-between border-b border-slate-100 pb-4 gap-3">
					<div class="flex items-center gap-3">
						<span class="px-2.5 py-1 text-xs font-bold bg-blue-100 text-blue-800 rounded-md">KASSA &amp; BUTIK</span>
						<h3 class="text-lg font-bold text-slate-900 m-0"><?php esc_html_e( 'Kustom POS Importguide', 'woo-kustom-export' ); ?></h3>
					</div>
					<div class="flex items-center gap-3">
						<a href="<?php echo esc_url( $kustom_tab_url ); ?>" class="text-xs font-semibold text-blue-700 hover:underline no-underline">
							<?php esc_html_e( 'Gå till Kustom POS-fliken →', 'woo-kustom-export' ); ?>
						</a>
					</div>
				</div>

				<!-- Viktig varning -->
				<div class="bg-amber-50 border-l-4 border-amber-500 p-4 rounded-r-lg">
					<div class="flex items-start gap-3">
						<svg class="w-5 h-5 text-amber-600 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
						</svg>
						<div class="text-xs text-amber-900 space-y-1">
							<p class="font-bold m-0"><?php esc_html_e( 'Viktigt vid filimport i Kustom POS', 'woo-kustom-export' ); ?></p>
							<p class="text-amber-800 m-0">
								<?php esc_html_e( 'Kustom POS skriver över befintliga artiklar med samma SKU/streckkod. Säkerställ alltid att du tar en säkerhetskopia i kassans administrationspanel innan du läser in en ny full CSV-katalog.', 'woo-kustom-export' ); ?>
							</p>
						</div>
					</div>
				</div>

				<!-- Steg 1-2-3 -->
				<div class="grid grid-cols-1 md:grid-cols-3 gap-4">
					<div class="border border-slate-200 rounded-lg p-4 bg-slate-50/50 space-y-2">
						<div class="flex items-center gap-2">
							<span class="w-6 h-6 rounded-full bg-blue-700 text-white text-xs font-bold flex items-center justify-center shrink-0">1</span>
							<span class="text-xs font-bold text-slate-900"><?php esc_html_e( 'Hämta CSV-fil', 'woo-kustom-export' ); ?></span>
						</div>
						<p class="text-xs text-slate-600 leading-relaxed m-0">
							<?php esc_html_e( 'Gå till fliken Kustom POS och klicka på "Ladda ner Kustom POS CSV". Kontrollera att dina önskade attribut (storlek, färg m.m.) är markerade.', 'woo-kustom-export' ); ?>
						</p>
					</div>

					<div class="border border-slate-200 rounded-lg p-4 bg-slate-50/50 space-y-2">
						<div class="flex items-center gap-2">
							<span class="w-6 h-6 rounded-full bg-blue-700 text-white text-xs font-bold flex items-center justify-center shrink-0">2</span>
							<span class="text-xs font-bold text-slate-900"><?php esc_html_e( 'Öppna Kustom Admin', 'woo-kustom-export' ); ?></span>
						</div>
						<p class="text-xs text-slate-600 leading-relaxed m-0">
							<?php esc_html_e( 'Logga in i ditt Kustom POS kassa-system, navigera till Artiklar → Import och välj teckensnitt UTF-8 med komma som avgränsare.', 'woo-kustom-export' ); ?>
						</p>
					</div>

					<div class="border border-slate-200 rounded-lg p-4 bg-slate-50/50 space-y-2">
						<div class="flex items-center gap-2">
							<span class="w-6 h-6 rounded-full bg-blue-700 text-white text-xs font-bold flex items-center justify-center shrink-0">3</span>
							<span class="text-xs font-bold text-slate-900"><?php esc_html_e( 'Verifiera fält', 'woo-kustom-export' ); ?></span>
						</div>
						<p class="text-xs text-slate-600 leading-relaxed m-0">
							<?php esc_html_e( 'Kustom mappar automatiskt de fyra obligatoriska kolumnerna: product_name, price_including_vat, vat_rate och currency. Klicka på "Starta import".', 'woo-kustom-export' ); ?>
						</p>
					</div>
				</div>

				<!-- Kolumnmappningstabell Kustom -->
				<div class="border border-slate-200 rounded-lg overflow-hidden">
					<div class="bg-slate-100 px-4 py-2 text-xs font-bold text-slate-700 border-b border-slate-200">
						<?php esc_html_e( 'Kustom POS: Standardkolumner och datatyper', 'woo-kustom-export' ); ?>
					</div>
					<div class="overflow-x-auto">
						<table class="w-full text-left text-xs border-collapse">
							<thead class="bg-slate-50 text-slate-600 font-semibold border-b border-slate-200">
								<tr>
									<th class="p-3"><?php esc_html_e( 'Kolumn i fil', 'woo-kustom-export' ); ?></th>
									<th class="p-3"><?php esc_html_e( 'Kravstatus', 'woo-kustom-export' ); ?></th>
									<th class="p-3"><?php esc_html_e( 'WooCommerce-fält', 'woo-kustom-export' ); ?></th>
									<th class="p-3"><?php esc_html_e( 'Beskrivning & Formatering', 'woo-kustom-export' ); ?></th>
								</tr>
							</thead>
							<tbody class="divide-y divide-slate-200 text-slate-700">
								<tr>
									<td class="p-3 font-mono font-medium text-blue-900">product_name</td>
									<td class="p-3"><span class="px-2 py-0.5 bg-blue-50 text-blue-700 font-bold rounded text-[10px]"><?php esc_html_e( 'OBLIGATORISK', 'woo-kustom-export' ); ?></span></td>
									<td class="p-3 font-mono text-slate-500">post_title</td>
									<td class="p-3"><?php esc_html_e( 'Artikelnamn inklusive variantbeskrivning (t.ex. "Linnéskjorta Blå - M").', 'woo-kustom-export' ); ?></td>
								</tr>
								<tr>
									<td class="p-3 font-mono font-medium text-blue-900">price_including_vat</td>
									<td class="p-3"><span class="px-2 py-0.5 bg-blue-50 text-blue-700 font-bold rounded text-[10px]"><?php esc_html_e( 'OBLIGATORISK', 'woo-kustom-export' ); ?></span></td>
									<td class="p-3 font-mono text-slate-500">_price (inkl moms)</td>
									<td class="p-3"><?php esc_html_e( 'Slutkundspris inklusive moms formaterat med 2 decimaler (punktavgränsare).', 'woo-kustom-export' ); ?></td>
								</tr>
								<tr>
									<td class="p-3 font-mono font-medium text-blue-900">vat_rate</td>
									<td class="p-3"><span class="px-2 py-0.5 bg-blue-50 text-blue-700 font-bold rounded text-[10px]"><?php esc_html_e( 'OBLIGATORISK', 'woo-kustom-export' ); ?></span></td>
									<td class="p-3 font-mono text-slate-500">tax_rate</td>
									<td class="p-3"><?php esc_html_e( 'Momssats i procent heltal (t.ex. 25, 12 eller 6).', 'woo-kustom-export' ); ?></td>
								</tr>
								<tr>
									<td class="p-3 font-mono font-medium text-blue-900">currency</td>
									<td class="p-3"><span class="px-2 py-0.5 bg-blue-50 text-blue-700 font-bold rounded text-[10px]"><?php esc_html_e( 'OBLIGATORISK', 'woo-kustom-export' ); ?></span></td>
									<td class="p-3 font-mono text-slate-500">get_woocommerce_currency()</td>
									<td class="p-3"><?php esc_html_e( 'Butikens standardvaluta i treställig ISO 4217-kod (t.ex. "SEK", "EUR").', 'woo-kustom-export' ); ?></td>
								</tr>
								<tr>
									<td class="p-3 font-mono font-medium text-blue-900">sku</td>
									<td class="p-3"><span class="px-2 py-0.5 bg-slate-100 text-slate-600 font-bold rounded text-[10px]"><?php esc_html_e( 'TILLVAL', 'woo-kustom-export' ); ?></span></td>
									<td class="p-3 font-mono text-slate-500">_sku</td>
									<td class="p-3"><?php esc_html_e( 'Unikt artikelnummer. Om tomt skapas automatiskt fallback [HUVUD-SKU]-[VARIANT-ID] eller post-ID.', 'woo-kustom-export' ); ?></td>
								</tr>
								<tr>
									<td class="p-3 font-mono font-medium text-blue-900">attribute_*</td>
									<td class="p-3"><span class="px-2 py-0.5 bg-indigo-50 text-indigo-700 font-bold rounded text-[10px]"><?php esc_html_e( 'ATTRIBUT', 'woo-kustom-export' ); ?></span></td>
									<td class="p-3 font-mono text-slate-500">pa_storlek, pa_farg</td>
									<td class="p-3"><?php esc_html_e( 'Egna butiksattribut skapas som individuella kolumner för exakt kassasökning.', 'woo-kustom-export' ); ?></td>
								</tr>
							</tbody>
						</table>
					</div>
				</div>
			</section>

			<!-- SEKTION 2: SELLO GUIDE -->
			<section id="sello-guide" class="bg-white border border-slate-200 rounded-xl p-6 shadow-sm space-y-6">
				<div class="flex flex-col sm:flex-row sm:items-center justify-between border-b border-slate-100 pb-4 gap-3">
					<div class="flex items-center gap-3">
						<span class="px-2.5 py-1 text-xs font-bold bg-emerald-100 text-emerald-800 rounded-md">MARKNADSPLATSER</span>
						<h3 class="text-lg font-bold text-slate-900 m-0"><?php esc_html_e( 'Sello Marknadsplatsintegration & Formatregler', 'woo-kustom-export' ); ?></h3>
					</div>
					<div class="flex items-center gap-3">
						<a href="<?php echo esc_url( $sello_tab_url ); ?>" class="text-xs font-semibold text-emerald-700 hover:underline no-underline">
							<?php esc_html_e( 'Gå till Sello-fliken →', 'woo-kustom-export' ); ?>
						</a>
					</div>
				</div>

				<div class="grid grid-cols-1 md:grid-cols-2 gap-6">
					<!-- Vänster: Viktiga regler -->
					<div class="space-y-4">
						<h4 class="text-sm font-bold text-slate-900 flex items-center gap-2 m-0">
							<span class="w-2 h-2 rounded-full bg-emerald-600"></span>
							<?php esc_html_e( 'Automatiska datakonverteringar för Sello', 'woo-kustom-export' ); ?>
						</h4>
						<ul class="text-xs text-slate-600 space-y-3 p-0 m-0 list-none">
							<li class="flex items-start gap-2 bg-slate-50 p-3 rounded-lg border border-slate-200">
								<span class="font-bold text-slate-900 shrink-0"><?php esc_html_e( '1. Fast butikspris för Tradera:', 'woo-kustom-export' ); ?></span>
								<span><?php esc_html_e( 'För butiksobjekt på Tradera exporteras priset i "Tradera price store" som heltal inklusive moms (slutkundspriset i SEK). Kolumnerna Price och Calculate utelämnas för att förhindra oönskade automatiska prisberäkningar i Sello.', 'woo-kustom-export' ); ?></span>
							</li>
							<li class="flex items-start gap-2 bg-slate-50 p-3 rounded-lg border border-slate-200">
								<span class="font-bold text-slate-900 shrink-0"><?php esc_html_e( '2. Variantgruppering:', 'woo-kustom-export' ); ?></span>
								<span><?php esc_html_e( 'Varianter binds automatiskt till föräldraartikeln med dess artikelnummer i kolumnen Group sku samt variationsdimensionen i Group type (color, size eller model).', 'woo-kustom-export' ); ?></span>
							</li>
							<li class="flex items-start gap-2 bg-slate-50 p-3 rounded-lg border border-slate-200">
								<span class="font-bold text-slate-900 shrink-0"><?php esc_html_e( '3. Viktenheter till gram:', 'woo-kustom-export' ); ?></span>
								<span><?php esc_html_e( 'Om WooCommerce använder kg konverteras vikten till gram för att uppfylla Sellos fraktschema för Tradera och CDON.', 'woo-kustom-export' ); ?></span>
							</li>
						</ul>
					</div>

					<!-- Höger: Sello API & Filuppladdning -->
					<div class="space-y-4">
						<h4 class="text-sm font-bold text-slate-900 flex items-center gap-2 m-0">
							<span class="w-2 h-2 rounded-full bg-emerald-600"></span>
							<?php esc_html_e( 'Rekommenderat arbetsflöde', 'woo-kustom-export' ); ?>
						</h4>
						<div class="bg-emerald-50/60 border border-emerald-200 rounded-lg p-4 text-xs text-emerald-950 space-y-3">
							<p class="m-0 leading-relaxed">
								<strong><?php esc_html_e( 'Steg 1:', 'woo-kustom-export' ); ?></strong> <?php esc_html_e( 'Ladda ner den färdiga .xlsx-filen från Sello-fliken i detta plugin. Excel-formatet bevarar tecken och kolumntyper bäst.', 'woo-kustom-export' ); ?>
							</p>
							<p class="m-0 leading-relaxed">
								<strong><?php esc_html_e( 'Steg 2:', 'woo-kustom-export' ); ?></strong> <?php printf( esc_html__( 'Öppna %s och gå till Import / export, välj sedan Excel under Import.', 'woo-kustom-export' ), '<a href="https://ui.sello.io/login" target="_blank" rel="noopener noreferrer" class="underline font-semibold text-emerald-800">ui.sello.io</a>' ); ?>
							</p>
							<p class="m-0 leading-relaxed">
								<strong><?php esc_html_e( 'Steg 3:', 'woo-kustom-export' ); ?></strong> <?php esc_html_e( 'Välj "Skapa nya produkter från min fil" för nya artiklar eller "Uppdatera produkter med min fil" för att uppdatera priser och lager.', 'woo-kustom-export' ); ?>
							</p>
							<div class="pt-2 border-t border-emerald-200 text-[11px] text-emerald-800">
								<?php esc_html_e( 'Tips: Exportfilerna är färdigformaterade enligt Sellos officiella Excel-importspecifikation (v4-kompatibel).', 'woo-kustom-export' ); ?>
							</div>
						</div>
					</div>
				</div>
			</section>

			<!-- SEKTION 3: FELSÖKNING & VANLIGA FRÅGOR (FAQ) -->
			<section id="faq-section" class="bg-white border border-slate-200 rounded-xl p-6 shadow-sm space-y-4">
				<div class="flex items-center justify-between border-b border-slate-100 pb-3">
					<h3 class="text-lg font-bold text-slate-900 m-0"><?php esc_html_e( 'Vanliga frågor & Felsökning', 'woo-kustom-export' ); ?></h3>
					<span class="text-xs text-slate-400"><?php esc_html_e( 'Klicka för att expandera svar', 'woo-kustom-export' ); ?></span>
				</div>

				<div class="divide-y divide-slate-100">
					<!-- Q1 -->
					<details class="group py-3" open>
						<summary class="flex justify-between items-center font-medium text-xs text-slate-900 cursor-pointer list-none select-none">
							<span class="font-semibold text-sm"><?php esc_html_e( 'Varför saknas vissa produkter i exportfilen?', 'woo-kustom-export' ); ?></span>
							<span class="transition group-open:rotate-180 text-slate-500">
								<svg fill="none" height="18" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewBox="0 0 24 24" width="18"><polyline points="6 9 12 15 18 9"></polyline></svg>
							</span>
						</summary>
						<p class="text-xs text-slate-600 mt-2 leading-relaxed m-0">
							<?php esc_html_e( 'Kontrollera Gemensamma exportfilter på fliken Översikt. Som standard exkluderas produkter som ligger i utkast, är privata eller har slut i lagersaldo (0). Kryssa i motsvarande rutor under inställningar om dessa ska inkluderas.', 'woo-kustom-export' ); ?>
						</p>
					</details>

					<!-- Q2 -->
					<details class="group py-3">
						<summary class="flex justify-between items-center font-medium text-xs text-slate-900 cursor-pointer list-none select-none">
							<span class="font-semibold text-sm"><?php esc_html_e( 'Hur hanteras varianter som saknar SKU?', 'woo-kustom-export' ); ?></span>
							<span class="transition group-open:rotate-180 text-slate-500">
								<svg fill="none" height="18" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewBox="0 0 24 24" width="18"><polyline points="6 9 12 15 18 9"></polyline></svg>
							</span>
						</summary>
						<p class="text-xs text-slate-600 mt-2 leading-relaxed m-0">
							<?php esc_html_e( 'Om en variant i WooCommerce saknar ett eget unikt SKU, genererar exportmotorn automatiskt ett fallback-SKU med syntaxen [HUVUD-SKU]-[VARIANT-ID] (eller post-ID för enkla produkter) för att förhindra att importen kraschar hos Sello eller Kustom POS.', 'woo-kustom-export' ); ?>
						</p>
					</details>

					<!-- Q3 -->
					<details class="group py-3">
						<summary class="flex justify-between items-center font-medium text-xs text-slate-900 cursor-pointer list-none select-none">
							<span class="font-semibold text-sm"><?php esc_html_e( 'Hur ökar jag minnestilldelningen om exporten avbryts vid extremt stora kataloger?', 'woo-kustom-export' ); ?></span>
							<span class="transition group-open:rotate-180 text-slate-500">
								<svg fill="none" height="18" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewBox="0 0 24 24" width="18"><polyline points="6 9 12 15 18 9"></polyline></svg>
							</span>
						</summary>
						<p class="text-xs text-slate-600 mt-2 leading-relaxed m-0">
							<?php esc_html_e( 'Pluginet är optimerat för batch-streaming, men om din katalog innehåller mer än 20 000 varianter kan du höja WP_MEMORY_LIMIT i wp-config.php till minst 256M eller 512M.', 'woo-kustom-export' ); ?>
						</p>
					</details>

					<!-- Q4 -->
					<details class="group py-3">
						<summary class="flex justify-between items-center font-medium text-xs text-slate-900 cursor-pointer list-none select-none">
							<span class="font-semibold text-sm"><?php esc_html_e( 'Kan jag lägga till anpassade attribut som egna kolumner i Kustom POS?', 'woo-kustom-export' ); ?></span>
							<span class="transition group-open:rotate-180 text-slate-500">
								<svg fill="none" height="18" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewBox="0 0 24 24" width="18"><polyline points="6 9 12 15 18 9"></polyline></svg>
							</span>
						</summary>
						<p class="text-xs text-slate-600 mt-2 leading-relaxed m-0">
							<?php esc_html_e( 'Ja! Under fliken Kustom POS kan du kryssa i butikens globala taxonomier (t.ex. storlek, färg, varumärke) samt ange egna produktattribut. De inkluderas då som egna sökbara kolumner i CSV-filen.', 'woo-kustom-export' ); ?>
						</p>
					</details>

					<!-- Q5 -->
					<details class="group py-3">
						<summary class="flex justify-between items-center font-medium text-xs text-slate-900 cursor-pointer list-none select-none">
							<span class="font-semibold text-sm"><?php esc_html_e( 'Hur förhindrar jag teckenfel (Å, Ä, Ö) när jag öppnar filen i Excel?', 'woo-kustom-export' ); ?></span>
							<span class="transition group-open:rotate-180 text-slate-500">
								<svg fill="none" height="18" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewBox="0 0 24 24" width="18"><polyline points="6 9 12 15 18 9"></polyline></svg>
							</span>
						</summary>
						<p class="text-xs text-slate-600 mt-2 leading-relaxed m-0">
							<?php esc_html_e( 'Pluginet bäddar automatiskt in en UTF-8 Byte Order Mark (BOM) i CSV-filen och använder svensk standard. Detta gör att Microsoft Excel automatiskt tolkar filen som UTF-8 och visar svenska tecken korrekt. För Sello rekommenderas dessutom att välja .xlsx-filen (Excel) som bevarar formatering helt utan importsteg.', 'woo-kustom-export' ); ?>
						</p>
					</details>
				</div>
			</section>

		</div>
		<?php
	}

	/**
	 * Rendera undersidan för Kustom POS-export.
	 */
	protected function render_kustom_tab() {
		$generator           = self::create_generator( 'kustom' );
		$stats               = $generator->get_stats();
		$total_rows          = (int) $stats['products'] + (int) $stats['variations'];
		$settings            = self::get_settings();
		$attributes          = method_exists( $generator, 'get_available_attributes' ) ? $generator->get_available_attributes() : array();
		$multi_value         = method_exists( $generator, 'get_multi_value_simple_products' ) ? $generator->get_multi_value_simple_products() : array();
		$selected_attributes = method_exists( $generator, 'get_selected_attributes' ) ? $generator->get_selected_attributes() : array();
		$supported_columns   = WOO_Kustom_CSV_Generator::get_supported_optional_columns();

		// Om inga attribut hittades i butiken (t.ex. ny butik/demo), visa standardförslag
		if ( empty( $attributes ) ) {
			$attributes = array( 'storlek', 'färg', 'varumärke', 'material' );
			if ( empty( $selected_attributes ) && empty( $settings['custom_attributes'] ) && ! isset( $_POST['action'] ) ) {
				$selected_attributes = array( 'storlek', 'färg', 'varumärke' );
			}
		}

		$download_url = $this->get_download_url( 'kustom' );

		// Standardkolumner i gränssnittet
		$standard_fields = array(
			'sku'               => array(
				'id'         => 'col-sku',
				'label_code' => 'sku',
				'name'       => 'col_sku',
				'title'      => __( 'SKU / Artikelnummer', 'woo-kustom-export' ),
				'sub'        => __( 'Fält: sku', 'woo-kustom-export' ),
				'default'    => true,
			),
			'gtin'              => array(
				'id'         => 'col-gtin',
				'label_code' => 'gtin',
				'name'       => 'col_gtin',
				'title'      => __( 'GTIN / EAN-streckkod', 'woo-kustom-export' ),
				'sub'        => __( 'Fält: gtin / barcode', 'woo-kustom-export' ),
				'default'    => true,
			),
			'regular_price'     => array(
				'id'         => 'col-reg-price',
				'label_code' => 'regular_price',
				'name'       => 'col_regular_price',
				'title'      => __( 'Ordinarie pris (inkl. moms)', 'woo-kustom-export' ),
				'sub'        => __( 'Fält: regular_price_inc_vat', 'woo-kustom-export' ),
				'default'    => true,
			),
			'sale_price'        => array(
				'id'         => 'col-sale-price',
				'label_code' => 'sale_price',
				'name'       => 'col_sale_price',
				'title'      => __( 'Reapris (inkl. moms)', 'woo-kustom-export' ),
				'sub'        => __( 'Fält: sale_price_inc_vat', 'woo-kustom-export' ),
				'default'    => true,
			),
			'stock_quantity'    => array(
				'id'         => 'col-stock',
				'label_code' => 'stock_quantity',
				'name'       => 'col_stock',
				'title'      => __( 'Lagersaldo', 'woo-kustom-export' ),
				'sub'        => __( 'Fält: stock_quantity', 'woo-kustom-export' ),
				'default'    => true,
			),
			'image_url'         => array(
				'id'         => 'col-image',
				'label_code' => 'image_url',
				'name'       => 'col_image',
				'title'      => __( 'Bild-URL (huvudbild)', 'woo-kustom-export' ),
				'sub'        => __( 'Fält: main_image_url', 'woo-kustom-export' ),
				'default'    => true,
			),
			'short_description' => array(
				'id'         => 'col-desc',
				'label_code' => 'short_description',
				'name'       => 'col_short_desc',
				'title'      => __( 'Kort beskrivning', 'woo-kustom-export' ),
				'sub'        => __( 'Fält: short_description', 'woo-kustom-export' ),
				'default'    => true,
			),
			'category_tree'     => array(
				'id'         => 'col-cat',
				'label_code' => 'category_tree',
				'name'       => 'col_category',
				'title'      => __( 'Produktkategori', 'woo-kustom-export' ),
				'sub'        => __( 'Fält: category_tree', 'woo-kustom-export' ),
				'default'    => true,
			),
		);

		// Avgör om inställningar sparats tidigare
		$has_saved_settings = ! empty( $settings['optional_columns'] );

		// Extra kolumner för utfällbar sektion
		$more_columns = array();
		foreach ( $supported_columns as $key => $col_data ) {
			if ( ! isset( $standard_fields[ $key ] ) && 'ean' !== $key && 'category' !== $key ) {
				$more_columns[ $key ] = $col_data;
			}
		}

		$active_attr_count = count( $selected_attributes );
		$total_attr_count  = count( $attributes );
		?>
		<div class="flex flex-col w-full gap-space-lg">
			<!-- Hero Card -->
			<section class="relative overflow-hidden bg-surface-container-lowest rounded-xl shadow-sm p-space-lg md:p-space-xl flex flex-col gap-space-lg">
				<div class="absolute -right-16 -top-16 w-80 h-80 rounded-full bg-primary-fixed opacity-40 blur-3xl pointer-events-none"></div>
				<div class="absolute -left-12 -bottom-12 w-64 h-64 rounded-full bg-surface-container opacity-60 blur-2xl pointer-events-none"></div>
				<div class="relative z-10 flex flex-col xl:flex-row xl:items-center justify-between gap-space-lg">
					<div class="flex flex-col gap-space-xs max-w-2xl">
						<div class="inline-flex items-center gap-space-xs">
							<span class="px-space-sm py-0.5 rounded bg-secondary-fixed text-primary font-label-sm text-label-sm font-semibold uppercase tracking-wider"><?php esc_html_e( 'Modul Export', 'woo-kustom-export' ); ?></span>
							<span class="font-body-sm text-body-sm text-on-surface-variant">• <?php esc_html_e( 'Kassaflöde', 'woo-kustom-export' ); ?></span>
						</div>
						<h2 class="font-headline-xl text-headline-xl text-on-surface font-bold tracking-tight"><?php esc_html_e( 'Kustom POS Produkt-export', 'woo-kustom-export' ); ?></h2>
						<p class="font-body-md text-body-md text-on-surface-variant leading-relaxed">
							<?php esc_html_e( 'Konfigurera produktkolumner och exportera sortimentet anpassat för Kustom POS kassaregister med optimerade fältdefinitioner och automatisk variantmappning.', 'woo-kustom-export' ); ?>
						</p>
					</div>
					<div class="flex items-center gap-space-md shrink-0">
						<button class="relative group inline-flex items-center justify-center gap-space-sm font-label-md text-label-md text-on-primary bg-primary-container hover:bg-primary px-space-xl py-3 rounded-lg shadow-md transition-all active:scale-[0.99] cursor-pointer border-0" id="downloadCsvBtn" type="button" data-download-url="<?php echo esc_url( $download_url ); ?>">
							<span class="material-symbols-outlined text-[20px] transition-transform group-hover:translate-y-0.5">download</span>
							<span class="font-semibold tracking-wide"><?php esc_html_e( 'Ladda ner Kustom POS CSV', 'woo-kustom-export' ); ?></span>
						</button>
					</div>
				</div>
				<div class="relative z-10 grid grid-cols-1 sm:grid-cols-3 gap-space-md pt-space-md border-t border-surface-container">
					<div class="flex flex-col p-space-md rounded-lg bg-surface-container-low transition-colors hover:bg-surface-container">
						<span class="font-label-sm text-label-sm text-on-surface-variant uppercase tracking-wider"><?php esc_html_e( 'Enkla produkter', 'woo-kustom-export' ); ?></span>
						<div class="flex items-baseline gap-space-xs mt-1">
							<span class="font-headline-lg text-headline-lg font-bold text-on-surface tracking-tight" id="countSimple"><?php echo esc_html( number_format_i18n( (int) $stats['products'] ) ); ?></span>
							<span class="font-label-sm text-label-sm text-tertiary font-medium"><?php esc_html_e( 'aktiva', 'woo-kustom-export' ); ?></span>
						</div>
					</div>
					<div class="flex flex-col p-space-md rounded-lg bg-surface-container-low transition-colors hover:bg-surface-container">
						<span class="font-label-sm text-label-sm text-on-surface-variant uppercase tracking-wider"><?php esc_html_e( 'Varianter', 'woo-kustom-export' ); ?></span>
						<div class="flex items-baseline gap-space-xs mt-1">
							<span class="font-headline-lg text-headline-lg font-bold text-on-surface tracking-tight" id="countVariants"><?php echo esc_html( number_format_i18n( (int) $stats['variations'] ) ); ?></span>
							<span class="font-label-sm text-label-sm text-secondary font-medium"><?php esc_html_e( 'kopplade', 'woo-kustom-export' ); ?></span>
						</div>
					</div>
					<div class="flex flex-col p-space-md rounded-lg bg-surface-container-high transition-colors">
						<span class="font-label-sm text-label-sm text-on-surface font-semibold uppercase tracking-wider"><?php esc_html_e( 'Totalt rader', 'woo-kustom-export' ); ?></span>
						<div class="flex items-baseline gap-space-xs mt-1">
							<span class="font-headline-lg text-headline-lg font-bold text-primary tracking-tight" id="countTotal"><?php echo esc_html( number_format_i18n( $total_rows ) ); ?></span>
							<span class="font-code-sm text-code-sm text-on-surface-variant"><?php esc_html_e( 'i exportfeed', 'woo-kustom-export' ); ?></span>
						</div>
					</div>
				</div>
			</section>

			<!-- Warning Banner -->
			<div class="flex items-start gap-space-md p-space-md rounded-lg bg-error-container/40 text-on-error-container shadow-sm border-l-4 border-error">
				<span class="material-symbols-outlined text-[22px] text-error shrink-0 mt-0.5" style="font-variation-settings: 'FILL' 1;">warning</span>
				<div class="flex flex-col gap-0.5">
					<span class="font-title-md text-title-md font-semibold text-error"><?php esc_html_e( 'Viktigt gällande import till Kustom POS', 'woo-kustom-export' ); ?></span>
					<p class="font-body-sm text-body-sm text-on-surface-variant leading-snug">
						<?php esc_html_e( 'När CSV-filen importeras till Kustom ersätts befintliga produkter i samma Kustom-butik. Säkerställ säkerhetskopia i ditt kassasystem innan du läser in filen.', 'woo-kustom-export' ); ?>
					</p>
				</div>
			</div>

			<!-- 2-kolumners layout -->
			<div class="grid grid-cols-1 lg:grid-cols-12 gap-space-lg items-start">
				<!-- Vänster kolumn (formulär för inställningar) -->
				<div class="lg:col-span-7 xl:col-span-7 flex flex-col gap-space-lg">
					<form id="kustomSettingsForm" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="flex flex-col gap-space-lg">
						<input type="hidden" name="action" value="woo_kustom_export_save_settings">
						<input type="hidden" name="return_tab" value="kustom">
						<input type="hidden" name="woo_kustom_export[include_drafts]" value="<?php echo ! empty( $settings['include_drafts'] ) ? '1' : '0'; ?>">
						<input type="hidden" name="woo_kustom_export[include_private]" value="<?php echo ! empty( $settings['include_private'] ) ? '1' : '0'; ?>">
						<input type="hidden" name="woo_kustom_export[include_outofstock]" value="<?php echo ! empty( $settings['include_outofstock'] ) ? '1' : '0'; ?>">
						<?php wp_nonce_field( 'woo_kustom_export_save_settings' ); ?>

						<!-- Sektion: Valbara produktkolumner -->
						<div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-lg">
							<div class="flex flex-col sm:flex-row sm:items-center justify-between pb-space-md border-b border-surface-container gap-space-sm">
								<div class="flex flex-col">
									<h3 class="font-headline-sm text-headline-sm text-on-surface font-semibold tracking-tight"><?php esc_html_e( 'Valbara produktkolumner', 'woo-kustom-export' ); ?></h3>
									<span class="font-body-sm text-body-sm text-on-surface-variant"><?php esc_html_e( 'Styr vilka standardfält som genereras i CSV-huvudet', 'woo-kustom-export' ); ?></span>
								</div>
								<div class="flex items-center gap-space-xs">
									<button class="px-2.5 py-1 rounded bg-surface-container-low hover:bg-surface-container font-label-sm text-label-sm text-secondary font-medium transition-colors cursor-pointer border-0" id="selectAllFields" type="button">
										<?php esc_html_e( 'Markera alla standardfält', 'woo-kustom-export' ); ?>
									</button>
									<span class="text-outline-variant text-[11px]">•</span>
									<button class="px-2.5 py-1 rounded bg-surface-container-low hover:bg-surface-container font-label-sm text-label-sm text-on-surface-variant hover:text-error transition-colors cursor-pointer border-0" id="deselectAllFields" type="button">
										<?php esc_html_e( 'Avmarkera alla standardfält', 'woo-kustom-export' ); ?>
									</button>
								</div>
							</div>

							<div class="grid grid-cols-1 md:grid-cols-2 gap-space-sm" id="standardFieldsGrid">
								<?php
								foreach ( $standard_fields as $field_key => $field_info ) :
									$is_checked = $has_saved_settings
										? ( ! empty( $settings['optional_columns'][ $field_key ] ) || ( 'gtin' === $field_key && ! empty( $settings['optional_columns']['ean'] ) ) || ( 'category_tree' === $field_key && ! empty( $settings['optional_columns']['category'] ) ) )
										: $field_info['default'];
									?>
									<label class="group flex items-start gap-space-md p-space-md rounded-lg bg-surface-container-low/70 hover:bg-surface-container cursor-pointer transition-colors border border-transparent hover:border-surface-container">
										<input class="mt-0.5 w-4 h-4 rounded text-primary focus:ring-secondary accent-primary transition-all js-col-checkbox" data-badge-type="optional" data-col-id="<?php echo esc_attr( $field_info['id'] ); ?>" data-col-label="<?php echo esc_attr( $field_info['label_code'] ); ?>" name="woo_kustom_export[optional_columns][<?php echo esc_attr( $field_key ); ?>]" type="checkbox" value="1" <?php checked( $is_checked ); ?>>
										<div class="flex flex-col">
											<span class="font-title-md text-title-md text-on-surface group-hover:text-primary transition-colors"><?php echo esc_html( $field_info['title'] ); ?></span>
											<span class="font-code-sm text-code-sm text-on-surface-variant"><?php echo esc_html( $field_info['sub'] ); ?></span>
										</div>
									</label>
								<?php endforeach; ?>
							</div>

							<?php if ( ! empty( $more_columns ) ) : ?>
								<div class="border-t border-surface-container pt-space-md">
									<button type="button" id="toggleMoreFieldsBtn" class="inline-flex items-center gap-space-xs font-label-sm text-label-sm text-secondary hover:text-primary transition-colors cursor-pointer border-0 bg-transparent p-0">
										<span class="material-symbols-outlined text-[18px]" id="toggleMoreFieldsIcon">expand_more</span>
										<span id="toggleMoreFieldsText"><?php esc_html_e( 'Visa fler WooCommerce-fält (mått, frakt, produkt-ID m.m.)', 'woo-kustom-export' ); ?></span>
									</button>
									<div id="moreFieldsContainer" class="hidden grid grid-cols-1 md:grid-cols-2 gap-space-sm mt-space-md">
										<?php foreach ( $more_columns as $more_key => $more_data ) : ?>
											<label class="group flex items-start gap-space-md p-space-md rounded-lg bg-surface-container-low/70 hover:bg-surface-container cursor-pointer transition-colors border border-transparent hover:border-surface-container">
												<input class="mt-0.5 w-4 h-4 rounded text-primary focus:ring-secondary accent-primary transition-all js-col-checkbox" data-badge-type="optional" data-col-id="col-<?php echo esc_attr( $more_key ); ?>" data-col-label="<?php echo esc_attr( $more_key ); ?>" name="woo_kustom_export[optional_columns][<?php echo esc_attr( $more_key ); ?>]" type="checkbox" value="1" <?php checked( ! empty( $settings['optional_columns'][ $more_key ] ) ); ?>>
												<div class="flex flex-col">
													<span class="font-title-md text-title-md text-on-surface group-hover:text-primary transition-colors"><?php echo esc_html( $more_data['label'] ); ?></span>
													<span class="font-code-sm text-code-sm text-on-surface-variant">Fält: <?php echo esc_html( $more_key ); ?></span>
												</div>
											</label>
										<?php endforeach; ?>
									</div>
								</div>
							<?php endif; ?>
						</div>

						<!-- Sektion: Produktattribut från butiken -->
						<div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-md">
							<div class="flex flex-col gap-0.5 pb-space-sm border-b border-surface-container">
								<div class="flex items-center justify-between">
									<h3 class="font-headline-sm text-headline-sm text-on-surface font-semibold tracking-tight"><?php esc_html_e( 'Produktattribut från butiken', 'woo-kustom-export' ); ?></h3>
									<span class="font-code-sm text-code-sm text-secondary bg-surface-container-low px-space-sm py-0.5 rounded" id="attributesBadge">
										<span id="activeAttrCount"><?php echo (int) $active_attr_count; ?></span> av <span id="totalAttrCount"><?php echo (int) $total_attr_count; ?></span> aktiva
									</span>
								</div>
								<p class="font-body-sm text-body-sm text-on-surface-variant">
									<?php esc_html_e( 'Välj vilka anpassade WooCommerce-attribut som ska exporteras som egna kolumner i filen:', 'woo-kustom-export' ); ?>
								</p>
							</div>

							<?php if ( empty( $attributes ) ) : ?>
								<p class="font-body-sm text-body-sm text-on-surface-variant italic py-2"><?php esc_html_e( 'Inga produktattribut hittades på butikens produkter.', 'woo-kustom-export' ); ?></p>
							<?php else : ?>
								<div class="flex flex-wrap gap-space-sm pt-space-xs" id="attributesContainer">
									<?php
									foreach ( $attributes as $attribute ) :
										$clean_attr = strtolower( trim( (string) $attribute ) );
										$attr_key   = 'attribute_' . sanitize_title( $clean_attr );
										$is_checked = in_array( $clean_attr, $selected_attributes, true );
										?>
										<label class="inline-flex items-center gap-space-sm px-space-md py-2 rounded-lg bg-surface-container-low hover:bg-surface-container cursor-pointer transition-all border-2 border-transparent has-[:checked]:border-secondary/30 has-[:checked]:bg-secondary-fixed/30">
											<input class="w-4 h-4 rounded text-primary focus:ring-secondary accent-primary js-attr-checkbox" data-attr-key="<?php echo esc_attr( $attr_key ); ?>" data-attr-label="<?php echo esc_attr( $clean_attr ); ?>" name="woo_kustom_export[custom_attributes][]" type="checkbox" value="<?php echo esc_attr( $clean_attr ); ?>" <?php checked( $is_checked ); ?>>
											<div class="flex items-center gap-space-xs">
												<span class="font-title-md text-title-md text-on-surface"><?php echo esc_html( $clean_attr ); ?></span>
												<span class="font-code-sm text-code-sm text-on-surface-variant">(pa_<?php echo esc_html( sanitize_title( $clean_attr ) ); ?>)</span>
											</div>
										</label>
									<?php endforeach; ?>
								</div>
							<?php endif; ?>

							<?php if ( ! empty( $multi_value ) ) : ?>
								<div class="mt-space-sm flex items-start gap-space-sm p-space-sm rounded-lg bg-surface-container-low text-on-surface-variant text-body-sm border border-surface-container">
									<span class="material-symbols-outlined text-[18px] text-outline shrink-0 mt-0.5">info</span>
									<div class="flex flex-col">
										<span class="font-semibold text-on-surface"><?php esc_html_e( 'Flerdimensionella attribut', 'woo-kustom-export' ); ?></span>
										<span class="font-body-sm text-body-sm text-on-surface-variant"><?php esc_html_e( 'Vissa enkla produkter har flera värden på valda attribut. Endast det första värdet exporteras per produkt eftersom CSV-formatet har en cell per rad.', 'woo-kustom-export' ); ?></span>
									</div>
								</div>
							<?php endif; ?>
						</div>

						<!-- Åtgärdsfält: Återställ & Spara -->
						<div class="flex items-center justify-between p-space-md rounded-xl bg-surface-container-lowest shadow-sm">
							<button class="inline-flex items-center gap-space-xs px-space-md py-2 rounded-lg bg-surface-container-low hover:bg-surface-container text-on-surface-variant hover:text-on-surface font-label-md text-label-md transition-colors cursor-pointer border-0" id="resetDefaultsBtn" type="button">
								<span class="material-symbols-outlined text-[18px]">history</span>
								<span><?php esc_html_e( 'Återställ standard', 'woo-kustom-export' ); ?></span>
							</button>
							<button class="inline-flex items-center gap-space-xs px-space-lg py-2.5 rounded-lg bg-primary hover:bg-primary-container text-on-primary font-label-md text-label-md font-semibold shadow transition-all active:scale-[0.99] cursor-pointer border-0" id="saveSettingsBtn" type="submit">
								<span class="material-symbols-outlined text-[18px]">check_circle</span>
								<span><?php esc_html_e( 'Spara Kustom-inställningar', 'woo-kustom-export' ); ?></span>
							</button>
						</div>
					</form>
				</div>

				<!-- Höger kolumn (Sticky förhandsgranskning av kolumner & CSV-snippet) -->
				<div class="lg:col-span-5 xl:col-span-5 flex flex-col gap-space-md sticky top-12">
					<div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-md">
						<div class="flex flex-col gap-1 pb-space-sm border-b border-surface-container">
							<div class="flex items-center justify-between">
								<h3 class="font-headline-sm text-headline-sm text-on-surface font-bold tracking-tight" id="columnsHeaderTitle">
									<?php
									/* translators: %s: placeholder count */
									printf( esc_html__( 'Kolumner i Kustom POS CSV (%s kolumner)', 'woo-kustom-export' ), '<span id="columnCountBadge">8</span>' );
									?>
								</h3>
								<span class="material-symbols-outlined text-secondary text-[20px]">view_column</span>
							</div>
							<p class="font-body-sm text-body-sm text-on-surface-variant"><?php esc_html_e( 'Aktuell ordning och datastruktur i exportfilen:', 'woo-kustom-export' ); ?></p>
						</div>

						<div class="flex flex-col gap-1.5" id="columnsPreviewList">
							<div class="flex items-center justify-between p-2 rounded-md bg-surface-container-low/90">
								<div class="flex items-center gap-space-sm">
									<span class="font-code-sm text-code-sm text-outline font-semibold w-4">1</span>
									<span class="font-code-sm text-code-sm text-on-surface font-semibold">product_name</span>
								</div>
								<span class="px-2 py-0.5 rounded-full bg-secondary-fixed text-primary font-label-sm text-label-sm font-bold tracking-wider">KRAV</span>
							</div>
							<div class="flex items-center justify-between p-2 rounded-md bg-surface-container-low/90">
								<div class="flex items-center gap-space-sm">
									<span class="font-code-sm text-code-sm text-outline font-semibold w-4">2</span>
									<span class="font-code-sm text-code-sm text-on-surface font-semibold">price_including_vat</span>
								</div>
								<span class="px-2 py-0.5 rounded-full bg-secondary-fixed text-primary font-label-sm text-label-sm font-bold tracking-wider">KRAV</span>
							</div>
							<div class="flex items-center justify-between p-2 rounded-md bg-surface-container-low/90">
								<div class="flex items-center gap-space-sm">
									<span class="font-code-sm text-code-sm text-outline font-semibold w-4">3</span>
									<span class="font-code-sm text-code-sm text-on-surface font-semibold">vat_rate</span>
								</div>
								<span class="px-2 py-0.5 rounded-full bg-secondary-fixed text-primary font-label-sm text-label-sm font-bold tracking-wider">KRAV</span>
							</div>
							<div class="flex items-center justify-between p-2 rounded-md bg-surface-container-low/90">
								<div class="flex items-center gap-space-sm">
									<span class="font-code-sm text-code-sm text-outline font-semibold w-4">4</span>
									<span class="font-code-sm text-code-sm text-on-surface font-semibold">currency</span>
								</div>
								<span class="px-2 py-0.5 rounded-full bg-secondary-fixed text-primary font-label-sm text-label-sm font-bold tracking-wider">KRAV</span>
							</div>
						</div>

						<div class="flex flex-col gap-space-xs mt-space-xs p-space-md rounded-lg bg-inverse-surface text-inverse-on-surface">
							<div class="flex items-center justify-between">
								<span class="font-label-sm text-label-sm text-surface-dim uppercase tracking-wider font-semibold"><?php esc_html_e( 'CSV Snippet Förhandsgranskning', 'woo-kustom-export' ); ?></span>
								<span class="font-code-sm text-code-sm text-tertiary-fixed font-semibold">UTF-8 med BOM</span>
							</div>
							<pre class="font-code-sm text-code-sm text-surface-container overflow-x-auto whitespace-pre p-space-xs rounded bg-inverse-surface/80"><code id="csvPreviewCode">"product_name","price_including_vat","vat_rate","currency","sku","gtin","attribute_storlek","attribute_farg"
"Linnéskjorta Klassisk",799.00,25.00,"SEK","LSH-001-L","735001234567","Large","Marinblå"
"Linnéskjorta Klassisk",799.00,25.00,"SEK","LSH-001-M","735001234568","Medium","Marinblå"</code></pre>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Rendera undersidan för Sello-export (anpassad för Tradera butiksimport).
	 */
	protected function render_sello_tab() {
		$generator  = self::create_generator( 'sello' );
		$stats      = $generator->get_stats();
		$total_rows = (int) $stats['products'] + (int) $stats['variations'];

		$xlsx_url       = $this->get_download_url( 'sello-xlsx' );
		$csv_url        = $this->get_download_url( 'sello' );
		$settings       = self::get_settings();
		$active_columns = $generator->collect_columns();
		$preview_rows   = $generator->get_preview_rows( 5 );
		$validation     = $generator->get_validation_issues( 20 );

		$has_missing_sku   = ! empty( $validation['missing_sku'] );
		$has_missing_price = ! empty( $validation['missing_price'] );
		$has_issues        = $has_missing_sku || $has_missing_price;
		$today_date        = gmdate( 'Y-m-d' );
		$all_cols_meta     = WOO_Sello_CSV_Generator::get_supported_columns_meta();
		$saved_sello_cols  = isset( $settings['sello_columns'] ) && is_array( $settings['sello_columns'] ) ? $settings['sello_columns'] : array();
		$has_saved_cols    = ! empty( $saved_sello_cols );
		?>
		<div class="flex flex-col w-full">
			<!-- Top Bar Backlink -->
			<div class="mb-space-md">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=woo-product-export' ) ); ?>" class="inline-flex items-center gap-1 font-label-sm text-label-sm text-on-surface-variant hover:text-primary transition-colors no-underline">
					<span class="material-symbols-outlined text-[16px]">arrow_back</span>
					<?php esc_html_e( 'Tillbaka till översikten', 'woo-kustom-export' ); ?>
				</a>
			</div>

			<!-- Hero Banner: Sello Product Export för Tradera -->
			<div class="relative overflow-hidden rounded-xl bg-surface-container-lowest shadow-sm mb-space-xl border border-surface-container-high/60">
				<div class="absolute -right-24 -top-24 w-96 h-96 rounded-full bg-gradient-to-br from-tertiary-fixed/20 via-surface-variant/30 to-transparent pointer-events-none"></div>
				<div class="absolute right-12 bottom-0 w-64 h-64 rounded-full bg-gradient-to-t from-primary-fixed/25 to-transparent pointer-events-none"></div>
				<div class="relative p-space-xl flex flex-col xl:flex-row xl:items-center justify-between gap-space-xl">
					<div class="max-w-3xl flex flex-col gap-space-sm">
						<h1 class="font-headline-xl text-headline-xl text-on-surface tracking-tight m-0 font-bold">
							<?php esc_html_e( 'Sello & Tradera Produkt-export', 'woo-kustom-export' ); ?>
						</h1>
						<p class="font-body-md text-body-md text-on-surface-variant leading-relaxed max-w-2xl m-0">
							<?php esc_html_e( 'Exportera artiklar till Sello för import till Tradera som butiksobjekt med fast pris. Varje enkel produkt och varje variation blir en egen rad med heltal i Tradera price store, unika SKU:er och automatisk gruppering.', 'woo-kustom-export' ); ?>
						</p>
						<!-- KPI Summary Stats -->
						<div class="grid grid-cols-3 gap-space-md pt-space-md max-w-lg">
							<div class="bg-surface-container-low p-space-md rounded flex flex-col">
								<span class="font-label-sm text-label-sm text-on-surface-variant uppercase tracking-wider"><?php esc_html_e( 'Enkla produkter', 'woo-kustom-export' ); ?></span>
								<div class="flex items-baseline gap-1 mt-0.5">
									<span class="font-headline-lg text-headline-lg font-bold text-on-surface" id="selloSimpleCount"><?php echo esc_html( number_format_i18n( (int) $stats['products'] ) ); ?></span>
									<span class="font-label-sm text-label-sm text-tertiary font-semibold"><?php esc_html_e( 'st', 'woo-kustom-export' ); ?></span>
								</div>
							</div>
							<div class="bg-surface-container-low p-space-md rounded flex flex-col">
								<span class="font-label-sm text-label-sm text-on-surface-variant uppercase tracking-wider"><?php esc_html_e( 'Varianter', 'woo-kustom-export' ); ?></span>
								<div class="flex items-baseline gap-1 mt-0.5">
									<span class="font-headline-lg text-headline-lg font-bold text-on-surface" id="selloVariantCount"><?php echo esc_html( number_format_i18n( (int) $stats['variations'] ) ); ?></span>
									<span class="font-label-sm text-label-sm text-secondary font-semibold"><?php esc_html_e( 'st', 'woo-kustom-export' ); ?></span>
								</div>
							</div>
							<div class="bg-surface-container p-space-md rounded flex flex-col">
								<span class="font-label-sm text-label-sm text-on-surface-variant uppercase tracking-wider"><?php esc_html_e( 'Totalt rader', 'woo-kustom-export' ); ?></span>
								<div class="flex items-baseline gap-1 mt-0.5">
									<span class="font-headline-lg text-headline-lg font-bold text-primary" id="selloTotalCount"><?php echo esc_html( number_format_i18n( $total_rows ) ); ?></span>
									<span class="font-label-sm text-label-sm text-primary font-semibold"><?php esc_html_e( 'aktiva', 'woo-kustom-export' ); ?></span>
								</div>
							</div>
						</div>
					</div>
					<!-- Action Panel & Download triggers -->
					<div class="flex flex-col sm:flex-row xl:flex-col gap-space-sm min-w-[280px]">
						<a href="<?php echo esc_url( $xlsx_url ); ?>" class="relative group inline-flex items-center justify-center gap-space-sm bg-tertiary-container text-on-tertiary hover:bg-tertiary px-space-lg py-3 rounded-lg shadow-sm transition-all duration-200 cursor-pointer no-underline text-white" id="downloadXlsxBtn" data-count="<?php echo esc_attr( number_format_i18n( $total_rows ) ); ?>" data-filename="sello-tradera-products-<?php echo esc_attr( $today_date ); ?>.xlsx">
							<span class="material-symbols-outlined text-[22px]">table_view</span>
							<div class="flex flex-col text-left">
								<span class="font-title-md text-title-md font-semibold leading-tight text-white"><?php esc_html_e( 'Ladda ner Sello Excel', 'woo-kustom-export' ); ?></span>
								<span class="font-label-sm text-label-sm text-on-tertiary-container opacity-90">.xlsx • sello-tradera-products-<?php echo esc_html( $today_date ); ?>.xlsx</span>
							</div>
							<span class="material-symbols-outlined text-[18px] ml-auto opacity-70 group-hover:translate-y-0.5 transition-transform text-white">download</span>
						</a>
						<a href="<?php echo esc_url( $csv_url ); ?>" class="inline-flex items-center justify-center gap-space-sm bg-surface-container hover:bg-surface-container-high text-on-surface px-space-lg py-2.5 rounded-lg transition-colors cursor-pointer no-underline text-slate-800" id="downloadCsvBtn" data-filename="sello-tradera-products-<?php echo esc_attr( $today_date ); ?>.csv">
							<span class="material-symbols-outlined text-[20px] text-on-surface-variant">description</span>
							<span class="font-label-md text-label-md font-semibold"><?php esc_html_e( 'Ladda ner Sello CSV', 'woo-kustom-export' ); ?></span>
							<span class="font-code-sm text-code-sm text-on-surface-variant ml-auto">UTF-8 • .csv</span>
						</a>
						<div class="flex items-center text-on-surface-variant px-space-xs pt-1">
							<span class="font-label-sm text-label-sm flex items-center gap-1">
								<span class="material-symbols-outlined text-[14px] text-tertiary">verified</span>
								<?php esc_html_e( '12 Tradera-kolumner utan Price/Calculate', 'woo-kustom-export' ); ?>
							</span>
						</div>
					</div>
				</div>
			</div>

			<?php if ( $has_issues ) : ?>
			<!-- Valideringsvarning: Saknar SKU eller Tradera-pris -->
			<div class="bg-amber-50 border border-amber-300 rounded-xl p-space-lg mb-space-xl text-amber-900 shadow-sm">
				<div class="flex items-start gap-3">
					<span class="material-symbols-outlined text-amber-600 text-[26px] shrink-0 mt-0.5">warning</span>
					<div class="flex-1">
						<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
							<h3 class="font-headline-sm text-headline-sm font-bold text-amber-950 m-0">
								<?php esc_html_e( 'Upptäckta fel/avvikelser inför Tradera-import', 'woo-kustom-export' ); ?>
							</h3>
							<span class="font-label-sm text-label-sm bg-amber-200 text-amber-950 font-semibold px-2 py-0.5 rounded">
								<?php printf( esc_html__( '%d kontrollerade artiklar', 'woo-kustom-export' ), (int) $validation['total_checked'] ); ?>
							</span>
						</div>
						<p class="font-body-md text-body-md text-amber-900 mt-1 mb-space-md">
							<?php esc_html_e( 'Tradera och Sello kräver att varje enkel produkt och varje variation har ett unikt SKU och ett aktivt försäljningspris. Följande artiklar saknar dessa värden i WooCommerce och bör korrigeras:', 'woo-kustom-export' ); ?>
						</p>

						<div class="grid grid-cols-1 md:grid-cols-2 gap-space-md mt-space-md">
							<?php if ( $has_missing_sku ) : ?>
							<div class="bg-white/80 border border-amber-200 rounded-lg p-space-md">
								<div class="flex items-center justify-between mb-2">
									<span class="font-title-sm text-title-sm font-semibold text-amber-950 flex items-center gap-1.5">
										<span class="material-symbols-outlined text-[18px] text-amber-700">fingerprint</span>
										<?php printf( esc_html__( 'Saknar SKU (%d st)', 'woo-kustom-export' ), count( $validation['missing_sku'] ) ); ?>
									</span>
									<span class="text-xs text-amber-700 font-mono"><?php esc_html_e( 'Kräver unikt SKU', 'woo-kustom-export' ); ?></span>
								</div>
								<ul class="text-xs divide-y divide-amber-100 list-none m-0 p-0 max-h-48 overflow-y-auto">
									<?php foreach ( $validation['missing_sku'] as $item ) : ?>
									<li class="py-1.5 flex items-center justify-between gap-2">
										<div class="truncate">
											<span class="font-medium text-slate-800"><?php echo esc_html( $item['name'] ); ?></span>
											<span class="text-slate-500 text-[11px]">(#<?php echo esc_html( $item['id'] ); ?> • <?php echo esc_html( $item['type'] ); ?>)</span>
										</div>
										<?php if ( ! empty( $item['edit_url'] ) ) : ?>
										<a href="<?php echo esc_url( $item['edit_url'] ); ?>" class="text-blue-700 hover:underline shrink-0 text-[11px] font-semibold" target="_blank">
											<?php esc_html_e( 'Redigera', 'woo-kustom-export' ); ?> &rarr;
										</a>
										<?php endif; ?>
									</li>
									<?php endforeach; ?>
								</ul>
							</div>
							<?php endif; ?>

							<?php if ( $has_missing_price ) : ?>
							<div class="bg-white/80 border border-amber-200 rounded-lg p-space-md">
								<div class="flex items-center justify-between mb-2">
									<span class="font-title-sm text-title-sm font-semibold text-amber-950 flex items-center gap-1.5">
										<span class="material-symbols-outlined text-[18px] text-amber-700">payments</span>
										<?php printf( esc_html__( 'Saknar pris (%d st)', 'woo-kustom-export' ), count( $validation['missing_price'] ) ); ?>
									</span>
									<span class="text-xs text-amber-700 font-mono"><?php esc_html_e( 'Tradera price store tomt', 'woo-kustom-export' ); ?></span>
								</div>
								<ul class="text-xs divide-y divide-amber-100 list-none m-0 p-0 max-h-48 overflow-y-auto">
									<?php foreach ( $validation['missing_price'] as $item ) : ?>
									<li class="py-1.5 flex items-center justify-between gap-2">
										<div class="truncate">
											<span class="font-medium text-slate-800"><?php echo esc_html( $item['name'] ); ?></span>
											<span class="text-slate-500 text-[11px] font-mono">[SKU: <?php echo esc_html( $item['sku'] ); ?>]</span>
										</div>
										<?php if ( ! empty( $item['edit_url'] ) ) : ?>
										<a href="<?php echo esc_url( $item['edit_url'] ); ?>" class="text-blue-700 hover:underline shrink-0 text-[11px] font-semibold" target="_blank">
											<?php esc_html_e( 'Sätt pris', 'woo-kustom-export' ); ?> &rarr;
										</a>
										<?php endif; ?>
									</li>
									<?php endforeach; ?>
								</ul>
							</div>
							<?php endif; ?>
						</div>
					</div>
				</div>
			</div>
			<?php else : ?>
			<!-- Validering Godkänd Badge -->
			<div class="bg-emerald-50 border border-emerald-200 rounded-xl p-space-md mb-space-xl flex items-center justify-between text-emerald-900 shadow-sm">
				<div class="flex items-center gap-2.5">
					<span class="material-symbols-outlined text-emerald-600 text-[22px]">check_circle</span>
					<span class="font-body-md text-body-md font-semibold text-emerald-950">
						<?php esc_html_e( 'Validering godkänd: Samtliga kontrollerade produkter och varianter har giltiga SKU:er och aktiva försäljningspriser för Tradera.', 'woo-kustom-export' ); ?>
					</span>
				</div>
				<span class="font-code-sm text-code-sm bg-emerald-100 text-emerald-800 px-2 py-0.5 rounded font-mono">
					<?php printf( esc_html__( '%d kontrollerade', 'woo-kustom-export' ), (int) $validation['total_checked'] ); ?>
				</span>
			</div>
			<?php endif; ?>

			<!-- Kolumnväljare för Sello / Tradera -->
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bg-surface-container-lowest rounded-xl shadow-sm p-space-xl mb-space-xl border border-surface-container-high/60" id="sello-columns-form">
				<?php wp_nonce_field( 'woo_kustom_export_save_settings' ); ?>
				<input type="hidden" name="action" value="woo_kustom_export_save_settings">
				<input type="hidden" name="return_tab" value="sello">

				<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-space-md pb-space-md mb-space-md border-b border-surface-container">
					<div>
						<div class="flex items-center gap-space-sm">
							<span class="inline-flex items-center justify-center w-7 h-7 rounded bg-primary text-on-primary text-white">
								<span class="material-symbols-outlined text-[16px]">tune</span>
							</span>
							<h2 class="font-headline-lg text-headline-lg text-on-surface m-0 tracking-tight font-bold">
								<?php esc_html_e( 'Välj kolumner för Sello-exporten', 'woo-kustom-export' ); ?>
							</h2>
						</div>
						<p class="font-body-md text-body-md text-on-surface-variant mt-1 mb-0">
							<?php esc_html_e( 'Som standard är alla 12 kolumner ibockade. Avmarkera de fält du inte vill inkludera i din Excel- (.xlsx) eller CSV-fil.', 'woo-kustom-export' ); ?>
						</p>
					</div>

					<!-- Snabbknappar för val och spara -->
					<div class="flex items-center flex-wrap gap-2">
						<button type="button" class="px-2.5 py-1.5 text-xs font-semibold rounded bg-slate-100 hover:bg-slate-200 text-slate-700 transition-colors cursor-pointer border border-slate-200" id="selloSelectAllBtn">
							<?php esc_html_e( 'Markera alla', 'woo-kustom-export' ); ?>
						</button>
						<button type="button" class="px-2.5 py-1.5 text-xs font-semibold rounded bg-slate-100 hover:bg-slate-200 text-slate-700 transition-colors cursor-pointer border border-slate-200" id="selloDeselectAllBtn">
							<?php esc_html_e( 'Avmarkera alla', 'woo-kustom-export' ); ?>
						</button>
						<button type="submit" class="px-3.5 py-1.5 text-xs font-semibold rounded bg-primary text-white hover:opacity-90 transition-opacity cursor-pointer border-0 shadow-sm inline-flex items-center gap-1.5" id="selloSaveBtn">
							<span class="material-symbols-outlined text-[15px]">save</span>
							<span><?php esc_html_e( 'Spara kolumnval', 'woo-kustom-export' ); ?></span>
						</button>
					</div>
				</div>

				<!-- Grid med de 12 valbara kolumnerna -->
				<div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-space-md">
					<?php
					foreach ( $all_cols_meta as $col_key => $col_meta ) :
						// Som standard är alla ibockade om inga inställningar har sparats tidigare
						$is_checked = ! $has_saved_cols || ! empty( $saved_sello_cols[ $col_key ] );
					?>
					<label class="flex items-start gap-2.5 p-3 rounded-lg border border-slate-200 bg-surface-container-low hover:bg-surface-container transition-colors cursor-pointer select-none">
						<input type="checkbox" name="woo_kustom_export[sello_columns][<?php echo esc_attr( $col_key ); ?>]" value="1" class="sello-col-checkbox mt-0.5 w-4 h-4 rounded text-primary accent-primary cursor-pointer" <?php checked( $is_checked ); ?>>
						<div class="flex flex-col">
							<div class="flex items-center gap-1">
								<span class="font-semibold text-xs text-slate-900"><?php echo esc_html( $col_meta['label'] ); ?></span>
								<?php if ( ! empty( $col_meta['required'] ) ) : ?>
								<span class="text-[10px] text-primary font-semibold uppercase bg-primary-fixed px-1 rounded"><?php esc_html_e( 'Bas', 'woo-kustom-export' ); ?></span>
								<?php endif; ?>
							</div>
							<span class="font-mono text-[11px] text-slate-500 font-semibold"><?php echo esc_html( $col_meta['code'] ); ?></span>
							<span class="text-[11px] text-slate-500 mt-0.5 leading-snug"><?php echo esc_html( $col_meta['desc'] ); ?></span>
						</div>
					</label>
					<?php endforeach; ?>
				</div>
			</form>

			<!-- Förhandsgranskning av de första raderna efter export -->
			<div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-xl flex flex-col mb-space-xl border border-surface-container-high/60">
				<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-space-md pb-space-md mb-space-md border-b border-surface-container">
					<div>
						<div class="flex items-center gap-space-sm">
							<span class="inline-flex items-center justify-center w-7 h-7 rounded bg-tertiary-container text-on-tertiary text-white">
								<span class="material-symbols-outlined text-[16px]">visibility</span>
							</span>
							<h2 class="font-headline-lg text-headline-lg text-on-surface m-0 tracking-tight font-bold">
								<?php esc_html_e( 'Förhandsgranskning av exporten (första raderna)', 'woo-kustom-export' ); ?>
							</h2>
						</div>
						<p class="font-body-md text-body-md text-on-surface-variant mt-1 mb-0">
							<?php printf( esc_html__( 'Visar exakt hur de %d valda kolumnerna genereras för dina enkla och variabla produkter i den nedladdade filen.', 'woo-kustom-export' ), count( $active_columns ) ); ?>
						</p>
					</div>
					<div class="flex items-center gap-2">
						<span class="font-label-sm text-label-sm bg-surface-container text-on-surface px-2.5 py-1 rounded font-mono font-semibold">
							<?php echo count( $preview_rows ); ?> <?php esc_html_e( 'exempelrader', 'woo-kustom-export' ); ?> • <?php echo count( $active_columns ); ?> <?php esc_html_e( 'kolumner', 'woo-kustom-export' ); ?>
						</span>
					</div>
				</div>

				<?php if ( ! empty( $preview_rows ) ) : ?>
				<div class="overflow-x-auto -mx-space-xl px-space-xl">
					<table class="w-full text-left border-collapse border-0 text-xs font-sans">
						<thead>
							<tr class="bg-surface-container-low text-on-surface-variant border-b border-surface-container-high">
								<?php foreach ( $active_columns as $col_name ) : ?>
								<th class="py-2.5 px-3 font-semibold uppercase tracking-wider whitespace-nowrap <?php echo in_array( $col_name, array( 'Tradera price store', 'Purchase price', 'Weight' ), true ) ? 'text-right' : ( in_array( $col_name, array( 'Quantity', 'Tax' ), true ) ? 'text-center' : '' ); ?>">
									<?php echo esc_html( $col_name ); ?>
								</th>
								<?php endforeach; ?>
							</tr>
						</thead>
						<tbody class="divide-y divide-slate-100">
							<?php foreach ( $preview_rows as $idx => $r ) : ?>
							<tr class="<?php echo 0 === $idx % 2 ? 'bg-white' : 'bg-slate-50/50'; ?> hover:bg-blue-50/40 transition-colors">
								<?php foreach ( $active_columns as $col_name ) :
									$val = isset( $r[ $col_name ] ) ? (string) $r[ $col_name ] : '';
								?>
									<?php if ( 'Sku' === $col_name ) : ?>
									<td class="py-2.5 px-3 font-mono font-semibold text-slate-900 whitespace-nowrap">
										<span class="bg-slate-100 px-1.5 py-0.5 rounded border border-slate-200"><?php echo esc_html( $val ); ?></span>
									</td>
									<?php elseif ( 'Standard name sv' === $col_name ) : ?>
									<td class="py-2.5 px-3 font-medium text-slate-900 whitespace-nowrap max-w-xs truncate" title="<?php echo esc_attr( $val ); ?>">
										<?php echo esc_html( $val ); ?>
									</td>
									<?php elseif ( 'Standard description sv' === $col_name ) : ?>
									<td class="py-2.5 px-3 text-slate-600 max-w-xs truncate" title="<?php echo esc_attr( $val ); ?>">
										<?php echo esc_html( mb_substr( $val, 0, 50 ) . ( mb_strlen( $val ) > 50 ? '...' : '' ) ); ?>
									</td>
									<?php elseif ( 'Tradera price store' === $col_name ) : ?>
									<td class="py-2.5 px-3 font-mono font-bold text-right whitespace-nowrap <?php echo '' !== $val ? 'text-emerald-700' : 'text-slate-400'; ?>">
										<?php if ( '' !== $val ) : ?>
											<span class="bg-emerald-50 text-emerald-800 border border-emerald-200 px-2 py-0.5 rounded"><?php echo esc_html( $val ); ?> kr</span>
										<?php else : ?>
											<span class="italic text-slate-400">—</span>
										<?php endif; ?>
									</td>
									<?php elseif ( 'Purchase price' === $col_name ) : ?>
									<td class="py-2.5 px-3 font-mono text-right text-slate-600 whitespace-nowrap">
										<?php echo '' !== $val ? esc_html( $val ) : '—'; ?>
									</td>
									<?php elseif ( 'Quantity' === $col_name ) : ?>
									<td class="py-2.5 px-3 font-mono text-center text-slate-800 whitespace-nowrap">
										<?php echo '' !== $val ? esc_html( $val ) : '—'; ?>
									</td>
									<?php elseif ( 'Tax' === $col_name ) : ?>
									<td class="py-2.5 px-3 font-mono text-center text-slate-800 whitespace-nowrap">
										<?php echo '' !== $val ? esc_html( $val ) . '%' : '—'; ?>
									</td>
									<?php elseif ( 'Weight' === $col_name ) : ?>
									<td class="py-2.5 px-3 font-mono text-right text-slate-700 whitespace-nowrap">
										<?php echo '' !== $val ? esc_html( $val ) . ' g' : '—'; ?>
									</td>
									<?php elseif ( 'Images' === $col_name ) : ?>
									<td class="py-2.5 px-3 text-slate-500 font-mono text-[11px] max-w-xs truncate" title="<?php echo esc_attr( $val ); ?>">
										<?php echo '' !== $val ? esc_html( mb_substr( $val, 0, 35 ) . '...' ) : '—'; ?>
									</td>
									<?php elseif ( 'Group sku' === $col_name ) : ?>
									<td class="py-2.5 px-3 font-mono text-slate-700 whitespace-nowrap">
										<?php if ( '' !== $val ) : ?>
											<span class="bg-purple-50 text-purple-700 border border-purple-200 px-1.5 py-0.5 rounded font-semibold"><?php echo esc_html( $val ); ?></span>
										<?php else : ?>
											<span class="text-slate-400">—</span>
										<?php endif; ?>
									</td>
									<?php elseif ( 'Group type' === $col_name ) : ?>
									<td class="py-2.5 px-3 text-slate-700 whitespace-nowrap">
										<?php if ( '' !== $val ) : ?>
											<span class="bg-blue-50 text-blue-700 border border-blue-200 px-1.5 py-0.5 rounded font-medium"><?php echo esc_html( $val ); ?></span>
										<?php else : ?>
											<span class="text-slate-400">—</span>
										<?php endif; ?>
									</td>
									<?php else : ?>
									<td class="py-2.5 px-3 text-slate-700 whitespace-nowrap">
										<?php echo esc_html( $val ); ?>
									</td>
									<?php endif; ?>
								<?php endforeach; ?>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php else : ?>
				<div class="p-space-lg text-center text-on-surface-variant bg-surface-container-low rounded-lg">
					<p class="font-body-md text-body-md m-0"><?php esc_html_e( 'Inga produkter matchar exportfiltret för tillfället. Skapa produkter i WooCommerce för att förhandsgranska.', 'woo-kustom-export' ); ?></p>
				</div>
				<?php endif; ?>
			</div>

			<!-- 2-Column Grid: Workflow & Critical Knowledge -->
			<div class="grid grid-cols-1 lg:grid-cols-12 gap-gutter-lg mb-space-xl">
				<!-- Left Column: Steg-för-steg importguide i Sello (7 columns) -->
				<div class="lg:col-span-7 flex flex-col">
					<div class="bg-surface-container-lowest p-space-xl rounded-xl shadow-sm flex flex-col h-full border border-surface-container-high/60">
						<div class="flex items-center justify-between pb-space-md mb-space-md bg-surface-container-lowest border-b border-surface-container">
							<div class="flex items-center gap-space-sm">
								<div class="w-8 h-8 rounded bg-primary-fixed text-on-primary-fixed flex items-center justify-center">
									<span class="material-symbols-outlined text-[18px]">alt_route</span>
								</div>
								<div>
									<h2 class="font-headline-sm text-headline-sm text-on-surface m-0 font-semibold"><?php esc_html_e( 'Steg-för-steg importguide i Sello för Tradera', 'woo-kustom-export' ); ?></h2>
									<p class="font-body-sm text-body-sm text-on-surface-variant m-0"><?php esc_html_e( 'Importera enkelt butiksobjekt med fast pris till Tradera via Sello', 'woo-kustom-export' ); ?></p>
								</div>
							</div>
							<span class="font-label-sm text-label-sm bg-surface-container text-on-surface-variant px-space-sm py-0.5 rounded font-medium"><?php esc_html_e( '5 steg', 'woo-kustom-export' ); ?></span>
						</div>
						<!-- Sequential Stepper Cards -->
						<div class="flex flex-col gap-space-md">
							<!-- Step 1 -->
							<div class="flex items-start gap-space-md p-space-md rounded-lg bg-surface-container-low hover:bg-surface-container transition-colors group">
								<div class="w-7 h-7 rounded-full bg-primary text-on-primary font-label-md text-label-md font-bold flex items-center justify-center shrink-0 text-white">
									1
								</div>
								<div class="flex-1">
									<div class="flex items-center justify-between">
										<span class="font-title-md text-title-md text-on-surface font-semibold"><?php esc_html_e( 'Ladda ner Sello Excel (.xlsx)', 'woo-kustom-export' ); ?></span>
										<span class="material-symbols-outlined text-[16px] text-tertiary">file_download</span>
									</div>
									<p class="font-body-md text-body-md text-on-surface-variant mt-1 mb-0 leading-normal">
										<?php printf( esc_html__( 'Klicka på knappen ovan för att ladda ner filen (%s). Den innehåller samtliga enkla produkter och varianter strukturerade rad för rad.', 'woo-kustom-export' ), '<span class="font-code-sm text-code-sm bg-surface-container-highest px-1 py-0.5 rounded text-on-surface">sello-tradera-products-' . esc_html( $today_date ) . '.xlsx</span>' ); ?>
									</p>
								</div>
							</div>
							<!-- Step 2 -->
							<div class="flex items-start gap-space-md p-space-md rounded-lg bg-surface-container-low hover:bg-surface-container transition-colors group">
								<div class="w-7 h-7 rounded-full bg-surface-variant text-on-surface font-label-md text-label-md font-bold flex items-center justify-center shrink-0">
									2
								</div>
								<div class="flex-1">
									<div class="flex items-center justify-between">
										<span class="font-title-md text-title-md text-on-surface font-semibold"><?php esc_html_e( 'Navigera i Sello Admin', 'woo-kustom-export' ); ?></span>
										<a class="font-label-sm text-label-sm text-secondary hover:text-primary flex items-center gap-0.5 no-underline" href="https://ui.sello.io/login" target="_blank" rel="noopener noreferrer">
											ui.sello.io <span class="material-symbols-outlined text-[13px]">open_in_new</span>
										</a>
									</div>
									<p class="font-body-md text-body-md text-on-surface-variant mt-1 mb-0 leading-normal">
										<?php printf( esc_html__( 'Öppna Sello (%s) och gå till %s i menyn.', 'woo-kustom-export' ), '<span class="font-code-sm text-code-sm">ui.sello.io</span>', '<span class="font-semibold text-on-surface">' . esc_html__( 'Import / Export → Excel', 'woo-kustom-export' ) . '</span>' ); ?>
									</p>
								</div>
							</div>
							<!-- Step 3 -->
							<div class="flex items-start gap-space-md p-space-md rounded-lg bg-surface-container-low hover:bg-surface-container transition-colors group">
								<div class="w-7 h-7 rounded-full bg-surface-variant text-on-surface font-label-md text-label-md font-bold flex items-center justify-center shrink-0">
									3
								</div>
								<div class="flex-1">
									<div class="flex items-center justify-between">
										<span class="font-title-md text-title-md text-on-surface font-semibold"><?php esc_html_e( 'Välj importläge & Ladda upp', 'woo-kustom-export' ); ?></span>
										<span class="material-symbols-outlined text-[16px] text-on-surface-variant">upload_file</span>
									</div>
									<p class="font-body-md text-body-md text-on-surface-variant mt-1 mb-0 leading-normal">
										<?php printf( esc_html__( 'Välj %s (vid ny import) eller %s (vid lager/pris-uppdatering) och ladda upp den genererade filen.', 'woo-kustom-export' ), '<span class="font-code-sm text-code-sm bg-surface-container-highest px-1 py-0.5 rounded text-on-surface">' . esc_html__( 'Skapa nya produkter', 'woo-kustom-export' ) . '</span>', '<span class="font-code-sm text-code-sm bg-surface-container-highest px-1 py-0.5 rounded text-on-surface">' . esc_html__( 'Uppdatera produkter', 'woo-kustom-export' ) . '</span>' ); ?>
									</p>
								</div>
							</div>
							<!-- Step 4 -->
							<div class="flex items-start gap-space-md p-space-md rounded-lg bg-surface-container-low hover:bg-surface-container transition-colors group">
								<div class="w-7 h-7 rounded-full bg-surface-variant text-on-surface font-label-md text-label-md font-bold flex items-center justify-center shrink-0">
									4
								</div>
								<div class="flex-1">
									<div class="flex items-center justify-between">
										<span class="font-title-md text-title-md text-on-surface font-semibold"><?php esc_html_e( 'Fast pris via Tradera price store', 'woo-kustom-export' ); ?></span>
										<span class="material-symbols-outlined text-[16px] text-on-surface-variant">tune</span>
									</div>
									<p class="font-body-md text-body-md text-on-surface-variant mt-1 mb-0 leading-normal">
										<?php esc_html_e( 'Eftersom priset mappas direkt till "Tradera price store" behövs varken kolumnen Price eller Sellos Calculate. Tradera tar emot fast pris direkt utan extra prisberäkningsregler.', 'woo-kustom-export' ); ?>
									</p>
								</div>
							</div>
							<!-- Step 5 -->
							<div class="flex items-start gap-space-md p-space-md rounded-lg bg-surface-container-low hover:bg-surface-container transition-colors group">
								<div class="w-7 h-7 rounded-full bg-tertiary-container text-on-tertiary font-label-md text-label-md font-bold flex items-center justify-center shrink-0 text-white">
									5
								</div>
								<div class="flex-1">
									<div class="flex items-center justify-between">
										<span class="font-title-md text-title-md text-on-surface font-semibold"><?php esc_html_e( 'Verifiera & Starta import', 'woo-kustom-export' ); ?></span>
										<span class="material-symbols-outlined text-[16px] text-tertiary">play_circle</span>
									</div>
									<p class="font-body-md text-body-md text-on-surface-variant mt-1 mb-0 leading-normal">
										<?php printf( esc_html__( 'Granska att fälten matchar Sellos mall och klicka på %s för att starta importen och synka mot Tradera.', 'woo-kustom-export' ), '<span class="font-semibold text-tertiary">' . esc_html__( 'Starta import', 'woo-kustom-export' ) . '</span>' ); ?>
									</p>
								</div>
							</div>
						</div>
					</div>
				</div>
				<!-- Right Column: Viktigt om Sellos data (5 columns) -->
				<div class="lg:col-span-5 flex flex-col">
					<div class="bg-surface-container-lowest p-space-xl rounded-xl shadow-sm flex flex-col h-full border border-surface-container-high/60">
						<div class="flex items-center justify-between pb-space-md mb-space-md bg-surface-container-lowest border-b border-surface-container">
							<div class="flex items-center gap-space-sm">
								<div class="w-8 h-8 rounded bg-secondary-fixed text-on-secondary-fixed flex items-center justify-center">
									<span class="material-symbols-outlined text-[18px]">verified_user</span>
								</div>
								<div>
									<h2 class="font-headline-sm text-headline-sm text-on-surface m-0 font-semibold"><?php esc_html_e( 'Regelverk för Tradera-import', 'woo-kustom-export' ); ?></h2>
									<p class="font-body-sm text-body-sm text-on-surface-variant m-0"><?php esc_html_e( 'Kritiska krav från Sello och Tradera', 'woo-kustom-export' ); ?></p>
								</div>
							</div>
							<span class="font-label-sm text-label-sm text-secondary font-semibold bg-secondary-fixed/50 px-2 py-0.5 rounded"><?php esc_html_e( 'Regler', 'woo-kustom-export' ); ?></span>
						</div>
						<!-- 4 Key Requirement Cards -->
						<div class="flex flex-col gap-space-md flex-1">
							<!-- SKU-matchning -->
							<div class="bg-surface-container-low p-space-md rounded-lg flex flex-col gap-1.5 hover:bg-surface-container-high/40 transition-colors">
								<div class="flex items-center justify-between">
									<span class="font-title-md text-title-md text-on-surface font-semibold flex items-center gap-1.5">
										<span class="material-symbols-outlined text-[16px] text-primary">fingerprint</span>
										<?php esc_html_e( 'Unikt SKU per rad', 'woo-kustom-export' ); ?>
									</span>
									<span class="px-2 py-0.5 rounded font-label-sm text-label-sm font-semibold bg-primary-fixed text-on-primary-fixed">
										<?php esc_html_e( 'Kritiskt', 'woo-kustom-export' ); ?>
									</span>
								</div>
								<p class="font-body-sm text-body-sm text-on-surface-variant m-0 leading-relaxed">
									<?php esc_html_e( 'Varje variation och enkel produkt måste ha ett unikt SKU. Vår export skapar automatiskt en unik rad per variation och en rad per enkel produkt.', 'woo-kustom-export' ); ?>
								</p>
							</div>
							<!-- Fast pris / Tradera price store -->
							<div class="bg-surface-container-low p-space-md rounded-lg flex flex-col gap-1.5 hover:bg-surface-container-high/40 transition-colors">
								<div class="flex items-center justify-between">
									<span class="font-title-md text-title-md text-on-surface font-semibold flex items-center gap-1.5">
										<span class="material-symbols-outlined text-[16px] text-tertiary">payments</span>
										<?php esc_html_e( 'Tradera price store', 'woo-kustom-export' ); ?>
									</span>
									<span class="px-2 py-0.5 rounded font-label-sm text-label-sm font-semibold bg-tertiary-fixed text-on-tertiary-fixed-variant">
										<?php esc_html_e( 'Heltal utan valuta', 'woo-kustom-export' ); ?>
									</span>
								</div>
								<p class="font-body-sm text-body-sm text-on-surface-variant m-0 leading-relaxed">
									<?php esc_html_e( 'För butiksobjekt på Tradera används Tradera price store (ex. 495). Price och Calculate används inte, vilket förhindrar felaktiga prisberäkningar i Sello.', 'woo-kustom-export' ); ?>
								</p>
							</div>
							<!-- Variantgruppering -->
							<div class="bg-surface-container-low p-space-md rounded-lg flex flex-col gap-1.5 hover:bg-surface-container-high/40 transition-colors">
								<div class="flex items-center justify-between">
									<span class="font-title-md text-title-md text-on-surface font-semibold flex items-center gap-1.5">
										<span class="material-symbols-outlined text-[16px] text-secondary">account_tree</span>
										<?php esc_html_e( 'Variantgruppering', 'woo-kustom-export' ); ?>
									</span>
									<span class="px-2 py-0.5 rounded font-label-sm text-label-sm font-semibold bg-secondary-fixed text-on-secondary-fixed">
										<?php esc_html_e( 'Group sku/type', 'woo-kustom-export' ); ?>
									</span>
								</div>
								<p class="font-body-sm text-body-sm text-on-surface-variant m-0 leading-relaxed">
									<?php printf( esc_html__( 'Alla variationer från samma huvudprodukt delar samma %s och märks med %s (color, size, model). Huvudprodukten har inget separat pris.', 'woo-kustom-export' ), '<code>Group sku</code>', '<code>Group type</code>' ); ?>
								</p>
							</div>
							<!-- Ren textbeskrivning -->
							<div class="bg-surface-container-low p-space-md rounded-lg flex flex-col gap-1.5 hover:bg-surface-container-high/40 transition-colors">
								<div class="flex items-center justify-between">
									<span class="font-title-md text-title-md text-on-surface font-semibold flex items-center gap-1.5">
										<span class="material-symbols-outlined text-[16px] text-slate-700">subject</span>
										<?php esc_html_e( 'Ren textbeskrivning', 'woo-kustom-export' ); ?>
									</span>
									<span class="px-2 py-0.5 rounded font-label-sm text-label-sm font-semibold bg-surface-container text-on-surface-variant">
										<?php esc_html_e( 'HTML-fri', 'woo-kustom-export' ); ?>
									</span>
								</div>
								<p class="font-body-sm text-body-sm text-on-surface-variant m-0 leading-relaxed">
									<?php esc_html_e( 'Kortkoder och taggar som <p> och <br> tas bort, samtidigt som meningsfulla styckebrytningar bibehålls för perfekt läsbarhet på Tradera.', 'woo-kustom-export' ); ?>
								</p>
							</div>
						</div>
					</div>
				</div>
			</div>

			<!-- Full-Width Bottom Panel: Kolumnöversikt för Sello (12 Tradera-kolumner) -->
			<div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-xl flex flex-col border border-surface-container-high/60">
				<div class="flex flex-col md:flex-row md:items-end justify-between gap-space-md pb-space-lg mb-space-md bg-surface-container-lowest border-b border-surface-container">
					<div>
						<div class="flex items-center gap-space-sm">
							<span class="inline-flex items-center justify-center w-6 h-6 rounded bg-primary text-on-primary text-white">
								<span class="material-symbols-outlined text-[14px]">view_column</span>
							</span>
							<h2 class="font-headline-lg text-headline-lg text-on-surface m-0 tracking-tight font-bold"><?php esc_html_e( 'Kolumnöversikt: Tradera-kolumner', 'woo-kustom-export' ); ?></h2>
						</div>
						<p class="font-body-md text-body-md text-on-surface-variant mt-1 mb-0">
							<?php printf( esc_html__( 'Följande %d kolumner genereras i ordning i både .xlsx- och .csv-filen:', 'woo-kustom-export' ), count( $active_columns ) ); ?>
						</p>
					</div>
					<div class="flex items-center gap-space-sm shrink-0">
						<span class="font-code-sm text-code-sm text-on-surface-variant bg-surface-container-low px-2 py-1 rounded font-mono"><?php echo count( $active_columns ); ?> / <?php echo count( $all_cols_meta ); ?> <?php esc_html_e( 'kolumner aktiva', 'woo-kustom-export' ); ?></span>
					</div>
				</div>

				<!-- Responsive Clean Data Table -->
				<div class="overflow-x-auto -mx-space-xl px-space-xl">
					<table class="w-full text-left border-collapse border-0">
						<thead>
							<tr class="bg-surface-container-low text-on-surface-variant">
								<th class="py-2.5 px-space-md font-label-sm text-label-sm uppercase tracking-wider rounded-l border-0"><?php esc_html_e( 'Kolumnnamn i fil', 'woo-kustom-export' ); ?></th>
								<th class="py-2.5 px-space-md font-label-sm text-label-sm uppercase tracking-wider border-0"><?php esc_html_e( 'Sello Fältkod', 'woo-kustom-export' ); ?></th>
								<th class="py-2.5 px-space-md font-label-sm text-label-sm uppercase tracking-wider border-0"><?php esc_html_e( 'WooCommerce Källa', 'woo-kustom-export' ); ?></th>
								<th class="py-2.5 px-space-md font-label-sm text-label-sm uppercase tracking-wider border-0"><?php esc_html_e( 'Beskrivning & Regel', 'woo-kustom-export' ); ?></th>
								<th class="py-2.5 px-space-md font-label-sm text-label-sm uppercase tracking-wider text-right rounded-r border-0"><?php esc_html_e( 'Exempelvärde', 'woo-kustom-export' ); ?></th>
							</tr>
						</thead>
						<tbody class="divide-y divide-slate-100 text-body-sm font-body-sm" id="selloColumnsTableBody">
							<!-- 1: Sku -->
							<tr class="hover:bg-surface-container-low/60 transition-colors border-0">
								<td class="py-space-md px-space-md font-semibold text-on-surface border-0 font-mono">Sku</td>
								<td class="py-space-md px-space-md border-0"><span class="font-code-sm text-code-sm bg-surface-container-high px-2 py-0.5 rounded text-primary font-semibold">SKU</span></td>
								<td class="py-space-md px-space-md text-on-surface-variant border-0"><code>_sku</code></td>
								<td class="py-space-md px-space-md text-on-surface border-0"><?php esc_html_e( 'Unikt artikelnummer för varje enkel produkt och variation. Kritiskt för matchning.', 'woo-kustom-export' ); ?></td>
								<td class="py-space-md px-space-md text-right font-code-sm text-code-sm text-on-surface-variant border-0 font-mono">LNT-8821-BLK-M</td>
							</tr>
							<!-- 2: Standard name sv -->
							<tr class="hover:bg-surface-container-low/60 transition-colors border-0">
								<td class="py-space-md px-space-md font-semibold text-on-surface border-0 font-mono">Standard name sv</td>
								<td class="py-space-md px-space-md border-0"><span class="font-code-sm text-code-sm bg-surface-container-high px-2 py-0.5 rounded text-primary font-semibold">Name</span></td>
								<td class="py-space-md px-space-md text-on-surface-variant border-0"><code>post_title</code></td>
								<td class="py-space-md px-space-md text-on-surface border-0"><?php esc_html_e( 'Produktnamn på svenska för butik och marknadsplatser.', 'woo-kustom-export' ); ?></td>
								<td class="py-space-md px-space-md text-right font-code-sm text-code-sm text-on-surface-variant border-0">Skjorta Linne Sand</td>
							</tr>
							<!-- 3: Standard description sv -->
							<tr class="hover:bg-surface-container-low/60 transition-colors border-0">
								<td class="py-space-md px-space-md font-semibold text-on-surface border-0 font-mono">Standard description sv</td>
								<td class="py-space-md px-space-md border-0"><span class="font-code-sm text-code-sm bg-surface-container-high px-2 py-0.5 rounded text-primary font-semibold">Description</span></td>
								<td class="py-space-md px-space-md text-on-surface-variant border-0"><code>post_content</code> / <code>excerpt</code></td>
								<td class="py-space-md px-space-md text-on-surface border-0"><?php esc_html_e( 'Ren textbeskrivning utan HTML-taggar eller kortkoder. Behåller styckebrytningar.', 'woo-kustom-export' ); ?></td>
								<td class="py-space-md px-space-md text-right font-code-sm text-code-sm text-on-surface-variant border-0 truncate max-w-xs">Klassisk linneskjorta med rak passform...</td>
							</tr>
							<!-- 4: Tradera price store -->
							<tr class="hover:bg-surface-container-low/60 transition-colors border-0">
								<td class="py-space-md px-space-md font-semibold text-emerald-800 border-0 font-mono">Tradera price store</td>
								<td class="py-space-md px-space-md border-0"><span class="font-code-sm text-code-sm bg-emerald-100 px-2 py-0.5 rounded text-emerald-800 font-semibold font-mono">Tradera Price</span></td>
								<td class="py-space-md px-space-md text-on-surface-variant border-0"><code>_regular_price</code> / aktivt pris</td>
								<td class="py-space-md px-space-md text-on-surface border-0"><?php esc_html_e( 'Fast pris för Tradera-butiksobjekt som heltal utan valuta. Ersätter Price och Calculate.', 'woo-kustom-export' ); ?></td>
								<td class="py-space-md px-space-md text-right font-code-sm text-code-sm text-emerald-800 font-bold border-0 font-mono">495</td>
							</tr>
							<!-- 5: Purchase price -->
							<tr class="hover:bg-surface-container-low/60 transition-colors border-0">
								<td class="py-space-md px-space-md font-semibold text-on-surface border-0 font-mono">Purchase price</td>
								<td class="py-space-md px-space-md border-0"><span class="font-code-sm text-code-sm bg-surface-container-high px-2 py-0.5 rounded text-primary font-semibold">Cost price</span></td>
								<td class="py-space-md px-space-md text-on-surface-variant border-0"><code>_purchase_price</code> / <code>_cost</code></td>
								<td class="py-space-md px-space-md text-on-surface border-0"><?php esc_html_e( 'Inköpspris/kalkylpris om angivet i WooCommerce.', 'woo-kustom-export' ); ?></td>
								<td class="py-space-md px-space-md text-right font-code-sm text-code-sm text-on-surface-variant border-0 font-mono">180</td>
							</tr>
							<!-- 6: Quantity -->
							<tr class="hover:bg-surface-container-low/60 transition-colors border-0">
								<td class="py-space-md px-space-md font-semibold text-on-surface border-0 font-mono">Quantity</td>
								<td class="py-space-md px-space-md border-0"><span class="font-code-sm text-code-sm bg-surface-container-high px-2 py-0.5 rounded text-primary font-semibold">Stock</span></td>
								<td class="py-space-md px-space-md text-on-surface-variant border-0"><code>_stock</code></td>
								<td class="py-space-md px-space-md text-on-surface border-0"><?php esc_html_e( 'Lagersaldo som heltal. Tomt om lagerhantering är inaktiv.', 'woo-kustom-export' ); ?></td>
								<td class="py-space-md px-space-md text-right font-code-sm text-code-sm text-tertiary font-bold border-0 font-mono">42</td>
							</tr>
							<!-- 7: Tax -->
							<tr class="hover:bg-surface-container-low/60 transition-colors border-0">
								<td class="py-space-md px-space-md font-semibold text-on-surface border-0 font-mono">Tax</td>
								<td class="py-space-md px-space-md border-0"><span class="font-code-sm text-code-sm bg-surface-container-high px-2 py-0.5 rounded text-primary font-semibold">Vat rate</span></td>
								<td class="py-space-md px-space-md text-on-surface-variant border-0"><code>tax_class</code></td>
								<td class="py-space-md px-space-md text-on-surface border-0"><?php esc_html_e( 'Momssats som heltal (normalt 25 för Sverige, 12 för livsmedel).', 'woo-kustom-export' ); ?></td>
								<td class="py-space-md px-space-md text-right font-code-sm text-code-sm text-on-surface-variant border-0 font-mono">25</td>
							</tr>
							<!-- 8: Weight -->
							<tr class="hover:bg-surface-container-low/60 transition-colors border-0">
								<td class="py-space-md px-space-md font-semibold text-on-surface border-0 font-mono">Weight</td>
								<td class="py-space-md px-space-md border-0"><span class="font-code-sm text-code-sm bg-surface-container-high px-2 py-0.5 rounded text-primary font-semibold">Weight (g)</span></td>
								<td class="py-space-md px-space-md text-on-surface-variant border-0"><code>_weight</code></td>
								<td class="py-space-md px-space-md text-on-surface border-0"><?php esc_html_e( 'Vikt automatiskt konverterad till heltal gram.', 'woo-kustom-export' ); ?></td>
								<td class="py-space-md px-space-md text-right font-code-sm text-code-sm text-on-surface-variant border-0 font-mono">350</td>
							</tr>
							<!-- 9: Images -->
							<tr class="hover:bg-surface-container-low/60 transition-colors border-0">
								<td class="py-space-md px-space-md font-semibold text-on-surface border-0 font-mono">Images</td>
								<td class="py-space-md px-space-md border-0"><span class="font-code-sm text-code-sm bg-surface-container-high px-2 py-0.5 rounded text-primary font-semibold">Image URLs</span></td>
								<td class="py-space-md px-space-md text-on-surface-variant border-0">Bild- & galleri-ID:n</td>
								<td class="py-space-md px-space-md text-on-surface border-0"><?php esc_html_e( 'Fullständiga https:// bild-URL:er separerade med kommatecken.', 'woo-kustom-export' ); ?></td>
								<td class="py-space-md px-space-md text-right font-code-sm text-code-sm text-secondary truncate max-w-xs border-0 font-mono">https://butik.se/img1.jpg,https://butik.se/img2.jpg</td>
							</tr>
							<!-- 10: Brand -->
							<tr class="hover:bg-surface-container-low/60 transition-colors border-0">
								<td class="py-space-md px-space-md font-semibold text-on-surface border-0 font-mono">Brand</td>
								<td class="py-space-md px-space-md border-0"><span class="font-code-sm text-code-sm bg-surface-container-high px-2 py-0.5 rounded text-primary font-semibold">Manufacturer</span></td>
								<td class="py-space-md px-space-md text-on-surface-variant border-0"><code>pa_brand</code> / attribut</td>
								<td class="py-space-md px-space-md text-on-surface border-0"><?php esc_html_e( 'Varumärke hämtat från taxonomi pa_brand, attribut eller metafält.', 'woo-kustom-export' ); ?></td>
								<td class="py-space-md px-space-md text-right font-code-sm text-code-sm text-on-surface-variant border-0">Linthor Heritage</td>
							</tr>
							<!-- 11: Group sku -->
							<tr class="hover:bg-surface-container-low/60 transition-colors border-0">
								<td class="py-space-md px-space-md font-semibold text-purple-800 border-0 font-mono">Group sku</td>
								<td class="py-space-md px-space-md border-0"><span class="font-code-sm text-code-sm bg-purple-100 px-2 py-0.5 rounded text-purple-800 font-semibold font-mono">Parent SKU</span></td>
								<td class="py-space-md px-space-md text-on-surface-variant border-0">Förälderns <code>_sku</code></td>
								<td class="py-space-md px-space-md text-on-surface border-0"><?php esc_html_e( 'Huvudproduktens SKU. Binder ihop variationer till en gemensam produktgrupp i Sello.', 'woo-kustom-export' ); ?></td>
								<td class="py-space-md px-space-md text-right font-code-sm text-code-sm text-purple-800 font-mono border-0">LNT-8821</td>
							</tr>
							<!-- 12: Group type -->
							<tr class="hover:bg-surface-container-low/60 transition-colors border-0">
								<td class="py-space-md px-space-md font-semibold text-blue-800 border-0 font-mono">Group type</td>
								<td class="py-space-md px-space-md border-0"><span class="font-code-sm text-code-sm bg-blue-100 px-2 py-0.5 rounded text-blue-800 font-semibold font-mono">Dimension</span></td>
								<td class="py-space-md px-space-md text-on-surface-variant border-0">Variationsattribut</td>
								<td class="py-space-md px-space-md text-on-surface border-0"><?php esc_html_e( 'Typ av variation i Sello: color, size eller model.', 'woo-kustom-export' ); ?></td>
								<td class="py-space-md px-space-md text-right font-code-sm text-code-sm text-blue-800 font-mono border-0">size</td>
							</tr>
						</tbody>
					</table>
				</div>

				<!-- Export Summary Bottom Action -->
				<div class="mt-space-lg pt-space-md flex flex-col sm:flex-row items-center justify-between gap-space-md text-on-surface-variant bg-surface-container-lowest border-t border-surface-container">
					<div class="flex items-center gap-space-sm font-body-sm text-body-sm">
						<span class="material-symbols-outlined text-[18px] text-tertiary">check_circle</span>
						<span><?php esc_html_e( 'Exakt 12 kolumner strukturerade för Sello och Tradera fastprisbutik.', 'woo-kustom-export' ); ?></span>
					</div>
					<div class="flex items-center gap-space-sm">
						<button class="font-label-sm text-label-sm text-primary hover:text-primary-container font-semibold flex items-center gap-1 cursor-pointer bg-transparent border-0 p-0" onclick="window.scrollTo({top: 0, behavior: 'smooth'})" type="button">
							<span class="material-symbols-outlined text-[16px]">arrow_upward</span>
							<?php esc_html_e( 'Tillbaka till nedladdning', 'woo-kustom-export' ); ?>
						</button>
					</div>
				</div>
			</div>

			<!-- Notification Toast Container for Interactive feedback -->
			<div class="fixed bottom-6 right-6 z-50 transform translate-y-20 opacity-0 transition-all duration-300 pointer-events-none bg-inverse-surface text-inverse-on-surface px-space-md py-space-sm rounded-lg shadow-xl flex items-center gap-space-sm font-label-md text-label-md" id="toastNotification">
				<span class="material-symbols-outlined text-[18px] text-tertiary-fixed">check</span>
				<span id="toastMessage"><?php esc_html_e( 'Åtgärd utförd!', 'woo-kustom-export' ); ?></span>
			</div>
		</div>

		<script>
		// Initiera Sello-interaktioner direkt om skript laddas inline
		(function() {
			var toast = document.getElementById('toastNotification');
			var toastMsg = document.getElementById('toastMessage');

			function showToast(text) {
				if (!toast || !toastMsg) return;
				toastMsg.textContent = text;
				toast.classList.remove('translate-y-20', 'opacity-0', 'pointer-events-none');
				toast.classList.add('translate-y-0', 'opacity-100');

				setTimeout(function() {
					toast.classList.remove('translate-y-0', 'opacity-100');
					toast.classList.add('translate-y-20', 'opacity-0', 'pointer-events-none');
				}, 4000);
			}

			var xlsxBtn = document.getElementById('downloadXlsxBtn');
			if (xlsxBtn) {
				xlsxBtn.addEventListener('click', function() {
					var count = xlsxBtn.getAttribute('data-count') || '<?php echo esc_js( number_format_i18n( $total_rows ) ); ?>';
					var fn = xlsxBtn.getAttribute('data-filename') || 'sello-tradera-products-<?php echo esc_js( $today_date ); ?>.xlsx';
					showToast('Genererar och laddar ner ' + fn + ' (' + count + ' rader)...');
				});
			}

			var csvBtn = document.getElementById('downloadCsvBtn');
			if (csvBtn) {
				csvBtn.addEventListener('click', function() {
					var fn = csvBtn.getAttribute('data-filename') || 'sello-tradera-products-<?php echo esc_js( $today_date ); ?>.csv';
					showToast('Genererar och laddar ner ' + fn + '...');
				});
			}

			// Snabbknappar för att markera / avmarkera kolumner
			var selectAllBtn = document.getElementById('selloSelectAllBtn');
			var deselectAllBtn = document.getElementById('selloDeselectAllBtn');
			var colCheckboxes = document.querySelectorAll('.sello-col-checkbox');

			if (selectAllBtn) {
				selectAllBtn.addEventListener('click', function() {
					colCheckboxes.forEach(function(cb) {
						cb.checked = true;
					});
					showToast('Samtliga 12 kolumner markerade. Klicka på "Spara kolumnval" för att verkställa.');
				});
			}

			if (deselectAllBtn) {
				deselectAllBtn.addEventListener('click', function() {
					colCheckboxes.forEach(function(cb) {
						cb.checked = false;
					});
					showToast('Alla kolumner avmarkerade. Välj önskade fält och klicka på "Spara kolumnval".');
				});
			}

			// AJAX-sparande av Sello-kolumnval
			var columnsForm = document.getElementById('sello-columns-form');
			var saveBtn = document.getElementById('selloSaveBtn');

			if (columnsForm && saveBtn) {
				columnsForm.addEventListener('submit', function(e) {
					e.preventDefault();
					var originalHtml = saveBtn.innerHTML;
					saveBtn.disabled = true;
					saveBtn.innerHTML = '<span class="material-symbols-outlined text-[15px] animate-spin">sync</span> <span>Sparar...</span>';

					var formData = new FormData(columnsForm);

					fetch(columnsForm.action, {
						method: 'POST',
						body: formData,
						headers: {
							'X-Requested-With': 'XMLHttpRequest'
						}
					}).then(function(res) {
						saveBtn.disabled = false;
						saveBtn.innerHTML = '<span class="material-symbols-outlined text-[15px]">done</span> <span>Sparat!</span>';
						showToast('Dina valda Sello-kolumner har sparats!');
						setTimeout(function() {
							saveBtn.innerHTML = originalHtml;
							// Ladda om sidan för att uppdatera förhandsgranskningen direkt
							window.location.reload();
						}, 1000);
					}).catch(function() {
						saveBtn.disabled = false;
						saveBtn.innerHTML = originalHtml;
						columnsForm.submit();
					});
				});
			}
		})();
		</script>
		<?php
	}

	/**
	 * Hämta katalog för arkiverade exportfiler och se till att den är skyddad.
	 *
	 * @return string Fullständig sökväg till arkivkatalogen.
	 */
	public static function get_archive_dir() {
		$upload_dir  = wp_upload_dir();
		$archive_dir = trailingslashit( $upload_dir['basedir'] ) . 'woo-kustom-exports';

		if ( ! file_exists( $archive_dir ) ) {
			wp_mkdir_p( $archive_dir );
		}

		// Skydda mot direkt webbåtkomst via .htaccess
		$htaccess_file = trailingslashit( $archive_dir ) . '.htaccess';
		if ( ! file_exists( $htaccess_file ) ) {
			@file_put_contents( $htaccess_file, "Order Deny,Allow\nDeny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n" );
		}

		// Skydda mot mappindexering via index.php
		$index_file = trailingslashit( $archive_dir ) . 'index.php';
		if ( ! file_exists( $index_file ) ) {
			@file_put_contents( $index_file, "<?php\n// Silence is golden.\n" );
		}

		return $archive_dir;
	}

	/**
	 * Hämta alla arkiverade poster från databasen.
	 *
	 * @return array<array{id:string,filename:string,filepath:string,platform:string,platform_label:string,rows:int,size:int,timestamp:int,status:string}>
	 */
	public static function get_archive_entries() {
		$entries = get_option( self::ARCHIVE_OPTION_KEY, array() );
		if ( ! is_array( $entries ) ) {
			return array();
		}

		// Kontrollera att filerna fortfarande finns fysiskt på disken och rensa bort saknade
		$valid_entries = array();
		$needs_update  = false;
		foreach ( $entries as $entry ) {
			if ( ! empty( $entry['filepath'] ) && file_exists( $entry['filepath'] ) ) {
				$valid_entries[] = $entry;
			} else {
				$needs_update = true;
			}
		}

		if ( $needs_update ) {
			update_option( self::ARCHIVE_OPTION_KEY, $valid_entries, false );
		}

		return $valid_entries;
	}

	/**
	 * Lägg till en ny exportpost i arkivloggen.
	 *
	 * @param array $new_entry Postdata.
	 */
	public static function add_archive_entry( $new_entry ) {
		$entries = self::get_archive_entries();
		array_unshift( $entries, $new_entry );
		// Spara högst 50 poster i loggen
		$entries = array_slice( $entries, 0, 50 );
		update_option( self::ARCHIVE_OPTION_KEY, $entries, false );
	}

	/**
	 * Ta bort en exportfil och dess loggpost från arkivet.
	 *
	 * @param string $entry_id Arkivpostens unika ID.
	 * @return bool True om posten togs bort.
	 */
	public static function delete_archive_entry( $entry_id ) {
		$entries     = self::get_archive_entries();
		$archive_dir = realpath( self::get_archive_dir() );
		$updated     = array();
		$deleted     = false;

		foreach ( $entries as $entry ) {
			if ( isset( $entry['id'] ) && $entry['id'] === $entry_id ) {
				if ( ! empty( $entry['filepath'] ) && file_exists( $entry['filepath'] ) ) {
					$real_file = realpath( $entry['filepath'] );
					if ( $real_file && $archive_dir && 0 === strpos( $real_file, $archive_dir ) ) {
						@unlink( $real_file );
					}
				}
				$deleted = true;
			} else {
				$updated[] = $entry;
			}
		}

		if ( $deleted ) {
			update_option( self::ARCHIVE_OPTION_KEY, $updated, false );
		}

		return $deleted;
	}

	/**
	 * Rensa exportfiler som är äldre än 7 dagar (körs via WP-Cron dagligen).
	 */
	public static function cleanup_archive() {
		$max_age     = 7 * DAY_IN_SECONDS;
		$cutoff_time = current_time( 'timestamp' ) - $max_age;
		$archive_dir = realpath( self::get_archive_dir() );
		$entries     = get_option( self::ARCHIVE_OPTION_KEY, array() );
		$kept        = array();

		if ( is_array( $entries ) ) {
			foreach ( $entries as $entry ) {
				$timestamp = isset( $entry['timestamp'] ) ? (int) $entry['timestamp'] : 0;
				$filepath  = isset( $entry['filepath'] ) ? $entry['filepath'] : '';

				if ( $timestamp < $cutoff_time ) {
					if ( ! empty( $filepath ) && file_exists( $filepath ) ) {
						$real_file = realpath( $filepath );
						if ( $real_file && $archive_dir && 0 === strpos( $real_file, $archive_dir ) ) {
							@unlink( $real_file );
						}
					}
				} else {
					if ( ! empty( $filepath ) && file_exists( $filepath ) ) {
						$kept[] = $entry;
					}
				}
			}
			update_option( self::ARCHIVE_OPTION_KEY, $kept, false );
		}

		// Rensa eventuella föräldralösa filer i mappen äldre än 7 dagar
		if ( $archive_dir && is_dir( $archive_dir ) ) {
			$files = glob( $archive_dir . '/*.{csv,xlsx}', GLOB_BRACE );
			if ( is_array( $files ) ) {
				$now = time();
				foreach ( $files as $file ) {
					if ( is_file( $file ) && ( $now - filemtime( $file ) ) > $max_age ) {
						@unlink( $file );
					}
				}
			}
		}
	}

	/**
	 * Hämta nedladdningslänk för en sparad arkivfil.
	 *
	 * @param string $entry_id Arkivpostens ID.
	 * @return string
	 */
	public static function get_archive_download_url( $entry_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'   => 'woo_kustom_export_download_archive',
					'entry_id' => $entry_id,
				),
				admin_url( 'admin-post.php' )
			),
			'woo_kustom_export_download_archive'
		);
	}

	/**
	 * Hämta borttagningslänk för en sparad arkivfil.
	 *
	 * @param string $entry_id Arkivpostens ID.
	 * @return string
	 */
	public static function get_archive_delete_url( $entry_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'   => 'woo_kustom_export_delete_archive',
					'entry_id' => $entry_id,
				),
				admin_url( 'admin-post.php' )
			),
			'woo_kustom_export_delete_archive'
		);
	}

	/**
	 * Formatera tidsstämpel för arkivtabellen med begripliga datum (Idag, Igår, etc.).
	 *
	 * @param int $timestamp Tidsstämpel.
	 * @return string
	 */
	public static function format_archive_date( $timestamp ) {
		$date_day  = wp_date( 'Y-m-d', $timestamp );
		$today     = wp_date( 'Y-m-d', current_time( 'timestamp' ) );
		$yesterday = wp_date( 'Y-m-d', current_time( 'timestamp' ) - DAY_IN_SECONDS );

		if ( $date_day === $today ) {
			return sprintf( __( 'Idag, %s', 'woo-kustom-export' ), wp_date( 'H:i', $timestamp ) );
		} elseif ( $date_day === $yesterday ) {
			return sprintf( __( 'Igår, %s', 'woo-kustom-export' ), wp_date( 'H:i', $timestamp ) );
		} else {
			return wp_date( 'j M, H:i', $timestamp );
		}
	}

	/**
	 * Strömma en befintlig fil till webbläsaren.
	 *
	 * @param string $filepath Filens sökväg på servern.
	 * @param string $filename Filnamn vid nedladdning.
	 * @param string $platform Exportplattform eller filtyp.
	 */
	public static function stream_existing_file( $filepath, $filename, $platform = 'kustom' ) {
		if ( ! file_exists( $filepath ) ) {
			wp_die( esc_html__( 'Filen kunde inte hittas på servern.', 'woo-kustom-export' ) );
		}

		nocache_headers();

		if ( 'sello-xlsx' === $platform || 'xlsx' === pathinfo( $filename, PATHINFO_EXTENSION ) ) {
			header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		} else {
			header( 'Content-Type: text/csv; charset=utf-8' );
		}

		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'Content-Length: ' . filesize( $filepath ) );

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		readfile( $filepath );
		exit;
	}

	/**
	 * Hantera filnedladdningen: sparar till filarkivet och strömmar till webbläsaren.
	 */
	public function handle_export() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Du har inte behörighet att exportera produkter.', 'woo-kustom-export' ) );
		}

		check_admin_referer( 'woo_kustom_export_download' );

		$platform    = self::sanitize_platform( isset( $_GET['platform'] ) ? wp_unslash( $_GET['platform'] ) : 'kustom' );
		$generator   = self::create_generator( $platform );
		$archive_dir = self::get_archive_dir();

		$filename = $generator->get_filename_prefix() . gmdate( 'Y-m-d-Hi' ) . '.' . $generator->get_filename_extension();
		$filepath = trailingslashit( $archive_dir ) . $filename;

		// Försök skriva filen till arkivmappen
		$stats = $generator->write_to_file( $filepath );

		if ( false !== $stats && file_exists( $filepath ) ) {
			$rows_count = isset( $stats['rows'] ) ? (int) $stats['rows'] : 0;
			$file_size  = isset( $stats['size'] ) ? (int) $stats['size'] : filesize( $filepath );

			self::add_archive_entry(
				array(
					'id'             => 'exp_' . wp_generate_password( 12, false, false ),
					'filename'       => $filename,
					'filepath'       => $filepath,
					'platform'       => $platform,
					'platform_label' => self::get_platform_label( $platform ),
					'rows'           => $rows_count,
					'size'           => $file_size,
					'timestamp'      => current_time( 'timestamp' ),
					'status'         => 'completed',
				)
			);

			self::stream_existing_file( $filepath, $filename, $platform );
			exit;
		}

		// Fallback till direkt strömning om skrivning till disk mot förmodan misslyckades
		if ( 'sello-xlsx' === $platform ) {
			$generator->stream_xlsx();
			return;
		}

		$generator->stream_csv();
	}

	/**
	 * Hantera säker nedladdning av en sparad arkivfil.
	 */
	public function handle_archive_download() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Du har inte behörighet att ladda ner sparade exporter.', 'woo-kustom-export' ) );
		}

		check_admin_referer( 'woo_kustom_export_download_archive' );

		$entry_id = isset( $_GET['entry_id'] ) ? sanitize_text_field( wp_unslash( $_GET['entry_id'] ) ) : '';
		$entries  = self::get_archive_entries();

		$found = null;
		foreach ( $entries as $entry ) {
			if ( isset( $entry['id'] ) && $entry['id'] === $entry_id ) {
				$found = $entry;
				break;
			}
		}

		if ( ! $found || empty( $found['filepath'] ) ) {
			wp_die( esc_html__( 'Den begärda exportfilen hittades inte i arkivet.', 'woo-kustom-export' ) );
		}

		$archive_dir = realpath( self::get_archive_dir() );
		$filepath    = realpath( $found['filepath'] );

		// Säkerhetskontroll: förhindra directory traversal
		if ( ! $filepath || ! $archive_dir || 0 !== strpos( $filepath, $archive_dir ) || ! file_exists( $filepath ) ) {
			wp_die( esc_html__( 'Filen är ogiltig eller finns inte längre.', 'woo-kustom-export' ) );
		}

		self::stream_existing_file( $filepath, $found['filename'], isset( $found['platform'] ) ? $found['platform'] : 'kustom' );
	}

	/**
	 * Hantera manuell borttagning av en sparad arkivfil.
	 */
	public function handle_archive_delete() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Du har inte behörighet att ta bort exporter.', 'woo-kustom-export' ) );
		}

		check_admin_referer( 'woo_kustom_export_delete_archive' );

		$entry_id = isset( $_GET['entry_id'] ) ? sanitize_text_field( wp_unslash( $_GET['entry_id'] ) ) : '';
		if ( ! empty( $entry_id ) ) {
			self::delete_archive_entry( $entry_id );
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'woo-product-export', 'tab' => 'dashboard', 'archive_deleted' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Spara exportfilter-inställningar.
	 */
	public function handle_save_settings() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Du har inte behörighet att ändra inställningarna.', 'woo-kustom-export' ) );
		}

		check_admin_referer( 'woo_kustom_export_save_settings' );

		$raw        = isset( $_POST['woo_kustom_export'] ) ? (array) wp_unslash( $_POST['woo_kustom_export'] ) : array();
		$return_tab = isset( $_POST['return_tab'] ) ? sanitize_key( wp_unslash( $_POST['return_tab'] ) ) : 'dashboard';

		$existing = self::get_settings();

		// Om formuläret skickas från Dashboard (filter) eller Kustom (kolumner & attribut).
		$include_drafts     = isset( $raw['include_drafts'] ) ? ( empty( $raw['include_drafts'] ) ? 0 : 1 ) : $existing['include_drafts'];
		$include_private    = isset( $raw['include_private'] ) ? ( empty( $raw['include_private'] ) ? 0 : 1 ) : $existing['include_private'];
		$include_outofstock = isset( $raw['include_outofstock'] ) ? ( empty( $raw['include_outofstock'] ) ? 0 : 1 ) : $existing['include_outofstock'];

		$optional_columns  = isset( $existing['optional_columns'] ) && is_array( $existing['optional_columns'] ) ? $existing['optional_columns'] : array();
		$custom_attributes = isset( $existing['custom_attributes'] ) && is_array( $existing['custom_attributes'] ) ? $existing['custom_attributes'] : array();
		if ( empty( $custom_attributes ) && ! empty( $existing['custom_attribute'] ) ) {
			$custom_attributes = array( (string) $existing['custom_attribute'] );
		}

		if ( 'kustom' === $return_tab ) {
			$supported_cols   = array_keys( WOO_Kustom_CSV_Generator::get_supported_optional_columns() );
			$optional_columns = array();
			if ( isset( $raw['optional_columns'] ) && is_array( $raw['optional_columns'] ) ) {
				foreach ( $raw['optional_columns'] as $col_key => $val ) {
					$sanitized_key = sanitize_key( $col_key );
					if ( in_array( $sanitized_key, $supported_cols, true ) && ! empty( $val ) ) {
						$optional_columns[ $sanitized_key ] = 1;
					}
				}
			}

			// Stöd även eventuella direkta col_* fält
			foreach ( $_POST as $p_key => $p_val ) {
				if ( 0 === strpos( $p_key, 'col_' ) && ! empty( $p_val ) ) {
					$cand = sanitize_key( str_replace( 'col_', '', $p_key ) );
					if ( 'category' === $cand ) {
						$cand = 'category_tree';
					} elseif ( 'short_desc' === $cand ) {
						$cand = 'short_description';
					}
					if ( in_array( $cand, $supported_cols, true ) ) {
						$optional_columns[ $cand ] = 1;
					}
				}
			}

			$custom_attributes = array();
			if ( isset( $raw['custom_attributes'] ) && is_array( $raw['custom_attributes'] ) ) {
				foreach ( $raw['custom_attributes'] as $attr_candidate ) {
					$attr_clean = strtolower( sanitize_text_field( (string) $attr_candidate ) );
					$attr_clean = preg_replace( '/^attribute_/', '', $attr_clean );
					if ( ! in_array( $attr_clean, $custom_attributes, true ) ) {
						$custom_attributes[] = $attr_clean;
					}
				}
			} elseif ( isset( $raw['custom_attribute'] ) ) {
				$candidate = strtolower( sanitize_text_field( (string) $raw['custom_attribute'] ) );
				$candidate = preg_replace( '/^attribute_/', '', $candidate );
				if ( ! empty( $candidate ) ) {
					$custom_attributes[] = $candidate;
				}
			}

			// Stöd även eventuella direkta attr_* fält
			foreach ( $_POST as $p_key => $p_val ) {
				if ( 0 === strpos( $p_key, 'attr_' ) && ! empty( $p_val ) ) {
					$attr_cand = strtolower( sanitize_text_field( str_replace( 'attr_', '', $p_key ) ) );
					$attr_cand = preg_replace( '/^attribute_/', '', $attr_cand );
					if ( ! in_array( $attr_cand, $custom_attributes, true ) ) {
						$custom_attributes[] = $attr_cand;
					}
				}
			}
		}

		$sello_columns = isset( $existing['sello_columns'] ) && is_array( $existing['sello_columns'] ) ? $existing['sello_columns'] : array();
		if ( 'sello' === $return_tab ) {
			$all_sello_cols = WOO_Sello_CSV_Generator::get_all_columns_list();
			$sello_columns  = array();
			if ( isset( $raw['sello_columns'] ) && is_array( $raw['sello_columns'] ) ) {
				foreach ( $raw['sello_columns'] as $col_key => $val ) {
					if ( in_array( $col_key, $all_sello_cols, true ) && ! empty( $val ) ) {
						$sello_columns[ $col_key ] = 1;
					}
				}
			}
		}

		$settings = array(
			'include_drafts'     => $include_drafts,
			'include_private'    => $include_private,
			'include_outofstock' => $include_outofstock,
			'optional_columns'   => $optional_columns,
			'custom_attribute'   => ! empty( $custom_attributes ) ? reset( $custom_attributes ) : '',
			'custom_attributes'  => $custom_attributes,
			'sello_columns'      => $sello_columns,
		);

		update_option( self::OPTION_KEY, $settings, false );

		if ( ! empty( $_SERVER['HTTP_X_REQUESTED_WITH'] ) && 'xmlhttprequest' === strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REQUESTED_WITH'] ) ) ) ) {
			wp_send_json_success( array(
				'message' => __( 'Inställningarna har sparats i databasen.', 'woo-kustom-export' ),
				'time'    => current_time( 'H:i' ),
			) );
			exit;
		}

		$redirect_args = array(
			'page'    => 'woo-product-export',
			'updated' => '1',
		);
		if ( in_array( $return_tab, array( 'kustom', 'sello', 'doc' ), true ) ) {
			$redirect_args['tab'] = $return_tab;
		}

		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
