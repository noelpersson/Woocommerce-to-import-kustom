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
		parent::__construct( $settings );

		foreach ( array_keys( $this->optional_columns ) as $key ) {
			$this->optional_columns[ $key ] = ! empty( $this->settings['optional_columns'][ $key ] );
		}
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

		return array_merge( $columns, array_values( $this->collect_variation_attributes() ) );
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
}
