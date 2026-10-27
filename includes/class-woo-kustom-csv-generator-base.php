<?php
/**
 * Basklass för CSV-generatorer med gemensam produkt hämtning, formatmäpning och RFC 4180-utskrift.
 *
 * @package Woo_Kustom_Export
 */

defined( 'ABSPATH' ) || exit;

/**
 * Klass WOO_Kustom_CSV_Generator_Base
 */
abstract class WOO_Kustom_CSV_Generator_Base {

	/**
	 * Inställningar för exporten.
	 *
	 * @var array
	 */
	protected $settings;

	/**
	 * Konstruktor.
	 *
	 * @param array $settings Exportinställningar.
	 */
	public function __construct( $settings = array() ) {
		$defaults = array(
			'include_drafts'      => 0,
			'include_private'     => 0,
			'include_outofstock'  => 0,
			'optional_columns'   => array(),
		);

		$this->settings = wp_parse_args( (array) $settings, $defaults );
	}

	/**
	 * Filnamnsprefix för nedladdningen, exempelvis "kustom-products".
	 *
	 * @return string
	 */
	abstract public function get_filename_prefix();

	/**
	 * Filändelse för nedladdningen.
	 *
	 * @return string
	 */
	public function get_filename_extension() {
		return 'csv';
	}

	/**
	 * Kolumnrubriker för formatet.
	 *
	 * @return string[]
	 */
	abstract public function collect_columns();

	/**
	 * Bygg en rad för en enkel produkt eller variation.
	 *
	 * @param WC_Product $product  Produkten/variationen som radens data hämtas från.
	 * @param WC_Product $parent   Föräldraprodukten (samma som $product för enkla produkter).
	 * @return array
	 */
	abstract protected function build_row( $product, $parent );

	/**
	 * Produktstatistik (antal enkla produkter/produktfamiljer och antal variationsrader).
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

		// UTF-8 BOM för att garantera korrekt teckenkodning (Å, Ä, Ö) i bl.a. Excel.
		fwrite( $out, "\xEF\xBB\xBF" );

		$this->write_row( $out, $this->collect_columns() );

		foreach ( $this->get_rows_iter() as $row ) {
			$this->write_row( $out, $row );
		}

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

		// UTF-8 BOM för att garantera korrekt teckenkodning (Å, Ä, Ö) i bl.a. Excel.
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
	 * Iterera färdiga rader (rubrikraden exkluderad) som associerade arrayer
	 * med samma nycklar som collect_columns(). Generatorer håller minnesanvändningen
	 * låg eftersom bara en produktfamilj finns i minnet åt gången.
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
				yield array_merge( $template, $this->build_row( $product, $product ) );
			}
		}
	}

	/**
	 * Iterera variationsrader för en variabel produkt.
	 *
	 * @param WC_Product $product  Föräldraprodukten.
	 * @param array      $template Radmall med rubrikens kolumnordning.
	 * @return iterable<array>
	 */
	protected function get_variable_rows_iter( $product, $template ) {
		foreach ( $product->get_children() as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( ! $variation || ! $variation->exists() || ! $this->include_product( $variation ) ) {
				continue;
			}

			$row = array_merge( $template, $this->build_row( $variation, $product ) );

			foreach ( $variation->get_attributes() as $taxonomy => $value ) {
				$label = $this->attribute_label( $taxonomy, $product );
				if ( '' === $label || ! array_key_exists( $label, $row ) ) {
					continue;
				}
				$row[ $label ] = $this->attribute_value( $taxonomy, $value );
			}

			yield $row;
		}
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

		// Större kataloger: hämta i sidor om 100 för att begränsa minnesanvändningen.
		$page = 1;
		do {
			$products = wc_get_products(
				array(
					'status' => $statuses,
					'type'   => array( 'simple', 'variable' ),
					'limit'  => 100,
					'page'   => $page,
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
	 * Attributets läsbara namn i gemener, används som kolumnrubrik.
	 *
	 * @param string     $taxonomy Taxonomi (pa_...) eller lokalt attributnamn.
	 * @param WC_Product $product  Föräldraprodukten.
	 * @return string
	 */
	protected function attribute_label( $taxonomy, $product ) {
		$label = $taxonomy;

		if ( 0 === strpos( $taxonomy, 'pa_' ) && taxonomy_exists( $taxonomy ) ) {
			$label = wc_attribute_label( $taxonomy, $product );
		} else {
			$product_attributes = $product->get_attributes();
			if ( isset( $product_attributes[ $taxonomy ] ) ) {
				$label = $product_attributes[ $taxonomy ]->get_name();
			}
		}

		return strtolower( trim( wp_specialchars_decode( $label, ENT_QUOTES ) ) );
	}

	/**
	 * Attributvärde: termens namn för taxonomier, annars råvärdet.
	 *
	 * @param string $taxonomy Taxonomi eller attributnamn.
	 * @param string $value    Variantens valda värde (slug).
	 * @return string
	 */
	protected function attribute_value( $taxonomy, $value ) {
		if ( taxonomy_exists( $taxonomy ) && $value ) {
			$term = get_term_by( 'slug', $value, $taxonomy );
			if ( $term && ! is_wp_error( $term ) ) {
				return $term->name;
			}
		}
		return $value;
	}

	/**
	 * Samla attributrubriker (läsbara namn i gemener) som används av variationer.
	 *
	 * @return string[]
	 */
	protected function collect_variation_attributes() {
		$attributes = array();

		foreach ( $this->get_products_iter() as $product ) {
			if ( ! $product->is_type( 'variable' ) ) {
				continue;
			}
			foreach ( $product->get_variation_attributes() as $taxonomy => $values ) {
				$label = $this->attribute_label( $taxonomy, $product );
				if ( ! in_array( $label, $attributes, true ) ) {
					$attributes[] = $label;
				}
			}
		}

		return $attributes;
	}

	/**
	 * Pris inklusive moms, punkt som decimaltecken, utan tusentalsavgränsare.
	 * Använder sale_price om den finns, annars regular_price.
	 * Säkerställer att priset alltid är inklusive moms.
	 *
	 * @param WC_Product      $product Produkten eller variationen.
	 * @param WC_Product|null $parent  Föräldraprodukten (om tillgänglig).
	 * @return string
	 */
	protected function get_price_including_vat( $product, $parent = null ) {
		$sale_price    = $product->get_sale_price();
		$regular_price = $product->get_regular_price();

		$raw_price = '';
		if ( '' !== $sale_price && null !== $sale_price && (float) $sale_price > 0 ) {
			$raw_price = $sale_price;
		} elseif ( '' !== $regular_price && null !== $regular_price ) {
			$raw_price = $regular_price;
		} else {
			$raw_price = $product->get_price();
		}

		if ( '' === $raw_price || null === $raw_price ) {
			return '0.00';
		}

		$raw_float = (float) $raw_price;

		// Om produkten inte är momspliktig (tax_status !== 'taxable').
		if ( method_exists( $product, 'is_taxable' ) && ! $product->is_taxable() ) {
			return number_format( $raw_float, 2, '.', '' );
		}

		$price_inc = null;

		// Försök först använda WooCommerces inbyggda skattemotor.
		if ( function_exists( 'wc_get_price_including_tax' ) ) {
			$calc = (float) wc_get_price_including_tax( $product, array( 'price' => $raw_float ) );
			$prices_include_tax = function_exists( 'wc_prices_include_tax' ) ? wc_prices_include_tax() : false;
			if ( $prices_include_tax || $calc > $raw_float ) {
				$price_inc = $calc;
			}
		}

		// Fallback om wc_get_price_including_tax inte lade på moms (t.ex. vid saknade skatteregler i DB).
		if ( null === $price_inc ) {
			$vat_rate = $this->get_vat_rate( $product, $parent );
			if ( $vat_rate > 0.0 ) {
				$price_inc = $raw_float * ( 1.0 + ( $vat_rate / 100.0 ) );
			} else {
				$price_inc = $raw_float;
			}
		}

		return number_format( (float) $price_inc, 2, '.', '' );
	}

	/**
	 * Pris exklusive moms, punkt som decimaltecken, utan tusentalsavgränsare.
	 * Använder sale_price om den finns, annars regular_price.
	 *
	 * @param WC_Product      $product Produkten eller variationen.
	 * @param WC_Product|null $parent  Föräldraprodukten (om tillgänglig).
	 * @return string
	 */
	protected function get_price_excluding_vat( $product, $parent = null ) {
		$sale_price    = $product->get_sale_price();
		$regular_price = $product->get_regular_price();

		$raw_price = '';
		if ( '' !== $sale_price && null !== $sale_price && (float) $sale_price > 0 ) {
			$raw_price = $sale_price;
		} elseif ( '' !== $regular_price && null !== $regular_price ) {
			$raw_price = $regular_price;
		} else {
			$raw_price = $product->get_price();
		}

		if ( '' === $raw_price || null === $raw_price ) {
			return '0.00';
		}

		$raw_float = (float) $raw_price;

		if ( method_exists( $product, 'is_taxable' ) && ! $product->is_taxable() ) {
			return number_format( $raw_float, 2, '.', '' );
		}

		$price_ex = null;
		if ( function_exists( 'wc_get_price_excluding_tax' ) ) {
			$calc = (float) wc_get_price_excluding_tax( $product, array( 'price' => $raw_float ) );
			$prices_include_tax = function_exists( 'wc_prices_include_tax' ) ? wc_prices_include_tax() : false;
			if ( ! $prices_include_tax || $calc < $raw_float ) {
				$price_ex = $calc;
			}
		}

		if ( null === $price_ex ) {
			$vat_rate = $this->get_vat_rate( $product, $parent );
			if ( $vat_rate > 0.0 ) {
				$price_ex = $raw_float / ( 1.0 + ( $vat_rate / 100.0 ) );
			} else {
				$price_ex = $raw_float;
			}
		}

		return number_format( (float) $price_ex, 2, '.', '' );
	}

	/**
	 * Hämta momssats i procent utifrån produktens skattestatus (Tax status) och skatteklass (Tax class).
	 * Kontrollerar skattestatus (taxable/none), skatteklass, variationer som ärver förälderns skatteklass,
	 * prisskillnad via WooCommerces skattemotor, WC_Tax-satser för butikens basland,
	 * samt mappning av svenska momsklasser (standard = 25%, reduced = 12%, super-reduced = 6%, zero = 0%).
	 *
	 * @param WC_Product      $product Produkten eller variationen.
	 * @param WC_Product|null $parent  Föräldraprodukten (om tillgänglig).
	 * @return float
	 */
	protected function get_vat_rate( $product, $parent = null ) {
		// 1. Kontrollera skattestatus (Tax status).
		// Om produkten inte är skattepliktig (tax_status === 'none' eller 'shipping'), returnera 0.
		if ( method_exists( $product, 'is_taxable' ) && ! $product->is_taxable() ) {
			return 0.0;
		}
		if ( method_exists( $product, 'get_tax_status' ) && 'taxable' !== $product->get_tax_status() ) {
			return 0.0;
		}

		$rate = null;

		// 2. Beräkna direkt från produktens priser via WooCommerces skattemotor.
		// Detta tar hänsyn till butikens aktiva skatteregler, zoner och skattesatser.
		if ( function_exists( 'wc_get_price_including_tax' ) && function_exists( 'wc_get_price_excluding_tax' ) ) {
			$dummy_price = 1000.0;
			$price_ex    = (float) wc_get_price_excluding_tax( $product, array( 'price' => $dummy_price ) );
			$price_inc   = (float) wc_get_price_including_tax( $product, array( 'price' => $dummy_price ) );

			if ( $price_ex > 0.0 && $price_inc > $price_ex ) {
				$rate = round( ( ( $price_inc - $price_ex ) / $price_ex ) * 100.0, 2 );
			}
		}

		// 3. Om beräkningen inte gav utslag, kontrollera skatteklasser via WC_Tax.
		if ( null === $rate || 0.0 === (float) $rate ) {
			$tax_class = $product->get_tax_class();
			if ( ( 'parent' === $tax_class || empty( $tax_class ) ) && $parent && $parent !== $product ) {
				$tax_class = $parent->get_tax_class();
			}

			// Normalisera om tax_class är satt till strängen "standard" -> standardklass är tom sträng i WooCommerce.
			$query_class = ( 'standard' === strtolower( (string) $tax_class ) ) ? '' : $tax_class;

			if ( class_exists( 'WC_Tax' ) ) {
				// Hämta satser för butikens basplats.
				$rates = WC_Tax::get_rates( $query_class );
				if ( ! empty( $rates ) ) {
					$first = reset( $rates );
					if ( isset( $first['rate'] ) && (float) $first['rate'] > 0 ) {
						$rate = (float) $first['rate'];
					}
				}

				if ( null === $rate || 0.0 === (float) $rate ) {
					$db_rates = WC_Tax::get_rates_for_tax_class( $query_class );
					if ( empty( $db_rates ) && '' !== $query_class ) {
						$db_rates = WC_Tax::get_rates_for_tax_class( '' );
					}
					if ( ! empty( $db_rates ) ) {
						$first_obj = reset( $db_rates );
						if ( isset( $first_obj->tax_rate ) && (float) $first_obj->tax_rate > 0 ) {
							$rate = (float) $first_obj->tax_rate;
						}
					}
				}

				if ( ( null === $rate || 0.0 === (float) $rate ) && method_exists( 'WC_Tax', 'get_base_tax_rates' ) ) {
					$base_rates = WC_Tax::get_base_tax_rates( $query_class );
					if ( ! empty( $base_rates ) ) {
						$first_base = reset( $base_rates );
						if ( isset( $first_base['rate'] ) && (float) $first_base['rate'] > 0 ) {
							$rate = (float) $first_base['rate'];
						}
					}
				}
			}
		}

		// 4. Mappning baserad på skatteklassens namn/slug (Tax class mappad mot momssats).
		if ( null === $rate || 0.0 === (float) $rate ) {
			$tax_class = strtolower( trim( (string) $product->get_tax_class() ) );
			if ( ( 'parent' === $tax_class || empty( $tax_class ) ) && $parent && $parent !== $product ) {
				$tax_class = strtolower( trim( (string) $parent->get_tax_class() ) );
			}

			if ( in_array( $tax_class, array( 'zero-rate', 'zero', '0', 'noll', 'nollmoms' ), true ) ) {
				$rate = 0.0;
			} elseif ( in_array( $tax_class, array( 'reduced-rate', 'reducerad', '12', 'moms-12', 'livsmedel', 'food' ), true ) ) {
				$rate = 12.0;
			} elseif ( in_array( $tax_class, array( 'super-reduced-rate', 'super-reduced', '6', 'moms-6', 'böcker', 'bocker', 'bokmoms', 'books' ), true ) ) {
				$rate = 6.0;
			} elseif ( preg_match( '/(\d+(?:[\.,]\d+)?)/', $tax_class, $matches ) ) {
				$extracted = (float) str_replace( ',', '.', $matches[1] );
				if ( $extracted > 0 ) {
					$rate = $extracted;
				}
			}
		}

		// 5. Fallback för svenska butiker / SEK: standardmoms blir oftast 25% i Sverige.
		if ( null === $rate || 0.0 === (float) $rate ) {
			$tax_class = strtolower( trim( (string) $product->get_tax_class() ) );
			if ( 'zero-rate' !== $tax_class && '0' !== $tax_class ) {
				$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'SEK';
				$country  = '';
				if ( function_exists( 'WC' ) && WC()->countries ) {
					$country = WC()->countries->get_base_country();
				}
				if ( empty( $country ) ) {
					$country = get_option( 'woocommerce_default_country' );
				}

				if ( 'SEK' === strtoupper( (string) $currency ) || empty( $country ) || 0 === strpos( (string) $country, 'SE' ) ) {
					$rate = 25.0;
				} else {
					$rate = 0.0;
				}
			} else {
				$rate = 0.0;
			}
		}

		$rate = (float) apply_filters( 'woo_kustom_export_default_vat_rate', $rate, $product, $parent );
		return (float) apply_filters( 'woo_kustom_export_vat_rate', $rate, $product, $parent );
	}

	/**
	 * Hämta EAN/GTIN från vanliga WooCommerce-fält eller meta-nycklar.
	 *
	 * @param WC_Product $product Produkten eller variationen.
	 * @return string
	 */
	protected function get_ean( $product ) {
		$keys = (array) apply_filters(
			'woo_kustom_export_ean_meta_keys',
			array( '_ean', 'ean', '_gtin', 'gtin', '_global_unique_id', 'global_unique_id', '_barcode', 'barcode' ),
			$product
		);

		foreach ( $keys as $key ) {
			$value = $product->get_meta( $key, true );
			if ( '' !== $value && null !== $value ) {
				return (string) $value;
			}
		}

		if ( method_exists( $product, 'get_global_unique_id' ) ) {
			$guid = $product->get_global_unique_id();
			if ( $guid ) {
				return (string) $guid;
			}
		}

		return '';
	}

	/**
	 * Fullständig URL till huvudbild; variantbild om den finns, annars förälderns.
	 *
	 * @param WC_Product $product Produkten/variationen.
	 * @param WC_Product $parent  Föräldraprodukten.
	 * @return string
	 */
	protected function get_image_url( $product, $parent ) {
		$image_id = $product->get_image_id();
		if ( ! $image_id && $parent ) {
			$image_id = $parent->get_image_id();
		}
		if ( ! $image_id ) {
			return '';
		}
		$url = wp_get_attachment_image_url( (int) $image_id, 'full' );
		return $url ? $url : '';
	}

	/**
	 * Produktens primära kategori (första kategorin, aldrig kommaseparerad).
	 *
	 * @param WC_Product $product Produkten.
	 * @return string
	 */
	protected function get_primary_category( $product ) {
		$ids = $product->get_category_ids();
		if ( empty( $ids ) ) {
			return '';
		}
		$primary_id = (int) apply_filters( 'woo_kustom_export_primary_category_id', (int) reset( $ids ), $product );
		$term = get_term_by( 'id', $primary_id, 'product_cat' );
		return ( $term && ! is_wp_error( $term ) ) ? $term->name : '';
	}

	/**
	 * Skriv en CSV-rad med skydd mot CSV-injection. LF används som radslut.
	 *
	 * @param resource $out Filhandtag.
	 * @param array     $row Raddata.
	 */
	protected function write_row( $out, $row ) {
		$sanitized = array();
		foreach ( $row as $value ) {
			$sanitized[] = $this->sanitize_cell( (string) $value );
		}

		fwrite( $out, $this->csv_line( $sanitized ) . "\n" );
	}

	/**
	 * Bygg en CSV-rad enligt RFC 4180: komma som avgränsare och dubbla citattecken
	 * runt fält som innehåller komma, citattecken eller radbrytningar.
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
