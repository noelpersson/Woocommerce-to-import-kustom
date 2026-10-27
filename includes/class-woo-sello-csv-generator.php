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
			'include_drafts'     => 0,
			'include_private'    => 0,
			'include_outofstock' => 0,
			'sello_columns'      => array(),
		);

		$this->settings = wp_parse_args( (array) $settings, $defaults );
	}

	/**
	 * Samtliga 12 tillgängliga standardkolumner för Sello/Tradera.
	 *
	 * @return string[]
	 */
	public static function get_all_columns_list() {
		return array(
			'Sku',
			'Standard name sv',
			'Standard description sv',
			'Tradera price store',
			'Purchase price',
			'Quantity',
			'Tax',
			'Weight',
			'Images',
			'Brand',
			'Group sku',
			'Group type',
		);
	}

	/**
	 * Metadata och etiketter för valbara Sello-kolumner.
	 *
	 * @return array<string,array{label:string,desc:string,code:string,required:bool}>
	 */
	public static function get_supported_columns_meta() {
		return array(
			'Sku'                     => array(
				'label'    => __( 'Artikelnummer', 'woo-kustom-export' ),
				'desc'     => __( 'Unikt artikelnummer (SKU). Rekommenderas för alla artiklar.', 'woo-kustom-export' ),
				'code'     => 'Sku',
				'required' => true,
			),
			'Standard name sv'        => array(
				'label'    => __( 'Produktnamn', 'woo-kustom-export' ),
				'desc'     => __( 'Produktnamn på svenska för butik och marknadsplats.', 'woo-kustom-export' ),
				'code'     => 'Standard name sv',
				'required' => false,
			),
			'Standard description sv' => array(
				'label'    => __( 'Produktbeskrivning', 'woo-kustom-export' ),
				'desc'     => __( 'Ren textbeskrivning utan HTML-taggar.', 'woo-kustom-export' ),
				'code'     => 'Standard description sv',
				'required' => false,
			),
			'Tradera price store'     => array(
				'label'    => __( 'Tradera butikspris', 'woo-kustom-export' ),
				'desc'     => __( 'Fast försäljningspris i heltal utan valuta.', 'woo-kustom-export' ),
				'code'     => 'Tradera price store',
				'required' => false,
			),
			'Purchase price'          => array(
				'label'    => __( 'Inköpspris', 'woo-kustom-export' ),
				'desc'     => __( 'Inköpspris/kalkylpris om angivet i WooCommerce.', 'woo-kustom-export' ),
				'code'     => 'Purchase price',
				'required' => false,
			),
			'Quantity'                => array(
				'label'    => __( 'Lagersaldo', 'woo-kustom-export' ),
				'desc'     => __( 'Aktuellt lagersaldo som heltal.', 'woo-kustom-export' ),
				'code'     => 'Quantity',
				'required' => false,
			),
			'Tax'                     => array(
				'label'    => __( 'Momssats', 'woo-kustom-export' ),
				'desc'     => __( 'Momssats i procent som heltal (t.ex. 25).', 'woo-kustom-export' ),
				'code'     => 'Tax',
				'required' => false,
			),
			'Weight'                  => array(
				'label'    => __( 'Vikt', 'woo-kustom-export' ),
				'desc'     => __( 'Produktens vikt konverterad till gram.', 'woo-kustom-export' ),
				'code'     => 'Weight',
				'required' => false,
			),
			'Images'                  => array(
				'label'    => __( 'Produktbilder', 'woo-kustom-export' ),
				'desc'     => __( 'Fullständiga https:// bild-URL:er separerade med komma.', 'woo-kustom-export' ),
				'code'     => 'Images',
				'required' => false,
			),
			'Brand'                   => array(
				'label'    => __( 'Varumärke', 'woo-kustom-export' ),
				'desc'     => __( 'Varumärke hämtat från taxonomi pa_brand eller attribut.', 'woo-kustom-export' ),
				'code'     => 'Brand',
				'required' => false,
			),
			'Group sku'               => array(
				'label'    => __( 'Grupp-SKU', 'woo-kustom-export' ),
				'desc'     => __( 'Huvudproduktens SKU för variantgruppering.', 'woo-kustom-export' ),
				'code'     => 'Group sku',
				'required' => false,
			),
			'Group type'              => array(
				'label'    => __( 'Grupptyp', 'woo-kustom-export' ),
				'desc'     => __( 'Variantdimension (color, size, model).', 'woo-kustom-export' ),
				'code'     => 'Group type',
				'required' => false,
			),
		);
	}

	/**
	 * Kolumnrubriker i Sellos format för Tradera butiksobjekt.
	 *
	 * Som standard är samtliga 12 kolumner aktiva. Om användaren har sparat
	 * specifika kolumnval filtreras kolumnerna utifrån inställningarna.
	 *
	 * @return string[]
	 */
	public function collect_columns() {
		$all_columns = self::get_all_columns_list();

		// Om inga specifika kolumnval har sparats är alla 12 kolumner ibockade som standard
		if ( ! isset( $this->settings['sello_columns'] ) || ! is_array( $this->settings['sello_columns'] ) || empty( $this->settings['sello_columns'] ) ) {
			return $all_columns;
		}

		$selected = array();
		foreach ( $all_columns as $col ) {
			if ( ! empty( $this->settings['sello_columns'][ $col ] ) ) {
				$selected[] = $col;
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
					if ( ! $this->include_product( $variation ) ) {
						continue;
					}
					$var_count++;
				}
			} elseif ( $this->include_product( $product ) ) {
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
		$filename = $this->get_filename_prefix() . gmdate( 'Y-m-d-Hi' ) . '.' . $this->get_filename_extension();

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

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
			} elseif ( $this->include_product( $product ) ) {
				yield array_merge( $template, $this->build_base_row( $product, $product ) );
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
					if ( ! $variation || ! $variation->exists() || ! $this->include_product( $variation ) ) {
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
			} elseif ( $this->include_product( $product ) ) {
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
		if ( $include_parent && $this->include_product( $product ) ) {
			$parent_row = array_merge( $template, $this->build_base_row( $product, $product ) );
			// Huvudprodukten ska inte få något värde i Tradera price store om den själv inte ska säljas separat
			$parent_row['Tradera price store'] = '';
			if ( $parent_sku && $group_type ) {
				$parent_row['Group sku']  = $parent_sku;
				$parent_row['Group type'] = $group_type;
			}
			yield $parent_row;
		}

		foreach ( $product->get_children() as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( ! $variation || ! $variation->exists() || ! $this->include_product( $variation ) ) {
				continue;
			}

			$row = array_merge( $template, $this->build_base_row( $variation, $product ) );

			if ( $parent_sku && $group_type ) {
				$row['Group sku']  = $parent_sku;
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

		return array(
			'Sku'                     => $this->get_sku( $product, $parent ),
			'Standard name sv'        => $parent->get_name(),
			'Standard description sv' => $this->get_clean_description( $product, $parent ),
			'Tradera price store'     => $is_parent_variable ? '' : $this->get_tradera_price_store( $product, $parent ),
			'Purchase price'          => $this->get_purchase_price( $product, $parent ),
			'Quantity'                => $this->get_quantity( $product ),
			'Tax'                     => $this->get_tax_rate( $product, $parent ),
			'Weight'                  => $this->get_weight_grams( $product, $parent ),
			'Images'                  => $this->get_images( $product, $parent ),
			'Brand'                   => $this->get_brand( $parent ),
			'Group sku'               => '',
			'Group type'              => '',
		);
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
	 * Produktbeskrivning som ren text utan HTML-taggar.
	 *
	 * - Tar bort kortkoder.
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

		// 2. Ersätt radbrytande HTML-taggar med en radbrytning för att bevara stycke- och liststruktur
		$desc = preg_replace( '/<br\s*\/?>/i', "\n", $desc );
		$desc = preg_replace( '/<\/(?:p|div|li|tr|h[1-6])>/i', "\n", $desc );

		// 3. Ta bort resterande HTML-taggar
		if ( function_exists( 'wp_strip_all_tags' ) ) {
			$desc = wp_strip_all_tags( $desc, false );
		} else {
			$desc = strip_tags( $desc );
		}

		// 4. Avkoda HTML-entiteter (&amp;, &aring;, &quot;, &#39; etc.)
		$desc = html_entity_decode( $desc, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// 5. Normalisera radbrytningar (\r\n och \r blir \n)
		$desc = str_replace( array( "\r\n", "\r" ), "\n", $desc );

		// 6. Rensa onödiga blanksteg i slutet av varje rad
		$lines       = explode( "\n", $desc );
		$clean_lines = array();
		foreach ( $lines as $line ) {
			$clean_lines[] = trim( $line );
		}
		$desc = implode( "\n", $clean_lines );

		// 7. Komprimera multipla på varandra följande tomma rader (max 2 \n = 1 tom rad emellan stycken)
		$desc = preg_replace( "/\n{3,}/", "\n\n", $desc );

		$desc = trim( $desc );

		return (string) apply_filters( 'woo_kustom_sello_description', $desc, $product, $parent );
	}

	/**
	 * Lagersaldo: heltalet i lager, 0 för produkter ur lager, tomt om lager inte hanteras.
	 *
	 * @param WC_Product $product Produkten eller variationen.
	 * @return string
	 */
	protected function get_quantity( $product ) {
		if ( ! $product->get_manage_stock() ) {
			return '';
		}
		return (string) (int) $product->get_stock_quantity();
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
	 * Märke/varumärke från taxonomi (pa_brand, pa_varumarke m.fl.), produktattribut eller metafält.
	 *
	 * @param WC_Product $product Produkten eller föräldraprodukten.
	 * @return string
	 */
	protected function get_brand( $product ) {
		if ( ! $product ) {
			return '';
		}

		$product_id = $product->get_id();

		// 1. Kända varumärkestaxonomier
		$known_taxonomies = array( 'pa_brand', 'pa_varumarke', 'pa_marke', 'yith_product_brand', 'pwb-brand', 'brand' );
		foreach ( $known_taxonomies as $tax ) {
			if ( taxonomy_exists( $tax ) ) {
				$terms = get_the_terms( $product_id, $tax );
				if ( $terms && ! is_wp_error( $terms ) ) {
					$first = reset( $terms );
					if ( ! empty( $first->name ) ) {
						return (string) $first->name;
					}
				}
			}
		}

		// 2. Produktattribut med etikett brand, varumärke eller tillverkare
		foreach ( $product->get_attributes() as $key => $attribute ) {
			$label = strtolower( trim( $this->attribute_name( $key, $product ) ) );
			if ( in_array( $label, array( 'brand', 'varumärke', 'varumarke', 'märke', 'marke', 'tillverkare' ), true ) ) {
				if ( $attribute instanceof WC_Product_Attribute_Taxonomy && taxonomy_exists( $attribute->get_name() ) ) {
					$terms = get_the_terms( $product_id, $attribute->get_name() );
					if ( $terms && ! is_wp_error( $terms ) ) {
						$first = reset( $terms );
						if ( ! empty( $first->name ) ) {
							return (string) $first->name;
						}
					}
				} elseif ( $attribute instanceof WC_Product_Attribute ) {
					$values = $attribute->get_options();
					if ( ! empty( $values ) ) {
						$val = reset( $values );
						if ( is_string( $val ) && '' !== trim( $val ) ) {
							return trim( $val );
						}
					}
				}
			}
		}

		// 3. Metafält för varumärke
		$meta_keys = array( '_brand', 'brand', '_product_brand', 'product_brand', '_varumarke', 'varumarke' );
		$meta_val  = $this->get_meta_first( $meta_keys, $product );
		if ( '' !== trim( $meta_val ) ) {
			return trim( $meta_val );
		}

		return (string) apply_filters( 'woo_kustom_sello_brand', '', $product );
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
				return (string) $value;
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
			}

			$page++;
		} while ( count( $products ) === 100 );
	}

	/**
	 * Avgör om en produkt/variation ska inkluderas utifrån lagerstatusinställningen.
	 *
	 * @param WC_Product $product Produkten eller variationen.
	 * @return bool
	 */
	protected function include_product( $product ) {
		if ( 'instock' === $product->get_stock_status() ) {
			return true;
		}
		return ! empty( $this->settings['include_outofstock'] );
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

		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@' ), true ) ) {
			return "'" . $value;
		}

		return $value;
	}
}
