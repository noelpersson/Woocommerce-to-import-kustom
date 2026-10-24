<?php
/**
 * XLSX-generator för Sello Excel-importformat.
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
	 * Generera XLSX-strömmen och skicka den till webbläsaren som nedladdning.
	 */
	public function stream_xlsx() {
		$filename = $this->get_filename_prefix() . gmdate( 'Y-m-d-Hi' ) . '.' . $this->get_filename_extension();

		nocache_headers();
		header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$writer = new XLSXWriter();
		$writer->setTitle( __( 'Sello produktexport', 'woo-kustom-export' ) );
		$writer->setAuthor( get_bloginfo( 'name' ) );

		$columns = $this->collect_columns();
		$types  = array_fill( 0, count( $columns ), 'string' );

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
}
