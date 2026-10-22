<?php
/**
 * CSV-generator för Kustom POS produktimportformat.
 *
 * Strömmar CSV-rader direkt till php://output för att hålla minnesanvändningen
 * låg även vid stora produktkataloger.
 *
 * @package Woo_Kustom_Export
 */

defined( 'ABSPATH' ) || exit;

/**
 * Klass WOO_Kustom_CSV_Generator
 *
 * Hämtar produkter via WooCommerce API:er och generera CSV-rader i Kustoms format.
 */
class WOO_Kustom_CSV_Generator {

	/**
	 * Inställningar för exporten.
	 *
	 * @var array
	 */
	protected $settings;

	/**
	 * Valfria kolumner och deras standardläge (avslagna tills formatet verifierats mot Kustoms exempelfiler).
	 *
	 * @var array<string,bool>
	 */
	protected $optional_columns = array(
		'sku'           => false,
		'category'       => false,
		'ean'            => false,
		'image_url'      => false,
		'stock_status'   => false,
	);

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
			'custom_attribute'   => '',
		);

		$this->settings = wp_parse_args( (array) $settings, $defaults );

		foreach ( array_keys( $this->optional_columns ) as $key ) {
			$this->optional_columns[ $key ] = ! empty( $this->settings['optional_columns'][ $key ] );
		}

		$this->settings['custom_attribute'] = $this->sanitize_custom_attribute( $this->settings['custom_attribute'] );
	}

	/**
	 * Sanera valt eget attribut: endast kända attributrubriker tillåts.
	 *
	 * @param string $label Vald attributrubrik.
	 * @return string
	 */
	protected function sanitize_custom_attribute( $label ) {
		$label = strtolower( trim( (string) wp_specialchars_decode( $label, ENT_QUOTES ) ) );
		if ( '' === $label ) {
			return '';
		}
		return in_array( $label, $this->get_available_attributes(), true ) ? $label : '';
	}

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
		$filename = 'kustom-products-' . gmdate( 'Y-m-d-Hi' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$out = fopen( 'php://output', 'wb' );
		if ( ! $out ) {
			wp_die( esc_html__( 'Kunde inte öppna utdataström för CSV-exporten.', 'woo-kustom-export' ) );
		}

		$header = $this->collect_columns();
		$this->write_row( $out, $header );

		$this->stream_rows( $out );

		fclose( $out );
		exit;
	}

	/**
	 * Samla alla kolumnnamn för rubrikraden: obligatoriska, valda valfria och attributkolumner.
	 *
	 * @return string[]
	 */
	public function collect_columns() {
		$columns = array( 'product_name', 'price_including_vat', 'vat_rate', 'currency' );

		foreach ( $this->optional_columns as $key => $enabled ) {
			if ( $enabled ) {
				$columns[] = $key;
			}
		}


		$attributes = $this->collect_variation_attributes();
		if ( ! empty( $this->settings['custom_attribute'] ) && ! in_array( $this->settings['custom_attribute'], $attributes, true ) ) {
			$attributes[] = $this->settings['custom_attribute'];
		}

		return array_merge( $columns, array_values( $attributes ) );
	}

	/**
	 * Samla alla attributrubriker som finns i katalogen (globala och produktspecifika),
	 * sorterade alfabetiskt, för dropdown-valet i admin.
	 *
	 * @return string[]
	 */
	public function get_available_attributes() {
		$attributes = array();

		foreach ( $this->get_products_iter() as $product ) {
			foreach ( $product->get_attributes() as $taxonomy => $data ) {
				$label = $this->attribute_label( $taxonomy, $product );
				if ( '' !== $label && ! in_array( $label, $attributes, true ) ) {
						$attributes[] = $label;
					}
				}
			}

		sort( $attributes, SORT_STRING | SORT_FLAG_CASE );

		return $attributes;
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
	 * Strömma produkt- och variationsrader till filhandtaget.
	 *
	 * @param resource $out Filhandtag (php://output).
	 */
	protected function stream_rows( $out ) {
		$currency = strtoupper( get_woocommerce_currency() );
		$columns  = $this->collect_columns();

		foreach ( $this->get_products_iter() as $product ) {
			$template = array_fill_keys( $columns, '' );

			if ( $product->is_type( 'variable' ) ) {
				$this->stream_variable_rows( $out, $product, $currency, $template );
			} elseif ( $this->include_product( $product ) ) {
				$row = array_merge( $template, $this->build_base_row( $product, $product, $currency ) );
				$row = array_merge( $row, $this->build_custom_attribute_cell( $product, $product ) );
				$this->write_row( $out, $row );
			}
		}
	}

	/**
	 * Strömma alla variationsrader för en variabel produkt.
	 *
	 * @param resource   $out     Filhandtag.
	 * @param WC_Product $product Föräldraprodukten.
	 * @param string     $currency Butikens valuta.
	 * @param array      $template Radmall med rubrikens kolumnordning.
	 */
	protected function stream_variable_rows( $out, $product, $currency, $template ) {
		foreach ( $product->get_children() as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( ! $variation || ! $variation->exists() || ! $this->include_product( $variation ) ) {
				continue;
			}

			$row = array_merge( $template, $this->build_base_row( $variation, $product, $currency ) );
			$row = array_merge( $row, $this->build_custom_attribute_cell( $variation, $product ) );

			foreach ( $variation->get_attributes() as $taxonomy => $value ) {
				$label = $this->attribute_label( $taxonomy, $product );
				if ( '' === $label || ! array_key_exists( $label, $row ) ) {
					continue;
				}
				$row[ $label ] = $this->attribute_value( $taxonomy, $value );
			}

			$this->write_row( $out, $row );
		}
	}

	/**
	 * Bygg cellvärdet för det valda egna attributet, som alltid ligger som sista kolumn.
	 *
	 * För variationer används variationens värde; om variationen saknar värde
	 * faller vi tillbaka på föräldraproduktens attributvärde.
	 *
	 * @param WC_Product $product Produkten/variationen.
	 * @param WC_Product $parent  Föräldraprodukten.
	 * @return array
	 */
	protected function build_custom_attribute_cell( $product, $parent ) {
		$selected = (string) $this->settings['custom_attribute'];
		if ( '' === $selected ) {
			return array();
		}

		$value = $this->get_attribute_value_by_label( $product, $selected, $parent );
		if ( '' === $value && $parent && $parent->get_id() !== $product->get_id() ) {
			$value = $this->get_attribute_value_by_label( $parent, $selected, $parent );
		}

		return array( $selected => $value );
	}

	/**
	 * Hämta en produkts värde för attributet med given rubrik.
	 *
	 * Hanterar både variationer (värde är slug-strängar) och enkla/variabla
	 * produkter (värde är WC_Product_Attribute-objekt).
	 *
	 * @param WC_Product $product Produkten/variationen.
	 * @param string     $label   Efterfrågad attributrubrik.
	 * @param WC_Product $parent  Föräldraprodukten (för attribute_label).
	 * @return string
	 */
	protected function get_attribute_value_by_label( $product, $label, $parent ) {
		foreach ( $product->get_attributes() as $taxonomy => $value ) {
			if ( $label !== $this->attribute_label( $taxonomy, $parent ) ) {
				continue;
			}

			if ( $value instanceof WC_Product_Attribute ) {
				$options = $value->get_options();
				if ( empty( $options ) ) {
					return '';
				}
				return $this->attribute_value( $taxonomy, (string) reset( $options ) );
			}

			if ( '' !== (string) $value ) {
				return $this->attribute_value( $taxonomy, $value );
			}
		}

		return '';
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
	 * Bygg grundrad (obligatoriska + valda valfria kolumner) för en produkt eller variation.
	 *
	 * @param WC_Product $product    Produkten/variationen som radens data hämtas från.
	 * @param WC_Product $parent     Föräldraprodukten (samma som $product för enkla produkter).
	 * @param string     $currency    Butikens valuta.
	 * @return array
	 */
	protected function build_base_row( $product, $parent, $currency ) {
		$row = array(
			'product_name'         => $parent->get_name(),
			'price_including_vat'  => $this->get_price_including_vat( $product ),
			'vat_rate'             => $this->get_vat_rate( $product ),
			'currency'             => $currency,
		);

		if ( $this->optional_columns['sku'] ) {
			$row['sku'] = $product->get_sku();
		}

		if ( $this->optional_columns['category'] ) {
			$row['category'] = $this->get_primary_category( $parent );
		}

		if ( $this->optional_columns['ean'] ) {
			$row['ean'] = $this->get_ean( $product );
		}

		if ( $this->optional_columns['image_url'] ) {
			$row['image_url'] = $this->get_image_url( $product, $parent );
		}

		if ( $this->optional_columns['stock_status'] ) {
			$row['stock_status'] = 'instock' === $product->get_stock_status() ? 'instock' : 'outofstock';
		}

		return $row;
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
	 * Hämta EAN/GTIN från vanliga WooCommerce-fält eller meta-nycklar.
	 *
	 * @param WC_Product $product Produkten eller variationen.
	 * @return string
	 */
	protected function get_ean( $product ) {
		$keys = (array) apply_filters(
			'woo_kustom_export_ean_meta_keys',
			array( '_ean', 'ean', '_gtin', 'gtin', '_global_unique_id', 'global_unique_id' ),
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
	 * Pris inklusive moms, punkt som decimaltecken, utan tusentalsavgränsare.
	 *
	 * @param WC_Product $product Produkten eller variationen.
	 * @return string
	 */
	protected function get_price_including_vat( $product ) {
		$price = wc_get_price_including_tax( $product, array( 'price' => $product->get_price() ) );
		return number_format( (float) $price, 2, '.', '' );
	}

	/**
	 * Hämta momssats i procent utifrån produktens tax class, annars butikens standardmomssats.
	 *
	 * @param WC_Product $product Produkten eller variationen.
	 * @return float
	 */
	protected function get_vat_rate( $product ) {
		$tax_class = $product->get_tax_class();
		$rates     = $tax_class ? WC_Tax::get_rates_for_tax_class( $tax_class ) : WC_Tax::get_rates_for_tax_class( '' );

		if ( empty( $rates ) && '' !== $tax_class ) {
			$rates = WC_Tax::get_rates_for_tax_class( '' );
		}

		if ( ! empty( $rates ) ) {
			$rate = reset( $rates );
			return (float) $rate->tax_rate;
		}

		return (float) apply_filters( 'woo_kustom_export_default_vat_rate', 0.0, $product );
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
	 * @param string $value Cellvärde.
	 * @return string
	 */
	protected function sanitize_cell( $value ) {
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@' ), true ) ) {
			return "'" . $value;
		}
		return $value;
	}
}
