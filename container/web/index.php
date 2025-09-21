<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/vendor/autoload.php';

function adminer_object()
{
	include_once __DIR__ . '/adminer/include/bootstrap.inc.php';
	include_once __DIR__ . '/adminer/include/adminer.inc.php';
	include_once __DIR__ . '/adminer/include/plugin.inc.php';

	require_once __DIR__ . '/plugins/drivers/bigquery.php';

	$plugins = array(
		new AdminerLoginBigQuery(array(
			'project_id' => getenv('GOOGLE_CLOUD_PROJECT')
		)),
		new AdminerBigQueryCSS(),
	);

	return new \Adminer\Plugins($plugins);
}

if (!file_exists(__DIR__ . '/adminer/index.php')) {
	echo "<h1>Error: Adminer core not found</h1>";
	echo "<p>The adminer core files are not available in this container.</p>";
	exit;
}

chdir(__DIR__ . '/adminer');
include 'index.php';
