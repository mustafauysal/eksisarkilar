<?php
// middleware, middleware olali boyle zulum gormedi!!1 🙈

// e.g: $app->add(new \Slim\Csrf\Guard);
use Symfony\Component\DomCrawler\Crawler;


function add_token_to_config( $token ) {
	$config_file_string = file_get_contents( APP_PATH . '/config.php' );

	if ( empty( $config_file_string ) ) {
		return false;
	}

	$config_file = preg_split( "#(\r\n|\r|\n)#", $config_file_string );
	$line_key    = false;
	foreach ( $config_file as $key => $line ) {
		if ( ! preg_match( '/^\s*define\(\s*(\'|")([A-Z_]+)(\'|")(.*)/', $line, $match ) ) {
			continue;
		}
		if ( $match[2] == 'SPOTIFY_TOKEN' ) {
			$line_key = $key;
		}
	}

	if ( $line_key !== false ) {
		unset( $config_file[ $line_key ] );
	}

	array_shift( $config_file );
	array_unshift( $config_file, '<?php', "define( 'SPOTIFY_TOKEN', '$token' ); // automatically added" );
	foreach ( $config_file as $key => $line ) {
		if ( '' === $line ) {
			unset( $config_file[ $key ] );
		}
	}
	if ( ! file_put_contents( APP_PATH . '/config.php', implode( PHP_EOL, $config_file ) ) ) {
		return false;
	}

	return true;
}


/**
 * Bu fonksiyon cok daha iyi yazilabilirdi,
 * Ayni sey bu kod icin de gecerli,
 *
 * @param string $html
 *
 * @return array|string
 */
function predict_the_song( $html ) {
	// bu kadar kisa entry genelde: sanatci - eser seklinde
	if ( 30 > strlen( $html ) ) {
		return strip_tags( $html );
	}

	$sub_crawler = new Crawler( $html );

	$parca = $sub_crawler->filter( 'a' )->each( function ( Crawler $node, $i ) {
		return $node->text();
	} );

	if ( $parca ) {
		return implode( ' - ', $parca );
	}

	$maybe_list = explode( '<br>', $html );

	// liste seklinde bir suru sarkiyi siralayanlar olabiliyor.
	if ( count( $maybe_list ) > 1 ) {
		$clean_list = array_map( function ( $item ) {
			if ( strlen( $item ) > 150 ) {
				return; // yorum yapip bokunu cikaranlar olabiliyor!!1
			}

			if ( false !== strpos( $item, '-' ) ) {
				return $item;
			}
		}, $maybe_list );

		if ( $clean_list ) {
			return array_filter( $clean_list );
		}
	}

}


function match_spotify_track( $api, $name ) {
	$name = trim( $name );

	// url temizle
	$name = preg_replace( '/\b((https?|ftp|file):\/\/|www\.)[-A-Z0-9+&@#\/%?=~_|$!:,.;]*[A-Z0-9+&@#\/%=~_|$]/i', ' ', $name );

	if ( mb_strlen( $name ) < 3 ) {
		return false;
	}

	if ( filter_var( $name, FILTER_VALIDATE_URL ) ) {
		return false;
	}


	$search_result = $api->search( $name, 'track' );


	if ( $search_result->tracks->items ) {
		return $search_result->tracks->items[0]->id;
	}

	return false;
}