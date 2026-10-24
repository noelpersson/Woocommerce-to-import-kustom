<?php
/**
 * CSV-generator för Sello Excel-importformat.
 *
 * Kolumnnamn följer Sellos officiella dokumentation för Excel-import:
 * - Identifierare: Sku (krävs för matchning vid uppdatering).
 * - Texter: Standard name / Standard description (standardtexter som gäller alla kanaler).
 * - Priser: Sales price (pris exklusive moms) och VAT rate (momssats i procent).
 * - EAN/GTIN: Property EAN / Property GTIN (attributkolumner enligt Sellos egendomskonvention).
 * - Bilder: Images (fullständiga URL:er, kommaseparerade).
 * - Grupper för varianter: Group sku och Group type (color, model eller size).
 *
 * @package Woo_Kustom_Export
 */

defined( 'ABSPATH' ) || exit;

/**
 * Klass WOO_Kustom_Sello_CSV_Generator
 */
class WOO_Kustom_Sello_CSV_Generator extends WOO_Kustom_CSV_Generator_Base {

	/**
	 * Filnamnsprefix.
	 *
	 * @return string
	 */
	public function get_filename_prefix() {
		return 'sello-products-';
	}

	/**
	 * Samla alla kolumnnamn för rubrikraden.
	 *
	 * @return string[]
	 */
	public function collect_columns() {
		return array(
			'Sku',
			'Standard name',
			'Standard description',
			'Price',
			'VAT rate',
			'Property EAN',
			'Property GTIN',
			'Images',
			'Group sku',
			'Group type',
			'Stock',
		);
	}

	/**
	 * Bygg rad för en enkel produkt eller variation.
	 *
	 * @param WC_Product $product Produkten/variationen som radens data hämtas från.
	 * @param WC_Product $parent  Föräldraprodukten (samma som $product för enkla produkter).
	 * @return array
	 */
	protected function build_row( $product, $parent ) {
		$sku   = $product->get_sku();
		$is_variation = $product->is_type( 'variation' );

		$row = array(
			'Sku'                 => $sku,
			'Standard name'       => $this->get_standard_name( $product, $parent ),
			'Standard description' => $this->get_standard_description( $parent ),
			'Price'               => $this->get_price_excluding_vat( $product ),
			'VAT rate'            => $this->get_vat_rate( $product ),
			'Property EAN'        => $this->get_ean( $product ),
			'Property GTIN'       => $this->get_ean( $product ),
			'Images'              => $this->get_image_url( $product, $parent ),
			'Group sku'           => '',
			'Group type'          => '',
			'Stock'               => $this->get_stock_quantity( $product ),
		);

		if ( $is_variation && $sku ) {
			$parent_sku = $parent->get_sku() ? $parent->get_sku() : $this->derive_parent_sku( $parent, $sku );
			$group_type = $this->get_group_type( $parent, $sku );

			$row['Group sku']  = $parent_sku;
			$row['Group type'] = $group_type;
		}

		return $row;
	}

	/**
	 * Standardnamn: förälderns titel plus attributvärden för variationer.
	 *
	 * @param WC_Product $product Produkten/variationen.
	 * @param WC_Product $parent  Föräldraprodukten.
	 * @return string
	 */
	protected function get_standard_name( $product, $parent ) {
		$name = $parent->get_name();

		if ( $product->is_type( 'variation' ) ) {
			$parts = array();
			foreach ( $product->get_attributes() as $taxonomy => $value ) {
				$parts[] = $this->attribute_value( $taxonomy, $value );
			}
			if ( $parts ) {
				$name .= ' - ' . implode( ', ', array_filter( $parts ) );
			}
		}

		return $name;
	}

	/**
	 * Standardbeskrivning: föräldraproduktens kortbeskrivning eller beskrivning.
	 *
	 * @param WC_Product $parent Föräldraprodukten.
	 * @return string
	 */
	protected function get_standard_description( $parent ) {
		$description = $parent->get_short_description();
		if ( '' === $description ) {
			$description = $parent->get_description();
		}
		return wp_strip_all_tags( (string) $description );
	}

	/**
	 * Lagerkvantitet: null när lagerstatus inte är instock.
	 *
	 * @param WC_Product $product Produkten eller variationen.
	 * @return string
	 */
	protected function get_stock_quantity( $product ) {
		if ( 'instock' !== $product->get_stock_status() ) {
			return '0';
		}
		if ( $product->get_manage_stock() ) {
			return (string) max( 0, (int) $product->get_stock_quantity() );
		}
		return '';
	}

	/**
	 * Härled en gruppsku för föräldern om den saknar egen SKU.
	 *
	 * @param WC_Product $parent      Föräldraprodukten.
	 * @param string     $variant_sku Variationens SKU.
	 * @return string
	 */
	protected function derive_parent_sku( $parent, $variant_sku ) {
		$derived = preg_replace( '/[-_ ].*$/', '', (string) $variant_sku );
		return $derived ? $derived : (string) $parent->get_id();
	}

	/**
	 * Grupptyp för variationen: color, model eller size baserat på förälderns attribut.
	 *
	 * @param WC_Product $parent      Föräldraprodukten.
	 * @param string     $variant_sku Variationens SKU.
	 * @return string
	 */
	protected function get_group_type( $parent, $variant_sku ) {
		$attributes = $parent->get_variation_attributes();

		if ( empty( $attributes ) ) {
			return 'model';
		}

		$taxonomy = (string) key( $attributes );
		$label    = strtolower( $this->attribute_label( $taxonomy, $parent ) );

		if ( in_array( $label, array( 'color', 'colour', 'färg' ), true ) ) {
			return 'color';
		}
		if ( in_array( $label, array( 'size', 'storlek' ), true ) ) {
			return 'size';
		}

		return 'model';
	}
}
