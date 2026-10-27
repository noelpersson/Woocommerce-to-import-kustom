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
	 * Valfria kolumner och deras aktiva status.
	 *
	 * @var array<string,bool>
	 */
	protected $optional_columns = array();

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
	 * Godkända kolumner i Kustom POS datamodell vid produktimport.
	 *
	 * Kustom POS har ett strikt importschema och ignorerar egna anpassade kolumner.
	 *
	 * @return string[]
	 */
	public static function get_approved_columns() {
		return array(
			'product_name',
			'price_including_vat',
			'vat_rate',
			'currency',
			'sku',
			'product_id',
			'parent_sku',
			'description',
			'category',
			'ean',
			'image_url',
		);
	}

	/**
	 * Hämta alla stödda valfria kolumner från WooCommerce med etiketter, grupp och beskrivning.
	 *
	 * @return array<string,array{label:string,group:string,desc:string}>
	 */
	public static function get_supported_optional_columns() {
		return array(
			// Grupp: Identifiering
			'sku'                => array(
				'label' => __( 'Artikelnummer (SKU)', 'woo-kustom-export' ),
				'group' => __( 'Identifiering & Grunddata', 'woo-kustom-export' ),
				'desc'  => __( 'Produktens eller variantens unika artikelnummer.', 'woo-kustom-export' ),
			),
			'product_id'         => array(
				'label' => __( 'Produkt-ID / Variant-ID', 'woo-kustom-export' ),
				'group' => __( 'Identifiering & Grunddata', 'woo-kustom-export' ),
				'desc'  => __( 'WooCommerce interna numeriska post-ID.', 'woo-kustom-export' ),
			),
			'parent_id'          => array(
				'label' => __( 'Föräldra-ID (Parent ID)', 'woo-kustom-export' ),
				'group' => __( 'Identifiering & Grunddata', 'woo-kustom-export' ),
				'desc'  => __( 'ID för föräldraprodukten vid varianter (post_parent).', 'woo-kustom-export' ),
			),
			'parent_sku'         => array(
				'label' => __( 'Föräldra-SKU', 'woo-kustom-export' ),
				'group' => __( 'Identifiering & Grunddata', 'woo-kustom-export' ),
				'desc'  => __( 'Artikelnummer för föräldraprodukten vid varianter.', 'woo-kustom-export' ),
			),
			'product_type'       => array(
				'label' => __( 'Produkttyp (Type)', 'woo-kustom-export' ),
				'group' => __( 'Identifiering & Grunddata', 'woo-kustom-export' ),
				'desc'  => __( 'Typ av produkt, t.ex. simple eller variation.', 'woo-kustom-export' ),
			),
			'status'             => array(
				'label' => __( 'Produktstatus (Status)', 'woo-kustom-export' ),
				'group' => __( 'Identifiering & Grunddata', 'woo-kustom-export' ),
				'desc'  => __( 'Produktens status i WooCommerce (t.ex. publish eller draft).', 'woo-kustom-export' ),
			),

			// Grupp: Priser & Moms
			'regular_price'      => array(
				'label' => __( 'Ordinarie pris (inkl. moms)', 'woo-kustom-export' ),
				'group' => __( 'Priser & Moms', 'woo-kustom-export' ),
				'desc'  => __( 'Standardpris inklusive moms.', 'woo-kustom-export' ),
			),
			'sale_price'         => array(
				'label' => __( 'Reapris (inkl. moms)', 'woo-kustom-export' ),
				'group' => __( 'Priser & Moms', 'woo-kustom-export' ),
				'desc'  => __( 'Kampanj- eller reapris inklusive moms om aktivt.', 'woo-kustom-export' ),
			),
			'price_excluding_vat'=> array(
				'label' => __( 'Pris exkl. moms', 'woo-kustom-export' ),
				'group' => __( 'Priser & Moms', 'woo-kustom-export' ),
				'desc'  => __( 'Aktuellt försäljningspris exklusive moms.', 'woo-kustom-export' ),
			),
			'tax_class'          => array(
				'label' => __( 'Momsklass (Tax class)', 'woo-kustom-export' ),
				'group' => __( 'Priser & Moms', 'woo-kustom-export' ),
				'desc'  => __( 'Momsklassens slug i WooCommerce (t.ex. standard eller reduced-rate).', 'woo-kustom-export' ),
			),
			'tax_status'         => array(
				'label' => __( 'Skattestatus (Tax status)', 'woo-kustom-export' ),
				'group' => __( 'Priser & Moms', 'woo-kustom-export' ),
				'desc'  => __( 'Anger om produkten är momspliktig (taxable, none, shipping).', 'woo-kustom-export' ),
			),

			// Grupp: Lager
			'stock_quantity'     => array(
				'label' => __( 'Lagersaldo (antal)', 'woo-kustom-export' ),
				'group' => __( 'Lager & Tillgänglighet', 'woo-kustom-export' ),
				'desc'  => __( 'Faktiskt antal enheter i lager.', 'woo-kustom-export' ),
			),
			'stock_status'       => array(
				'label' => __( 'Lagerstatus', 'woo-kustom-export' ),
				'group' => __( 'Lager & Tillgänglighet', 'woo-kustom-export' ),
				'desc'  => __( 'Lagerstatus: instock eller outofstock.', 'woo-kustom-export' ),
			),
			'manage_stock'       => array(
				'label' => __( 'Lagerhantering', 'woo-kustom-export' ),
				'group' => __( 'Lager & Tillgänglighet', 'woo-kustom-export' ),
				'desc'  => __( 'Anger om lagersaldo hanteras (yes / no).', 'woo-kustom-export' ),
			),

			// Grupp: Streckkoder
			'gtin'               => array(
				'label' => __( 'GTIN / EAN-streckkod', 'woo-kustom-export' ),
				'group' => __( 'Streckkoder & Koder', 'woo-kustom-export' ),
				'desc'  => __( 'GTIN / EAN-streckkod för kassa och scanner.', 'woo-kustom-export' ),
			),
			'ean'                => array(
				'label' => __( 'EAN / GTIN / Streckkod', 'woo-kustom-export' ),
				'group' => __( 'Streckkoder & Koder', 'woo-kustom-export' ),
				'desc'  => __( 'Hämtas från standardfält eller metafält (_ean, barcode etc.).', 'woo-kustom-export' ),
			),

			// Grupp: Beskrivning & Text
			'description'        => array(
				'label' => __( 'Produktbeskrivning', 'woo-kustom-export' ),
				'group' => __( 'Beskrivning & Text', 'woo-kustom-export' ),
				'desc'  => __( 'Fullständig beskrivning (rensad från HTML-taggar).', 'woo-kustom-export' ),
			),
			'short_description'  => array(
				'label' => __( 'Kort beskrivning', 'woo-kustom-export' ),
				'group' => __( 'Beskrivning & Text', 'woo-kustom-export' ),
				'desc'  => __( 'Kort produktbeskrivning / sammanfattning.', 'woo-kustom-export' ),
			),

			// Grupp: Kategorier & Taggar
			'category'           => array(
				'label' => __( 'Primär kategori', 'woo-kustom-export' ),
				'group' => __( 'Kategorier & Taggar', 'woo-kustom-export' ),
				'desc'  => __( 'Produktens första/huvudsakliga varukategori.', 'woo-kustom-export' ),
			),
			'category_tree'      => array(
				'label' => __( 'Produktkategori (Kategoriträd)', 'woo-kustom-export' ),
				'group' => __( 'Kategorier & Taggar', 'woo-kustom-export' ),
				'desc'  => __( 'Produktens kategori anpassad för Kustom POS.', 'woo-kustom-export' ),
			),
			'categories'         => array(
				'label' => __( 'Alla kategorier', 'woo-kustom-export' ),
				'group' => __( 'Kategorier & Taggar', 'woo-kustom-export' ),
				'desc'  => __( 'Alla tilldelade kategorier, kommaseparerade.', 'woo-kustom-export' ),
			),
			'tags'               => array(
				'label' => __( 'Produkttaggar', 'woo-kustom-export' ),
				'group' => __( 'Kategorier & Taggar', 'woo-kustom-export' ),
				'desc'  => __( 'Tilldelade produkttaggar, kommaseparerade.', 'woo-kustom-export' ),
			),

			// Grupp: Mått & Frakt
			'weight'             => array(
				'label' => __( 'Vikt', 'woo-kustom-export' ),
				'group' => __( 'Mått & Frakt', 'woo-kustom-export' ),
				'desc'  => __( 'Produktens vikt enligt butikens viktenhet.', 'woo-kustom-export' ),
			),
			'length'             => array(
				'label' => __( 'Längd', 'woo-kustom-export' ),
				'group' => __( 'Mått & Frakt', 'woo-kustom-export' ),
				'desc'  => __( 'Produktens längd.', 'woo-kustom-export' ),
			),
			'width'              => array(
				'label' => __( 'Bredd', 'woo-kustom-export' ),
				'group' => __( 'Mått & Frakt', 'woo-kustom-export' ),
				'desc'  => __( 'Produktens bredd.', 'woo-kustom-export' ),
			),
			'height'             => array(
				'label' => __( 'Höjd', 'woo-kustom-export' ),
				'group' => __( 'Mått & Frakt', 'woo-kustom-export' ),
				'desc'  => __( 'Produktens höjd.', 'woo-kustom-export' ),
			),
			'shipping_class'     => array(
				'label' => __( 'Fraktklass', 'woo-kustom-export' ),
				'group' => __( 'Mått & Frakt', 'woo-kustom-export' ),
				'desc'  => __( 'Namn på tilldelad fraktklass.', 'woo-kustom-export' ),
			),

			// Grupp: Bilder
			'image_url'          => array(
				'label' => __( 'Huvudbild (URL)', 'woo-kustom-export' ),
				'group' => __( 'Bilder & Media', 'woo-kustom-export' ),
				'desc'  => __( 'Fullständig URL till produktens eller variantens huvudbild.', 'woo-kustom-export' ),
			),
			'gallery_images'     => array(
				'label' => __( 'Galleribilder (URL:er)', 'woo-kustom-export' ),
				'group' => __( 'Bilder & Media', 'woo-kustom-export' ),
				'desc'  => __( 'URL:er till produktgalleriets bilder, kommaseparerade.', 'woo-kustom-export' ),
			),
		);
	}

	/**
	 * Konstruktor.
	 *
	 * @param array $settings Exportinställningar.
	 */
	public function __construct( $settings = array() ) {
		parent::__construct( $settings );

		$defaults_standard = array( 'sku', 'gtin', 'regular_price', 'sale_price', 'stock_quantity', 'image_url', 'short_description', 'category_tree' );
		$has_saved         = ! empty( $this->settings['optional_columns'] );
		$supported         = self::get_supported_optional_columns();
		foreach ( array_keys( $supported ) as $key ) {
			if ( $has_saved ) {
				$this->optional_columns[ $key ] = ! empty( $this->settings['optional_columns'][ $key ] );
			} else {
				$this->optional_columns[ $key ] = in_array( $key, $defaults_standard, true );
			}
		}

		if ( ! isset( $this->settings['custom_attribute'] ) ) {
			$this->settings['custom_attribute'] = '';
		}

		if ( ! isset( $this->settings['custom_attributes'] ) || ! is_array( $this->settings['custom_attributes'] ) ) {
			$this->settings['custom_attributes'] = array();
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
	 * Hämta lista över valda egna attribut (sanerade mot tillgängliga attribut i butiken).
	 * Skick och dess synonymer exkluderas alltid eftersom det ingår i namn och beskrivning.
	 *
	 * @return string[]
	 */
	public function get_selected_attributes() {
		$selected = array();

		if ( ! empty( $this->settings['custom_attributes'] ) && is_array( $this->settings['custom_attributes'] ) ) {
			$selected = array_map( 'strval', $this->settings['custom_attributes'] );
		} elseif ( ! empty( $this->settings['custom_attribute'] ) ) {
			$selected = array( (string) $this->settings['custom_attribute'] );
		}

		$selected = array_values( array_unique( array_filter( array_map( 'strtolower', array_map( 'trim', $selected ) ) ) ) );

		// Exkludera alltid skick ur separata kolumner.
		$excluded = array( 'skick', 'pa_skick', 'condition', 'pa_condition', 'tillstånd', 'tillstand', 'pa_tillstand' );
		$selected = array_values( array_diff( $selected, $excluded ) );

		$available = $this->get_available_attributes();
		return array_values( array_intersect( $selected, $available ) );
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

		$selected_attributes = $this->get_selected_attributes();

		return array_merge( $columns, $selected_attributes );
	}

	/**
	 * Samla alla attributrubriker som finns i katalogen (globala och produktspecifika),
	 * sorterade alfabetiskt. Skick exkluderas då det slås ihop med namn och beskrivning.
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
			self::get_approved_columns(),
			array( 'skick', 'pa_skick', 'condition', 'pa_condition', 'tillstånd', 'tillstand', 'pa_tillstand' ),
			array_keys( self::get_supported_optional_columns() )
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
	 * Hitta enkla produkter med flera värden på något av de valda egna attributen.
	 *
	 * @return array<int,array{name:string,attribute:string,count:int}>
	 */
	public function get_multi_value_simple_products() {
		$selected_list = $this->get_selected_attributes();
		if ( empty( $selected_list ) ) {
			return array();
		}

		$affected = array();

		foreach ( $this->get_products_iter() as $product ) {
			if ( $product->is_type( 'variable' ) ) {
				continue;
			}

			foreach ( $product->get_attributes() as $taxonomy => $value ) {
				$label = $this->attribute_label( $taxonomy, $product );
				if ( ! in_array( $label, $selected_list, true ) ) {
					continue;
				}

				if ( ! $value instanceof WC_Product_Attribute ) {
					continue;
				}

				$options = $value->get_options();
				if ( count( $options ) > 1 ) {
					$affected[] = array(
						'name'      => $product->get_name(),
						'attribute' => $label,
						'count'     => count( $options ),
					);
				}
			}
		}

		return $affected;
	}

	/**
	 * Hämta skick för en produkt eller variation från attribut eller metadata.
	 *
	 * Kontrollerar attribut (skick, pa_skick, condition, pa_condition etc.)
	 * samt metadata (_skick, skick, _condition, condition etc.). Faller tillbaka till
	 * föräldraprodukten om produkten är en variation och saknar eget värde.
	 *
	 * @param WC_Product      $product Produkten eller variationen.
	 * @param WC_Product|null $parent  Föräldraprodukten vid varianter.
	 * @return string Skick-sträng, eller tom sträng om skick saknas.
	 */
	public function get_condition( $product, $parent = null ) {
		if ( ! $product || ! is_object( $product ) ) {
			return '';
		}

		$condition = '';

		// 1. Kontrollera standardattribut via get_attribute()
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

		// 2. Kontrollera produktens attributobjekt/array via get_attributes()
		if ( '' === $condition && method_exists( $product, 'get_attributes' ) ) {
			foreach ( $product->get_attributes() as $taxonomy => $val ) {
				$label       = $this->attribute_label( $taxonomy, $parent ? $parent : $product );
				$label_clean = strtolower( trim( (string) $label ) );
				if ( in_array( $label_clean, array( 'skick', 'condition', 'tillstånd', 'tillstand' ), true ) ) {
					if ( $val instanceof WC_Product_Attribute ) {
						$condition = trim( $this->attribute_option_value( $val ) );
					} elseif ( is_string( $val ) || is_numeric( $val ) ) {
						$condition = trim( $this->attribute_value( $taxonomy, (string) $val ) );
					}
					if ( '' !== $condition ) {
						break;
					}
				}
			}
		}

		// 3. Kontrollera metafält (post meta)
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

		// Fallback till get_post_meta om get_meta inte gav något och funktionen finns
		if ( '' === $condition && function_exists( 'get_post_meta' ) && method_exists( $product, 'get_id' ) ) {
			$post_id   = $product->get_id();
			$meta_keys = array( '_skick', 'skick', '_condition', 'condition' );
			foreach ( $meta_keys as $meta_key ) {
				$val = get_post_meta( $post_id, $meta_key, true );
				if ( is_string( $val ) || is_numeric( $val ) ) {
					$val_str = trim( (string) $val );
					if ( '' !== $val_str ) {
						$condition = $val_str;
						break;
					}
				}
			}
		}

		// 4. Om produkten är en variation och inget skick hittats, ärv från föräldern
		if ( '' === $condition && $parent && $parent !== $product ) {
			return $this->get_condition( $parent, null );
		}

		return wp_strip_all_tags( $condition );
	}

	/**
	 * Bygg rad (obligatoriska + valda valfria kolumner) för en produkt eller variation.
	 *
	 * @param WC_Product $product Produkten/variationen som radens data hämtas från.
	 * @param WC_Product $parent  Föräldraprodukten (samma som $product för enkla produkter).
	 * @return array
	 */
	protected function build_row( $product, $parent ) {
		$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'SEK';
		if ( empty( $currency ) ) {
			$currency = 'SEK';
		}
		$currency = strtoupper( (string) $currency );

		// post_title som produktnamn: använd förälderns namn för varianter eller produktens eget namn för enkla produkter.
		// Extra fält (som skick) läggs inte till i produktnamnet för att hålla namnet rent.
		$product_name = $parent ? $parent->get_name() : $product->get_name();
		if ( empty( $product_name ) && $parent ) {
			$product_name = get_the_title( $parent->get_id() );
		}
		$product_name = trim( (string) $product_name );

		$vat_rate_val = $this->get_vat_rate( $product, $parent );
		$vat_rate_str = ( (float) $vat_rate_val === (float) (int) $vat_rate_val )
			? (string) (int) $vat_rate_val
			: (string) $vat_rate_val;

		$row = array(
			'product_name'        => (string) $product_name,
			'price_including_vat' => $this->get_price_including_vat( $product, $parent ),
			'vat_rate'            => $vat_rate_str,
			'currency'            => $currency,
		);

		foreach ( $this->optional_columns as $col => $enabled ) {
			if ( $enabled ) {
				$row[ $col ] = $this->get_column_value( $col, $product, $parent );
			}
		}

		if ( ! $product->is_type( 'variation' ) ) {
			$row = array_merge( $row, $this->build_custom_attributes_cells( $product, $parent, $row ) );
		}

		return $row;
	}

	/**
	 * Formatera angivet prisbelopp inklusive moms.
	 *
	 * @param float|string    $price   Prisbelopp.
	 * @param WC_Product      $product Produkten eller variationen.
	 * @param WC_Product|null $parent  Föräldraprodukten.
	 * @return string
	 */
	protected function format_custom_price_including_vat( $price, $product, $parent = null ) {
		$raw_float = (float) $price;
		if ( method_exists( $product, 'is_taxable' ) && ! $product->is_taxable() ) {
			return number_format( $raw_float, 2, '.', '' );
		}

		$price_inc = null;
		if ( function_exists( 'wc_get_price_including_tax' ) ) {
			$calc = (float) wc_get_price_including_tax( $product, array( 'price' => $raw_float ) );
			$prices_include_tax = function_exists( 'wc_prices_include_tax' ) ? wc_prices_include_tax() : false;
			if ( $prices_include_tax || $calc > $raw_float ) {
				$price_inc = $calc;
			}
		}

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
	 * Hämta värde för en valfri kolumn.
	 *
	 * @param string     $col     Kolumnnyckel.
	 * @param WC_Product $product Produkten eller variationen.
	 * @param WC_Product $parent  Föräldraprodukten.
	 * @return string
	 */
	protected function get_column_value( $col, $product, $parent ) {
		switch ( $col ) {
			case 'sku':
				$sku = (string) $product->get_sku();
				if ( '' === trim( $sku ) ) {
					$meta_keys = array( '_sku', 'sku', '_artnr', 'artnr', '_artikelnr', 'artikelnr' );
					$sku       = (string) $this->get_meta_first( $meta_keys, $product );
				}
				if ( '' === trim( $sku ) ) {
					if ( $product->is_type( 'variation' ) ) {
						$parent_sku = ( $parent && $parent !== $product ) ? (string) $parent->get_sku() : '';
						if ( '' !== trim( $parent_sku ) ) {
							$sku = trim( $parent_sku ) . '-' . $product->get_id();
						} else {
							$sku = (string) $product->get_id();
						}
					} else {
						$sku = (string) $product->get_id();
					}
				}
				return trim( $sku );

			case 'product_id':
			case 'id':
				return (string) $product->get_id();

			case 'parent_id':
				return $product->is_type( 'variation' ) ? (string) $product->get_parent_id() : '';

			case 'parent_sku':
				return $product->is_type( 'variation' ) ? (string) $parent->get_sku() : '';

			case 'product_type':
			case 'type':
				return (string) $product->get_type();

			case 'status':
				return (string) $product->get_status();

			case 'regular_price':
				$regular = $product->get_regular_price();
				if ( '' !== $regular && null !== $regular ) {
					return $this->format_custom_price_including_vat( $regular, $product, $parent );
				}
				return '';

			case 'sale_price':
				$sale = $product->get_sale_price();
				if ( '' !== $sale && null !== $sale ) {
					return $this->format_custom_price_including_vat( $sale, $product, $parent );
				}
				return '';

			case 'price_excluding_vat':
				return $this->get_price_excluding_vat( $product, $parent );

			case 'tax_class':
				$tax_class = $product->get_tax_class();
				if ( ( 'parent' === $tax_class || empty( $tax_class ) ) && $parent && $parent !== $product ) {
					$tax_class = $parent->get_tax_class();
				}
				return (string) $tax_class;

			case 'tax_status':
				$tax_status = $product->get_tax_status();
				if ( empty( $tax_status ) && $parent ) {
					$tax_status = $parent->get_tax_status();
				}
				return (string) $tax_status;

			case 'stock_quantity':
				$qty = $product->get_stock_quantity();
				return null !== $qty ? (string) $qty : '';

			case 'stock_status':
				return (string) $product->get_stock_status();

			case 'manage_stock':
				return $product->get_manage_stock() ? 'yes' : 'no';

			case 'ean':
			case 'gtin':
				return $this->get_ean( $product );

			case 'description':
				$desc = ( $product->is_type( 'variation' ) && $product->get_description() )
					? $product->get_description()
					: ( $parent ? $parent->get_description() : $product->get_description() );
				$desc = trim( wp_strip_all_tags( (string) $desc ) );

				// Krav: Säkerställ att fältet description fylls med informationen om skicket.
				$condition = $this->get_condition( $product, $parent );
				if ( '' !== $condition ) {
					$condition_line = 'Skick: ' . $condition;
					if ( '' !== $desc ) {
						if ( false === stripos( $desc, $condition_line ) && false === stripos( $desc, 'skick: ' . $condition ) ) {
							$desc .= "\n" . $condition_line;
						}
					} else {
						$desc = $condition_line;
					}
				}
				return $desc;

			case 'short_description':
				return wp_strip_all_tags( (string) $parent->get_short_description() );

			case 'category':
			case 'category_tree':
				$cat_prod = $parent ? $parent : $product;
				return $this->get_primary_category( $cat_prod );

			case 'categories':
				$terms = wp_get_post_terms( $parent->get_id(), 'product_cat', array( 'fields' => 'names' ) );
				return ( ! is_wp_error( $terms ) && ! empty( $terms ) ) ? implode( ', ', $terms ) : '';

			case 'tags':
				$terms = wp_get_post_terms( $parent->get_id(), 'product_tag', array( 'fields' => 'names' ) );
				return ( ! is_wp_error( $terms ) && ! empty( $terms ) ) ? implode( ', ', $terms ) : '';

			case 'weight':
				$weight = $product->get_weight();
				return ( '' !== $weight && null !== $weight ) ? (string) $weight : '';

			case 'length':
				$length = $product->get_length();
				return ( '' !== $length && null !== $length ) ? (string) $length : '';

			case 'width':
				$width = $product->get_width();
				return ( '' !== $width && null !== $width ) ? (string) $width : '';

			case 'height':
				$height = $product->get_height();
				return ( '' !== $height && null !== $height ) ? (string) $height : '';

			case 'shipping_class':
				$sc_id = $product->get_shipping_class_id();
				if ( ! $sc_id && $parent ) {
					$sc_id = $parent->get_shipping_class_id();
				}
				$sc = $sc_id ? get_term_by( 'id', $sc_id, 'product_shipping_class' ) : null;
				return ( $sc && ! is_wp_error( $sc ) ) ? (string) $sc->name : '';

			case 'image_url':
				return $this->get_image_url( $product, $parent );

			case 'gallery_images':
				$gallery_ids = $parent ? $parent->get_gallery_image_ids() : array();
				$urls        = array();
				foreach ( $gallery_ids as $id ) {
					$url = wp_get_attachment_image_url( (int) $id, 'full' );
					if ( $url ) {
						$urls[] = $url;
					}
				}
				return implode( ', ', $urls );

			default:
				return '';
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

			$row = array_merge( $row, $this->build_custom_attributes_cells( $variation, $product, $row ) );

			yield $row;
		}
	}

	/**
	 * Bygg cellvärden för de valda egna attributen.
	 *
	 * @param WC_Product $product      Produkten/variationen.
	 * @param WC_Product $parent       Föräldraprodukten.
	 * @param array      $existing_row Befintlig rad (för att inte skriva över redan satta variationsvärden).
	 * @return array
	 */
	protected function build_custom_attributes_cells( $product, $parent, $existing_row = array() ) {
		$selected_list = $this->get_selected_attributes();
		if ( empty( $selected_list ) ) {
			return array();
		}

		$cells = array();
		foreach ( $selected_list as $selected ) {
			if ( isset( $existing_row[ $selected ] ) && '' !== $existing_row[ $selected ] ) {
				continue;
			}

			$value = $this->get_attribute_value_by_label( $product, $selected, $parent );
			if ( '' === $value && $parent && $parent->get_id() !== $product->get_id() ) {
				$value = $this->get_attribute_value_by_label( $parent, $selected, $parent );
			}

			$cells[ $selected ] = $value;
		}

		return $cells;
	}

	/**
	 * Hämta en produkts värde för attributet med given rubrik.
	 *
	 * @param WC_Product $product Produkten/variationen.
	 * @param string     $label   Efterfrågad attributrubrik.
	 * @param WC_Product $parent  Föräldraprodukten (för attribute_label).
	 * @return string
	 */
	protected function get_attribute_value_by_label( $product, $label, $parent ) {
		$clean_label = preg_replace( '/^attribute_/', '', $label );
		foreach ( $product->get_attributes() as $taxonomy => $value ) {
			$attr_label = $this->attribute_label( $taxonomy, $parent );
			if ( $label !== $attr_label && $clean_label !== $attr_label && $label !== 'attribute_' . $attr_label ) {
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
