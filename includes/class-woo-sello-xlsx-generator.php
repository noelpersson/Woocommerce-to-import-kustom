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

		$filename = $this->get_filename_prefix() . gmdate( 'Y-m-d-Hi' ) . '.' . $this->get_filename_extension();

		nocache_headers();
		header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$writer = new XLSXWriter();
		$writer->setTitle( __( 'Sello Tradera produktexport', 'woo-kustom-export' ) );
		$writer->setAuthor( get_bloginfo( 'name' ) );

		$columns = $this->collect_columns();
		$types   = array_fill( 0, count( $columns ), 'string' );

		$writer->writeSheetHeader( 'Products', array_combine( $columns, $types ), array( 'freeze_rows' => true ) );

		foreach ( $this->get_rows_iter() as $row ) {
			$writer->writeSheetRow(
				'Products',
				array_map( array( $this, 'sanitize_cell' ), array_values( $row ) )
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
		$types   = array_fill( 0, count( $columns ), 'string' );

		$writer->writeSheetHeader( 'Products', array_combine( $columns, $types ), array( 'freeze_rows' => true ) );

		$rows_count = 0;
		foreach ( $this->get_rows_iter() as $row ) {
			$writer->writeSheetRow(
				'Products',
				array_map( array( $this, 'sanitize_cell' ), array_values( $row ) )
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
	 * Exekvera exporten och strömma Excel-filen (alias för stream_xlsx, anropas bl.a. via WP-CLI).
	 */
	public function generate() {
		$this->stream_xlsx();
	}
}
