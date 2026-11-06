<?php
/**
 * Holt die öffentliche Suche der Ausbildungsbörse der Handwerkskammer Kassel
 * (Ausbildung, duales Studium, Schul-, FOS- und Ferienpraktika im Umkreis von 25 km um 34302 Guxhagen)
 * und schreibt sie nach hwk.json. Die Homepage der IGS Guxhagen liest diese Datei,
 * weil ihr Server die Börse nicht direkt erreicht.
 *
 * Neue Anzeigen werden einmal einzeln gelesen (Schulabschluss, Vergütung im 1. Jahr,
 * ob ein Praktikum angeboten wird); bekannte Anzeigen nimmt das Skript aus der letzten hwk.json.
 */

define( 'IGA_HWK_SUCHE', 'https://hwk-kassel.odav.de/43,50,jpguestsearch.html' );
define( 'IGA_HWK_DETAIL', 'https://hwk-kassel.odav.de/43,50,jpguestdetail.html?id=' );
define( 'PLZ', '34302' );
define( 'UMKREIS', 25 );
define( 'DATEI', __DIR__ . '/hwk.json' );

class WP_Error {
	private $m;
	public function __construct( $code, $msg = '' ) {
		$this->m = '' !== $msg ? $msg : $code;
	}
	public function get_error_message() {
		return $this->m;
	}
}
function is_wp_error( $x ) {
	return $x instanceof WP_Error;
}
function wp_strip_all_tags( $s ) {
	$s = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $s );
	return trim( strip_tags( $s ) );
}
function add_query_arg( $p, $url ) {
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $p );
}
function iga_hwk_get( $url ) {
	$c = curl_init( $url );
	curl_setopt_array(
		$c,
		array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_CONNECTTIMEOUT => 15,
			CURLOPT_TIMEOUT        => 30,
			CURLOPT_USERAGENT      => 'IGS-Ausbildungsplaetze (Schulhomepage IGS Guxhagen; https://www.igs-guxhagen.de)',
		)
	);
	$body = curl_exec( $c );
	$code = (int) curl_getinfo( $c, CURLINFO_HTTP_CODE );
	$err  = curl_error( $c );
	curl_close( $c );
	if ( false === $body ) {
		return new WP_Error( 'netz', 'Handwerkskammer: ' . $err );
	}
	if ( 200 !== $code ) {
		return new WP_Error( 'http', 'Handwerkskammer: HTTP ' . $code );
	}
	return (string) $body;
}

require __DIR__ . '/lesen.php';

$alt = is_file( DATEI ) ? json_decode( (string) file_get_contents( DATEI ), true ) : null;
$det = is_array( $alt ) && isset( $alt['det'] ) && is_array( $alt['det'] ) ? $alt['det'] : array();

$liste = iga_hwk_alle( PLZ, UMKREIS, array( 'ausbildung', 'dual', 'praktikum' ) );
if ( is_wp_error( $liste ) ) {
	fwrite( STDERR, $liste->get_error_message() . "\n" );
	exit( 1 ); // alte Datei bleibt stehen
}
if ( ! $liste && is_array( $alt ) && ! empty( $alt['liste'] ) ) {
	fwrite( STDERR, "Keine Treffer, alte Datei bleibt stehen.\n" );
	exit( 1 );
}

$start = time();
$neu   = array();
foreach ( $liste as $z ) {
	$nr = $z['nr'];
	if ( isset( $det[ $nr ] ) ) {
		$neu[ $nr ] = $det[ $nr ];
		continue;
	}
	if ( time() - $start > 600 ) {
		continue; // Rest beim nächsten Lauf
	}
	$d = iga_hwk_detail( $nr );
	if ( ! is_wp_error( $d ) ) {
		$neu[ $nr ] = $d;
	}
	usleep( 300000 ); // freundlich zur Börse
}

$out = array(
	'stand'   => time(),
	'plz'     => PLZ,
	'umkreis' => UMKREIS,
	'quelle'  => IGA_HWK_SUCHE,
	'liste'   => $liste,
	'det'     => (object) $neu,
);
// Nur schreiben, wenn sich am Inhalt etwas geändert hat (sonst entsteht kein Commit).
if ( is_array( $alt ) ) {
	$a = $alt;
	$b = json_decode( json_encode( $out ), true );
	unset( $a['stand'], $b['stand'] );
	if ( $a == $b ) {
		echo 'Unverändert: ' . count( $liste ) . " Anzeigen.\n";
		exit( 0 );
	}
}
file_put_contents( DATEI, json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) . "\n" );
echo count( $liste ) . ' Anzeigen, ' . count( $neu ) . " mit Details.\n";
