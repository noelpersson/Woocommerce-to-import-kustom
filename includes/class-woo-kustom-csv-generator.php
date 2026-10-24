<?php
/**
 * CSV-generator för Kustom POS produktimportformat.
 *
 * @package Woo_Kustom_Export
 */

defined( 'ABSPATH' ) || exit;

/**
 * Klass WOO_Kustom_CSV_Generator
 */
class WOO_Kustom_CSV_Generator extends WOO_Kustom_CSV_Generator_Base {

	/**
	 * Valfria kolumner och deras standardläge (avslagna tills formatet verifierats mot Kustoms exempelfiler).
	 *
	 * @var array<string,bool>
	 */
	protected $optional_columns = array(
		'sku'          => false,
		'category'     => false,
		'ean'          => false,
		'image_url'    => false,
		'stock_status' => false,
	);

	/**
	 * Intern cache för attributrubriker, undviker upprepade katalogskanningar.
	 *
	 * @var array<string,string[]>
	 */
	protected $attribute_cache = array();

	/**
	 * Intern cache för variationsattributrubriker.
	 *
	 * @var array<string,string[]>
	 */
	protected $variation_attribute_cache = array();

	/**
	 * Konstruktor.
	 *
	 * @param array $settings Exportinställningar.
	 */
	public function __construct( $settings = array() ) {
		parent::__construct( $settings );

		foreach ( array_keys( $this->optional_columns ) as $key ) {
			$this->optional_columns[ $key ] = ! empty( $this->settings['optional_columns'][ $key ] );
		}

		if ( ! isset( $this->settings['custom_attribute'] ) ) {
			$this->settings['custom_attribute'] = '';
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
	 * Filnamnsprefix.
	 *
	 * @return string
	 */
	public function get_filename_prefix() {
		return 'kustom-products-';
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
		$selected   = (string) $this->settings['custom_attribute'];

		if ( '' !== $selected ) {
			$attributes = array_values(
				array_filter(
					$attributes,
					static function ( $label ) use ( $selected ) {
						return $label !== $selected;
					}
				)
			);
			$attributes[] = $selected;
		}

		return array_merge( $columns, $attributes );
	}

	/**
	 * Samla alla attributrubriker som finns i katalogen (globala och produktspecifika),
	 * sorterade alfabetiskt, för dropdown-valet i admin.
	 *
	 * @return string[]
	 */
	public function get_available_attributes() {
		$statuses_key = md5( wp_json_encode( $this->get_status_scope() ) );
		if ( isset( $this->attribute_cache[ $statuses_key ] ) ) {
			return $this->attribute_cache[ $statuses_key ];
		}

		$attributes = array();
		$reserved   = array_merge(
			array( 'product_name', 'price_including_vat', 'vat_rate', 'currency' ),
			array_keys( $this->optional_columns )
		);

		foreach ( $this->get_products_iter() as $product ) {
			foreach ( $product->get_attributes() as $taxonomy => $data ) {
				$label = $this->attribute_label( $taxonomy, $product );
				if ( '' === $label || in_array( $label, $reserved, true ) ) {
					continue;
				}
				if ( ! in_array( $label, $attributes, true ) ) {
					$attributes[] = $label;
				}
			}
		}

		sort( $attributes, SORT_STRING | SORT_FLAG_CASE );
		$this->attribute_cache[ $statuses_key ] = $attributes;

		return $attributes;
	}

	/**
	 * Hitta enkla produkter med flera värden på det valda egna attributet.
	 *
	 * CSV-formatet har en cell per rad, så endast det första värdet kan
	 * exporteras för sådana produkter. Returnerar produkter som påverkas.
	 *
	 * @return array<int,array{name:string,count:int}> Produkter med fler än ett värde.
	 */
	public function get_multi_value_simple_products() {
		$selected = (string) $this->settings['custom_attribute'];
		if ( '' === $selected ) {
			return array();
		}

		$affected = array();

		foreach ( $this->get_products_iter() as $product ) {
			if ( $product->is_type( 'variable' ) ) {
				continue;
			}

			foreach ( $product->get_attributes() as $taxonomy => $value ) {
				if ( $selected !== $this->attribute_label( $taxonomy, $product ) ) {
					continue;
				}

				if ( ! $value instanceof WC_Product_Attribute ) {
					continue;
				}

				$options = $value->get_options();
				if ( count( $options ) > 1 ) {
					$affected[] = array(
						'name'  => $product->get_name(),
						'count' => count( $options ),
					);
				}
				break;
			}
		}

		return $affected;
	}

	/**
	 * Bygg grundrad (obligatoriska + valda valfria kolumner) för en produkt eller variation.
	 *
	 * @param WC_Product $product Produkten/variationen som radens data hämtas från.
	 * @param WC_Product $parent  Föräldraprodukten (samma som $product för enkla produkter).
	 * @return array
	 */
	protected function build_row( $product, $parent ) {
		$currency = strtoupper( get_woocommerce_currency() );

		$row = array(
			'product_name'        => $parent->get_name(),
			'price_including_vat' => $this->get_price_including_vat( $product ),
			'vat_rate'            => $this->get_vat_rate( $product ),
			'currency'            => $currency,
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

		if ( ! $product->is_type( 'variation' ) ) {
			$row = array_merge( $row, $this->build_custom_attribute_cell( $product, $parent ) );
		}

		return $row;
	}

	/**
	 * Strömma alla variationsrader för en variabel produkt.
	 *
	 * @param resource   $out      Filhandtag.
	 * @param WC_Product $product  Föräldraprodukten.
	 * @param array      $template Radmall med rubrikens kolumnordning.
	 */
	protected function stream_variable_rows( $out, $product, $template ) {
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

			$row = array_merge( $row, $this->build_custom_attribute_cell( $variation, $product ) );

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
				return $this->attribute_option_value( $value );
			}

			if ( '' !== (string) $value ) {
				return $this->attribute_value( $taxonomy, $value );
			}
		}

		return '';
	}

	/**
	 * Hämta det första attributvärdet ur ett WC_Product_Attribute-objekt.
	 *
	 * För taxonomiattribut innehåller get_options() term-ID:n som måste slås
	 * upp mot termens namn; för lokala attribut är alternativen fritext.
	 *
	 * @param WC_Product_Attribute $attribute Attributobjektet.
	 * @return string
	 */
	protected function attribute_option_value( $attribute ) {
		$options = $attribute->get_options();
		if ( empty( $options ) ) {
			return '';
		}

		$option = reset( $options );

		if ( $attribute->is_taxonomy() ) {
			$term = get_term_by( 'id', (int) $option, $attribute->get_name() );
			return ( $term && ! is_wp_error( $term ) ) ? $term->name : '';
		}

		return (string) $option;
	}

	/**
	 * Produktstatusar som omfattas av aktuella inställningar (cache-nyckel).
	 *
	 * @return string[]
	 */
	protected function get_status_scope() {
		$statuses = array( 'publish' );

		if ( ! empty( $this->settings['include_drafts'] ) ) {
			$statuses[] = 'draft';
		}
		if ( ! empty( $this->settings['include_private'] ) ) {
			$statuses[] = 'private';
		}

		return $statuses;
	}

	/**
	 * Samla attributrubriker (läsbara namn i gemener) som används av variationer.
	 *
	 * @return string[]
	 */
	protected function collect_variation_attributes() {
		$statuses_key = md5( wp_json_encode( $this->get_status_scope() ) );
		if ( isset( $this->variation_attribute_cache[ $statuses_key ] ) ) {
			return $this->variation_attribute_cache[ $statuses_key ];
		}

		$attributes = parent::collect_variation_attributes();
		$this->variation_attribute_cache[ $statuses_key ] = $attributes;

		return $attributes;
	}
}
