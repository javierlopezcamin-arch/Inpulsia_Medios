<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Returns rows keyed by normalized column name (id, filename, title, alt, caption, description…).
function inpulsia_medios_parse_file( $path, $name = '' ) {
	$ext   = strtolower( pathinfo( $name !== '' ? $name : $path, PATHINFO_EXTENSION ) );
	$table = $ext === 'xlsx' ? inpulsia_medios_read_xlsx( $path ) : inpulsia_medios_read_csv( $path );
	if ( is_wp_error( $table ) ) {
		return $table;
	}
	return inpulsia_medios_table_to_rows( $table );
}

function inpulsia_medios_table_to_rows( $table ) {
	$headers = array_shift( $table );
	if ( ! $headers ) {
		return [];
	}
	$aliases = [
		'nombre'            => 'filename',
		'archivo'           => 'filename',
		'nombre de archivo' => 'filename',
		'titulo'            => 'title',
		'texto alternativo' => 'alt',
		'leyenda'           => 'caption',
		'descripcion'       => 'description',
	];
	$headers = array_map( function ( $h ) use ( $aliases ) {
		$h = strtolower( remove_accents( trim( (string) $h ) ) );
		return $aliases[ $h ] ?? $h;
	}, $headers );

	$rows = [];
	foreach ( $table as $data ) {
		$row = [];
		foreach ( $headers as $i => $h ) {
			$row[ $h ] = isset( $data[ $i ] ) ? trim( (string) $data[ $i ] ) : '';
		}
		if ( isset( $row['id'] ) && $row['id'] !== '' ) {
			$rows[] = $row;
		}
	}
	return $rows;
}

function inpulsia_medios_read_csv( $path ) {
	$content = file_get_contents( $path );
	if ( $content === false || $content === '' ) {
		return [];
	}
	if ( strpos( $content, "\xEF\xBB\xBF" ) === 0 ) {
		$content = substr( $content, 3 );
	}
	if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $content, 'UTF-8' ) ) {
		$content = mb_convert_encoding( $content, 'UTF-8', 'Windows-1252' );
	}

	$first     = strtok( $content, "\n" );
	$delimiter = substr_count( $first, ';' ) >= substr_count( $first, ',' ) ? ';' : ',';

	$handle = fopen( 'php://temp', 'r+' );
	fwrite( $handle, $content );
	rewind( $handle );
	$table = [];
	while ( ( $data = fgetcsv( $handle, 0, $delimiter, '"', '' ) ) !== false ) {
		if ( $data !== [ null ] ) {
			$table[] = $data;
		}
	}
	fclose( $handle );
	return $table;
}

// Minimal reader for the first worksheet of an .xlsx file (shared, inline and numeric cells).
function inpulsia_medios_read_xlsx( $path ) {
	if ( ! class_exists( 'ZipArchive' ) ) {
		return new WP_Error( 'no_zip', __( 'El servidor no tiene la extensión ZIP de PHP. Guarda el archivo como CSV.', 'inpulsia-medios' ) );
	}
	$zip = new ZipArchive();
	if ( $zip->open( $path ) !== true ) {
		return new WP_Error( 'bad_xlsx', __( 'No se pudo abrir el archivo Excel.', 'inpulsia-medios' ) );
	}

	$xml = function ( $name ) use ( $zip ) {
		$raw = $zip->getFromName( $name );
		return $raw === false ? null : simplexml_load_string( $raw, 'SimpleXMLElement', LIBXML_NONET );
	};

	$shared = [];
	$sst    = $xml( 'xl/sharedStrings.xml' );
	if ( $sst ) {
		foreach ( $sst->si as $si ) {
			$shared[] = inpulsia_medios_xlsx_text( $si );
		}
	}

	$sheet_path = 'xl/worksheets/sheet1.xml';
	$workbook   = $xml( 'xl/workbook.xml' );
	$rels       = $xml( 'xl/_rels/workbook.xml.rels' );
	if ( $workbook && $rels && isset( $workbook->sheets->sheet[0] ) ) {
		$rid = (string) $workbook->sheets->sheet[0]->attributes( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships' )->id;
		foreach ( $rels->Relationship as $rel ) {
			if ( (string) $rel['Id'] === $rid ) {
				$target     = ltrim( (string) $rel['Target'], '/' );
				$sheet_path = strpos( $target, 'xl/' ) === 0 ? $target : 'xl/' . $target;
			}
		}
	}

	$sheet = $xml( $sheet_path );
	$zip->close();
	if ( ! $sheet ) {
		return new WP_Error( 'bad_xlsx', __( 'El archivo Excel no tiene hojas legibles.', 'inpulsia-medios' ) );
	}

	$table = [];
	foreach ( $sheet->sheetData->row as $r ) {
		$line = [];
		foreach ( $r->c as $c ) {
			$col  = inpulsia_medios_xlsx_col( (string) $c['r'], count( $line ) );
			$type = (string) $c['t'];
			if ( $type === 's' ) {
				$val = $shared[ (int) $c->v ] ?? '';
			} elseif ( $type === 'inlineStr' ) {
				$val = inpulsia_medios_xlsx_text( $c->is );
			} elseif ( $type === 'b' ) {
				$val = (string) $c->v === '1' ? 'TRUE' : 'FALSE';
			} else {
				$val = (string) $c->v;
				if ( is_numeric( $val ) && (float) $val === floor( (float) $val ) && abs( (float) $val ) < PHP_INT_MAX ) {
					$val = (string) (int) $val;
				}
			}
			$line[ $col ] = $val;
		}
		if ( $line ) {
			$max  = max( array_keys( $line ) );
			$full = array_fill( 0, $max + 1, '' );
			$table[] = array_replace( $full, $line );
		}
	}
	return $table;
}

function inpulsia_medios_xlsx_text( $node ) {
	if ( isset( $node->t ) ) {
		return (string) $node->t;
	}
	$text = '';
	foreach ( $node->r as $run ) {
		$text .= (string) $run->t;
	}
	return $text;
}

function inpulsia_medios_xlsx_col( $ref, $fallback ) {
	if ( ! preg_match( '/^([A-Z]+)/', $ref, $m ) ) {
		return $fallback;
	}
	$n = 0;
	foreach ( str_split( $m[1] ) as $ch ) {
		$n = $n * 26 + ( ord( $ch ) - 64 );
	}
	return $n - 1;
}
