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
	 * Tillgängliga exportplattformar och deras CSV-generatorer.
	 *
	 * @return array<string,array{label:string,generator:string}>
	 */
	public static function get_platforms() {
		return array(
			'kustom' => array(
				'label'     => __( 'Kustom POS', 'woo-kustom-export' ),
				'generator' => 'WOO_Kustom_CSV_Generator',
			),
			'sello'  => array(
				'label'     => __( 'Sello', 'woo-kustom-export' ),
				'generator' => 'WOO_Kustom_Sello_CSV_Generator',
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
	 * Skapa CSV-generator för en plattform.
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
			__( 'Exportera produkter (Kustom & Sello)', 'woo-kustom-export' ),
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
	 * Rendera adminsida i WordPress-adminens egna design-språk, med WCAG-anpassad struktur.
	 */
	public function render_admin_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Du har inte behörighet att använda denna sida.', 'woo-kustom-export' ) );
		}

		$settings  = self::get_settings();
		$generator = self::create_generator( 'kustom' );
		$stats     = $generator->get_stats();

		$download_urls = array();
		foreach ( self::get_platforms() as $key => $platform ) {
			$download_urls[ $key ] = wp_nonce_url(
				add_query_arg(
					array(
						'action'   => 'woo_kustom_export_download',
						'platform' => $key,
					),
					admin_url( 'admin-post.php' )
				),
				'woo_kustom_export_download'
			);
		}

		$total_rows = (int) $stats['products'] + (int) $stats['variations'];
		?>
		<div class="wrap woo-kustom-wrap">
			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Exportfiltren har sparats.', 'woo-kustom-export' ); ?></p></div>
			<?php endif; ?>

			<header class="woo-kustom-hero">
				<div class="woo-kustom-hero__inner">
					<h1 class="woo-kustom-hero__title"><?php esc_html_e( 'Exportera produkter (Kustom & Sello)', 'woo-kustom-export' ); ?></h1>
					<p class="woo-kustom-hero__intro">
						<?php esc_html_e( 'Skapa CSV-filer redo för bulkimport av produkter i Kustom POS och Sello.', 'woo-kustom-export' ); ?>
					</p>

					<dl class="woo-kustom-metrics">
						<div class="woo-kustom-metric">
							<dt class="woo-kustom-metric__label"><?php esc_html_e( 'Enkla produkter', 'woo-kustom-export' ); ?></dt>
							<dd class="woo-kustom-metric__value"><?php echo esc_html( number_format_i18n( (int) $stats['products'] ) ); ?></dd>
						</div>
						<div class="woo-kustom-metric">
							<dt class="woo-kustom-metric__label"><?php esc_html_e( 'Varianter', 'woo-kustom-export' ); ?></dt>
							<dd class="woo-kustom-metric__value"><?php echo esc_html( number_format_i18n( (int) $stats['variations'] ) ); ?></dd>
						</div>
						<div class="woo-kustom-metric">
							<dt class="woo-kustom-metric__label"><?php esc_html_e( 'Rader i filen', 'woo-kustom-export' ); ?></dt>
							<dd class="woo-kustom-metric__value"><?php echo esc_html( number_format_i18n( $total_rows ) ); ?></dd>
						</div>
					</dl>

					<?php if ( 0 === $total_rows ) : ?>
						<p class="woo-kustom-hero__status woo-kustom-hero__status--empty">
							<?php esc_html_e( 'Inga produkter matchar de aktuella filtren. Justera filtren nedan eller lägg till produkter i butiken.', 'woo-kustom-export' ); ?>
						</p>
						<p class="woo-kustom-hero__action">
							<span class="woo-kustom-btn woo-kustom-btn--primary is-disabled" aria-disabled="true">
								<?php esc_html_e( 'Inga produkter att exportera', 'woo-kustom-export' ); ?>
							</span>
						</p>
					<?php else : ?>
						<p class="woo-kustom-hero__action">
							<?php foreach ( self::get_platforms() as $key => $platform ) : ?>
								<a href="<?php echo esc_url( $download_urls[ $key ] ); ?>" class="woo-kustom-btn woo-kustom-btn--primary">
									<?php echo esc_html( sprintf( __( 'Exportera produkter till %s CSV', 'woo-kustom-export' ), $platform['label'] ) ); ?>
								</a>
							<?php endforeach; ?>
						</p>
					<?php endif; ?>
				</div>
			</header>

			<div class="woo-kustom-alert" role="note">
				<p class="woo-kustom-alert__title"><?php esc_html_e( 'Viktigt', 'woo-kustom-export' ); ?></p>
				<p class="woo-kustom-alert__text">
					<?php esc_html_e( 'När CSV-filen importeras till Kustom ersätts befintliga produkter i samma Kustom-butik. Vid import till Sello matchas produkter mot SKU. Granska filen innan du importerar den.', 'woo-kustom-export' ); ?>
				</p>
			</div>

			<div class="woo-kustom-panels">
				<section class="woo-kustom-panel" aria-labelledby="woo-kustom-filter-heading">
					<h2 id="woo-kustom-filter-heading" class="woo-kustom-panel__title"><?php esc_html_e( 'Exportfilter', 'woo-kustom-export' ); ?></h2>
					<p class="woo-kustom-panel__text">
						<?php esc_html_e( 'Standard är att endast publicerade produkter i lager exporteras. Filtren gäller båda plattformarna.', 'woo-kustom-export' ); ?>
					</p>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="woo-kustom-form">
						<input type="hidden" name="action" value="woo_kustom_export_save_settings">
						<?php wp_nonce_field( 'woo_kustom_export_save_settings' ); ?>

						<fieldset class="woo-kustom-fieldset">
							<legend class="screen-reader-text"><?php esc_html_e( 'Välj vilka produkter som ska inkluderas i exporten.', 'woo-kustom-export' ); ?></legend>

							<div class="woo-kustom-option">
								<input type="checkbox" id="woo-kustom-include-drafts" name="woo_kustom_export[include_drafts]" value="1" <?php checked( ! empty( $settings['include_drafts'] ) ); ?>>
								<label for="woo-kustom-include-drafts" class="woo-kustom-option__label"><?php esc_html_e( 'Inkludera utkast', 'woo-kustom-export' ); ?></label>
							</div>

							<div class="woo-kustom-option">
								<input type="checkbox" id="woo-kustom-include-private" name="woo_kustom_export[include_private]" value="1" <?php checked( ! empty( $settings['include_private'] ) ); ?>>
								<label for="woo-kustom-include-private" class="woo-kustom-option__label"><?php esc_html_e( 'Inkludera privata produkter', 'woo-kustom-export' ); ?></label>
							</div>

							<div class="woo-kustom-option">
								<input type="checkbox" id="woo-kustom-include-outofstock" name="woo_kustom_export[include_outofstock]" value="1" <?php checked( ! empty( $settings['include_outofstock'] ) ); ?>>
								<label for="woo-kustom-include-outofstock" class="woo-kustom-option__label"><?php esc_html_e( 'Inkludera produkter ur lager', 'woo-kustom-export' ); ?></label>
							</div>
						</fieldset>

						<button type="submit" class="woo-kustom-btn woo-kustom-btn--secondary"><?php esc_html_e( 'Spara filter', 'woo-kustom-export' ); ?></button>
					</form>
				</section>

				<?php foreach ( self::get_platforms() as $key => $platform ) : ?>
					<?php $platform_generator = self::create_generator( $key ); ?>
					<section class="woo-kustom-panel" aria-labelledby="woo-kustom-columns-heading-<?php echo esc_attr( $key ); ?>">
						<h2 id="woo-kustom-columns-heading-<?php echo esc_attr( $key ); ?>" class="woo-kustom-panel__title"><?php echo esc_html( sprintf( __( 'Kolumner i exporten (%s)', 'woo-kustom-export' ), $platform['label'] ) ); ?></h2>
						<ul class="woo-kustom-columns">
							<?php foreach ( $platform_generator->collect_columns() as $column ) : ?>
								<li class="woo-kustom-column"><code><?php echo esc_html( $column ); ?></code></li>
							<?php endforeach; ?>
						</ul>
						<p class="woo-kustom-panel__text">
							<?php
							printf(
								// translators: 1: Plattformens namn.
								esc_html__( 'Kolumnnamnen följer %s officiella importdokumentation.', 'woo-kustom-export' ),
								esc_html( $platform['label'] )
							);
							?>
						</p>
					</section>
				<?php endforeach; ?>
			</div>

			<p class="woo-kustom-footer">
				<?php
				printf(
					// translators: 1: URL till Linthor Webbyrå.
					esc_html__( 'WooCommerce Kustom Product Export är utvecklad av %1$s.', 'woo-kustom-export' ),
					'<a href="https://linthor.com" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Linthor Webbyrå', 'woo-kustom-export' ) . '</a>'
				);
				?>
			</p>
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

		$platform = self::sanitize_platform( isset( $_GET['platform'] ) ? wp_unslash( $_GET['platform'] ) : 'kustom' );

		$generator = self::create_generator( $platform );
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
