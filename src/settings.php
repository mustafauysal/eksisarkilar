<?php

$config_file = dirname( __DIR__ ) . '/config.php';
if ( $config_file ) {
	require_once $config_file;
} else {
	echo 'config.php lazim, ama yok!';
	exit;
}

// Define a working path
define( 'APP_PATH', dirname( __DIR__ ) );
define( 'ROOT_PATH', dirname( APP_PATH ) );


return [
	'settings' => [
		'displayErrorDetails'    => true, // set to false in production
		'addContentLengthHeader' => false, // Allow the web server to send the content-length header

		// Renderer settings
		'renderer'               => [
			'template_path' => __DIR__ . '/../templates/',
		],

		// Monolog settings
		'logger'                 => [
			'name'  => 'slim-app',
			'path'  => isset( $_ENV['docker'] ) ? 'php://stdout' : __DIR__ . '/../logs/app.log',
			'level' => \Monolog\Logger::DEBUG,
		],
	],
];
