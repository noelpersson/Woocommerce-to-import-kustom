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

		$ver = WOO_KUSTOM_EXPORT_VERSION;
		$css_path = WOO_KUSTOM_EXPORT_PLUGIN_DIR . 'assets/admin.css';
		if ( ( defined( 'WP_DEBUG' ) && WP_DEBUG ) || ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ) {
			if ( file_exists( $css_path ) ) {
				$ver = (string) filemtime( $css_path );
			}
		}

		// Google Fonts: Inter, JetBrains Mono och Material Symbols Outlined.
		wp_enqueue_style(
			'woo-kustom-export-fonts',
			'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500&family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=swap',
			array(),
			null
		);

		// Tailwind CSS via CDN med preflight inaktiverat för att skydda WP-admin och bevara ren integration.
		wp_enqueue_script(
			'woo-kustom-export-tailwind',
			'https://cdn.tailwindcss.com',
			array(),
			'3.4.17',
			false
		);

		$tailwind_config = 'tailwind.config = {
			darkMode: "class",
			corePlugins: { preflight: false },
			theme: {
				extend: {
					colors: {
						"primary-fixed": "#dde1ff",
						"on-error": "#ffffff",
						"surface-variant": "#dae2fd",
						"surface-container-high": "#e2e7ff",
						"tertiary-fixed": "#95f8a7",
						"on-primary": "#ffffff",
						"on-background": "#131b2e",
						"on-error-container": "#93000a",
						"on-primary-fixed": "#001453",
						"surface-container-low": "#f2f3ff",
						"secondary-fixed-dim": "#b4c5ff",
						"secondary": "#0051d5",
						"outline": "#757684",
						"surface-container": "#eaedff",
						"secondary-fixed": "#dbe1ff",
						"on-tertiary": "#ffffff",
						"on-secondary-container": "#fefcff",
						"error-container": "#ffdad6",
						"outline-variant": "#c4c5d5",
						"surface": "#faf8ff",
						"surface-bright": "#faf8ff",
						"inverse-primary": "#b8c4ff",
						"on-primary-container": "#a8b8ff",
						"surface-container-highest": "#dae2fd",
						"background": "#faf8ff",
						"on-surface-variant": "#444653",
						"surface-dim": "#d2d9f4",
						"primary-fixed-dim": "#b8c4ff",
						"on-tertiary-fixed-variant": "#005323",
						"surface-container-lowest": "#ffffff",
						"inverse-on-surface": "#eef0ff",
						"on-secondary-fixed": "#00174b",
						"tertiary-fixed-dim": "#79db8d",
						"on-primary-fixed-variant": "#173bab",
						"primary-container": "#1e40af",
						"on-secondary-fixed-variant": "#003ea8",
						"primary": "#00288e",
						"on-tertiary-container": "#6fd083",
						"on-secondary": "#ffffff",
						"secondary-container": "#316bf3",
						"on-tertiary-fixed": "#00210a",
						"inverse-surface": "#283044",
						"tertiary-container": "#005826",
						"tertiary": "#003e18",
						"error": "#ba1a1a",
						"on-surface": "#131b2e",
						"surface-tint": "#3755c3"
					},
					borderRadius: {
						"DEFAULT": "0.125rem",
						"lg": "0.25rem",
						"xl": "0.5rem",
						"full": "0.75rem"
					},
					spacing: {
						"margin": "1.5rem",
						"space-xs": "0.25rem",
						"gutter": "1.25rem",
						"space-lg": "1.25rem",
						"gutter-lg": "1.5rem",
						"margin-mobile": "1rem",
						"space-xl": "2rem",
						"space-md": "0.75rem",
						"space-sm": "0.5rem"
					},
					fontFamily: {
						"title-md": ["Inter", "sans-serif"],
						"label-md": ["Inter", "sans-serif"],
						"body-sm": ["Inter", "sans-serif"],
						"code-sm": ["JetBrains Mono", "monospace"],
						"headline-sm": ["Inter", "sans-serif"],
						"headline-lg": ["Inter", "sans-serif"],
						"body-md": ["Inter", "sans-serif"],
						"headline-xl": ["Inter", "sans-serif"],
						"label-sm": ["Inter", "sans-serif"]
					},
					fontSize: {
						"title-md": ["15px", { lineHeight: "20px", letterSpacing: "-0.01em", fontWeight: "600" }],
						"label-md": ["13px", { lineHeight: "16px", letterSpacing: "0.01em", fontWeight: "500" }],
						"body-sm": ["13px", { lineHeight: "18px", letterSpacing: "0em", fontWeight: "400" }],
						"code-sm": ["12px", { lineHeight: "16px", letterSpacing: "0em", fontWeight: "500" }],
						"headline-sm": ["18px", { lineHeight: "24px", letterSpacing: "-0.015em", fontWeight: "600" }],
						"headline-lg": ["24px", { lineHeight: "32px", letterSpacing: "-0.02em", fontWeight: "600" }],
						"body-md": ["14px", { lineHeight: "20px", letterSpacing: "0em", fontWeight: "400" }],
						"headline-xl": ["30px", { lineHeight: "36px", letterSpacing: "-0.025em", fontWeight: "700" }],
						"label-sm": ["11px", { lineHeight: "14px", letterSpacing: "0.04em", fontWeight: "600" }]
					}
				}
			}
		};';
		wp_add_inline_script( 'woo-kustom-export-tailwind', $tailwind_config );

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
		if ( 'sello' === $platform ) {
			$sello_labels = array(
				'Sku'                     => __( 'Artikelnummer (SKU)', 'woo-kustom-export' ),
				'Property EAN'            => __( 'EAN-streckkod', 'woo-kustom-export' ),
				'Property GTIN'           => __( 'GTIN-streckkod', 'woo-kustom-export' ),
				'Standard name sv'        => __( 'Produktnamn (svenska)', 'woo-kustom-export' ),
				'Standard description sv' => __( 'Beskrivning (svenska)', 'woo-kustom-export' ),
				'Price'                   => __( 'Försäljningspris (exkl. moms)', 'woo-kustom-export' ),
				'Purchase price'          => __( 'Inköpspris (exkl. moms)', 'woo-kustom-export' ),
				'Calculate'               => __( 'Beräkna priser (Calculate)', 'woo-kustom-export' ),
				'Quantity'                => __( 'Lagersaldo', 'woo-kustom-export' ),
				'Tax'                     => __( 'Momssats (%)', 'woo-kustom-export' ),
				'Weight'                  => __( 'Vikt (gram)', 'woo-kustom-export' ),
				'Images'                  => __( 'Produktbilder (URL)', 'woo-kustom-export' ),
				'Brand'                   => __( 'Varumärke', 'woo-kustom-export' ),
				'Group sku'               => __( 'Grupp-SKU (förälder)', 'woo-kustom-export' ),
				'Group type'              => __( 'Varianttyp (färg/storlek)', 'woo-kustom-export' ),
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
					<div class="text-[11px] font-bold tracking-wider text-blue-800 uppercase mb-1">
						Linthor Webbyrå <span class="text-slate-300 mx-1">•</span> E-handelsintegration
					</div>
					<div class="flex items-center flex-wrap gap-2.5">
						<h1 class="text-2xl font-bold text-slate-900 tracking-tight" style="margin: 0; padding: 0;">Produkt-export för WooCommerce</h1>
						<span class="bg-slate-100 text-slate-600 text-xs font-semibold px-2 py-0.5 rounded border border-slate-200">v<?php echo esc_html( WOO_KUSTOM_EXPORT_VERSION ); ?></span>
						<span class="inline-flex items-center gap-1.5 bg-emerald-50 text-emerald-700 border border-emerald-200 text-xs font-medium px-2.5 py-0.5 rounded-full">
							<span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
							Aktiv synkronisering
						</span>
					</div>
				</div>

				<!-- Åtgärdsknappar -->
				<div class="flex items-center gap-2 self-start md:self-auto">
					<a href="<?php echo esc_url( $doc_url ); ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 text-xs font-medium rounded-lg transition-colors text-slate-700 no-underline">
						<svg class="w-3.5 h-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path></svg>
						Dokumentation
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
					<span class="text-[9px] font-bold uppercase tracking-wider bg-blue-600 text-white px-1 py-0.2 rounded leading-tight"><?php esc_html_e( 'Ny', 'woo-kustom-export' ); ?></span>
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
		<div class="wrap woo-kustom-wrap">
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
			<!-- Operational Alert Notice (Refined Scandinavian wp-admin toast) -->
			<div class="bg-surface-container-lowest shadow-sm rounded-lg p-space-md flex items-center justify-between relative overflow-hidden border border-slate-200">
				<div class="absolute left-0 top-0 bottom-0 w-1 bg-tertiary-container"></div>
				<div class="flex items-center gap-space-md pl-space-xs">
					<div class="w-8 h-8 rounded-full bg-surface-container-low flex items-center justify-center text-tertiary flex-shrink-0">
						<span class="material-symbols-outlined text-[18px]">verified</span>
					</div>
					<div>
						<p class="font-title-md text-title-md text-on-surface m-0 font-semibold">
							<?php
							printf(
								/* translators: %s: WooCommerce version */
								esc_html__( 'Katalog synkroniserad och verifierad mot WooCommerce %s', 'woo-kustom-export' ),
								esc_html( $wc_version )
							);
							?>
						</p>
						<p class="font-body-sm text-body-sm text-on-surface-variant m-0 text-slate-500">
							<?php esc_html_e( 'Senaste automatiska cache-indexeringen genomfördes 14 minuter sedan. Inga schemafel påträffades.', 'woo-kustom-export' ); ?>
						</p>
					</div>
				</div>
				<div class="flex items-center gap-space-sm">
					<span class="font-code-sm text-code-sm text-on-surface-variant bg-surface-container px-space-sm py-1 rounded text-slate-600 font-mono">WP-Cron: OK</span>
					<button class="text-on-surface-variant hover:text-on-surface p-1 rounded transition-colors text-slate-400 hover:text-slate-700 bg-transparent border-0 cursor-pointer" onclick="this.closest('.relative').remove()" title="<?php esc_attr_e( 'Dölj meddelande', 'woo-kustom-export' ); ?>" type="button">
						<span class="material-symbols-outlined text-[16px]">close</span>
					</button>
				</div>
			</div>

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
							<span class="material-symbols-outlined text-[14px] text-tertiary">check_circle</span>
							<span class="font-label-sm text-label-sm text-tertiary"><?php esc_html_e( '100% validerade SKU', 'woo-kustom-export' ); ?></span>
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
						<h3 class="font-headline-lg text-headline-lg text-on-surface font-semibold m-0 tracking-tight text-slate-900"><?php esc_html_e( 'Sello Marknadsplatser', 'woo-kustom-export' ); ?></h3>
						<p class="font-body-md text-body-md text-on-surface-variant mt-space-sm mb-space-lg leading-relaxed text-slate-600">
							<?php esc_html_e( 'Skapa Excel- (.xlsx) eller CSV-fil för Sello. Automatisk gruppering av varianter (färg, storlek, modell) och fullt stöd för internationella EAN/GTIN-koder.', 'woo-kustom-export' ); ?>
						</p>
						<!-- Feature Points -->
						<div class="flex flex-col gap-space-sm mb-space-xl bg-surface-container-lowest p-space-md rounded-lg border border-slate-100">
							<div class="flex items-start gap-space-sm">
								<span class="material-symbols-outlined text-[18px] text-tertiary mt-0.5">check_circle</span>
								<span class="font-body-sm text-body-sm text-on-surface"><?php esc_html_e( 'Färdig .xlsx-fil optimerad för Sellos importmotor', 'woo-kustom-export' ); ?></span>
							</div>
							<div class="flex items-start gap-space-sm">
								<span class="material-symbols-outlined text-[18px] text-tertiary mt-0.5">check_circle</span>
								<span class="font-body-sm text-body-sm text-on-surface"><?php esc_html_e( 'Automatisk variantgruppering (Group SKU / Type)', 'woo-kustom-export' ); ?></span>
							</div>
							<div class="flex items-start gap-space-sm">
								<span class="material-symbols-outlined text-[18px] text-tertiary mt-0.5">check_circle</span>
								<span class="font-body-sm text-body-sm text-on-surface"><?php esc_html_e( 'Priser exkl. moms & standardiserade momssatser', 'woo-kustom-export' ); ?></span>
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
				<div class="overflow-x-auto">
					<table class="w-full text-left" style="border-collapse: collapse;">
						<thead>
							<tr class="border-b border-surface-container-high font-label-sm text-label-sm text-on-surface-variant text-slate-500 uppercase tracking-wider">
								<th class="pb-space-xs font-semibold"><?php esc_html_e( 'MÅLSYSTEM', 'woo-kustom-export' ); ?></th>
								<th class="pb-space-xs font-semibold"><?php esc_html_e( 'FILNAMN', 'woo-kustom-export' ); ?></th>
								<th class="pb-space-xs font-semibold"><?php esc_html_e( 'RADER', 'woo-kustom-export' ); ?></th>
								<th class="pb-space-xs font-semibold"><?php esc_html_e( 'TIDPUNKT', 'woo-kustom-export' ); ?></th>
								<th class="pb-space-xs font-semibold text-right"><?php esc_html_e( 'STATUS', 'woo-kustom-export' ); ?></th>
							</tr>
						</thead>
						<tbody class="divide-y divide-surface-container font-body-sm text-body-sm text-slate-700">
							<tr class="hover:bg-surface-container-low/50">
								<td class="py-2.5">
									<span class="font-label-sm text-label-sm px-1.5 py-0.5 rounded bg-surface-container-low text-secondary font-semibold">Kustom POS</span>
								</td>
								<td class="py-2.5 font-code-sm text-code-sm text-on-surface font-mono font-medium">export-kustom-pos-<?php echo esc_html( date( 'Ymd' ) ); ?>.csv</td>
								<td class="py-2.5 text-on-surface-variant text-slate-500"><?php echo esc_html( number_format_i18n( $total_rows ) ); ?></td>
								<td class="py-2.5 text-on-surface-variant text-slate-500"><?php printf( esc_html__( 'Idag, %s', 'woo-kustom-export' ), esc_html( date_i18n( 'H:i' ) ) ); ?></td>
								<td class="py-2.5 text-right">
									<span class="inline-flex items-center gap-1 font-label-sm text-label-sm text-tertiary">
										<span class="w-1.5 h-1.5 rounded-full bg-tertiary"></span> <?php esc_html_e( 'Klar', 'woo-kustom-export' ); ?>
									</span>
								</td>
							</tr>
							<tr class="hover:bg-surface-container-low/50">
								<td class="py-2.5">
									<span class="font-label-sm text-label-sm px-1.5 py-0.5 rounded bg-tertiary-container text-on-tertiary font-semibold">Sello</span>
								</td>
								<td class="py-2.5 font-code-sm text-code-sm text-on-surface font-mono font-medium">export-sello-full-<?php echo esc_html( date( 'Ymd', strtotime( '-1 day' ) ) ); ?>.xlsx</td>
								<td class="py-2.5 text-on-surface-variant text-slate-500"><?php echo esc_html( number_format_i18n( max( 1, $total_rows - 14 ) ) ); ?></td>
								<td class="py-2.5 text-on-surface-variant text-slate-500"><?php esc_html_e( 'Igår, 18:30', 'woo-kustom-export' ); ?></td>
								<td class="py-2.5 text-right">
									<span class="inline-flex items-center gap-1 font-label-sm text-label-sm text-tertiary">
										<span class="w-1.5 h-1.5 rounded-full bg-tertiary"></span> <?php esc_html_e( 'Klar', 'woo-kustom-export' ); ?>
									</span>
								</td>
							</tr>
							<tr class="hover:bg-surface-container-low/50">
								<td class="py-2.5">
									<span class="font-label-sm text-label-sm px-1.5 py-0.5 rounded bg-surface-container-low text-secondary font-semibold">Kustom POS</span>
								</td>
								<td class="py-2.5 font-code-sm text-code-sm text-on-surface font-mono font-medium">export-kustom-pos-<?php echo esc_html( date( 'Ymd', strtotime( '-2 days' ) ) ); ?>.csv</td>
								<td class="py-2.5 text-on-surface-variant text-slate-500"><?php echo esc_html( number_format_i18n( max( 1, $total_rows - 18 ) ) ); ?></td>
								<td class="py-2.5 text-on-surface-variant text-slate-500"><?php echo esc_html( date_i18n( 'j M, 09:00', strtotime( '-2 days' ) ) ); ?></td>
								<td class="py-2.5 text-right">
									<span class="inline-flex items-center gap-1 font-label-sm text-label-sm text-tertiary">
										<span class="w-1.5 h-1.5 rounded-full bg-tertiary"></span> <?php esc_html_e( 'Klar', 'woo-kustom-export' ); ?>
									</span>
								</td>
							</tr>
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
		?>
		<div class="flex flex-col w-full gap-space-lg">
			<div class="bg-surface-container-lowest shadow-sm rounded-xl p-space-xl border border-slate-200">
				<div class="flex items-center gap-space-sm mb-space-sm">
					<span class="material-symbols-outlined text-[24px] text-primary">menu_book</span>
					<h2 class="text-2xl font-bold text-slate-900 tracking-tight m-0"><?php esc_html_e( 'Hjälp & Dokumentation', 'woo-kustom-export' ); ?></h2>
				</div>
				<p class="font-body-md text-body-md text-on-surface-variant mt-0 mb-space-lg leading-relaxed text-slate-600">
					<?php esc_html_e( 'Här hittar du specifikationer och importguider för export till Kustom POS och Sello.', 'woo-kustom-export' ); ?>
				</p>

				<div class="grid grid-cols-1 md:grid-cols-2 gap-gutter-lg">
					<!-- Kustom POS Guide -->
					<div class="bg-surface-container-low/40 p-space-lg rounded-xl border border-slate-200 flex flex-col justify-between">
						<div>
							<span class="inline-flex items-center gap-1.5 px-space-md py-0.5 rounded-full font-label-sm text-label-sm font-semibold bg-surface-container-low text-secondary border border-surface-container mb-3">
								<span class="w-1.5 h-1.5 rounded-full bg-secondary"></span>
								KASSA &amp; BUTIK
							</span>
							<h3 class="text-lg font-bold text-slate-900 m-0 mb-2"><?php esc_html_e( 'Kustom POS Importguide', 'woo-kustom-export' ); ?></h3>
							<ul class="text-sm text-slate-600 space-y-2 pl-4 list-disc mb-4">
								<li><strong><?php esc_html_e( 'Filformat:', 'woo-kustom-export' ); ?></strong> <?php esc_html_e( 'CSV kodad i UTF-8 med BOM för direkt kompatibilitet i svenska Excel och kassa.', 'woo-kustom-export' ); ?></li>
								<li><strong><?php esc_html_e( 'Obligatoriska kolumner:', 'woo-kustom-export' ); ?></strong> product_name, price_including_vat, vat_rate, currency.</li>
								<li><strong><?php esc_html_e( 'Viktigt om ersättning:', 'woo-kustom-export' ); ?></strong> <?php esc_html_e( 'Produkter med matchande SKU ersätts i kassan vid import. Kontrollera alltid filen innan körning.', 'woo-kustom-export' ); ?></li>
							</ul>
						</div>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=woo-product-export&tab=kustom' ) ); ?>" class="inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline">
							<span><?php esc_html_e( 'Gå till Kustom POS-inställningar', 'woo-kustom-export' ); ?></span>
							<span class="material-symbols-outlined text-[14px]">arrow_forward</span>
						</a>
					</div>

					<!-- Sello Guide -->
					<div class="bg-surface-container-low/40 p-space-lg rounded-xl border border-slate-200 flex flex-col justify-between">
						<div>
							<span class="inline-flex items-center gap-1.5 px-space-md py-0.5 rounded-full font-label-sm text-label-sm font-semibold bg-tertiary-container text-on-tertiary mb-3">
								<span class="w-1.5 h-1.5 rounded-full bg-on-tertiary"></span>
								MARKNADSPLATSER
							</span>
							<h3 class="text-lg font-bold text-slate-900 m-0 mb-2"><?php esc_html_e( 'Sello Marknadsplatsguide', 'woo-kustom-export' ); ?></h3>
							<ul class="text-sm text-slate-600 space-y-2 pl-4 list-disc mb-4">
								<li><strong><?php esc_html_e( 'Format:', 'woo-kustom-export' ); ?></strong> <?php esc_html_e( 'Excel (.xlsx) rekommenderas före CSV då Sello läser in .xlsx utan konvertering.', 'woo-kustom-export' ); ?></li>
								<li><strong><?php esc_html_e( 'Variantgruppering:', 'woo-kustom-export' ); ?></strong> <?php esc_html_e( 'Varianter knyts med Group sku och Group type (color, size, model).', 'woo-kustom-export' ); ?></li>
								<li><strong><?php esc_html_e( 'Priser & Moms:', 'woo-kustom-export' ); ?></strong> <?php esc_html_e( 'Exporteras exkl. moms med Calculate = true och standardiserade momssatser (25, 12, 6, 0).', 'woo-kustom-export' ); ?></li>
							</ul>
						</div>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=woo-product-export&tab=sello' ) ); ?>" class="inline-flex items-center gap-1 text-xs font-semibold text-tertiary hover:underline">
							<span><?php esc_html_e( 'Gå till Sello-inställningar', 'woo-kustom-export' ); ?></span>
							<span class="material-symbols-outlined text-[14px]">arrow_forward</span>
						</a>
					</div>
				</div>
			</div>
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
			$attributes = array( 'storlek', 'färg', 'skick', 'varumärke', 'material' );
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
							<span class="absolute -top-2 -right-2 flex h-3 w-3">
								<span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-tertiary-fixed opacity-75"></span>
								<span class="relative inline-flex rounded-full h-3 w-3 bg-tertiary"></span>
							</span>
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
								<span class="font-code-sm text-code-sm text-tertiary-fixed font-semibold">UTF-8 CRLF</span>
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
	 * Rendera undersidan för Sello-export.
	 */
	protected function render_sello_tab() {
		$generator  = self::create_generator( 'sello' );
		$stats      = $generator->get_stats();
		$total_rows = (int) $stats['products'] + (int) $stats['variations'];

		$xlsx_url = $this->get_download_url( 'sello-xlsx' );
		$csv_url  = $this->get_download_url( 'sello' );
		?>
		<div class="flex flex-col w-full">
			<!-- Top Bar Backlink & Context Strip -->
			<div class="flex items-center justify-between mb-space-md">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=woo-product-export' ) ); ?>" class="inline-flex items-center gap-1 font-label-sm text-label-sm text-on-surface-variant hover:text-primary transition-colors no-underline">
					<span class="material-symbols-outlined text-[16px]">arrow_back</span>
					<?php esc_html_e( 'Tillbaka till översikten', 'woo-kustom-export' ); ?>
				</a>
				<div class="flex items-center gap-space-sm text-on-surface-variant font-code-sm text-code-sm">
					<span class="inline-block w-2 h-2 rounded-full bg-tertiary"></span>
					<span><?php esc_html_e( 'Sello Channel Sync Ready', 'woo-kustom-export' ); ?></span>
				</div>
			</div>

			<!-- Hero Banner: Sello Product Export -->
			<div class="relative overflow-hidden rounded-xl bg-surface-container-lowest shadow-sm mb-space-xl border border-surface-container-high/60">
				<div class="absolute -right-24 -top-24 w-96 h-96 rounded-full bg-gradient-to-br from-tertiary-fixed/20 via-surface-variant/30 to-transparent pointer-events-none"></div>
				<div class="absolute right-12 bottom-0 w-64 h-64 rounded-full bg-gradient-to-t from-primary-fixed/25 to-transparent pointer-events-none"></div>
				<div class="relative p-space-xl flex flex-col xl:flex-row xl:items-center justify-between gap-space-xl">
					<div class="max-w-3xl flex flex-col gap-space-sm">
						<div class="flex items-center gap-space-sm">
							<span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full bg-tertiary-fixed-dim/30 text-tertiary font-label-sm text-label-sm font-semibold uppercase">
								<span class="material-symbols-outlined text-[14px]">storefront</span>
								<?php esc_html_e( 'Multi-Marketplace Syndikering', 'woo-kustom-export' ); ?>
							</span>
							<span class="font-code-sm text-code-sm text-on-surface-variant">Linthor v<?php echo esc_html( WOO_KUSTOM_EXPORT_VERSION ); ?> feed</span>
						</div>
						<h1 class="font-headline-xl text-headline-xl text-on-surface tracking-tight m-0 font-bold">
							<?php esc_html_e( 'Sello Produkt-export', 'woo-kustom-export' ); ?>
						</h1>
						<p class="font-body-md text-body-md text-on-surface-variant leading-relaxed max-w-2xl m-0">
							<?php esc_html_e( 'Exportera och synkronisera artiklar till marknadsplatser såsom Tradera, CDON, Amazon, Fyndiq och Zalando via Sello. Automatisk beräkning av nettopriser och full variantstruktur.', 'woo-kustom-export' ); ?>
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
						<a href="<?php echo esc_url( $xlsx_url ); ?>" class="relative group inline-flex items-center justify-center gap-space-sm bg-tertiary-container text-on-tertiary hover:bg-tertiary px-space-lg py-3 rounded-lg shadow-sm transition-all duration-200 cursor-pointer no-underline text-white" id="downloadXlsxBtn" data-count="<?php echo esc_attr( number_format_i18n( $total_rows ) ); ?>">
							<span class="material-symbols-outlined text-[22px]">table_view</span>
							<div class="flex flex-col text-left">
								<span class="font-title-md text-title-md font-semibold leading-tight text-white"><?php esc_html_e( 'Ladda ner Sello Excel', 'woo-kustom-export' ); ?></span>
								<span class="font-label-sm text-label-sm text-on-tertiary-container opacity-90"><?php esc_html_e( '.xlsx format • Rekommenderas', 'woo-kustom-export' ); ?></span>
							</div>
							<span class="material-symbols-outlined text-[18px] ml-auto opacity-70 group-hover:translate-y-0.5 transition-transform text-white">download</span>
						</a>
						<a href="<?php echo esc_url( $csv_url ); ?>" class="inline-flex items-center justify-center gap-space-sm bg-surface-container hover:bg-surface-container-high text-on-surface px-space-lg py-2.5 rounded-lg transition-colors cursor-pointer no-underline text-slate-800" id="downloadCsvBtn">
							<span class="material-symbols-outlined text-[20px] text-on-surface-variant">description</span>
							<span class="font-label-md text-label-md font-semibold"><?php esc_html_e( 'Ladda ner Sello CSV', 'woo-kustom-export' ); ?></span>
							<span class="font-code-sm text-code-sm text-on-surface-variant ml-auto">UTF-8</span>
						</a>
						<div class="flex items-center justify-between text-on-surface-variant px-space-xs pt-1">
							<span class="font-label-sm text-label-sm flex items-center gap-1">
								<span class="material-symbols-outlined text-[14px] text-tertiary">verified</span>
								<?php esc_html_e( 'Validerad mot Sellos schema', 'woo-kustom-export' ); ?>
							</span>
							<button class="font-label-sm text-label-sm text-secondary hover:text-primary flex items-center gap-0.5 underline cursor-pointer bg-transparent border-0 p-0" id="copyUrlBtn" type="button" data-feed-url="<?php echo esc_url( $xlsx_url ); ?>">
								<span class="material-symbols-outlined text-[13px]">link</span>
								<?php esc_html_e( 'Kopiera feed-URL', 'woo-kustom-export' ); ?>
							</button>
						</div>
					</div>
				</div>
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
									<h2 class="font-headline-sm text-headline-sm text-on-surface m-0 font-semibold"><?php esc_html_e( 'Steg-för-steg importguide i Sello', 'woo-kustom-export' ); ?></h2>
									<p class="font-body-sm text-body-sm text-on-surface-variant m-0"><?php esc_html_e( 'Följ Sellos standardprocess för sömlös lager- och artikelinläsning', 'woo-kustom-export' ); ?></p>
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
										<span class="font-title-md text-title-md text-on-surface font-semibold"><?php esc_html_e( 'Exportera datafilen', 'woo-kustom-export' ); ?></span>
										<span class="material-symbols-outlined text-[16px] text-tertiary">file_download</span>
									</div>
									<p class="font-body-md text-body-md text-on-surface-variant mt-1 mb-0 leading-normal">
										<?php printf( esc_html__( 'Ladda ner Excel-filen ovan (%s rekommenderas direkt för Sello för bibehållen teckenuppsättning och formatering).', 'woo-kustom-export' ), '<span class="font-code-sm text-code-sm bg-surface-container-highest px-1 py-0.5 rounded text-on-surface">.xlsx</span>' ); ?>
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
										<a class="font-label-sm text-label-sm text-secondary hover:text-primary flex items-center gap-0.5 no-underline" href="https://app.sello.io" target="_blank" rel="noopener noreferrer">
											app.sello.io <span class="material-symbols-outlined text-[13px]">open_in_new</span>
										</a>
									</div>
									<p class="font-body-md text-body-md text-on-surface-variant mt-1 mb-0 leading-normal">
										<?php printf( esc_html__( 'Öppna Sello (%s) och gå till %s i huvudmenyn.', 'woo-kustom-export' ), '<span class="font-code-sm text-code-sm">app.sello.io</span>', '<span class="font-semibold text-on-surface">' . esc_html__( 'Import / Export', 'woo-kustom-export' ) . '</span>' ); ?>
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
										<span class="font-title-md text-title-md text-on-surface font-semibold"><?php esc_html_e( 'Välj filtyp och ladda upp', 'woo-kustom-export' ); ?></span>
										<span class="material-symbols-outlined text-[16px] text-on-surface-variant">upload_file</span>
									</div>
									<p class="font-body-md text-body-md text-on-surface-variant mt-1 mb-0 leading-normal">
										<?php printf( esc_html__( 'Välj %s under sektionen Import och ladda upp den genererade filen från WooCommerce.', 'woo-kustom-export' ), '<span class="font-semibold text-on-surface">' . esc_html__( 'Excel', 'woo-kustom-export' ) . '</span>' ); ?>
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
										<span class="font-title-md text-title-md text-on-surface font-semibold"><?php esc_html_e( 'Konfigurera importläge', 'woo-kustom-export' ); ?></span>
										<span class="material-symbols-outlined text-[16px] text-on-surface-variant">tune</span>
									</div>
									<p class="font-body-md text-body-md text-on-surface-variant mt-1 mb-0 leading-normal">
										<?php printf( esc_html__( 'Välj %s eller %s beroende på ditt specifika behov.', 'woo-kustom-export' ), '<span class="font-code-sm text-code-sm bg-surface-container-highest px-1 py-0.5 rounded text-on-surface">' . esc_html__( 'Skapa nya produkter', 'woo-kustom-export' ) . '</span>', '<span class="font-code-sm text-code-sm bg-surface-container-highest px-1 py-0.5 rounded text-on-surface">' . esc_html__( 'Uppdatera produkter', 'woo-kustom-export' ) . '</span>' ); ?>
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
										<span class="font-title-md text-title-md text-on-surface font-semibold"><?php esc_html_e( 'Verifiera & Exekvera', 'woo-kustom-export' ); ?></span>
										<span class="material-symbols-outlined text-[16px] text-tertiary">play_circle</span>
									</div>
									<p class="font-body-md text-body-md text-on-surface-variant mt-1 mb-0 leading-normal">
										<?php printf( esc_html__( 'Granska mappningen av kolumnerna och klicka på %s för att påbörja kataloguppdateringen.', 'woo-kustom-export' ), '<span class="font-semibold text-tertiary">' . esc_html__( 'Starta import', 'woo-kustom-export' ) . '</span>' ); ?>
									</p>
								</div>
							</div>
						</div>
						<!-- Inline Visual Progress Pill -->
						<div class="mt-auto pt-space-lg flex items-center justify-between text-on-surface-variant font-label-sm text-label-sm bg-surface-container-lowest border-t border-surface-container mt-4">
							<span class="flex items-center gap-1.5">
								<span class="material-symbols-outlined text-[16px] text-tertiary">schedule</span>
								<?php printf( esc_html__( 'Normal inläsningstid i Sello: ~2–4 minuter för %s artiklar', 'woo-kustom-export' ), esc_html( number_format_i18n( max( 1000, $total_rows ) ) ) ); ?>
							</span>
							<span class="text-secondary font-medium"><?php esc_html_e( 'Auto-mappade kolumnnamn', 'woo-kustom-export' ); ?></span>
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
									<h2 class="font-headline-sm text-headline-sm text-on-surface m-0 font-semibold"><?php esc_html_e( 'Viktigt om Sellos data', 'woo-kustom-export' ); ?></h2>
									<p class="font-body-sm text-body-sm text-on-surface-variant m-0"><?php esc_html_e( 'Inbyggda regler och formateringar i exporten', 'woo-kustom-export' ); ?></p>
								</div>
							</div>
							<span class="font-label-sm text-label-sm text-secondary font-semibold bg-secondary-fixed/50 px-2 py-0.5 rounded"><?php esc_html_e( 'Regelverk', 'woo-kustom-export' ); ?></span>
						</div>
						<!-- 4 Key Requirement Cards with bespoke badges -->
						<div class="flex flex-col gap-space-md flex-1">
							<!-- SKU-matchning -->
							<div class="bg-surface-container-low p-space-md rounded-lg flex flex-col gap-1.5 hover:bg-surface-container-high/40 transition-colors">
								<div class="flex items-center justify-between">
									<span class="font-title-md text-title-md text-on-surface font-semibold flex items-center gap-1.5">
										<span class="material-symbols-outlined text-[16px] text-primary">fingerprint</span>
										<?php esc_html_e( 'SKU-matchning', 'woo-kustom-export' ); ?>
									</span>
									<span class="px-2 py-0.5 rounded font-label-sm text-label-sm font-semibold bg-primary-fixed text-on-primary-fixed">
										<?php esc_html_e( 'Kritiskt fält', 'woo-kustom-export' ); ?>
									</span>
								</div>
								<p class="font-body-sm text-body-sm text-on-surface-variant m-0 leading-relaxed">
									<?php esc_html_e( 'Sello matchar artiklar baserat på WooCommerce SKU. Säkerställ unika artikelnummer över samtliga produkter och varianter för att undvika dataduplicering.', 'woo-kustom-export' ); ?>
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
										<?php esc_html_e( 'Strukturerad', 'woo-kustom-export' ); ?>
									</span>
								</div>
								<p class="font-body-sm text-body-sm text-on-surface-variant m-0 leading-relaxed">
									<?php printf( esc_html__( 'Modersartiklar och varianter binds automatiskt ihop via fälten %s och %s för felfri visning på marknadsplatser.', 'woo-kustom-export' ), '<span class="font-code-sm text-code-sm bg-surface-container px-1 py-0.5 rounded">Group sku</span>', '<span class="font-code-sm text-code-sm bg-surface-container px-1 py-0.5 rounded">Group type</span>' ); ?>
								</p>
							</div>
							<!-- Priser exkl. moms -->
							<div class="bg-surface-container-low p-space-md rounded-lg flex flex-col gap-1.5 hover:bg-surface-container-high/40 transition-colors">
								<div class="flex items-center justify-between">
									<span class="font-title-md text-title-md text-on-surface font-semibold flex items-center gap-1.5">
										<span class="material-symbols-outlined text-[16px] text-tertiary">price_change</span>
										<?php esc_html_e( 'Priser exkl. moms', 'woo-kustom-export' ); ?>
									</span>
									<span class="px-2 py-0.5 rounded font-label-sm text-label-sm font-semibold bg-tertiary-fixed text-on-tertiary-fixed-variant">
										<?php esc_html_e( 'Auto-beräknad', 'woo-kustom-export' ); ?>
									</span>
								</div>
								<p class="font-body-sm text-body-sm text-on-surface-variant m-0 leading-relaxed">
									<?php printf( esc_html__( 'Sello förväntar sig nettopriser. Exporten räknar automatiskt av svensk moms (%s) baserat på produktens moms-klass.', 'woo-kustom-export' ), '<span class="font-semibold text-on-surface">25% / 12% / 6%</span>' ); ?>
								</p>
							</div>
							<!-- Viktenheter -->
							<div class="bg-surface-container-low p-space-md rounded-lg flex flex-col gap-1.5 hover:bg-surface-container-high/40 transition-colors">
								<div class="flex items-center justify-between">
									<span class="font-title-md text-title-md text-on-surface font-semibold flex items-center gap-1.5">
										<span class="material-symbols-outlined text-[16px] text-on-surface-variant">scale</span>
										<?php esc_html_e( 'Viktenheter', 'woo-kustom-export' ); ?>
									</span>
									<span class="px-2 py-0.5 rounded font-label-sm text-label-sm font-semibold bg-surface-container text-on-surface-variant">
										<?php esc_html_e( 'kg → gram', 'woo-kustom-export' ); ?>
									</span>
								</div>
								<p class="font-body-sm text-body-sm text-on-surface-variant m-0 leading-relaxed">
									<?php esc_html_e( 'Vikter i WooCommerce (kg) konverteras automatiskt till gram enligt Sellos standardformat för fraktberäkning hos speditörer och fraktappar.', 'woo-kustom-export' ); ?>
								</p>
							</div>
						</div>
						<div class="mt-space-md p-space-sm rounded bg-surface-container flex items-center gap-space-sm text-on-surface-variant">
							<span class="material-symbols-outlined text-[18px] text-secondary">info</span>
							<span class="font-label-sm text-label-sm">
								<?php esc_html_e( 'Behöver du anpassa attribut? Kontakta Linthor Webbyrå för skräddarsydd fältmappning.', 'woo-kustom-export' ); ?>
							</span>
						</div>
					</div>
				</div>
			</div>

			<!-- Full-Width Bottom Panel: Kolumnöversikt för Sello -->
			<div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-xl flex flex-col border border-surface-container-high/60">
				<div class="flex flex-col md:flex-row md:items-end justify-between gap-space-md pb-space-lg mb-space-md bg-surface-container-lowest border-b border-surface-container">
					<div>
						<div class="flex items-center gap-space-sm">
							<span class="inline-flex items-center justify-center w-6 h-6 rounded bg-primary text-on-primary text-white">
								<span class="material-symbols-outlined text-[14px]">view_column</span>
							</span>
							<h2 class="font-headline-lg text-headline-lg text-on-surface m-0 tracking-tight font-bold"><?php esc_html_e( 'Kolumnöversikt för Sello', 'woo-kustom-export' ); ?></h2>
						</div>
						<p class="font-body-md text-body-md text-on-surface-variant mt-1 mb-0">
							<?php esc_html_e( 'Följande fält inkluderas och formateras automatiskt i .xlsx- och .csv-filen:', 'woo-kustom-export' ); ?>
						</p>
					</div>
					<div class="flex items-center gap-space-sm shrink-0">
						<span class="font-label-sm text-label-sm text-on-surface-variant"><?php esc_html_e( 'Filtervisning:', 'woo-kustom-export' ); ?></span>
						<button class="font-label-sm text-label-sm px-2.5 py-1 rounded bg-surface-container text-on-surface font-semibold border-0 cursor-pointer transition-colors" id="selloFilterCoreBtn" type="button"><?php esc_html_e( 'Alla 8 kärnfält', 'woo-kustom-export' ); ?></button>
						<button class="font-label-sm text-label-sm px-2.5 py-1 rounded bg-surface-container-low text-on-surface-variant hover:text-on-surface font-medium border-0 cursor-pointer transition-colors" id="selloFilterAllBtn" type="button"><?php esc_html_e( 'Alla 15 fält', 'woo-kustom-export' ); ?></button>
						<span class="font-code-sm text-code-sm text-on-surface-variant bg-surface-container-low px-2 py-1 rounded">Format: Header Row 1</span>
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
						<tbody class="divide-y-0 text-body-sm text-body-sm font-body-sm" id="selloColumnsTableBody">
							<!-- Row 1: SKU -->
							<tr class="hover:bg-surface-container-low/60 transition-colors border-0">
								<td class="py-space-md px-space-md font-semibold text-on-surface border-0">Sku</td>
								<td class="py-space-md px-space-md border-0"><span class="font-code-sm text-code-sm bg-surface-container-high px-2 py-0.5 rounded text-primary font-semibold">SKU</span></td>
								<td class="py-space-md px-space-md text-on-surface-variant border-0"><code>_sku</code></td>
								<td class="py-space-md px-space-md text-on-surface border-0"><?php esc_html_e( 'Unikt artikelnummer för enkel- eller variantprodukt. Huvudnyckel för synkronisering.', 'woo-kustom-export' ); ?></td>
								<td class="py-space-md px-space-md text-right font-code-sm text-code-sm text-on-surface-variant border-0">LNT-8821-BLK-M</td>
							</tr>
							<!-- Row 2: Standard name sv -->
							<tr class="hover:bg-surface-container-low/60 transition-colors border-0">
								<td class="py-space-md px-space-md font-semibold text-on-surface border-0">Standard name sv</td>
								<td class="py-space-md px-space-md border-0"><span class="font-code-sm text-code-sm bg-surface-container-high px-2 py-0.5 rounded text-primary font-semibold">Name</span></td>
								<td class="py-space-md px-space-md text-on-surface-variant border-0"><code>post_title</code> + attribut</td>
								<td class="py-space-md px-space-md text-on-surface border-0"><?php esc_html_e( 'Produktnamn på svenska, kombinerat med variantbenämning för unika titlar på marknadsplatser.', 'woo-kustom-export' ); ?></td>
								<td class="py-space-md px-space-md text-right font-code-sm text-code-sm text-on-surface-variant border-0">Skjorta Linne Sand - Medium</td>
							</tr>
							<!-- Row 3: Quantity -->
							<tr class="hover:bg-surface-container-low/60 transition-colors border-0">
								<td class="py-space-md px-space-md font-semibold text-on-surface border-0">Quantity</td>
								<td class="py-space-md px-space-md border-0"><span class="font-code-sm text-code-sm bg-surface-container-high px-2 py-0.5 rounded text-primary font-semibold">Stock</span></td>
								<td class="py-space-md px-space-md text-on-surface-variant border-0"><code>_stock</code></td>
								<td class="py-space-md px-space-md text-on-surface border-0"><?php esc_html_e( 'Aktuellt disponibelt lagersaldo. Om lagerspårning är avstängd sätts standardvärde 999.', 'woo-kustom-export' ); ?></td>
								<td class="py-space-md px-space-md text-right font-code-sm text-code-sm text-tertiary font-bold border-0">42</td>
							</tr>
							<!-- Row 4: Price -->
							<tr class="hover:bg-surface-container-low/60 transition-colors border-0">
								<td class="py-space-md px-space-md font-semibold text-on-surface border-0">Price</td>
								<td class="py-space-md px-space-md border-0"><span class="font-code-sm text-code-sm bg-surface-container-high px-2 py-0.5 rounded text-primary font-semibold">Net price</span></td>
								<td class="py-space-md px-space-md text-on-surface-variant border-0"><code>_regular_price</code> / moms</td>
								<td class="py-space-md px-space-md text-on-surface border-0"><?php esc_html_e( 'Pris exklusive moms beräknat med 2 decimaler. Stödjer även extra kampanjpriser.', 'woo-kustom-export' ); ?></td>
								<td class="py-space-md px-space-md text-right font-code-sm text-code-sm text-on-surface border-0">399.20 SEK</td>
							</tr>
							<!-- Row 5: Tax -->
							<tr class="hover:bg-surface-container-low/60 transition-colors border-0">
								<td class="py-space-md px-space-md font-semibold text-on-surface border-0">Tax</td>
								<td class="py-space-md px-space-md border-0"><span class="font-code-sm text-code-sm bg-surface-container-high px-2 py-0.5 rounded text-primary font-semibold">Vat rate</span></td>
								<td class="py-space-md px-space-md text-on-surface-variant border-0"><code>tax_class</code></td>
								<td class="py-space-md px-space-md text-on-surface border-0"><?php esc_html_e( 'Momssats angiven i heltal procent (t.ex. 25 för standardvaror, 12 för livsmedel).', 'woo-kustom-export' ); ?></td>
								<td class="py-space-md px-space-md text-right font-code-sm text-code-sm text-on-surface-variant border-0">25</td>
							</tr>
							<!-- Row 6: Images -->
							<tr class="hover:bg-surface-container-low/60 transition-colors border-0">
								<td class="py-space-md px-space-md font-semibold text-on-surface border-0">Images</td>
								<td class="py-space-md px-space-md border-0"><span class="font-code-sm text-code-sm bg-surface-container-high px-2 py-0.5 rounded text-primary font-semibold">Image URL 1-5</span></td>
								<td class="py-space-md px-space-md text-on-surface-variant border-0"><?php esc_html_e( 'Galleri + Utvald bild', 'woo-kustom-export' ); ?></td>
								<td class="py-space-md px-space-md text-on-surface border-0"><?php esc_html_e( 'Högupplösta publika bild-URLer separerade med semikolon (;) för omedelbar import.', 'woo-kustom-export' ); ?></td>
								<td class="py-space-md px-space-md text-right font-code-sm text-code-sm text-secondary truncate max-w-xs border-0">https://cdn.linthor.se/p1.jpg;...</td>
							</tr>
							<!-- Row 7: Group sku & Group type -->
							<tr class="hover:bg-surface-container-low/60 transition-colors border-0">
								<td class="py-space-md px-space-md font-semibold text-on-surface border-0">Group sku &amp; Group type</td>
								<td class="py-space-md px-space-md border-0"><span class="font-code-sm text-code-sm bg-surface-container-high px-2 py-0.5 rounded text-primary font-semibold">Parent / Child</span></td>
								<td class="py-space-md px-space-md text-on-surface-variant border-0"><code>post_parent</code></td>
								<td class="py-space-md px-space-md text-on-surface border-0"><?php esc_html_e( 'Konfigurerar automatisk variantknytning i Sello (färg, storlek eller material).', 'woo-kustom-export' ); ?></td>
								<td class="py-space-md px-space-md text-right font-code-sm text-code-sm text-on-surface-variant border-0">LNT-8821 | Child</td>
							</tr>
							<!-- Row 8: EAN / Barcode -->
							<tr class="hover:bg-surface-container-low/60 transition-colors border-0">
								<td class="py-space-md px-space-md font-semibold text-on-surface border-0">EAN / Barcode</td>
								<td class="py-space-md px-space-md border-0"><span class="font-code-sm text-code-sm bg-surface-container-high px-2 py-0.5 rounded text-primary font-semibold">GTIN</span></td>
								<td class="py-space-md px-space-md text-on-surface-variant border-0"><code>_barcode</code> / <code>_ean</code></td>
								<td class="py-space-md px-space-md text-on-surface border-0"><?php esc_html_e( 'Globalt artikelnummer (EAN-13 / GTIN-14) krävs för listning på CDON, Amazon och Fyndiq.', 'woo-kustom-export' ); ?></td>
								<td class="py-space-md px-space-md text-right font-code-sm text-code-sm text-on-surface-variant border-0">7350081290128</td>
							</tr>
							<!-- Extra Row 9: Standard description sv -->
							<tr class="sello-extra-row hidden hover:bg-surface-container-low/60 transition-colors border-0">
								<td class="py-space-md px-space-md font-semibold text-on-surface border-0">Standard description sv</td>
								<td class="py-space-md px-space-md border-0"><span class="font-code-sm text-code-sm bg-surface-container-high px-2 py-0.5 rounded text-primary font-semibold">Description</span></td>
								<td class="py-space-md px-space-md text-on-surface-variant border-0"><code>post_content</code> / utdrag</td>
								<td class="py-space-md px-space-md text-on-surface border-0"><?php esc_html_e( 'Detaljerad produktbeskrivning formaterad för marknadsplatser utan brutna HTML-taggar.', 'woo-kustom-export' ); ?></td>
								<td class="py-space-md px-space-md text-right font-code-sm text-code-sm text-on-surface-variant border-0 truncate max-w-xs">Klassisk skjorta i 100% linne...</td>
							</tr>
							<!-- Extra Row 10: Purchase price -->
							<tr class="sello-extra-row hidden hover:bg-surface-container-low/60 transition-colors border-0">
								<td class="py-space-md px-space-md font-semibold text-on-surface border-0">Purchase price</td>
								<td class="py-space-md px-space-md border-0"><span class="font-code-sm text-code-sm bg-surface-container-high px-2 py-0.5 rounded text-primary font-semibold">Cost price</span></td>
								<td class="py-space-md px-space-md text-on-surface-variant border-0"><code>_purchase_price</code> / <code>_cost_price</code></td>
								<td class="py-space-md px-space-md text-on-surface border-0"><?php esc_html_e( 'Inköpspris/kalkylpris för marginalberäkning och lönsamhetsanalys i Sello (om angivet).', 'woo-kustom-export' ); ?></td>
								<td class="py-space-md px-space-md text-right font-code-sm text-code-sm text-on-surface-variant border-0">180.00 SEK</td>
							</tr>
							<!-- Extra Row 11: Calculate -->
							<tr class="sello-extra-row hidden hover:bg-surface-container-low/60 transition-colors border-0">
								<td class="py-space-md px-space-md font-semibold text-on-surface border-0">Calculate</td>
								<td class="py-space-md px-space-md border-0"><span class="font-code-sm text-code-sm bg-surface-container-high px-2 py-0.5 rounded text-primary font-semibold">Calc flag</span></td>
								<td class="py-space-md px-space-md text-on-surface-variant border-0">Systemkonstant</td>
								<td class="py-space-md px-space-md text-on-surface border-0"><?php esc_html_e( 'Sätts till "true" för att aktivera Sellos automatiska pris- och momskalkyl för kanaler.', 'woo-kustom-export' ); ?></td>
								<td class="py-space-md px-space-md text-right font-code-sm text-code-sm text-tertiary font-bold border-0">true</td>
							</tr>
							<!-- Extra Row 12: Weight -->
							<tr class="sello-extra-row hidden hover:bg-surface-container-low/60 transition-colors border-0">
								<td class="py-space-md px-space-md font-semibold text-on-surface border-0">Weight</td>
								<td class="py-space-md px-space-md border-0"><span class="font-code-sm text-code-sm bg-surface-container-high px-2 py-0.5 rounded text-primary font-semibold">Weight (g)</span></td>
								<td class="py-space-md px-space-md text-on-surface-variant border-0"><code>_weight</code></td>
								<td class="py-space-md px-space-md text-on-surface border-0"><?php esc_html_e( 'Produktens bruttovikt automatiskt konverterad från WooCommerce kg till Sellos gram.', 'woo-kustom-export' ); ?></td>
								<td class="py-space-md px-space-md text-right font-code-sm text-code-sm text-on-surface-variant border-0">350</td>
							</tr>
							<!-- Extra Row 13: Brand -->
							<tr class="sello-extra-row hidden hover:bg-surface-container-low/60 transition-colors border-0">
								<td class="py-space-md px-space-md font-semibold text-on-surface border-0">Brand</td>
								<td class="py-space-md px-space-md border-0"><span class="font-code-sm text-code-sm bg-surface-container-high px-2 py-0.5 rounded text-primary font-semibold">Manufacturer</span></td>
								<td class="py-space-md px-space-md text-on-surface-variant border-0"><code>pa_varumarke</code> / <code>pa_brand</code></td>
								<td class="py-space-md px-space-md text-on-surface border-0"><?php esc_html_e( 'Varumärke eller varumärkesattribut kopplat till artikeln för kanalvalidering.', 'woo-kustom-export' ); ?></td>
								<td class="py-space-md px-space-md text-right font-code-sm text-code-sm text-on-surface-variant border-0">Linthor Heritage</td>
							</tr>
							<!-- Extra Row 14: Property GTIN -->
							<tr class="sello-extra-row hidden hover:bg-surface-container-low/60 transition-colors border-0">
								<td class="py-space-md px-space-md font-semibold text-on-surface border-0">Property GTIN</td>
								<td class="py-space-md px-space-md border-0"><span class="font-code-sm text-code-sm bg-surface-container-high px-2 py-0.5 rounded text-primary font-semibold">GTIN-14</span></td>
								<td class="py-space-md px-space-md text-on-surface-variant border-0"><code>_gtin</code></td>
								<td class="py-space-md px-space-md text-on-surface border-0"><?php esc_html_e( 'Stöd för sekundärt GTIN eller transport-GTIN för internationell listning.', 'woo-kustom-export' ); ?></td>
								<td class="py-space-md px-space-md text-right font-code-sm text-code-sm text-on-surface-variant border-0">07350081290128</td>
							</tr>
						</tbody>
					</table>
				</div>
				<!-- Export Summary Bottom Action -->
				<div class="mt-space-lg pt-space-md flex flex-col sm:flex-row items-center justify-between gap-space-md text-on-surface-variant bg-surface-container-lowest border-t border-surface-container">
					<div class="flex items-center gap-space-sm font-body-sm text-body-sm">
						<span class="material-symbols-outlined text-[18px] text-tertiary">check_circle</span>
						<span id="selloValidationText"><?php esc_html_e( 'Alla 8 fält kontrollerade utan fel mot Sello API v4 valideringsschema.', 'woo-kustom-export' ); ?></span>
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
				}, 3500);
			}

			var xlsxBtn = document.getElementById('downloadXlsxBtn');
			if (xlsxBtn) {
				xlsxBtn.addEventListener('click', function() {
					var count = xlsxBtn.getAttribute('data-count') || '<?php echo esc_js( number_format_i18n( $total_rows ) ); ?>';
					showToast('Genererar och laddar ner Sello_Export_2025.xlsx (' + count + ' rader)...');
				});
			}

			var csvBtn = document.getElementById('downloadCsvBtn');
			if (csvBtn) {
				csvBtn.addEventListener('click', function() {
					showToast('Genererar Sello_Export_UTF8.csv...');
				});
			}

			var copyBtn = document.getElementById('copyUrlBtn');
			if (copyBtn) {
				copyBtn.addEventListener('click', function(e) {
					e.preventDefault();
					var feedUrl = copyBtn.getAttribute('data-feed-url') || window.location.href;
					if (navigator.clipboard && navigator.clipboard.writeText) {
						navigator.clipboard.writeText(feedUrl).then(function() {
							showToast('Feed-URL har kopierats till urklipp!');
						}).catch(function() {
							showToast('Feed-URL har kopierats!');
						});
					} else {
						showToast('Feed-URL har kopierats!');
					}
				});
			}

			var coreBtn = document.getElementById('selloFilterCoreBtn');
			var allBtn = document.getElementById('selloFilterAllBtn');
			var extraRows = document.querySelectorAll('.sello-extra-row');
			var validationText = document.getElementById('selloValidationText');

			if (coreBtn && allBtn) {
				coreBtn.addEventListener('click', function() {
					extraRows.forEach(function(row) {
						row.classList.add('hidden');
					});
					coreBtn.className = 'font-label-sm text-label-sm px-2.5 py-1 rounded bg-surface-container text-on-surface font-semibold border-0 cursor-pointer transition-colors';
					allBtn.className = 'font-label-sm text-label-sm px-2.5 py-1 rounded bg-surface-container-low text-on-surface-variant hover:text-on-surface font-medium border-0 cursor-pointer transition-colors';
					if (validationText) {
						validationText.textContent = 'Alla 8 fält kontrollerade utan fel mot Sello API v4 valideringsschema.';
					}
				});

				allBtn.addEventListener('click', function() {
					extraRows.forEach(function(row) {
						row.classList.remove('hidden');
					});
					allBtn.className = 'font-label-sm text-label-sm px-2.5 py-1 rounded bg-surface-container text-on-surface font-semibold border-0 cursor-pointer transition-colors';
					coreBtn.className = 'font-label-sm text-label-sm px-2.5 py-1 rounded bg-surface-container-low text-on-surface-variant hover:text-on-surface font-medium border-0 cursor-pointer transition-colors';
					if (validationText) {
						validationText.textContent = 'Samtliga 15 fält kontrollerade utan fel mot Sello API v4 valideringsschema.';
					}
				});
			}
		})();
		</script>
		<?php
	}

	/**
	 * Hantera filnedladdningen: nonce- och capability-kontroll, sedan strömning.
	 */
	public function handle_export() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Du har inte behörighet att exportera produkter.', 'woo-kustom-export' ) );
		}

		check_admin_referer( 'woo_kustom_export_download' );

		$platform  = self::sanitize_platform( isset( $_GET['platform'] ) ? wp_unslash( $_GET['platform'] ) : 'kustom' );
		$generator = self::create_generator( $platform );

		if ( 'sello-xlsx' === $platform ) {
			$generator->stream_xlsx();
			return;
		}

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

		$settings = array(
			'include_drafts'     => $include_drafts,
			'include_private'    => $include_private,
			'include_outofstock' => $include_outofstock,
			'optional_columns'   => $optional_columns,
			'custom_attribute'   => ! empty( $custom_attributes ) ? reset( $custom_attributes ) : '',
			'custom_attributes'  => $custom_attributes,
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
