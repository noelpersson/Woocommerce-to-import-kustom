<?php
/**
 * CSV-generator för Sellos importformat anpassat för Tradera butiksförsäljning.
 *
 * Genererar CSV-rader med Sellos officiella kolumnnamn för Tradera fastprisbutik:
 * Sku, Standard name sv, Standard description sv, Tradera price store, Purchase price,
 * Quantity, Tax, Weight, Images, Brand, Group sku, Group type.
 *
 * Varje enkel produkt och varje produktvariation exporteras som en egen rad.
 * Priser mappas till Tradera price store som heltal utan valuta (ex. 495), och
 * Price samt Calculate utelämnas för att undvika Sellos automatiska prisberäkning.
 *
 * @package Woo_Kustom_Export
 */

defined( 'ABSPATH' ) || exit;

/**
 * Klass WOO_Sello_CSV_Generator
 *
 * Hämtar produkter via WooCommerce API:er och genererar CSV-rader i Sellos Tradera-format.
 */
class WOO_Sello_CSV_Generator {

	/**
	 * Inställningar för exporten.
	 *
	 * @var array
	 */
	protected $settings;

	/**
	 * Giltiga momssatser i Sello.
	 *
	 * @var int[]
	 */
	protected $valid_tax_rates = array( 25, 12, 6, 0 );

	/**
	 * Attributnamn (i gemener) som accepteras som grupptyp i Sello.
	 *
	 * @var string[]
	 */
	protected $group_types = array( 'color', 'size', 'model' );

	/**
	 * Konstruktor.
	 *
	 * @param array $settings Exportinställningar.
	 */
	public function __construct( $settings = array() ) {
		$defaults = array(
			'include_drafts'          => 0,
			'include_private'         => 0,
			'include_outofstock'      => 0,
			'sello_columns'           => array(),
			'sello_vintage_condition' => 'Gott skick',
			'sello_default_condition' => 'Oanvänt',
			'sello_strip_links'       => 1,
			'sello_strip_domains'     => 'glasprinsen.se, glasprinsen',
			'sello_category_mode'     => 'woocommerce',
			'sello_custom_category'   => 'importerad',
		);

		$this->settings = wp_parse_args( (array) $settings, $defaults );
	}

	/**
	 * Samtliga tillgängliga standardkolumner för Sello/Tradera.
	 *
	 * @return string[]
	 */
	public static function get_all_columns_list() {
		return array(
			'Sku',
			'Standard name sv',
			'Standard description sv',
			'Tradera category',
			'Category',
			'Tradera price store',
			'Purchase price',
			'Quantity',
			'Tax',
			'Weight',
			'Images',
			'Brand',
			'Group sku',
			'Group type',
			'Condition',
		);
	}

	/**
	 * Hämta alla tillgängliga varumärken i WooCommerce-butiken med antal produkter.
	 *
	 * Söker igenom:
	 * 1. Kända varumärkestaxonomier (pa_brand, pa_varumarke, yith_product_brand m.fl.)
	 * 2. Dynamiskt registrerade WooCommerce-attribut med etikett brand/varumärke
	 * 3. Fallback mot postmeta om inga taxonomier används
	 *
	 * @return array<string,int> Associativ array med varumärkesnamn och antal artiklar, sorterade alfabetiskt.
	 */
	public static function get_available_store_brands() {
		$brands           = array();
		$known_taxonomies = array( 'pa_brand', 'pa_varumarke', 'pa_marke', 'yith_product_brand', 'pwb-brand', 'brand' );

		// Undersök om ytterligare pa_-attribut i butiken representerar varumärke
		if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
			$attribute_taxonomies = wc_get_attribute_taxonomies();
			if ( ! empty( $attribute_taxonomies ) ) {
				foreach ( $attribute_taxonomies as $tax ) {
					$label_clean = strtolower( trim( (string) $tax->attribute_label ) );
					if ( in_array( $label_clean, array( 'brand', 'varumärke', 'varumarke', 'märke', 'marke', 'tillverkare' ), true ) ) {
						$tax_name = wc_attribute_taxonomy_name( $tax->attribute_name );
						if ( ! in_array( $tax_name, $known_taxonomies, true ) ) {
							$known_taxonomies[] = $tax_name;
						}
					}
				}
			}
		}

		foreach ( $known_taxonomies as $taxonomy ) {
			if ( taxonomy_exists( $taxonomy ) ) {
				$terms = get_terms(
					array(
						'taxonomy'   => $taxonomy,
						'hide_empty' => false,
					)
				);
				if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
					foreach ( $terms as $term ) {
						$name = trim( (string) $term->name );
						if ( '' !== $name ) {
							$count = (int) $term->count;
							if ( ! isset( $brands[ $name ] ) ) {
								$brands[ $name ] = $count;
							} else {
								$brands[ $name ] += $count;
							}
						}
					}
				}
			}
		}

		// Fallback till vanliga postmeta-nycklar om inga termer hittades via taxonomier
		if ( empty( $brands ) ) {
			global $wpdb;
			if ( isset( $wpdb ) && isset( $wpdb->postmeta ) ) {
				$meta_results = $wpdb->get_results(
					"SELECT meta_value, COUNT(*) as cnt 
					 FROM {$wpdb->postmeta} 
					 WHERE meta_key IN ('_brand', 'brand', '_product_brand', 'product_brand', '_varumarke', 'varumarke') 
					   AND meta_value != '' 
					 GROUP BY meta_value 
					 ORDER BY cnt DESC 
					 LIMIT 300"
				);
				if ( ! empty( $meta_results ) ) {
					foreach ( $meta_results as $row ) {
						$b_name = trim( (string) $row->meta_value );
						if ( '' !== $b_name && ! isset( $brands[ $b_name ] ) ) {
							$brands[ $b_name ] = (int) $row->cnt;
						}
					}
				}
			}
		}

		ksort( $brands, SORT_NATURAL | SORT_FLAG_CASE );
		return $brands;
	}

	/**
	 * Metadata och etiketter för valbara Sello-kolumner.
	 *
	 * @return array<string,array{label:string,desc:string,code:string,required:bool}>
	 */
	public static function get_supported_columns_meta() {
		return array(
			'Sku'                     => array(
				'label'      => __( 'Artikelnummer', 'woo-kustom-export' ),
				'desc'       => __( 'Unikt artikelnummer (SKU) för varje enkel produkt och variation. Kritiskt för matchning.', 'woo-kustom-export' ),
				'code'       => 'Sku',
				'sello_code' => 'SKU',
				'source'     => '_sku',
				'example'    => 'LNT-8821-BLK-M',
				'required'   => true,
			),
			'Standard name sv'        => array(
				'label'      => __( 'Produktnamn', 'woo-kustom-export' ),
				'desc'       => __( 'Produktnamn på svenska för butik och marknadsplatser.', 'woo-kustom-export' ),
				'code'       => 'Standard name sv',
				'sello_code' => 'Name',
				'source'     => 'post_title',
				'example'    => 'Skjorta Linne Sand',
				'required'   => false,
			),
			'Standard description sv' => array(
				'label'      => __( 'Produktbeskrivning', 'woo-kustom-export' ),
				'desc'       => __( 'Ren textbeskrivning utan HTML-taggar eller kortkoder. Behåller styckebrytningar.', 'woo-kustom-export' ),
				'code'       => 'Standard description sv',
				'sello_code' => 'Description',
				'source'     => 'post_content / excerpt',
				'example'    => 'Klassisk linneskjorta med rak passform...',
				'required'   => false,
			),
			'Tradera category'        => array(
				'label'      => __( 'Tradera-kategori (Tradera category)', 'woo-kustom-export' ),
				'desc'       => __( 'Sellos officiella kolumn för Tradera-kategori. Sätter produktens kategori så att marknadsplatsegenskaper (t.ex. Tradera skick) aktiveras under Details.', 'woo-kustom-export' ),
				'code'       => 'Tradera category',
				'sello_code' => 'Tradera category',
				'source'     => 'Tradera kategori-ID / meta / heuristik',
				'example'    => '341181',
				'required'   => true,
			),
			'Category'                => array(
				'label'      => __( 'Intern kategori (Category)', 'woo-kustom-export' ),
				'desc'       => __( 'Sellos allmänna interna kategorifält för sortering och mappning inom Sello.', 'woo-kustom-export' ),
				'code'       => 'Category',
				'sello_code' => 'Category',
				'source'     => 'product_cat',
				'example'    => 'Belysning > Kristallkronor',
				'required'   => false,
			),
			'Tradera price store'     => array(
				'label'      => __( 'Tradera butikspris', 'woo-kustom-export' ),
				'desc'       => __( 'Fast försäljningspris i heltal utan valuta. Ersätter Price och Calculate.', 'woo-kustom-export' ),
				'code'       => 'Tradera price store',
				'sello_code' => 'Tradera Price',
				'source'     => '_regular_price / aktivt pris',
				'example'    => '495',
				'required'   => false,
			),
			'Purchase price'          => array(
				'label'      => __( 'Inköpspris', 'woo-kustom-export' ),
				'desc'       => __( 'Inköpspris/kalkylpris om angivet i WooCommerce.', 'woo-kustom-export' ),
				'code'       => 'Purchase price',
				'sello_code' => 'Cost price',
				'source'     => '_purchase_price / _cost',
				'example'    => '180',
				'required'   => false,
			),
			'Quantity'                => array(
				'label'      => __( 'Lagersaldo', 'woo-kustom-export' ),
				'desc'       => __( 'Aktuellt lagersaldo som heltal. Tomt om lagerhantering är inaktiv.', 'woo-kustom-export' ),
				'code'       => 'Quantity',
				'sello_code' => 'Stock',
				'source'     => '_stock',
				'example'    => '42',
				'required'   => false,
			),
			'Tax'                     => array(
				'label'      => __( 'Momssats', 'woo-kustom-export' ),
				'desc'       => __( 'Momssats i procent som heltal (normalt 25 för Sverige, 12 för livsmedel).', 'woo-kustom-export' ),
				'code'       => 'Tax',
				'sello_code' => 'Vat rate',
				'source'     => 'tax_class',
				'example'    => '25',
				'required'   => false,
			),
			'Weight'                  => array(
				'label'      => __( 'Vikt', 'woo-kustom-export' ),
				'desc'       => __( 'Produktens vikt automatiskt konverterad till gram.', 'woo-kustom-export' ),
				'code'       => 'Weight',
				'sello_code' => 'Weight (g)',
				'source'     => '_weight',
				'example'    => '350',
				'required'   => false,
			),
			'Images'                  => array(
				'label'      => __( 'Produktbilder', 'woo-kustom-export' ),
				'desc'       => __( 'Fullständiga https:// bild-URL:er separerade med komma.', 'woo-kustom-export' ),
				'code'       => 'Images',
				'sello_code' => 'Image URLs',
				'source'     => 'Bild- & galleri-ID:n',
				'example'    => 'https://butik.se/img1.jpg,https://butik.se/img2.jpg',
				'required'   => false,
			),
			'Brand'                   => array(
				'label'      => __( 'Varumärke', 'woo-kustom-export' ),
				'desc'       => __( 'Varumärke hämtat från taxonomi pa_brand eller attribut.', 'woo-kustom-export' ),
				'code'       => 'Brand',
				'sello_code' => 'Manufacturer',
				'source'     => 'pa_brand / attribut',
				'example'    => 'Linthor Heritage',
				'required'   => false,
			),
			'Group sku'               => array(
				'label'      => __( 'Grupp-SKU', 'woo-kustom-export' ),
				'desc'       => __( 'Huvudproduktens SKU för variantgruppering. Binder ihop variationer.', 'woo-kustom-export' ),
				'code'       => 'Group sku',
				'sello_code' => 'Parent SKU',
				'source'     => 'Förälderns _sku',
				'example'    => 'LNT-8821',
				'required'   => false,
			),
			'Group type'              => array(
				'label'      => __( 'Grupptyp (Group type)', 'woo-kustom-export' ),
				'desc'       => __( 'Sellos typ för variantgruppering (t.ex. model, size, color för varianter).', 'woo-kustom-export' ),
				'code'       => 'Group type',
				'sello_code' => 'Group type',
				'source'     => 'Beräknas automatiskt (model / size / color)',
				'example'    => 'model',
				'required'   => false,
			),
			'Condition'               => array(
				'label'      => __( 'Produktens skick (Condition)', 'woo-kustom-export' ),
				'desc'       => __( 'Sellos officiella skickkolumn (new eller used). Sätts automatiskt till new för oanvända artiklar och used för vintage/begagnat, vilket styr Traderas skick.', 'woo-kustom-export' ),
				'code'       => 'Condition',
				'sello_code' => 'Condition',
				'source'     => 'Skick / _sello_condition',
				'example'    => 'used',
				'required'   => false,
			),
		);
	}

	/**
	 * Kolumnrubriker i Sellos format för Tradera butiksobjekt.
	 *
	 * Som standard är samtliga 13 kolumner aktiva. Om användaren har sparat
	 * specifika kolumnval filtreras kolumnerna utifrån inställningarna.
	 *
	 * @return string[]
	 */
	public function collect_columns() {
		$all_columns = self::get_all_columns_list();

		// Om inga specifika kolumnval har sparats är alla kolumner ibockade som standard
		$is_configured = ! empty( $this->settings['sello_columns_configured'] );
		if ( ! $is_configured && ( ! isset( $this->settings['sello_columns'] ) || ! is_array( $this->settings['sello_columns'] ) || empty( $this->settings['sello_columns'] ) ) ) {
			return $all_columns;
		}

		$selected = array();
		foreach ( $all_columns as $col ) {
			if ( ! empty( $this->settings['sello_columns'][ $col ] ) ) {
				$selected[] = $col;
			} elseif ( 'Tradera category' === $col && ( ! isset( $this->settings['sello_columns'] ) || ! array_key_exists( 'Tradera category', $this->settings['sello_columns'] ) ) ) {
				// Om användaren sparade inställningar i en tidigare version innan 'Tradera category' lades till
				$selected[] = $col;
			} elseif ( 'Condition' === $col && ( ! empty( $this->settings['sello_columns']['Property Skick'] ) || ! empty( $this->settings['sello_columns']['Property Är begagnad'] ) ) ) {
				// Bakåtkompatibilitet: om användaren tidigare hade Property Skick/Är begagnad ibockat, inkludera Condition
				$selected[] = $col;
			} elseif ( 'Tradera category' === $col && ! empty( $this->settings['sello_columns']['Category'] ) ) {
				// Bakåtkompatibilitet: om användaren tidigare hade Category ibockat
				$selected[] = $col;
			}
		}

		// Om användaren specifikt även valt att exportera intern kategori (Category)
		if ( ! empty( $this->settings['sello_columns']['Category'] ) && ! in_array( 'Category', $selected, true ) ) {
			$tc_pos = array_search( 'Tradera category', $selected, true );
			if ( false !== $tc_pos ) {
				array_splice( $selected, $tc_pos + 1, 0, array( 'Category' ) );
			} else {
				$selected[] = 'Category';
			}
		}

		// Fallback om användaren avmarkerat precis allt: behåll Sku
		return ! empty( $selected ) ? $selected : array( 'Sku' );
	}

	/**
	 * Produktstatistik (antal enkla produkter och antal variationsrader).
	 *
	 * @return array{products:int,variations:int}
	 */
	public function get_stats() {
		$prod_count = 0;
		$var_count  = 0;

		foreach ( $this->get_products_iter() as $product ) {
			if ( $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $variation_id ) {
					$variation = wc_get_product( $variation_id );
					if ( ! $variation || ! $variation->exists() ) {
						continue;
					}
					if ( ! $this->include_product( $variation, $product ) ) {
						continue;
					}
					$var_count++;
				}
			} elseif ( $this->include_product( $product, $product ) ) {
				$prod_count++;
			}
		}

		return array(
			'products'   => $prod_count,
			'variations' => $var_count,
		);
	}

	/**
	 * Filnamnsprefix för nedladdningen.
	 *
	 * @return string
	 */
	public function get_filename_prefix() {
		return 'sello-tradera-products-';
	}

	/**
	 * Filändelse för nedladdningen.
	 *
	 * @return string
	 */
	public function get_filename_extension() {
		return 'csv';
	}

	/**
	 * Generera CSV-strömmen och skicka den till webbläsaren som nedladdning.
	 * Använder UTF-8 med BOM och komma som avgränsare.
	 */
	public function stream_csv() {
		$filename = $this->get_filename_prefix() . gmdate( 'Y-m-d-His' ) . '.' . $this->get_filename_extension();

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		$out = fopen( 'php://output', 'wb' );
		if ( ! $out ) {
			wp_die( esc_html__( 'Kunde inte öppna utdataström för CSV-exporten.', 'woo-kustom-export' ) );
		}

		// UTF-8 BOM för att garantera korrekt teckenkodning (Å, Ä, Ö) i Excel och andra kalkylprogram.
		fwrite( $out, "\xEF\xBB\xBF" );

		$this->write_row( $out, $this->collect_columns() );
		$this->stream_rows( $out );

		fclose( $out );
		exit;
	}

	/**
	 * Skriver CSV-exporten till en fil på disken och returnerar antal rader och filstorlek.
	 *
	 * @param string $filepath Fullständig sökväg till destinationsfilen.
	 * @return array{rows: int, size: int}|false Statistik eller false vid fel.
	 */
	public function write_to_file( $filepath ) {
		$out = fopen( $filepath, 'wb' );
		if ( ! $out ) {
			return false;
		}

		// UTF-8 BOM för att garantera korrekt teckenkodning (Å, Ä, Ö) i Excel och andra kalkylprogram.
		fwrite( $out, "\xEF\xBB\xBF" );

		$this->write_row( $out, $this->collect_columns() );

		$rows_count = 0;
		foreach ( $this->get_rows_iter() as $row ) {
			$this->write_row( $out, $row );
			$rows_count++;
		}

		fclose( $out );

		return array(
			'rows' => $rows_count,
			'size' => file_exists( $filepath ) ? filesize( $filepath ) : 0,
		);
	}

	/**
	 * Exekvera exporten och strömma filen (alias för stream_csv, anropas bl.a. via WP-CLI).
	 */
	public function generate() {
		$this->stream_csv();
	}

	/**
	 * Strömma produkt- och variationsrader till filhandtaget.
	 *
	 * @param resource $out Filhandtag (php://output).
	 */
	protected function stream_rows( $out ) {
		foreach ( $this->get_rows_iter() as $row ) {
			$this->write_row( $out, $row );
		}
	}

	/**
	 * Iterera färdiga rader (rubrikraden exkluderad) som associerade arrayer
	 * med samma nycklar som collect_columns(). En generator håller
	 * minnesanvändningen minimal eftersom enbart en produktfamilj bearbetas åt gången.
	 *
	 * @return iterable<array>
	 */
	public function get_rows_iter() {
		$columns = $this->collect_columns();

		foreach ( $this->get_products_iter() as $product ) {
			$template = array_fill_keys( $columns, '' );

			if ( $product->is_type( 'variable' ) ) {
				yield from $this->get_variable_rows_iter( $product, $template );
			} elseif ( $this->include_product( $product, $product ) ) {
				$row = $template;
				foreach ( $this->build_base_row( $product, $product ) as $k => $v ) {
					$row[ $k ] = $v;
				}
				yield $row;
			}
		}
	}

	/**
	 * Hämta de första raderna för förhandsgranskning i adminpanelen.
	 *
	 * @param int $limit Antal rader att returnera (standard: 5).
	 * @return array[] Lista med associerade rader.
	 */
	public function get_preview_rows( $limit = 5 ) {
		$rows = array();
		foreach ( $this->get_rows_iter() as $row ) {
			$rows[] = $row;
			if ( count( $rows ) >= $limit ) {
				break;
			}
		}
		return $rows;
	}

	/**
	 * Identifiera eventuella produkter eller variationer som saknar SKU eller pris för Tradera.
	 *
	 * @param int $limit Max antal avvikelser att samla per typ.
	 * @return array{missing_sku:array,missing_price:array,total_checked:int}
	 */
	public function get_validation_issues( $limit = 50 ) {
		$missing_sku   = array();
		$missing_price = array();
		$total_checked = 0;

		foreach ( $this->get_products_iter() as $product ) {
			if ( $product->is_type( 'variable' ) ) {
				$parent_name = $product->get_name();
				foreach ( $product->get_children() as $variation_id ) {
					$variation = wc_get_product( $variation_id );
					if ( ! $variation || ! $variation->exists() || ! $this->include_product( $variation, $product ) ) {
						continue;
					}

					$total_checked++;
					$sku   = trim( (string) $variation->get_sku() );
					$price = $variation->get_price();
					if ( '' === $price || null === $price ) {
						$price = $variation->get_regular_price();
					}

					$name = $variation->get_formatted_name();
					if ( empty( $name ) ) {
						$name = $parent_name . ' (#' . $variation->get_id() . ')';
					}

					if ( '' === $sku && count( $missing_sku ) < $limit ) {
						$missing_sku[] = array(
							'id'          => $variation->get_id(),
							'name'        => $name,
							'type'        => __( 'Variation', 'woo-kustom-export' ),
							'parent_id'   => $product->get_id(),
							'parent_name' => $parent_name,
							'edit_url'    => get_edit_post_link( $product->get_id() ),
						);
					}

					if ( ( '' === $price || null === $price || (float) $price <= 0 ) && count( $missing_price ) < $limit ) {
						$missing_price[] = array(
							'id'          => $variation->get_id(),
							'sku'         => $sku ? $sku : $this->get_sku( $variation, $product ),
							'name'        => $name,
							'type'        => __( 'Variation', 'woo-kustom-export' ),
							'parent_id'   => $product->get_id(),
							'parent_name' => $parent_name,
							'edit_url'    => get_edit_post_link( $product->get_id() ),
						);
					}
				}
			} elseif ( $this->include_product( $product, $product ) ) {
				$total_checked++;
				$sku   = trim( (string) $product->get_sku() );
				$price = $product->get_price();
				if ( '' === $price || null === $price ) {
					$price = $product->get_regular_price();
				}

				if ( '' === $sku && count( $missing_sku ) < $limit ) {
					$missing_sku[] = array(
						'id'       => $product->get_id(),
						'name'     => $product->get_name(),
						'type'     => __( 'Enkel produkt', 'woo-kustom-export' ),
						'edit_url' => get_edit_post_link( $product->get_id() ),
					);
				}

				if ( ( '' === $price || null === $price || (float) $price <= 0 ) && count( $missing_price ) < $limit ) {
					$missing_price[] = array(
						'id'       => $product->get_id(),
						'sku'      => $sku ? $sku : (string) $product->get_id(),
						'name'     => $product->get_name(),
						'type'     => __( 'Enkel produkt', 'woo-kustom-export' ),
						'edit_url' => get_edit_post_link( $product->get_id() ),
					);
				}
			}
		}

		return array(
			'missing_sku'   => $missing_sku,
			'missing_price' => $missing_price,
			'total_checked' => $total_checked,
		);
	}

	/**
	 * Iterera variationsrader för en variabel produkt.
	 *
	 * - Skapar en rad per variation.
	 * - Varje variation får ett unikt SKU.
	 * - Alla variationer som tillhör samma huvudprodukt får samma värde i Group sku.
	 * - Group type anger grupperingstyp (color, size, model).
	 * - Huvudprodukten exporteras normalt inte som egen rad (och om den skulle göra
	 *   det får den inget värde i Tradera price store om den inte säljs separat).
	 *
	 * @param WC_Product $product  Föräldraprodukten.
	 * @param array      $template Radmall med rubrikens kolumnordning.
	 * @return iterable<array>
	 */
	protected function get_variable_rows_iter( $product, $template ) {
		$parent_sku = $this->get_sku( $product, $product );
		$group_type = $this->get_group_type( $product );

		// Om filter uttryckligen aktiverar huvudprodukten som egen rad:
		$include_parent = (bool) apply_filters( 'woo_kustom_sello_include_parent_row', false, $product );
		if ( $include_parent && $this->include_product( $product, $product ) ) {
			$parent_row = array_merge( $template, $this->build_base_row( $product, $product ) );
			// Huvudprodukten ska inte få något värde i Tradera price store om den själv inte ska säljas separat
			$parent_row['Tradera price store'] = '';
			if ( $parent_sku ) {
				$parent_row['Group sku'] = $parent_sku;
			}
			if ( $group_type ) {
				$parent_row['Group type'] = $group_type;
			}
			yield $parent_row;
		}

		foreach ( $product->get_children() as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( ! $variation || ! $variation->exists() || ! $this->include_product( $variation, $product ) ) {
				continue;
			}

			$row = $template;
			foreach ( $this->build_base_row( $variation, $product ) as $k => $v ) {
				$row[ $k ] = $v;
			}

			if ( $parent_sku ) {
				$row['Group sku'] = $parent_sku;
			}
			if ( $group_type ) {
				$row['Group type'] = $group_type;
			}

			yield $row;
		}
	}

	/**
	 * Avgör grupptyp för en variabel produkt.
	 *
	 * Sello accepterar följande 3 grupptyper för varianter:
	 * - 'color' (färg)
	 * - 'size' (storlek)
	 * - 'model' (modell / utförande)
	 *
	 * Metoden mappar automatiskt svenska attributnamn (Storlek, Färg, Modell)
	 * samt deras engelska motsvarigheter.
	 *
	 * @param WC_Product $product Föräldraprodukten.
	 * @return string
	 */
	protected function get_group_type( $product ) {
		$var_attributes = $product->get_variation_attributes();

		foreach ( $var_attributes as $taxonomy => $values ) {
			$label = strtolower( trim( $this->attribute_name( $taxonomy, $product ) ) );

			// Direkt matchning mot Sellos standardtyper
			if ( in_array( $label, $this->group_types, true ) ) {
				return $label;
			}

			// Svenska synonymer och mönster för storlek
			if ( in_array( $label, array( 'storlek', 'storlekar', 'stlk', 'size', 'sizes', 'skostorlek', 'klädstorlek' ), true )
				|| false !== strpos( $label, 'storlek' ) || false !== strpos( $label, 'size' ) ) {
				return 'size';
			}

			// Svenska synonymer och mönster för färg
			if ( in_array( $label, array( 'färg', 'farg', 'färger', 'farger', 'color', 'colour', 'colors', 'kulör', 'kulor' ), true )
				|| false !== strpos( $label, 'färg' ) || false !== strpos( $label, 'farg' ) || false !== strpos( $label, 'color' ) ) {
				return 'color';
			}

			// Svenska synonymer och mönster för modell/utförande
			if ( in_array( $label, array( 'modell', 'modeller', 'model', 'models', 'utförande', 'typ', 'variant' ), true )
				|| false !== strpos( $label, 'modell' ) || false !== strpos( $label, 'model' ) ) {
				return 'model';
			}
		}

		// Om det finns variationsattribut men ingen passade ovanstående mönster,
		// använd 'model' som standard så att Sello kan gruppera variationerna.
		if ( ! empty( $var_attributes ) ) {
			return 'model';
		}

		return '';
	}

	/**
	 * Attributets läsbara namn.
	 *
	 * @param string     $taxonomy Taxonomi (pa_...) eller lokalt attributnamn.
	 * @param WC_Product $product  Föräldraprodukten.
	 * @return string
	 */
	protected function attribute_name( $taxonomy, $product ) {
		if ( 0 === strpos( $taxonomy, 'pa_' ) && taxonomy_exists( $taxonomy ) ) {
			return wc_attribute_label( $taxonomy, $product );
		}

		$product_attributes = $product->get_attributes();
		if ( isset( $product_attributes[ $taxonomy ] ) ) {
			return $product_attributes[ $taxonomy ]->get_name();
		}

		return $taxonomy;
	}

	/**
	 * Bygg grundrad för en produkt eller variation.
	 *
	 * @param WC_Product $product Produkten/variationen som radens data hämtas från.
	 * @param WC_Product $parent  Föräldraprodukten (samma som $product för enkla produkter).
	 * @return array
	 */
	protected function build_base_row( $product, $parent ) {
		$is_parent_variable = ( $product === $parent && $product->is_type( 'variable' ) );
		$condition_data     = $this->get_tradera_condition_data( $product, $parent );
		$is_used            = ( 'Yes' === $condition_data['is_used'] || 'Oanvänt' !== $condition_data['condition'] );

		$row = array(
			'Sku'                     => $this->get_sku( $product, $parent ),
			'Standard name sv'        => $this->get_clean_name( $parent ),
			'Standard description sv' => $this->get_clean_description( $product, $parent ),
			'Tradera category'        => $this->get_tradera_category( $product, $parent ),
			'Category'                => $this->get_internal_category( $product, $parent ),
			'Tradera price store'     => $is_parent_variable ? '' : $this->get_tradera_price_store( $product, $parent ),
			'Purchase price'          => $this->get_purchase_price( $product, $parent ),
			'Quantity'                => $this->get_quantity( $product ),
			'Tax'                     => $this->get_tax_rate( $product, $parent ),
			'Weight'                  => $this->get_weight_grams( $product, $parent ),
			'Images'                  => $this->get_images( $product, $parent ),
			'Brand'                   => $this->get_brand( $product, $parent ),
			'Group sku'               => '',
			'Group type'              => '',
			'Condition'               => $is_used ? 'used' : 'new',
		);

		foreach ( $row as $k => $v ) {
			if ( is_string( $v ) && in_array( strtolower( trim( $v ) ), array( 'undefined', 'null' ), true ) ) {
				$row[ $k ] = '';
			}
		}

		return $row;
	}

	/**
	 * Hämta och mappa skick för Tradera/Sello utifrån WooCommerce-attribut och metadata.
	 *
	 * Mappar "Ny" -> "Oanvänt" (Är begagnad: No)
	 * Mappar "Vintage" -> Inställt begagnat skick, standard "Gott skick" (Är begagnad: Yes)
	 *
	 * @param WC_Product      $product Produkten eller variationen.
	 * @param WC_Product|null $parent  Föräldraprodukten.
	 * @return array{condition: string, is_used: string}
	 */
	public function get_tradera_condition_data( $product, $parent = null ) {
		$raw_condition = $this->get_raw_condition( $product, $parent );
		$clean         = strtolower( trim( $raw_condition ) );

		$vintage_target = ! empty( $this->settings['sello_vintage_condition'] ) ? $this->settings['sello_vintage_condition'] : 'Gott skick';
		$default_target = ! empty( $this->settings['sello_default_condition'] ) ? $this->settings['sello_default_condition'] : 'Oanvänt';

		// Om inget skick hittats på produkten
		if ( '' === $clean ) {
			$is_used = ( 'Oanvänt' === $default_target ) ? 'No' : 'Yes';
			return array(
				'condition' => $default_target,
				'is_used'   => $is_used,
			);
		}

		// Ny / New / Oanvänt
		if ( in_array( $clean, array( 'ny', 'nytt', 'new', 'brand new', 'oanvänt', 'oanvant', 'unused' ), true ) ) {
			return array(
				'condition' => 'Oanvänt',
				'is_used'   => 'No',
			);
		}

		// Vintage / Retro / Begagnad
		if ( in_array( $clean, array( 'vintage', 'retro', 'begagnad', 'begagnat', 'used', 'second hand', 'secondhand' ), true ) ) {
			return array(
				'condition' => $vintage_target,
				'is_used'   => 'Yes',
			);
		}

		// Direkta matchningar mot Traderas officiella skickgraderingar
		if ( false !== strpos( $clean, 'mycket gott' ) || false !== strpos( $clean, 'nyskick' ) || false !== strpos( $clean, 'very good' ) ) {
			return array(
				'condition' => 'Mycket gott skick',
				'is_used'   => 'Yes',
			);
		}

		if ( false !== strpos( $clean, 'gott skick' ) || false !== strpos( $clean, 'bra skick' ) || false !== strpos( $clean, 'good' ) ) {
			return array(
				'condition' => 'Gott skick',
				'is_used'   => 'Yes',
			);
		}

		if ( false !== strpos( $clean, 'okej' ) || false !== strpos( $clean, 'ok' ) || false !== strpos( $clean, 'slitage' ) || false !== strpos( $clean, 'fair' ) ) {
			return array(
				'condition' => 'Okej skick',
				'is_used'   => 'Yes',
			);
		}

		if ( false !== strpos( $clean, 'defekt' ) || false !== strpos( $clean, 'trasig' ) || false !== strpos( $clean, 'damaged' ) ) {
			return array(
				'condition' => 'Defekt',
				'is_used'   => 'Yes',
			);
		}

		// Fallback för övriga värden
		return array(
			'condition' => $vintage_target,
			'is_used'   => 'Yes',
		);
	}

	/**
	 * Hämta rått skickvärde från WooCommerce-attribut eller metadata.
	 *
	 * @param WC_Product      $product Produkten eller variationen.
	 * @param WC_Product|null $parent  Föräldraprodukten.
	 * @return string
	 */
	protected function get_raw_condition( $product, $parent = null ) {
		if ( ! $product || ! is_object( $product ) ) {
			return '';
		}

		$condition      = '';
		$candidate_keys = array( 'skick', 'pa_skick', 'condition', 'pa_condition', 'tillstand', 'pa_tillstand' );

		if ( method_exists( $product, 'get_attribute' ) ) {
			foreach ( $candidate_keys as $key ) {
				$val = $product->get_attribute( $key );
				if ( '' !== trim( (string) $val ) ) {
					$condition = trim( (string) $val );
					break;
				}
			}
		}

		if ( '' === $condition && method_exists( $product, 'get_attributes' ) ) {
			foreach ( $product->get_attributes() as $taxonomy => $val ) {
				$label = '';
				if ( taxonomy_exists( $taxonomy ) ) {
					$label = wc_attribute_label( $taxonomy );
				} elseif ( is_string( $taxonomy ) ) {
					$label = $taxonomy;
				}
				$label_clean = strtolower( trim( (string) $label ) );
				if ( in_array( $label_clean, array( 'skick', 'condition', 'tillstånd', 'tillstand' ), true ) ) {
					if ( $val instanceof WC_Product_Attribute ) {
						$options = $val->get_options();
						if ( ! empty( $options ) ) {
							$first = reset( $options );
							if ( is_numeric( $first ) ) {
								$term = get_term( (int) $first );
								$condition = ( $term && ! is_wp_error( $term ) ) ? $term->name : (string) $first;
							} else {
								$condition = (string) $first;
							}
						}
					} elseif ( is_string( $val ) || is_numeric( $val ) ) {
						$condition = (string) $val;
					}
					if ( '' !== $condition ) {
						break;
					}
				}
			}
		}

		if ( '' === $condition && method_exists( $product, 'get_meta' ) ) {
			$meta_keys = array( '_skick', 'skick', '_condition', 'condition', '_pa_skick', 'pa_skick', '_tillstand', 'tillstand' );
			foreach ( $meta_keys as $meta_key ) {
				$val = $product->get_meta( $meta_key, true );
				if ( is_string( $val ) || is_numeric( $val ) ) {
					$val_str = trim( (string) $val );
					if ( '' !== $val_str ) {
						$condition = $val_str;
						break;
					}
				}
			}
		}

		// Om variation och tomt, ärv från föräldern
		if ( '' === $condition && $parent && $parent !== $product ) {
			return $this->get_raw_condition( $parent, null );
		}

		return wp_strip_all_tags( $condition );
	}

	/**
	 * Hämta SKU för produkt eller variation med robust fallback.
	 * Varje variation garanteras ett unikt SKU.
	 *
	 * @param WC_Product      $product Produkten eller variationen.
	 * @param WC_Product|null $parent  Föräldraprodukten.
	 * @return string
	 */
	protected function get_sku( $product, $parent = null ) {
		$sku = (string) $product->get_sku();

		if ( '' === trim( $sku ) ) {
			$meta_keys = array( '_sku', 'sku', '_artnr', 'artnr', '_artikelnr', 'artikelnr' );
			$sku       = (string) $this->get_meta_first( $meta_keys, $product );
		}

		if ( '' === trim( $sku ) ) {
			if ( $product->is_type( 'variation' ) ) {
				$parent_sku = ( $parent && $parent !== $product ) ? $this->get_sku( $parent, $parent ) : '';
				if ( '' !== $parent_sku ) {
					$sku = $parent_sku . '-' . $product->get_id();
				} else {
					$sku = 'VAR-' . $product->get_id();
				}
			} else {
				$sku = (string) $product->get_id();
			}
		}

		return (string) apply_filters( 'woo_kustom_sello_sku', trim( $sku ), $product, $parent );
	}

	/**
	 * Tradera fast butikspris (Tradera price store) som heltal utan valuta, t.ex. "495".
	 *
	 * - Hämtar WooCommerce regular_price eller variationens aktiva pris.
	 * - Formaterar alltid som heltal utan decimaler eller valutasymboler.
	 * - Huvudprodukten (förälder till varianter) ges inget pris om den inte säljs separat.
	 * - Lämpar sig direkt för fastprislistning i Tradera-butik utan Sellos Calculate.
	 *
	 * @param WC_Product      $product Produkten eller variationen.
	 * @param WC_Product|null $parent  Föräldraprodukten.
	 * @return string Heltal som sträng, t.ex. "495", eller tom sträng om pris saknas.
	 */
	protected function get_tradera_price_store( $product, $parent = null ) {
		// Huvudprodukt som är variabel ska inte få pris i Tradera price store
		// såvida den inte uttryckligen ska säljas separat.
		if ( $product->is_type( 'variable' ) ) {
			$sell_parent = (bool) apply_filters( 'woo_kustom_sello_sell_parent_separately', false, $product );
			if ( ! $sell_parent ) {
				return '';
			}
		}

		$price = $product->get_price();
		if ( '' === $price || null === $price ) {
			$price = $product->get_regular_price();
		}

		if ( '' === $price || null === $price ) {
			return '';
		}

		$price_float = (float) $price;
		if ( $price_float <= 0.0 ) {
			return '';
		}

		// Säkerställ att butikspriset för Tradera alltid inkluderar moms (B2C)
		if ( method_exists( $product, 'is_taxable' ) && $product->is_taxable() ) {
			if ( function_exists( 'wc_get_price_including_tax' ) ) {
				$calc = (float) wc_get_price_including_tax( $product, array( 'price' => $price_float ) );
				if ( $calc > $price_float ) {
					$price_float = $calc;
				} elseif ( function_exists( 'wc_prices_include_tax' ) && ! wc_prices_include_tax() ) {
					$tax_rate = (float) $this->get_tax_rate( $product, $parent );
					if ( $tax_rate > 0.0 ) {
						$price_float = $price_float * ( 1.0 + ( $tax_rate / 100.0 ) );
					}
				}
			}
		}

		// Skriv priset som heltal utan valuta, till exempel 495
		$price_int = (string) (int) round( $price_float );

		return (string) apply_filters( 'woo_kustom_sello_tradera_price_store', $price_int, $product, $parent );
	}

	/**
	 * Inköpspris (Purchase price) från kända WooCommerce-metafält.
	 * Formateras som heltal eller decimaltal utan valutasymbol.
	 *
	 * @param WC_Product      $product Produkten eller variationen.
	 * @param WC_Product|null $parent  Föräldraprodukten.
	 * @return string
	 */
	protected function get_purchase_price( $product, $parent = null ) {
		$keys = (array) apply_filters(
			'woo_kustom_sello_purchase_price_meta_keys',
			array(
				'_purchase_price',
				'purchase_price',
				'_cost',
				'cost',
				'_cost_price',
				'cost_price',
				'_wc_cog_cost',
				'wc_cog_cost',
				'_cogs_cost',
				'cogs_cost',
				'_alg_wc_cog_cost',
				'_supplier_price',
				'supplier_price',
			),
			$product
		);

		$cost = $this->get_meta_first( $keys, $product );
		if ( ( '' === $cost || null === $cost ) && $parent && $parent !== $product ) {
			$cost = $this->get_meta_first( $keys, $parent );
		}

		$cost = apply_filters( 'woo_kustom_sello_purchase_price', $cost, $product, $parent );

		if ( '' !== $cost && null !== $cost && is_numeric( $cost ) ) {
			$cost_float = (float) $cost;
			// Om heltalsvärde, skriv som heltal; annars 2 decimaler
			if ( floor( $cost_float ) === $cost_float ) {
				return (string) (int) $cost_float;
			}
			return number_format( $cost_float, 2, '.', '' );
		}

		return '';
	}

	/**
	 * Rensa produktnamn för Tradera (tar bort eventuella HTML-taggar, länkar och URL:er).
	 *
	 * @param WC_Product $parent Föräldraprodukten.
	 * @return string
	 */
	protected function get_clean_name( $parent ) {
		$name = (string) $parent->get_name();
		if ( '' === trim( $name ) ) {
			return '';
		}

		$strip_links = ! isset( $this->settings['sello_strip_links'] ) || ! empty( $this->settings['sello_strip_links'] );
		if ( $strip_links ) {
			$name = $this->strip_tradera_links( $name, false );
		}

		if ( function_exists( 'wp_strip_all_tags' ) ) {
			$name = wp_strip_all_tags( $name, true );
		} else {
			$name = strip_tags( $name );
		}

		$name = html_entity_decode( $name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( preg_replace( '/\s+/', ' ', $name ) );
	}

	/**
	 * Tar bort länkar, webbadresser och webbshopshänvisningar på ett snyggt sätt för Tradera.
	 *
	 * Tradera förbjuder strikt externa länkar och webbadresser i objektsbeskrivningar.
	 * Denna metod:
	 * 1. Processar <a>-taggar:
	 *    - Om länktexten är en URL eller call-to-action (t.ex. "Klicka här", "Besök Glasprinsen"): tas hela länken bort.
	 *    - Om länktexten är beskrivande text (t.ex. "Fuga skål"): behålls texten medan själva länken tas bort.
	 * 2. Tar bort hela rader som är rena butikshänvisningar (t.ex. "Besök gärna vår webbshop på glasprinsen.se...").
	 * 3. Tar bort råa URL:er (http://, https://, www., glasprinsen.se och angivna domäner).
	 * 4. Städar upp överflödig interpunktion (t.ex. "För mer info:", "Länk:", tomma parenteser, dubbla punkter).
	 *
	 * @param string $text      Texten som ska rensas.
	 * @param bool   $multiline Huruvida texten kan ha flera rader (sant för beskrivning, falskt för rubrik).
	 * @return string
	 */
	public function strip_tradera_links( $text, $multiline = true ) {
		if ( '' === trim( $text ) ) {
			return '';
		}

		$custom_domains_raw = ! empty( $this->settings['sello_strip_domains'] )
			? (string) $this->settings['sello_strip_domains']
			: 'glasprinsen.se, glasprinsen';

		$domains_list = array_filter( array_map( 'trim', explode( ',', strtolower( $custom_domains_raw ) ) ) );
		if ( ! in_array( 'glasprinsen.se', $domains_list, true ) ) {
			$domains_list[] = 'glasprinsen.se';
		}
		if ( ! in_array( 'glasprinsen', $domains_list, true ) ) {
			$domains_list[] = 'glasprinsen';
		}

		// 1. Processa HTML-länkar <a ...>...</a> medan taggstrukturen är intakt
		$text = preg_replace_callback(
			'/<a\b[^>]*\bhref=(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))[^>]*>(.*?)<\/a>/is',
			function ( $matches ) use ( $domains_list ) {
				$href = ! empty( $matches[1] ) ? $matches[1] : ( ! empty( $matches[2] ) ? $matches[2] : ( ! empty( $matches[3] ) ? $matches[3] : '' ) );
				$inner_html  = isset( $matches[4] ) ? $matches[4] : '';
				$anchor_text = trim( function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $inner_html, false ) : strip_tags( $inner_html ) );

				$href_lower   = strtolower( $href );
				$anchor_lower = strtolower( $anchor_text );

				// Kontrollera om målet eller ankartexten är kopplad till Glasprinsen eller domänlistan
				$is_target_site = false;
				foreach ( $domains_list as $d ) {
					if ( false !== strpos( $href_lower, $d ) || false !== strpos( $anchor_lower, $d ) ) {
						$is_target_site = true;
						break;
					}
				}

				// Kontrollera om ankartexten ser ut som en URL eller domän
				$is_url_anchor = (
					0 === strpos( $anchor_lower, 'http://' ) ||
					0 === strpos( $anchor_lower, 'https://' ) ||
					0 === strpos( $anchor_lower, 'www.' ) ||
					false !== strpos( $anchor_lower, 'glasprinsen.se' ) ||
					$anchor_text === $href
				);

				// Kontrollera om ankartexten är en call-to-action eller länkfras
				$cta_pattern = '/^(?:klicka här|läs mer|se mer|här|besök|webbshop|butik|till butiken|vår hemsida|vår butik|mer info|länk|extern länk)/i';
				$is_cta      = (bool) preg_match( $cta_pattern, $anchor_lower );

				// Om ankartexten är en URL, CTA eller refererar till butiken -> ta bort helt
				if ( $is_url_anchor || $is_cta || ( $is_target_site && ( false !== strpos( $anchor_lower, 'glasprinsen' ) || '' === $anchor_text ) ) ) {
					return '';
				}

				// Om det är en länk med beskrivande varutext (t.ex. "Fuga skål"): behåll texten så meningen blir naturlig
				return $anchor_text;
			},
			$text
		);

		// 2. Ta bort hela rader som är rena butikshänvisningar (t.ex. "Besök gärna vår webbshop...")
		if ( $multiline ) {
			$referral_line_pattern = '/^\s*(?:(?:besök|se även|se alla|hitta fler|välkommen till|följ oss på|kolla in|handla hos)\s+.*?(?:glasprinsen|webbshop|hemsida|webbutik).*?|(?:https?:\/\/)?(?:www\.)?glasprinsen\.se(?:\/\S*)?)\s*$/im';
			$text                  = preg_replace( $referral_line_pattern, '', $text );
		}

		// 3. Ta bort alla råa URL:er och webbadresser
		// http:// och https://
		$text = preg_replace( '/https?:\/\/[^\s<>"\'()]+/i', '', $text );
		// www.
		$text = preg_replace( '/www\.[a-z0-9\-]+(?:\.[a-z0-9\-]+)*(?:\/[^\s<>"\'()]*)?/i', '', $text );
		// glasprinsen.se med valfri subdomän eller sökväg
		$text = preg_replace( '/(?:https?:\/\/)?(?:www\.)?glasprinsen\.se(?:\/[^\s<>"\'()]*)?/i', '', $text );

		// Övriga angivna domäner
		foreach ( $domains_list as $d ) {
			if ( '' === $d ) {
				continue;
			}
			$escaped = preg_quote( $d, '/' );
			// Om det är en faktisk domän med punkt (t.ex. glasprinsen.se)
			if ( false !== strpos( $d, '.' ) ) {
				$text = preg_replace( '/(?:https?:\/\/)?(?:www\.)?' . $escaped . '(?:\/[^\s<>"\'()]*)?/i', '', $text );
			} else {
				// Om det är ett rent nyckelord utan domänändelse, matcha enbart om det föregås av www. eller http(s)://
				$text = preg_replace( '/(?:https?:\/\/|www\.)' . $escaped . '(?:\.[a-z]{2,})?(?:\/[^\s<>"\'()]*)?/i', '', $text );
			}
		}

		// 4. Städa upp kolon före avslutning (t.ex. "För mer info:." -> "För mer info.")
		$text = preg_replace( '/:\s*([.!?]|<\/(?:p|div|li)>)/i', '$1', $text );

		// 5. Rensa tomma parenteser och hakparenteser: () eller [] eller (se )
		$text = preg_replace( '/\(\s*(?:se|läs mer|mer info)?\s*[,.;:]*\s*\)/i', '', $text );
		$text = preg_replace( '/\[\s*(?:se|läs mer|mer info)?\s*[,.;:]*\s*\]/i', '', $text );

		// 6. Rensa hängande kolon, tankstreck eller bindestreck i slutet av rader
		$text = preg_replace( '/[:\-–—]\s*$/m', '', $text );

		// 7. Ta bort rader som nu bara består av en ensam uppmaningsfras ("För mer information:", "Läs mer:")
		if ( $multiline ) {
			$lead_in_pattern = '/^\s*(?:(?:för\s+)?(?:mer\s+info(?:rmation)?|se\s+mer|se\s+även|läs\s+mer|besök\s+oss|länk|källa|kontakt))\s*[:\-–—.]?\s*$/im';
			$text            = preg_replace( $lead_in_pattern, '', $text );
		}

		// 8. Normalisera interpunktion och blanksteg
		$text = preg_replace( '/[ \t]{2,}/', ' ', $text );
		$text = preg_replace( '/\s+([,.:;!?])/', '$1', $text );
		$text = preg_replace( '/\.{2,}/', '.', $text );
		$text = preg_replace( '/,{2,}/', ',', $text );

		return trim( $text );
	}

	/**
	 * Produktbeskrivning som ren text utan HTML-taggar och länkar.
	 *
	 * - Tar bort kortkoder.
	 * - Tar bort länkar, webbadresser och Glasprinsen-hänvisningar (om aktivt, standard: ja).
	 * - Tar bort HTML som <p>, <br>, <div> osv.
	 * - Behåller meningsfulla radbrytningar och styckeindelning.
	 * - Avkodar HTML-entiteter till ren text (Å, Ä, Ö, &, etc.).
	 *
	 * @param WC_Product      $product Produkten eller variationen.
	 * @param WC_Product|null $parent  Föräldraprodukten.
	 * @return string
	 */
	protected function get_clean_description( $product, $parent = null ) {
		$main = $parent ? $parent : $product;

		$desc = (string) $main->get_description();
		if ( '' === trim( $desc ) ) {
			$desc = (string) $main->get_short_description();
		}

		if ( '' === trim( $desc ) && $product !== $parent ) {
			$desc = (string) $product->get_description();
		}

		if ( '' === trim( $desc ) ) {
			return '';
		}

		// 1. Ta bort WordPress-kortkoder ([shortcode]...[/shortcode] eller [shortcode])
		if ( function_exists( 'strip_shortcodes' ) ) {
			$desc = strip_shortcodes( $desc );
		} else {
			$desc = preg_replace( '/\[\/?(?:[a-zA-Z0-9_\-]+)[^\]]*\]/', '', $desc );
		}

		// 2. Ta bort länkar, webbadresser och Glasprinsen-hänvisningar för Tradera
		$strip_links = ! isset( $this->settings['sello_strip_links'] ) || ! empty( $this->settings['sello_strip_links'] );
		if ( $strip_links ) {
			$desc = $this->strip_tradera_links( $desc, true );
		}

		// 3. Ersätt radbrytande HTML-taggar med en radbrytning för att bevara stycke- och liststruktur
		$desc = preg_replace( '/<br\s*\/?>/i', "\n", $desc );
		$desc = preg_replace( '/<\/(?:p|div|li|tr|h[1-6])>/i', "\n", $desc );

		// 4. Ta bort resterande HTML-taggar
		if ( function_exists( 'wp_strip_all_tags' ) ) {
			$desc = wp_strip_all_tags( $desc, false );
		} else {
			$desc = strip_tags( $desc );
		}

		// 5. Avkoda HTML-entiteter (&amp;, &aring;, &quot;, &#39; etc.)
		$desc = html_entity_decode( $desc, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// 6. Normalisera radbrytningar (\r\n och \r blir \n)
		$desc = str_replace( array( "\r\n", "\r" ), "\n", $desc );

		// 7. Om länkrensning är aktiv, kör en efterstädning på ren text
		if ( $strip_links ) {
			$desc = preg_replace( '/[:\-–—]\s*$/m', '', $desc );
			$desc = preg_replace( '/\(\s*[,.;:]*\s*\)/', '', $desc );
			$desc = preg_replace( '/\[\s*[,.;:]*\s*\]/', '', $desc );
			$desc = preg_replace( '/^\s*(?:(?:för\s+)?(?:mer\s+info(?:rmation)?|se\s+mer|se\s+även|läs\s+mer|besök\s+oss|länk|källa|kontakt))\s*[:\-–—.]?\s*$/im', '', $desc );
			$desc = preg_replace( '/\s+([,.:;!?])/', '$1', $desc );
			$desc = preg_replace( '/\.{2,}/', '.', $desc );
		}

		// 7b. Ta bort rader med oinitierade variabler som "Läs mer om undefined." samt eventuella "undefined"
		$desc = preg_replace( '/^\s*läs\s+mer\s+om\s+undefined\.?\s*$/imu', '', $desc );
		$desc = preg_replace( '/\s*läs\s+mer\s+om\s+undefined\.?/iu', '', $desc );
		$desc = preg_replace( '/\bundefined\b/i', '', $desc );

		// 8. Rensa onödiga blanksteg och filtrera bort skräprader samtidigt som styckebrytningar bevaras
		$lines          = explode( "\n", $desc );
		$clean_lines    = array();
		$last_was_empty = true;
		foreach ( $lines as $line ) {
			$trimmed = trim( $line );
			if ( '' === $trimmed || preg_match( '/^[\s,.:;!\-–—•*#]+$/u', $trimmed ) ) {
				if ( ! $last_was_empty ) {
					$clean_lines[]  = '';
					$last_was_empty = true;
				}
				continue;
			}
			$clean_lines[]  = $trimmed;
			$last_was_empty = false;
		}
		$desc = implode( "\n", $clean_lines );

		// 9. Komprimera multipla tomma rader (max 2 \n = 1 tom rad emellan stycken)
		$desc = preg_replace( "/\n{3,}/", "\n\n", $desc );

		// 10. Säkerställ att detaljerat Tradera-skick framgår i beskrivningen för köpare
		$condition_data = $this->get_tradera_condition_data( $product, $parent );
		if ( ! empty( $condition_data['condition'] ) ) {
			$condition_line = 'Skick: ' . $condition_data['condition'];
			if ( ! preg_match( '/\bskick\s*:\s*/iu', $desc ) ) {
				$desc = '' !== $desc ? $desc . "\n\n" . $condition_line : $condition_line;
			}
		}

		$desc = trim( $desc );

		return (string) apply_filters( 'woo_kustom_sello_description', $desc, $product, $parent );
	}

	/**
	 * Hämta butikens interna kategori för Sello-kolumnen 'Category'.
	 *
	 * Respekterar inställningen:
	 * - 'woocommerce' (standard): Hämtar aktuell kategori från WooCommerce.
	 * - 'fixed': Använder samma fasta kategori för samtliga produkter (t.ex. "importerad").
	 *
	 * @param WC_Product      $product Produkten eller variationen.
	 * @param WC_Product|null $parent  Föräldraprodukten.
	 * @return string
	 */
	public function get_internal_category( $product, $parent = null ) {
		$mode = ! empty( $this->settings['sello_category_mode'] ) ? $this->settings['sello_category_mode'] : 'woocommerce';

		// Om användaren valt en och samma fasta kategori för alla produkter
		if ( 'fixed' === $mode ) {
			$fixed = ! empty( $this->settings['sello_custom_category'] ) ? trim( (string) $this->settings['sello_custom_category'] ) : 'importerad';
			return (string) apply_filters( 'woo_kustom_sello_internal_category', $fixed, $product, $parent );
		}

		// Standard: hämta aktuell kategori från WooCommerce
		$main          = $parent ? $parent : $product;
		$category_name = '';

		// 1. Kontrollera Yoast SEO primär kategori om aktivt
		$primary_cat_id = (int) get_post_meta( $main->get_id(), '_yoast_wpseo_primary_product_cat', true );
		if ( $primary_cat_id > 0 ) {
			$term = get_term( $primary_cat_id, 'product_cat' );
			if ( $term && ! is_wp_error( $term ) && ! empty( $term->name ) ) {
				$category_name = $term->name;
			}
		}

		// 2. Kontrollera Rank Math primär kategori om aktivt
		if ( '' === $category_name ) {
			$rank_math_cat_id = (int) get_post_meta( $main->get_id(), 'rank_math_primary_category', true );
			if ( $rank_math_cat_id > 0 ) {
				$term = get_term( $rank_math_cat_id, 'product_cat' );
				if ( $term && ! is_wp_error( $term ) && ! empty( $term->name ) ) {
					$category_name = $term->name;
				}
			}
		}

		// 3. Hämta tilldelade kategorier via WooCommerce
		if ( '' === $category_name && method_exists( $main, 'get_category_ids' ) ) {
			$cat_ids = $main->get_category_ids();
			if ( ! empty( $cat_ids ) ) {
				// Föredra den mest specifika underkategorin (störst hierarkiskt djup)
				$best_term = null;
				$max_depth = -1;
				foreach ( $cat_ids as $cat_id ) {
					$term = get_term( (int) $cat_id, 'product_cat' );
					if ( $term && ! is_wp_error( $term ) ) {
						$ancestors = get_ancestors( $term->term_id, 'product_cat', 'taxonomy' );
						$depth     = is_array( $ancestors ) ? count( $ancestors ) : 0;
						if ( $depth > $max_depth ) {
							$max_depth = $depth;
							$best_term = $term;
						}
					}
				}
				if ( $best_term && ! empty( $best_term->name ) ) {
					$category_name = $best_term->name;
				}
			}
		}

		// 4. Fallback till wp_get_post_terms om get_category_ids inte gav träff
		if ( '' === $category_name ) {
			$terms = wp_get_post_terms( $main->get_id(), 'product_cat', array( 'fields' => 'names' ) );
			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
				$category_name = (string) reset( $terms );
			}
		}

		// Om produkten helt saknar kategori i butiken och en anpassad fallback finns
		if ( '' === trim( $category_name ) && ! empty( $this->settings['sello_custom_category'] ) ) {
			$category_name = trim( (string) $this->settings['sello_custom_category'] );
		}

		return (string) apply_filters( 'woo_kustom_sello_internal_category', trim( $category_name ), $product, $parent );
	}

	/**
	 * Hämta Traderas officiella numeriska kategori-ID för Sello-kolumnen 'Tradera category'.
	 *
	 * @param WC_Product      $product Produkten eller variationen.
	 * @param WC_Product|null $parent  Föräldraprodukten.
	 * @return string Numeriskt Tradera kategori-ID.
	 */
	public function get_tradera_category( $product, $parent = null ) {
		$internal_cat = $this->get_internal_category( $product, $parent );
		$tradera_cat  = self::convert_to_tradera_category( $internal_cat, $product, $parent );

		return (string) apply_filters( 'woo_kustom_sello_tradera_category', trim( $tradera_cat ), $product, $parent );
	}

	/**
	 * Hämta kategori för Sello-export (bakåtkompatibelt alias som anropar get_tradera_category).
	 *
	 * @param WC_Product      $product Produkten eller variationen.
	 * @param WC_Product|null $parent  Föräldraprodukten.
	 * @return string
	 */
	public function get_category( $product, $parent = null ) {
		$cat = $this->get_tradera_category( $product, $parent );
		return (string) apply_filters( 'woo_kustom_sello_category', $cat, $product, $parent );
	}

	/**
	 * Hämta Tradera-kategorier från CSV-databasen.
	 *
	 * @return array<string,array{id:string,parent_id:string,name:string,crumb:string,path:string}>
	 */
	public static function get_tradera_categories() {
		static $cached = null;
		if ( null !== $cached ) {
			return $cached;
		}

		$cached = array();
		$file   = dirname( __FILE__ ) . '/data/tradera-categories.csv';
		if ( ! file_exists( $file ) || ! is_readable( $file ) ) {
			return $cached;
		}

		$handle = fopen( $file, 'r' );
		if ( ! $handle ) {
			return $cached;
		}

		$header = fgetcsv( $handle, 0, ',' );
		if ( $header ) {
			while ( ( $row = fgetcsv( $handle, 0, ',' ) ) !== false ) {
				if ( ! empty( $row[0] ) ) {
					$cached[ (string) $row[0] ] = array(
						'id'        => (string) $row[0],
						'parent_id' => isset( $row[1] ) ? (string) $row[1] : '',
						'name'      => isset( $row[2] ) ? (string) $row[2] : '',
						'crumb'     => isset( $row[3] ) ? (string) $row[3] : '',
						'path'      => isset( $row[4] ) ? (string) $row[4] : '',
					);
				}
			}
		}
		fclose( $handle );

		return $cached;
	}

	/**
	 * Konvertera en WooCommerce-kategori eller produktnamn till Traderas officiella kategori-ID.
	 *
	 * @param string          $category_name WooCommerce-kategorinamn eller initial kategori.
	 * @param WC_Product|null $product       Aktuell produkt.
	 * @param WC_Product|null $parent        Föräldraprodukt.
	 * @return string Tradera Kategori-ID eller ursprungligt namn om ingen matchning hittas.
	 */
	public static function convert_to_tradera_category( $category_name, $product = null, $parent = null ) {
		$main = $parent ? $parent : $product;

		// 1. Om värdet redan är ett numeriskt Tradera Kategori-ID
		if ( is_numeric( $category_name ) && (int) $category_name > 0 ) {
			return (string) (int) $category_name;
		}

		// 2. Kontrollera om produkten har ett specifikt Tradera-kategori-ID sparat i post meta
		if ( $main && method_exists( $main, 'get_id' ) ) {
			foreach ( array( '_tradera_category_id', 'tradera_category_id', '_tradera_category', 'tradera_category', '_sello_category_id' ) as $meta_key ) {
				$val = get_post_meta( $main->get_id(), $meta_key, true );
				if ( ! empty( $val ) && ( is_numeric( $val ) || is_string( $val ) ) ) {
					return is_numeric( $val ) ? (string) (int) $val : trim( (string) $val );
				}
			}
		}

		// 3. Söksträng för heuristisk matchning (kombinera kategorinamn och produktnamn)
		$search_text = strtolower( trim( (string) $category_name ) );
		if ( $main && method_exists( $main, 'get_name' ) ) {
			$search_text .= ' ' . strtolower( (string) $main->get_name() );
		}

		// Snabbmatchning för kända domänspecifika termer (t.ex. Glasprinsen / belysning / glas)
		if ( false !== strpos( $search_text, 'prism' ) || false !== strpos( $search_text, 'piggprism' ) ) {
			return '341181'; // Prisms & lamp parts
		}
		if ( false !== strpos( $search_text, 'kristallkrona' ) || false !== strpos( $search_text, 'takkrona' ) ) {
			return '344611'; // Crystal Chandeliers
		}
		if ( false !== strpos( $search_text, 'bordslampa' ) ) {
			return '1000001'; // Table lamps
		}
		if ( false !== strpos( $search_text, 'golvlampa' ) ) {
			return '344593'; // Floor lamps
		}
		if ( false !== strpos( $search_text, 'vägglampa' ) || false !== strpos( $search_text, 'vagglampa' ) ) {
			return '344592'; // Wall lamps
		}
		if ( false !== strpos( $search_text, 'taklampa' ) || false !== strpos( $search_text, 'takarmatur' ) ) {
			return '344664'; // Ceiling Lights
		}
		if ( false !== strpos( $search_text, 'vinglas' ) ) {
			return '341195'; // Wine Glasses
		}
		if ( false !== strpos( $search_text, 'champagneglas' ) ) {
			return '1001291'; // Champagne Glasses
		}
		if ( false !== strpos( $search_text, 'karaff' ) || false !== strpos( $search_text, 'flaska' ) ) {
			return '341194'; // Bottles & decanters
		}
		if ( false !== strpos( $search_text, 'dricksglas' ) ) {
			return '341196'; // Glasses
		}
		if ( preg_match( '/\bvas(?:er)?\b/iu', $search_text ) ) {
			return '200708'; // Vases
		}
		if ( preg_match( '/\b(?:sk[åa]l(?:ar)?|tallrik(?:ar)?|assiett(?:er)?)\b/iu', $search_text ) ) {
			return '200707'; // Bowls
		}
		if ( false !== strpos( $search_text, 'konstglas' ) ) {
			return '200706'; // Art glass signed
		}

		// 4. Sök i Tradera-kategoridatabasen efter namn eller crumb
		$categories = self::get_tradera_categories();
		if ( ! empty( $categories ) && ! empty( $category_name ) ) {
			$clean_cat = strtolower( trim( (string) $category_name ) );
			// Exakt namn-match
			foreach ( $categories as $id => $cat ) {
				if ( strtolower( $cat['name'] ) === $clean_cat ) {
					return (string) $id;
				}
			}
			// Crumb-match
			foreach ( $categories as $id => $cat ) {
				if ( false !== strpos( strtolower( $cat['crumb'] ), $clean_cat ) ) {
					return (string) $id;
				}
			}
		}

		// 5. Fallback: Tradera category kräver ett numeriskt kategori-ID i Sello.
		if ( is_numeric( $category_name ) && (int) $category_name > 0 ) {
			return (string) (int) $category_name;
		}

		// Standard Tradera kategori-ID (341181: Belysning / prismor / inredning)
		return (string) apply_filters( 'woo_kustom_sello_default_tradera_category_id', '341181', $category_name, $product, $parent );
	}

	/**
	 * Lagersaldo: heltalet i lager, 0 för produkter ur lager.
	 * Om lagerhantering inte är aktiv i WooCommerce sätts 1 för varor i lager och 0 för slutsålda.
	 *
	 * @param WC_Product $product Produkten eller variationen.
	 * @return string
	 */
	protected function get_quantity( $product ) {
		if ( method_exists( $product, 'get_manage_stock' ) && $product->get_manage_stock() ) {
			return (string) (int) $product->get_stock_quantity();
		}

		$in_stock = method_exists( $product, 'is_in_stock' ) ? $product->is_in_stock() : ( 'instock' === $product->get_stock_status() );
		return $in_stock ? '1' : '0';
	}

	/**
	 * Momssats i procent som heltal (t.ex. 25 för Sverige, 12 för livsmedel, 6, 0).
	 *
	 * @param WC_Product      $product Produkten eller variationen.
	 * @param WC_Product|null $parent  Föräldraprodukten.
	 * @return int
	 */
	protected function get_tax_rate( $product, $parent = null ) {
		if ( method_exists( $product, 'is_taxable' ) && ! $product->is_taxable() ) {
			return 0;
		}

		$rate = null;

		// 1. Beräkna direkt från produktens priser via WooCommerces skattemotor.
		if ( function_exists( 'wc_get_price_including_tax' ) && function_exists( 'wc_get_price_excluding_tax' ) ) {
			$dummy_price = 1000.0;
			$price_ex    = (float) wc_get_price_excluding_tax( $product, array( 'price' => $dummy_price ) );
			$price_inc   = (float) wc_get_price_including_tax( $product, array( 'price' => $dummy_price ) );

			if ( $price_ex > 0.0 && $price_inc > $price_ex ) {
				$rate = ( ( $price_inc - $price_ex ) / $price_ex ) * 100.0;
			}
		}

		// 2. Om beräkningen gav 0 eller inte gav utslag, kontrollera skatteklasser via WC_Tax.
		if ( null === $rate || 0.0 === (float) $rate ) {
			$tax_class = $product->get_tax_class();
			if ( 'parent' === $tax_class && $parent && $parent !== $product ) {
				$tax_class = $parent->get_tax_class();
			}

			if ( class_exists( 'WC_Tax' ) ) {
				$rates = WC_Tax::get_rates( $tax_class );
				if ( ! empty( $rates ) ) {
					$first = reset( $rates );
					if ( isset( $first['rate'] ) ) {
						$rate = (float) $first['rate'];
					}
				}

				if ( null === $rate || 0.0 === (float) $rate ) {
					$db_rates = WC_Tax::get_rates_for_tax_class( $tax_class );
					if ( empty( $db_rates ) && '' !== $tax_class ) {
						$db_rates = WC_Tax::get_rates_for_tax_class( '' );
					}
					if ( ! empty( $db_rates ) ) {
						$first_obj = reset( $db_rates );
						if ( isset( $first_obj->tax_rate ) ) {
							$rate = (float) $first_obj->tax_rate;
						}
					}
				}

				if ( ( null === $rate || 0.0 === (float) $rate ) && method_exists( 'WC_Tax', 'get_base_tax_rates' ) ) {
					$base_rates = WC_Tax::get_base_tax_rates( $tax_class );
					if ( ! empty( $base_rates ) ) {
						$first_base = reset( $base_rates );
						if ( isset( $first_base['rate'] ) ) {
							$rate = (float) $first_base['rate'];
						}
					}
				}
			}
		}

		// 3. Standardmoms: 25% för skattepliktiga produkter i Sverige.
		if ( null === $rate || 0.0 === (float) $rate ) {
			$tax_class = $product->get_tax_class();
			if ( 'zero-rate' !== $tax_class ) {
				$base_country = '';
				if ( function_exists( 'WC' ) && WC()->countries ) {
					$base_country = WC()->countries->get_base_country();
				}
				if ( empty( $base_country ) ) {
					$base_country = get_option( 'woocommerce_default_country' );
				}

				if ( empty( $base_country ) || 0 === strpos( (string) $base_country, 'SE' ) ) {
					$rate = 25.0;
				} else {
					$rate = 0.0;
				}
			} else {
				$rate = 0.0;
			}
		}

		$rate = (float) apply_filters( 'woo_kustom_sello_tax_rate', $rate, $product, $parent );

		$closest = 0;
		foreach ( $this->valid_tax_rates as $valid ) {
			if ( abs( $valid - $rate ) < abs( $closest - $rate ) ) {
				$closest = $valid;
			}
		}

		return $closest;
	}

	/**
	 * Vikt i gram. Konverterar automatiskt från butikens viktenhet i WooCommerce (kg, lbs, oz, g).
	 *
	 * @param WC_Product      $product Produkten eller variationen.
	 * @param WC_Product|null $parent  Föräldraprodukten.
	 * @return string Vikt i heltal gram, eller tom sträng om vikt saknas.
	 */
	protected function get_weight_grams( $product, $parent = null ) {
		$weight = $product->get_weight();

		if ( ( '' === $weight || null === $weight || 0.0 === (float) $weight ) && $parent && $parent !== $product ) {
			$weight = $parent->get_weight();
		}

		if ( '' === $weight || null === $weight || 0.0 === (float) $weight ) {
			$meta_keys = array( '_weight', 'weight' );
			$weight    = $this->get_meta_first( $meta_keys, $product );
			if ( ( '' === $weight || null === $weight || 0.0 === (float) $weight ) && $parent && $parent !== $product ) {
				$weight = $this->get_meta_first( $meta_keys, $parent );
			}
		}

		$weight = apply_filters( 'woo_kustom_sello_product_weight', $weight, $product, $parent );

		$weight_float = (float) $weight;
		if ( $weight_float <= 0.0 ) {
			return '';
		}

		$unit = strtolower( (string) get_option( 'woocommerce_weight_unit', 'kg' ) );

		switch ( $unit ) {
			case 'g':
				$grams = $weight_float;
				break;
			case 'lbs':
				$grams = $weight_float * 453.59237;
				break;
			case 'oz':
				$grams = $weight_float * 28.349523125;
				break;
			case 'kg':
			default:
				$grams = $weight_float * 1000;
		}

		return (string) (int) round( $grams );
	}

	/**
	 * Bilder: fullständiga URL:er som börjar med https://, separerade med kommatecken.
	 *
	 * Lägger variationens specifika bild först (om det är en variation och bilden finns),
	 * följt av förälderns/produktens huvudbild och eventuella galleribilder.
	 *
	 * @param WC_Product      $product Produkten eller variationen.
	 * @param WC_Product|null $parent  Föräldraprodukten.
	 * @return string Kommaseparerade fullständiga https:// bild-URL:er.
	 */
	protected function get_images( $product, $parent = null ) {
		$image_ids = array();

		// Om det är en variation med egen utvald bild
		if ( $parent && $parent !== $product ) {
			$variation_image_id = $product->get_image_id();
			if ( $variation_image_id ) {
				$image_ids[] = (int) $variation_image_id;
			}
		}

		// Huvudproduktens / enkla produktens utvalda bild
		$main_product   = $parent ? $parent : $product;
		$parent_image_id = $main_product->get_image_id();
		if ( $parent_image_id ) {
			$image_ids[] = (int) $parent_image_id;
		}

		// Galleribilder
		$gallery_ids = $main_product->get_gallery_image_ids();
		if ( ! empty( $gallery_ids ) ) {
			$image_ids = array_merge( $image_ids, array_map( 'intval', $gallery_ids ) );
		}

		$image_ids = array_unique( array_filter( $image_ids ) );

		$urls = array();
		foreach ( $image_ids as $image_id ) {
			$url = wp_get_attachment_image_url( $image_id, 'full' );
			if ( ! empty( $url ) ) {
				// Säkerställ fullständig https:// URL
				if ( function_exists( 'set_url_scheme' ) ) {
					$url = set_url_scheme( $url, 'https' );
				} else {
					$url = preg_replace( '#^http://#i', 'https://', (string) $url );
				}
				$urls[] = $url;
			}
		}

		$urls = array_unique( $urls );

		return (string) apply_filters( 'woo_kustom_sello_images', implode( ',', $urls ), $product, $parent );
	}

	/**
	 * Identifiera varumärke för en produkt eller variation.
	 *
	 * Kontrollerar:
	 * 1. Kända varumärkestaxonomier (pa_brand, pa_varumarke m.fl.)
	 * 2. Anpassade attribut märkta med brand/varumärke/märke/tillverkare
	 * 3. Metafält för varumärke
	 * 4. Automatisk fallback till föräldraprodukt för variationer
	 *
	 * @param WC_Product      $product Produkten eller variationen.
	 * @param WC_Product|null $parent  Föräldraprodukten.
	 * @return string
	 */
	public function get_brand( $product, $parent = null ) {
		if ( ! $product || ! is_object( $product ) ) {
			return '';
		}

		$product_id = $product->get_id();
		$parent_id  = 0;
		if ( method_exists( $product, 'get_parent_id' ) && $product->get_parent_id() > 0 ) {
			$parent_id = $product->get_parent_id();
		} elseif ( $parent && is_object( $parent ) && $parent !== $product ) {
			$parent_id = $parent->get_id();
		}

		// 1. Kända varumärkestaxonomier
		$known_taxonomies = array( 'pa_brand', 'pa_varumarke', 'pa_marke', 'yith_product_brand', 'pwb-brand', 'brand' );
		foreach ( $known_taxonomies as $tax ) {
			if ( taxonomy_exists( $tax ) ) {
				$terms = get_the_terms( $product_id, $tax );
				if ( ( ! $terms || is_wp_error( $terms ) ) && $parent_id > 0 ) {
					$terms = get_the_terms( $parent_id, $tax );
				}
				if ( $terms && ! is_wp_error( $terms ) ) {
					$first = reset( $terms );
					if ( ! empty( $first->name ) && ! in_array( strtolower( trim( $first->name ) ), array( 'undefined', 'null' ), true ) ) {
						return (string) $first->name;
					}
				}
			}
		}

		// 2. Produktattribut med etikett brand, varumärke eller tillverkare
		if ( method_exists( $product, 'get_attributes' ) ) {
			foreach ( $product->get_attributes() as $key => $attribute ) {
				$label = strtolower( trim( $this->attribute_name( $key, $product ) ) );
				if ( in_array( $label, array( 'brand', 'varumärke', 'varumarke', 'märke', 'marke', 'tillverkare' ), true ) ) {
					if ( $attribute instanceof WC_Product_Attribute ) {
						if ( $attribute->is_taxonomy() && taxonomy_exists( $attribute->get_name() ) ) {
							$terms = get_the_terms( $product_id, $attribute->get_name() );
							if ( ( ! $terms || is_wp_error( $terms ) ) && $parent_id > 0 ) {
								$terms = get_the_terms( $parent_id, $attribute->get_name() );
							}
							if ( $terms && ! is_wp_error( $terms ) ) {
								$first = reset( $terms );
								if ( ! empty( $first->name ) && ! in_array( strtolower( trim( $first->name ) ), array( 'undefined', 'null' ), true ) ) {
									return (string) $first->name;
								}
							}
						} else {
							$values = $attribute->get_options();
							if ( ! empty( $values ) ) {
								$val = reset( $values );
								if ( is_string( $val ) && '' !== trim( $val ) && ! in_array( strtolower( trim( $val ) ), array( 'undefined', 'null' ), true ) ) {
									return trim( $val );
								}
							}
						}
					}
				}
			}
		}

		// Om produkten är en variation och attribut inte gav något, kontrollera förälderns attribut
		if ( $parent_id > 0 ) {
			$parent = wc_get_product( $parent_id );
			if ( $parent && method_exists( $parent, 'get_attributes' ) ) {
				foreach ( $parent->get_attributes() as $key => $attribute ) {
					$label = strtolower( trim( $this->attribute_name( $key, $parent ) ) );
					if ( in_array( $label, array( 'brand', 'varumärke', 'varumarke', 'märke', 'marke', 'tillverkare' ), true ) ) {
						if ( $attribute instanceof WC_Product_Attribute ) {
							if ( $attribute->is_taxonomy() && taxonomy_exists( $attribute->get_name() ) ) {
								$terms = get_the_terms( $parent_id, $attribute->get_name() );
								if ( $terms && ! is_wp_error( $terms ) ) {
									$first = reset( $terms );
									if ( ! empty( $first->name ) && ! in_array( strtolower( trim( $first->name ) ), array( 'undefined', 'null' ), true ) ) {
										return (string) $first->name;
									}
								}
							} else {
								$values = $attribute->get_options();
								if ( ! empty( $values ) ) {
									$val = reset( $values );
									if ( is_string( $val ) && '' !== trim( $val ) && ! in_array( strtolower( trim( $val ) ), array( 'undefined', 'null' ), true ) ) {
										return trim( $val );
									}
								}
							}
						}
					}
				}
			}
		}

		// 3. Metafält för varumärke
		$meta_keys = array( '_brand', 'brand', '_product_brand', 'product_brand', '_varumarke', 'varumarke' );
		$meta_val  = $this->get_meta_first( $meta_keys, $product );
		if ( '' === trim( $meta_val ) && $parent_id > 0 ) {
			$parent_prod = wc_get_product( $parent_id );
			if ( $parent_prod ) {
				$meta_val = $this->get_meta_first( $meta_keys, $parent_prod );
			}
		}

		$brand = '';
		if ( '' !== trim( $meta_val ) ) {
			$brand = trim( $meta_val );
		} else {
			$brand = (string) apply_filters( 'woo_kustom_sello_brand', '', $product );
		}

		if ( in_array( strtolower( trim( $brand ) ), array( 'undefined', 'null' ), true ) ) {
			return '';
		}

		return trim( $brand );
	}

	/**
	 * Returnera första icke-tomma metavärdet bland angivna nycklar.
	 *
	 * @param string[]   $keys    Meta-nycklar i prioritetsordning.
	 * @param WC_Product $product Produkten eller variationen.
	 * @return string
	 */
	protected function get_meta_first( $keys, $product ) {
		foreach ( $keys as $key ) {
			$value = $product->get_meta( $key, true );
			if ( '' !== $value && null !== $value ) {
				$str = trim( (string) $value );
				if ( ! in_array( strtolower( $str ), array( 'undefined', 'null' ), true ) && '' !== $str ) {
					return $str;
				}
			}
		}
		return '';
	}

	/**
	 * Produktiterator som respekterar exportinställningarna.
	 *
	 * @return iterable<WC_Product>
	 */
	protected function get_products_iter() {
		$statuses = array( 'publish' );

		if ( ! empty( $this->settings['include_drafts'] ) ) {
			$statuses[] = 'draft';
		}
		if ( ! empty( $this->settings['include_private'] ) ) {
			$statuses[] = 'private';
		}

		$page = 1;
		do {
			$products = wc_get_products(
				array(
					'status'  => $statuses,
					'type'    => array( 'simple', 'variable' ),
					'limit'   => 100,
					'page'    => $page,
					'orderby' => 'ID',
					'order'   => 'ASC',
				)
			);

			foreach ( $products as $product ) {
				yield $product;
				if ( function_exists( 'clean_post_cache' ) && method_exists( $product, 'get_id' ) ) {
					clean_post_cache( $product->get_id() );
				}
			}

			$page++;
		} while ( count( $products ) === 100 );
	}

	/**
	 * Avgör om en produkt/variation ska inkluderas utifrån lagerstatus samt skick- och varumärkesfilter för Sello.
	 *
	 * Filtreringsregler:
	 * 1. Lagerstatus (instock eller om include_outofstock är aktiverat)
	 * 2. Skick-filter (all, vintage_only, new_only eller custom urval av Tradera-skick)
	 * 3. Varumärkesfilter (all eller include_only med utvalda varumärken)
	 *
	 * @param WC_Product      $product Produkten eller variationen.
	 * @param WC_Product|null $parent  Föräldraprodukten (om tillgänglig).
	 * @return bool
	 */
	public function include_product( $product, $parent = null ) {
		if ( ! $product || ! is_object( $product ) ) {
			return false;
		}

		// 1. Lagerstatus (instock / onbackorder / outofstock)
		$is_in_stock = method_exists( $product, 'is_in_stock' ) ? $product->is_in_stock() : in_array( $product->get_stock_status(), array( 'instock', 'onbackorder' ), true );
		if ( ! $is_in_stock && empty( $this->settings['include_outofstock'] ) ) {
			return false;
		}

		if ( null === $parent && method_exists( $product, 'is_type' ) && $product->is_type( 'variation' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );
		}
		if ( null === $parent ) {
			$parent = $product;
		}

		// 2. Skick-filter för Sello (Tradera)
		$cond_mode = ! empty( $this->settings['sello_filter_condition_mode'] ) ? $this->settings['sello_filter_condition_mode'] : 'all';
		if ( 'all' !== $cond_mode ) {
			$cond_data = $this->get_tradera_condition_data( $product, $parent );
			if ( 'vintage_only' === $cond_mode ) {
				// Vintage / begagnat: Tradera kräver Är begagnad: Yes (eller inte "Oanvänt")
				if ( 'Yes' !== $cond_data['is_used'] || 'Oanvänt' === $cond_data['condition'] ) {
					return false;
				}
			} elseif ( 'new_only' === $cond_mode ) {
				// Nya artiklar: Kräver Är begagnad: No och skick "Oanvänt"
				if ( 'No' !== $cond_data['is_used'] || 'Oanvänt' !== $cond_data['condition'] ) {
					return false;
				}
			} elseif ( 'custom' === $cond_mode ) {
				$allowed_conds = ! empty( $this->settings['sello_filter_conditions'] ) && is_array( $this->settings['sello_filter_conditions'] )
					? $this->settings['sello_filter_conditions']
					: array();
				if ( empty( $allowed_conds ) || ! in_array( $cond_data['condition'], $allowed_conds, true ) ) {
					return false;
				}
			}
		}

		// 3. Varumärkesfilter för Sello (Tradera)
		$brand_mode = ! empty( $this->settings['sello_filter_brand_mode'] ) ? $this->settings['sello_filter_brand_mode'] : 'all';
		if ( 'include_only' === $brand_mode ) {
			$allowed_brands = ! empty( $this->settings['sello_filter_brands'] ) && is_array( $this->settings['sello_filter_brands'] )
				? $this->settings['sello_filter_brands']
				: array();

			if ( ! empty( $allowed_brands ) ) {
				$brand = $this->get_brand( $parent );
				if ( '' === $brand && $product !== $parent ) {
					$brand = $this->get_brand( $product );
				}

				$matched     = false;
				$clean_brand = mb_strtolower( trim( (string) $brand ) );
				if ( '' !== $clean_brand ) {
					foreach ( $allowed_brands as $allowed ) {
						if ( mb_strtolower( trim( (string) $allowed ) ) === $clean_brand ) {
							$matched = true;
							break;
						}
					}
				}

				if ( ! $matched ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Skriv en CSV-rad med skydd mot CSV-injection. LF används som radslut.
	 *
	 * @param resource $out Filhandtag.
	 * @param array    $row Raddata.
	 */
	protected function write_row( $out, $row ) {
		$sanitized = array();
		foreach ( $row as $value ) {
			$sanitized[] = $this->sanitize_cell( (string) $value );
		}

		fwrite( $out, $this->csv_line( $sanitized ) . "\n" );
	}

	/**
	 * Bygg en CSV-rad enligt RFC 4180 med komma som avgränsare och dubbla
	 * citattecken runt fält som innehåller komma, citattecken eller radbrytningar.
	 *
	 * @param array $fields Fältvärden.
	 * @return string
	 */
	protected function csv_line( $fields ) {
		$line = '';

		foreach ( $fields as $index => $field ) {
			if ( 0 < $index ) {
				$line .= ',';
			}

			$quote = false !== strpos( $field, '"' )
				|| false !== strpos( $field, ',' )
				|| false !== strpos( $field, "\n" )
				|| false !== strpos( $field, "\r" );

			if ( $quote ) {
				$line .= '"' . str_replace( '"', '""', $field ) . '"';
			} else {
				$line .= $field;
			}
		}

		return $line;
	}

	/**
	 * Skydda cellvärde mot CSV-injection genom att prefixa farliga inledande tecken.
	 *
	 * @param mixed $value Cellvärde.
	 * @return mixed
	 */
	public function sanitize_cell( $value ) {
		if ( ! is_string( $value ) ) {
			return $value;
		}

		if ( '' !== $value ) {
			$first = $value[0];
			if ( '=' === $first || '@' === $first ) {
				return "'" . $value;
			}
			if ( ( '+' === $first || '-' === $first ) && preg_match( '/^[+\-][a-zA-Z_]/', $value ) ) {
				return "'" . $value;
			}
		}

		return $value;
	}
}
