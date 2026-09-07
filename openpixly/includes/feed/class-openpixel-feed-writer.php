<?php
/**
 * Writes feed rows to CSV, TSV or JSONL following the OpenAI product feed
 * value conventions (https://developers.openai.com/commerce/specs/file-upload/products):
 *  - UTF-8, one header row for delimited formats, one item/variant per row
 *  - JSON objects / arrays inside delimited cells are serialized as JSON
 *  - booleans as true/false strings in delimited files
 *  - empty cell = no value (never "null", "n/a", ...)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OpenPixel_Feed_Writer {

	const FORMATS = array( 'csv', 'tsv', 'jsonl' );

	/** @var resource */
	private $handle;

	/** @var string csv|tsv|jsonl */
	private $format;

	/** @var string[] */
	private $columns;

	/** @var bool */
	private $header_written = false;

	/**
	 * @param string   $path    File to (over)write.
	 * @param string   $format  csv | tsv | jsonl
	 * @param string[] $columns Column order for delimited formats.
	 */
	public function __construct( $path, $format, array $columns ) {
		$this->format  = in_array( $format, self::FORMATS, true ) ? $format : 'csv';
		$this->columns = $columns;
		$this->handle  = fopen( $path, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( ! $this->handle ) {
			throw new RuntimeException( 'Cannot open feed file for writing: ' . esc_html( $path ) );
		}
	}

	/**
	 * Open an existing file for appending (batch builds).
	 */
	public static function open_append( $path, $format, array $columns ) {
		$writer                 = new self( $path . '.__tmp', $format, $columns );
		fclose( $writer->handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		wp_delete_file( $path . '.__tmp' );
		$writer->handle         = fopen( $path, 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$writer->header_written = filesize( $path ) > 0;
		return $writer;
	}

	public static function extension( $format ) {
		return 'jsonl' === $format ? 'jsonl' : ( 'tsv' === $format ? 'tsv' : 'csv' );
	}

	public static function mime( $format ) {
		switch ( $format ) {
			case 'jsonl':
				return 'application/x-ndjson';
			case 'tsv':
				return 'text/tab-separated-values';
			default:
				return 'text/csv';
		}
	}

	/**
	 * Write the header row now (delimited formats only). Used so an empty
	 * catalog still yields a well-formed file.
	 */
	public function write_header() {
		if ( 'jsonl' !== $this->format && ! $this->header_written ) {
			$this->write_delimited( $this->columns );
			$this->header_written = true;
		}
	}

	/**
	 * @param array $row column => scalar|array|bool|null
	 */
	public function write( array $row ) {
		if ( 'jsonl' === $this->format ) {
			$clean = array();
			foreach ( $row as $key => $value ) {
				if ( null === $value || '' === $value || array() === $value ) {
					continue;
				}
				$clean[ $key ] = $value;
			}
			fwrite( $this->handle, wp_json_encode( $clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			return;
		}

		if ( ! $this->header_written ) {
			$this->write_delimited( $this->columns );
			$this->header_written = true;
		}

		$cells = array();
		foreach ( $this->columns as $column ) {
			$cells[] = $this->to_cell( isset( $row[ $column ] ) ? $row[ $column ] : null );
		}
		$this->write_delimited( $cells );
	}

	private function to_cell( $value ) {
		if ( null === $value ) {
			return '';
		}
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_array( $value ) ) {
			// Lists of URLs are comma separated in delimited files; maps are JSON.
			$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
			if ( ! $value ) {
				return '';
			}
			return $is_list
				? implode( ',', array_map( 'strval', $value ) )
				: wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}
		return (string) $value;
	}

	private function write_delimited( array $cells ) {
		if ( 'tsv' === $this->format ) {
			$cells = array_map(
				function ( $cell ) {
					return str_replace( array( "\t", "\r", "\n" ), ' ', (string) $cell );
				},
				$cells
			);
			fwrite( $this->handle, implode( "\t", $cells ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			return;
		}

		fputcsv( $this->handle, $cells, ',', '"', '\\', "\n" );
	}

	public function close() {
		if ( $this->handle ) {
			fclose( $this->handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$this->handle = null;
		}
	}
}
