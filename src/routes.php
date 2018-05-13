<?php

use Slim\Http\Request;
use Slim\Http\Response;
use Symfony\Component\DomCrawler\Crawler;


// Routes
$app->get( '/', function ( Request $request, Response $response, array $args ) {
	// Sample log message
	$this->logger->info( "Slim-Skeleton '/' route" );

	// Render index view
	return $this->renderer->render( $response, 'index.phtml', $args );
} );

$app->get( '/spotify-connect', function ( Request $request, Response $response, array $args ) {
	$session = new SpotifyWebAPI\Session(
		CLIENT_ID,
		CLIENT_SECRET,
		REDIRECT_URL
	);

	if ( isset( $_GET['code'] ) ) {
		$session->requestAccessToken( $_GET['code'] );
		$status = add_token_to_config( $session->getAccessToken() );

		// Render index view
		return $this->renderer->render( $response, 'spotify-connect.phtml', [ 'status' => $status, 'token' => $session->getAccessToken() ] );
	} else {
		$options = [
			'scope' => [
				'user-read-email',
				'playlist-modify-public',
			],
		];

		header( 'Location: ' . $session->getAuthorizeUrl( $options ) );
		exit;
	}
} );

$app->get( '/crawl', function ( Request $request, Response $response, array $args ) {
	/**
	 * Burasi parametrik olabilirdi, itligine hardcoded birakiyorum :trollface:
	 */
	$base_url = 'https://eksisozluk.com/durduk-yere-adamin-amina-koyan-sarkilar--2430892';

	$html = file_get_contents( $base_url );

	$crawler      = new Crawler( $html );
	$pager        = $crawler->filter( '#topic .pager' )->eq( 0 );
	$page_count   = $pager->attr( 'data-pagecount' );
	$current_page = $pager->attr( 'data-currentpage' );


	$api = new SpotifyWebAPI\SpotifyWebAPI();
	$api->setAccessToken( SPOTIFY_TOKEN );


	if ( isset( $_GET['page'] ) ) {
		$current_page = (int) $_GET['page'];
	}


	if ( ! file_exists( APP_PATH . '/' . LIST_FILE ) ) {
		touch( APP_PATH . '/' . LIST_FILE );
	}

	if ( $current_page <= $page_count ) {

		$url     = $base_url . '?p=' . $current_page;
		$html    = file_get_contents( $url );
		$crawler = new Crawler( $html );
		$rows    = array();


		try {
			$crawler->filterXPath( '//*[@id="entry-item-list"]/li' )->each( function ( Crawler $node, $i ) use ( $api ) {
				global $rows;
				$result = predict_the_song( trim( $node->filter( 'div.content' )->html() ) );

				if ( $result ) {
					if ( is_array( $result ) ) {
						foreach ( $result as $maybe_song ) {
							$row['track']      = $maybe_song;
							$row['entry_id']   = $node->attr( 'data-id' );
							$row['spotify_id'] = match_spotify_track( $api, $maybe_song );
							$rows[]            = $row;
							file_put_contents( APP_PATH . '/' . LIST_FILE, implode( ' || ', $row ) . PHP_EOL, FILE_APPEND );
						}
					} else {
						$row    = array(
							'track'      => $result,
							'entry_id'   => $node->attr( 'data-id' ),
							'spotify_id' => match_spotify_track( $api, $result ),
						);
						$rows[] = $row;
						file_put_contents( APP_PATH . '/' . LIST_FILE, implode( ' || ', $row ) . PHP_EOL, FILE_APPEND );
					}
				}


			} );
		} catch ( Exception $ex ) {
			if ( 'The access token expired' == $ex->getMessage() ) {
				header( 'Location: /spotify-connect?auto-redirect=true&page=' . $current_page );
				exit;
			}
		}

		global $rows;

		//var_dump( $row ); // make love not var_dump ∞

		$sleep_time = rand( 1, 4 ); // hayvan gibi şiy yapmayalim
		$current_page ++;

		return $this->renderer->render( $response, 'crawl.phtml', [ 'rows' => $rows, 'sleep' => $sleep_time, 'next_page' => $current_page ] );
	}

	// Tum sayfalar tarandi, artik playlist import edilebilir
	header( 'Location: /make-playlist' );
	exit;
} );

$app->get( '/make-playlist', function ( Request $request, Response $response, array $args ) {

	$list_file = APP_PATH . '/' . LIST_FILE;
	if ( ! file_exists( $list_file ) ) {
		echo 'Playlist olusturabilmem icin dosya lazim. Once crawl edip ' . LIST_FILE . ' dosyasini hazirlamak gerek.';
		exit;
	}

	$entries   = file( $list_file );
	$song_list = array();
	foreach ( $entries as $key => $maybe_song ) {
		list( $track_name, $entry_id, $spotify_id ) = explode( ' || ', $maybe_song );
		$spotify_id = trim( $spotify_id );
		if ( ! empty( $spotify_id ) && ! in_array( $spotify_id, $song_list ) ) {
			$song_list[] = $spotify_id;
		}
	}


	$api = new SpotifyWebAPI\SpotifyWebAPI();
	$api->setAccessToken( SPOTIFY_TOKEN );

	try {

		$playlists = $api->getMyPlaylists( array( 'limit' => 50 ) );

		// life is too short to check all playlist by using offset
		foreach ( $playlists->items as $playlist ) {
			if ( PLAYLIST_NAME == $playlist->name ) {
				$playlist_id = $playlist->id;
				break;
			}
		}

		if ( ! isset( $playlist_id ) ) {
			$response    = $api->createUserPlaylist( $api->me()->id, array( 'name' => PLAYLIST_NAME ) );
			$playlist_id = $response->id;
		}

		$current_playlist = $api->getUserPlaylist( $api->me()->id, $playlist_id );


		// spotify apisi max 100 sarki import etmeye izin veriyor tek seferde
		$track_sets = array_chunk( $song_list, 50 );

		foreach ( $track_sets as $key => $tracks ) {
			if ( 0 === $key ) { // sync yaparken once playlist'i temizliyoruz
				$api->replaceUserPlaylistTracks( $api->me()->id, $playlist_id, $tracks );
			} else {
				$api->addUserPlaylistTracks( $api->me()->id, $playlist_id, $tracks );
			}
		}

		$link = $current_playlist->external_urls->spotify;

		return $this->renderer->render( $response, 'playlist.phtml', [ 'playlist_link' => $link ] );

	} catch ( Exception $ex ) {
		if ( 'The access token expired' == $ex->getMessage() ) {
			header( 'Location: /spotify-connect&redirect=make-playlist' );
			exit;
		}
		throw new Exception( $ex );
	}

} );