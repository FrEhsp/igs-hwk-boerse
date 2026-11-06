<?php
// Aus dem Plugin igs-ausbildungsplaetze übernommen (gleiche Leseregeln). Nicht von Hand ändern.

function iga_lower( $s ) {
	return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $s, 'UTF-8' ) : strtolower( (string) $s );
}

/** Sagt der Betrieb in der Anzeige, dass man vorher ein Praktikum oder einen Probetag machen kann? */
function iga_praktikum_im_text( $t ) {
	$t = preg_replace( '/praktikums(bescheinigung|nachweis|zeugnis|trimester|semester|phase)\w*/u', '', $t );
	return (bool) preg_match( '/schnupperpraktikum|schülerpraktik|schuelerpraktik|praktikum\s+(ist\s+)?(jederzeit\s+|gerne\s+|auch\s+)?möglich|(ein|einem|eines)\s+(schnupper|kurz|tages)?praktikums?\s+(bei\s+uns\s+)?(zu\s+)?(absolvieren|machen|kennenlernen|kennen\s+zu\s+lernen|kennen\s+lernen|anbieten)|im\s+rahmen\s+eines\s+praktikums|vorab\s+ein\s+praktikum|vorher\s+ein\s+praktikum|praktikum\s+bei\s+uns|praktikumspl(a|ä)tze?\s+(an|frei|zu\s+vergeben)|probetag|probearbeit|reinschnuppern|schnuppertag|schnupperwoche/u', $t );
}

function iga_hwk_text( $h ) {
	$h = preg_replace( '/<br\s*\/?>/i', "\n", (string) $h );
	return trim( preg_replace( '/[ \t\x{00A0}]+/u', ' ', html_entity_decode( wp_strip_all_tags( $h ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
}

/** „01.08.2027, alternativ 01.09.2027“ → 2027-08-01; „nach Absprache“ → ''. */
function iga_hwk_datum( $t ) {
	return preg_match( '/(\d{2})\.(\d{2})\.(\d{4})/', (string) $t, $m ) ? $m[3] . '-' . $m[2] . '-' . $m[1] : '';
}

/** Trefferliste der Suche lesen: id, Art, Beruf, Betrieb, Beginn, PLZ, Ort. */
function iga_hwk_liste_lesen( $html ) {
	$out = array();
	if ( ! preg_match_all( '#<a class="list-group-item[^"]*" href="/43,50,jpguestdetail\.html\?id=(\d+)"(.*?)</a>#s', $html, $mm, PREG_SET_ORDER ) ) {
		return $out;
	}
	foreach ( $mm as $m ) {
		$typ = preg_match( '/<span class="glyphicon[^"]*"\s+title="([^"]*)"/', $m[2], $t ) ? html_entity_decode( $t[1], ENT_QUOTES, 'UTF-8' ) : '';
		if ( ! preg_match_all( '#<div class="col-sm-(?:5|3)[^"]*">(.*?)</div>#s', $m[2], $sp ) || count( $sp[1] ) < 3 ) {
			continue;
		}
		$name = explode( "\n", iga_hwk_text( $sp[1][0] ) );
		$adr  = iga_hwk_text( $sp[1][2] );
		$out[] = array(
			'nr'    => $m[1],
			'typ'   => $typ,
			'beruf' => trim( $name[0] ),
			'firma' => isset( $name[1] ) ? trim( $name[1] ) : '',
			'ab'    => iga_hwk_datum( iga_hwk_text( $sp[1][1] ) ),
			'plz'   => preg_match( '/^(\d{5})\s+(.+)$/u', $adr, $a ) ? $a[1] : '',
			'ort'   => isset( $a[2] ) ? trim( $a[2] ) : $adr,
		);
	}
	return $out;
}

/** Alle Treffer im Umkreis holen (Seiten zu je 20, höchstens 15 Seiten). */
function iga_hwk_alle( $plz, $um, $arten ) {
	$p = array(
		'searchPerformed'      => 1,
		'search-filter-zipcode' => $plz,
		'search-filter-radius' => $um,
	);
	if ( in_array( 'ausbildung', $arten, true ) ) {
		$p['search-apprenticeship'] = 'on';
	}
	if ( in_array( 'dual', $arten, true ) ) {
		$p['search-dual-studies'] = 'on';
	}
	if ( in_array( 'praktikum', $arten, true ) ) {
		$p['search-internship-school']        = 'on'; // Schulpraktika
		$p['search-internship-co-op-program'] = 'on'; // FOS-Praktika
		$p['search-compound-studies']         = 'on'; // Ferienpraktika
	}
	$alle = array();
	for ( $seite = 0; $seite < 15; $seite++ ) {
		$url  = add_query_arg( $p + array( 'offset' => $seite * 20 ), IGA_HWK_SUCHE ) . '&search-typeofjob=1&search-typeofjob=2';
		$html = iga_hwk_get( $url );
		if ( is_wp_error( $html ) ) {
			return 0 === $seite ? $html : $alle;
		}
		$neu = 0;
		foreach ( iga_hwk_liste_lesen( $html ) as $z ) {
			if ( ! isset( $alle[ $z['nr'] ] ) ) {
				$alle[ $z['nr'] ] = $z;
				$neu++;
			}
		}
		if ( $neu < 20 ) {
			break;
		}
		usleep( 500000 ); // freundlich zur Börse
	}
	return array_values( $alle );
}

/**
 * Eine Anzeige der HWK nachlesen: niedrigster genannter Schulabschluss, Vergütung im 1. Jahr,
 * ob der Betrieb ein Praktikum anbietet. Gespeichert werden nur diese drei Angaben.
 */
function iga_hwk_detail( $nr ) {
	$html = iga_hwk_get( IGA_HWK_DETAIL . rawurlencode( $nr ) );
	if ( is_wp_error( $html ) ) {
		return $html;
	}
	$a = '';
	if ( preg_match( '#<h5[^>]*>\s*Schulabschluss\s*</h5>\s*<ul>(.*?)</ul>#s', $html, $m ) ) {
		$l = iga_lower( iga_hwk_text( $m[1] ) );
		if ( preg_match( '/ohne|kein|nicht erforderlich/u', $l ) ) {
			$a = 'egal';
		} elseif ( false !== strpos( $l, 'hauptschul' ) ) {
			$a = 'hs';
		} elseif ( preg_match( '/mittler|realschul/u', $l ) ) {
			$a = 'rs';
		} elseif ( preg_match( '/fachabitur|fachhochschulreife/u', $l ) ) {
			$a = 'fh';
		} elseif ( preg_match( '/abitur|hochschulreife/u', $l ) ) {
			$a = 'abi';
		}
	}
	$g = 0;
	if ( preg_match( '/1\.\s*Jahr:\s*(?:<[^>]+>\s*)*([\d.]+),(\d{2})\s*(?:&euro;|€)/u', $html, $m ) ) {
		$g = (int) str_replace( '.', '', $m[1] );
	}
	$text = '';
	if ( preg_match( '#<div id="tab1".*?(?=<div id="tab2")#s', $html, $m ) ) {
		$text = iga_lower( iga_hwk_text( $m[0] ) );
	}
	return array( 'a' => $a, 'p' => iga_praktikum_im_text( $text ) ? 1 : 0, 'g' => $g );
}
