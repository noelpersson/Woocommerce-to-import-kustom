<?php
/**
 * XLSX-generator för Sellos importformat anpassat för Tradera.
 *
 * Återanvänder samma kolumnmappning och raddata som CSV-versionen
 * (class-woo-sello-csv-generator.php) men skriver en äkta .xlsx-fil
 * via det licensfria biblioteket XLSXWriter (MIT), som strömmar rad
 * för rad och håller minnesanvändningen låg även för stora kataloger.
 *
 * @package Woo_Kustom_Export
 */

defined( 'ABSPATH' ) || exit;

/**
 * Klass WOO_Sello_XLSX_Generator
 */
class WOO_Sello_XLSX_Generator extends WOO_Sello_CSV_Generator {

	/**
	 * Filändelse för nedladdningen.
	 *
	 * @return string
	 */
	public function get_filename_extension() {
		return 'xlsx';
	}

	/**
	 * Generera XLSX-strömmen och skicka den till webbläsaren som nedladdning.
	 */
	public function stream_xlsx() {
		if ( ! class_exists( 'ZipArchive' ) ) {
			wp_die( esc_html__( 'PHP-tillägget ZipArchive krävs för att generera Excel (.xlsx)-filer, men är inte aktiverat på webbservern.', 'woo-kustom-export' ) );
		}

		$filename = $this->get_filename_prefix() . gmdate( 'Y-m-d-His' ) . '.' . $this->get_filename_extension();

		nocache_headers();
		header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		$writer = new XLSXWriter();
		$writer->setTitle( __( 'Sello Tradera produktexport', 'woo-kustom-export' ) );
		$writer->setAuthor( get_bloginfo( 'name' ) );

		$columns = $this->collect_columns();
		$types   = $this->get_column_types( $columns );

		$writer->writeSheetHeader( 'Products', $types, array( 'freeze_rows' => true ) );

		foreach ( $this->get_rows_iter() as $row ) {
			$writer->writeSheetRow(
				'Products',
				$this->prepare_sheet_row_values( $row, $columns )
			);
		}

		$writer->writeToStdOut();
		exit;
	}

	/**
	 * Skriver XLSX-exporten till en fil på disken och returnerar antal rader och filstorlek.
	 *
	 * @param string $filepath Fullständig sökväg till destinationsfilen.
	 * @return array{rows: int, size: int}|false Statistik eller false vid fel.
	 */
	public function write_to_file( $filepath ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return false;
		}

		$writer = new XLSXWriter();
		$writer->setTitle( __( 'Sello Tradera produktexport', 'woo-kustom-export' ) );
		$writer->setAuthor( get_bloginfo( 'name' ) );

		$columns = $this->collect_columns();
		$types   = $this->get_column_types( $columns );

		$writer->writeSheetHeader( 'Products', $types, array( 'freeze_rows' => true ) );

		$rows_count = 0;
		foreach ( $this->get_rows_iter() as $row ) {
			$writer->writeSheetRow(
				'Products',
				$this->prepare_sheet_row_values( $row, $columns )
			);
			$rows_count++;
		}

		$writer->writeToFile( $filepath );

		return array(
			'rows' => $rows_count,
			'size' => file_exists( $filepath ) ? filesize( $filepath ) : 0,
		);
	}

	/**
	 * Mappa kolumner till motsvarande datatyper i XLSXWriter.
	 *
	 * Numeriska kolumner (Tradera price store, Purchase price, Quantity, Tax, Weight)
	 * deklareras som integer eller price så att de skrivs som äkta tal (t="n") i Excel.
	 *
	 * @param string[] $columns Aktiva kolumnnamn.
	 * @return array<string,string> Associativ array [kolumn => typ].
	 */
	protected function get_column_types( $columns ) {
		$types = array();
		foreach ( $columns as $col ) {
			switch ( $col ) {
				case 'Tradera price store':
				case 'Quantity':
				case 'Tax':
				case 'Weight':
					$types[ $col ] = 'integer';
					break;
				case 'Purchase price':
					$types[ $col ] = 'price';
					break;
				default:
					$types[ $col ] = 'string';
					break;
			}
		}
		return $types;
	}

	/**
	 * Formatera radens cellvärden enligt Sellos typkrav innan de skrivs till Excel.
	 *
	 * @param array    $row     Rad med kolumnnamn som nycklar.
	 * @param string[] $columns Kolumnordning.
	 * @return array Numeriskt indexerad array med typkonverterade värden.
	 */
	protected function prepare_sheet_row_values( $row, $columns ) {
		$row_values = array();
		foreach ( $columns as $col ) {
			$val = isset( $row[ $col ] ) ? $row[ $col ] : '';

			// Guard mot strängen "undefined" eller "null"
			if ( is_string( $val ) && in_array( strtolower( trim( $val ) ), array( 'undefined', 'null' ), true ) ) {
				$val = '';
			}

			if ( in_array( $col, array( 'Tradera price store', 'Quantity', 'Tax', 'Weight' ), true ) ) {
				if ( '' !== $val && null !== $val && is_numeric( $val ) ) {
					$row_values[] = (int) round( (float) $val );
				} else {
					$row_values[] = '';
				}
			} elseif ( 'Purchase price' === $col ) {
				if ( '' !== $val && null !== $val && is_numeric( $val ) ) {
					$row_values[] = (float) $val;
				} else {
					$row_values[] = '';
				}
			} elseif ( 'Condition' === $col ) {
				// Sello kräver exakt "new" eller "used" i gemener
				$cond = strtolower( trim( (string) $val ) );
				$row_values[] = ( 'new' === $cond ) ? 'new' : 'used';
			} elseif ( 'Tradera category' === $col ) {
				// Tradera kategori-ID som heltal om numeriskt, annars sträng
				if ( '' !== $val && null !== $val && is_numeric( $val ) ) {
					$row_values[] = (int) round( (float) $val );
				} else {
					$row_values[] = (string) $val;
				}
			} else {
				$row_values[] = (string) $val;
			}
		}
		return $row_values;
	}

	/**
	 * Exekvera exporten och strömma Excel-filen (alias för stream_xlsx, anropas bl.a. via WP-CLI).
	 */
	public function generate() {
		$this->stream_xlsx();
	}
}
