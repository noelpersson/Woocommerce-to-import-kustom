<?php
/**
 * CSV-generator för Sellos Excel-importformat.
 *
 * Genererar CSV-rader med Sellos standardkolumnnamn (Sku, Property EAN,
 * Property GTIN, Standard name sv, Price, Purchase price, Calculate m.fl.) som kan
 * öppnas i Excel eller importeras direkt till Sello. Raderna strömmas
 * direkt till php://output för att minimera minnesanvändning.
 *
 * @package Woo_Kustom_Export
 */

defined( 'ABSPATH' ) || exit;

/**
 * Klass WOO_Sello_CSV_Generator
 *
 * Hämtar produkter via WooCommerce API:er och genererar CSV-rader i Sellos format.
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
		);

		$this->settings = wp_parse_args( (array) $settings, $defaults );
	}

	/**
	 * Kolumnrubriker i Sellos format.
	 *
	 * @return string[]
	 */
	public function collect_columns() {
		return array(
			'Sku',
			'Property EAN',
			'Property GTIN',
			'Standard name sv',
			'Standard description sv',
			'Price',
			'Purchase price',
			'Calculate',
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
	 * Produktstatistik (antal produktfamiljer och antal variationsrader).
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
		return 'sello-products-';
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

		$this->write_row( $out, $this->collect_columns() );
		$this->stream_rows( $out );

		fclose( $out );
		exit;
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
	 * minnesanvändningen låg eftersom bara en produktfamilj finns i minnet åt gången.
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
	 * Iterera variationsrader för en variabel produkt.
	 *
	 * Varianter med ett attribut som matchar Sellos grupptyper (color, size,
	 * model) grupperas via Group sku och Group type.
	 *
	 * @param WC_Product $product  Föräldraprodukten.
	 * @param array      $template Radmall med rubrikens kolumnordning.
	 * @return iterable<array>
	 */
	protected function get_variable_rows_iter( $product, $template ) {
		$parent_sku = $this->get_sku( $product, $product );
		$group_type = $this->get_group_type( $product );

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
	 * Avgör grupptyp för en variabel produkt: color, size eller model om något
	 * av produktens variationsattribut matchar, annars tom sträng.
	 *
	 * @param WC_Product $product Föräldraprodukten.
	 * @return string
	 */
	protected function get_group_type( $product ) {
		foreach ( $product->get_variation_attributes() as $taxonomy => $values ) {
			$label = strtolower( trim( $this->attribute_name( $taxonomy, $product ) ) );
			if ( in_array( $label, $this->group_types, true ) ) {
				return $label;
			}
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
		$ean  = $this->get_ean( $product, $parent );
		$gtin = $this->get_gtin( $product, $parent );

		if ( '' === $ean && '' !== $gtin ) {
			$ean = $gtin;
		} elseif ( '' === $gtin && '' !== $ean ) {
			$gtin = $ean;
		}

		$price = $this->get_price_excluding_vat( $product );

		return array(
			'Sku'                     => $this->get_sku( $product, $parent ),
			'Property EAN'            => $ean,
			'Property GTIN'           => $gtin,
			'Standard name sv'        => $parent->get_name(),
			'Standard description sv' => $this->get_description( $parent ),
			'Price'                   => $price,
			'Purchase price'          => $this->get_purchase_price( $product, $parent ),
			'Calculate'               => $this->get_calculate( $product, $parent, $price ),
			'Quantity'                => $this->get_quantity( $product ),
			'Tax'                     => $this->get_tax_rate( $product, $parent ),
			'Weight'                  => $this->get_weight_grams( $product, $parent ),
			'Images'                  => $this->get_images( $product, $parent ),
			'Brand'                   => $this->get_brand( $parent ),
		);
	}

	/**
	 * Hämta SKU för produkt eller variation med robust fallback.
	 * Om SKU saknas i WooCommerce används produktens post-ID eller parent-sku kombination.
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
					$sku = (string) $product->get_id();
				}
			} else {
				$sku = (string) $product->get_id();
			}
		}

		return (string) apply_filters( 'woo_kustom_sello_sku', trim( $sku ), $product, $parent );
	}

	/**
	 * Försäljningspris exklusive moms, avrundat till 2 decimaler med punkt.
	 *
	 * @param WC_Product $product Produkten eller variationen.
	 * @return string
	 */
	protected function get_price_excluding_vat( $product ) {
		$price = $product->get_price();
		if ( '' === $price || null === $price ) {
			return '';
		}

		if ( function_exists( 'wc_get_price_excluding_tax' ) ) {
			$price_ex = wc_get_price_excluding_tax( $product, array( 'price' => $price ) );
		} else {
			$price_ex = (float) $price;
		}

		$formatted = number_format( (float) $price_ex, 2, '.', '' );
		return (string) apply_filters( 'woo_kustom_sello_price', $formatted, $product );
	}

	/**
	 * Värde för kolumnen Calculate.
	 *
	 * Sello kräver obligatoriskt kolumnen "Calculate" när prisfält ingår vid import/uppdatering,
	 * eftersom den instruerar systemet hur prisberäkningen ska hanteras.
	 * Sätts normalt till 'true' för rader med priser.
	 *
	 * @param WC_Product      $product Produkten eller variationen.
	 * @param WC_Product|null $parent  Föräldraprodukten.
	 * @param string          $price   Försäljningspris exklusive moms.
	 * @return string
	 */
	protected function get_calculate( $product, $parent = null, $price = '' ) {
		$calculate = 'true';
		return (string) apply_filters( 'woo_kustom_sello_calculate', $calculate, $product, $parent, $price );
	}

	/**
	 * Inköpspris (Purchase price) från kända WooCommerce-metafält.
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
			return number_format( (float) $cost, 2, '.', '' );
		}

		return '';
	}

	/**
	 * Produktbeskrivning: kort beskrivning om den finns, annars fullständig.
	 *
	 * @param WC_Product $product Föräldraprodukten.
	 * @return string
	 */
	protected function get_description( $product ) {
		$short = $product->get_short_description();
		if ( '' !== trim( wp_strip_all_tags( $short ) ) ) {
			return wp_strip_all_tags( $short );
		}
		return wp_strip_all_tags( $product->get_description() );
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
	 * Momssats avrundad till närmsta giltiga Sello-värde (25, 12, 6, 0).
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

		// 3. Fallback: om butiken är svensk och produkten är skattepliktig, standardisera till 25%.
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
	 * Vikt i gram (WooCommerce lagrar vikt i butikens viktenhet, normalt kg).
	 * Ärver från föräldraprodukt om variation saknar egen vikt.
	 *
	 * @param WC_Product      $product Produkten eller variationen.
	 * @param WC_Product|null $parent  Föräldraprodukten.
	 * @return string
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
		if ( 0.0 === $weight_float ) {
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
	 * Bilder: huvudbild och galleri som kommaseparerad URL-lista.
	 * För varianter används variantbild om den finns, annars förälderns bilder.
	 *
	 * @param WC_Product $product Produkten/variationen.
	 * @param WC_Product $parent  Föräldraprodukten.
	 * @return string
	 */
	protected function get_images( $product, $parent ) {
		$image_ids = array();

		$variation_image_id = $parent !== $product ? $product->get_image_id() : 0;
		if ( $variation_image_id ) {
			$image_ids[] = (int) $variation_image_id;
		}

		$image_ids = array_merge( $image_ids, array_map( 'intval', $parent->get_gallery_image_ids() ) );

		if ( empty( $image_ids ) ) {
			$parent_image_id = $parent->get_image_id();
			if ( $parent_image_id ) {
				$image_ids[] = (int) $parent_image_id;
			}
		}

		$urls = array();
		foreach ( array_unique( $image_ids ) as $image_id ) {
			$url = wp_get_attachment_image_url( $image_id, 'full' );
			if ( $url ) {
				$urls[] = $url;
			}
		}

		return implode( ',', $urls );
	}

	/**
	 * Märke från pa_brand-taxonomin eller ett brand-attribut.
	 *
	 * @param WC_Product $product Föräldraprodukten.
	 * @return string
	 */
	protected function get_brand( $product ) {
		$terms = get_the_terms( $product->get_id(), 'pa_brand' );
		if ( $terms && ! is_wp_error( $terms ) ) {
			return reset( $terms )->name;
		}

		foreach ( $product->get_attributes() as $key => $attribute ) {
			$label = strtolower( trim( $this->attribute_name( $key, $product ) ) );
			if ( 'brand' !== $label && 'märke' !== $label ) {
				continue;
			}

			if ( $attribute instanceof WC_Product_Attribute_Taxonomy && taxonomy_exists( $attribute->get_name() ) ) {
				$terms = get_the_terms( $product->get_id(), $attribute->get_name() );
				if ( $terms && ! is_wp_error( $terms ) ) {
					return reset( $terms )->name;
				}
			} elseif ( $attribute instanceof WC_Product_Attribute ) {
				$values = $attribute->get_options();
				if ( ! empty( $values ) ) {
					return reset( $values );
				}
			}
		}

		return '';
	}

	/**
	 * EAN från vanliga WooCommerce-meta-nycklar och plugins.
	 * Ärver från föräldraprodukt om variation saknar egen streckkod.
	 *
	 * @param WC_Product      $product Produkten eller variationen.
	 * @param WC_Product|null $parent  Föräldraprodukten.
	 * @return string
	 */
	protected function get_ean( $product, $parent = null ) {
		$keys = (array) apply_filters(
			'woo_kustom_sello_ean_meta_keys',
			array(
				'_ean',
				'ean',
				'_barcode',
				'barcode',
				'_gtin',
				'gtin',
				'_alg_ean',
				'alg_ean',
				'_global_unique_id',
				'global_unique_id',
				'_upc',
				'upc',
				'_ts_gtin',
				'ts_gtin',
				'_wpm_gtin_code',
				'wpm_gtin_code',
				'hwp_product_gtin',
			),
			$product
		);

		$ean = $this->get_meta_first( $keys, $product );
		if ( ( '' === $ean || null === $ean ) && $parent && $parent !== $product ) {
			$ean = $this->get_meta_first( $keys, $parent );
		}

		return (string) apply_filters( 'woo_kustom_sello_product_ean', $ean, $product, $parent );
	}

	/**
	 * GTIN från vanliga WooCommerce-meta-nycklar och plugins.
	 * Ärver från föräldraprodukt och faller tillbaka på EAN om GTIN saknas.
	 *
	 * @param WC_Product      $product Produkten eller variationen.
	 * @param WC_Product|null $parent  Föräldraprodukten.
	 * @return string
	 */
	protected function get_gtin( $product, $parent = null ) {
		$keys = (array) apply_filters(
			'woo_kustom_sello_gtin_meta_keys',
			array(
				'_gtin',
				'gtin',
				'_ean',
				'ean',
				'_barcode',
				'barcode',
				'_alg_ean',
				'alg_ean',
				'_global_unique_id',
				'global_unique_id',
				'_upc',
				'upc',
			),
			$product
		);

		$gtin = $this->get_meta_first( $keys, $product );
		if ( ( '' === $gtin || null === $gtin ) && $parent && $parent !== $product ) {
			$gtin = $this->get_meta_first( $keys, $parent );
		}

		if ( '' === $gtin || null === $gtin ) {
			$gtin = $this->get_ean( $product, $parent );
		}

		return (string) apply_filters( 'woo_kustom_sello_product_gtin', $gtin, $product, $parent );
	}

	/**
	 * Returnera första icke-tomma metavärdet bland nycklarna.
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
	 * Produktiterator som respekterar inställningarna.
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
	protected function sanitize_cell( $value ) {
		if ( ! is_string( $value ) ) {
			return $value;
		}

		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@' ), true ) ) {
			return "'" . $value;
		}

		return $value;
	}
}
