<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/vendor/autoload.php';

function adminer_object() {
	include_once __DIR__ . '/adminer/include/bootstrap.inc.php';
	include_once __DIR__ . '/adminer/include/adminer.inc.php';
	include_once __DIR__ . '/adminer/include/plugin.inc.php';
	require_once __DIR__ . '/plugins/drivers/bigquery.php';

	$plugins = array(
		new \Adminer\AdminerLoginBigQuery(array(
			'project_id' => getenv('GOOGLE_CLOUD_PROJECT')
		)),
		new \Adminer\AdminerBigQueryCSS(),
	);

	return new \Adminer\Plugins($plugins);
}

// OAuth2コールバック処理（Adminer実行前に処理）
$is_oauth_callback = isset($_GET['oauth2']) && $_GET['oauth2'] === 'callback' &&
	isset($_GET['code']) && isset($_GET['state']);

if ($is_oauth_callback) {
	// デバッグログ出力
	error_log('OAuth2 callback detected. Query parameter oauth2=callback');
	error_log('GET parameters: ' . json_encode($_GET));

	include_once __DIR__ . '/adminer/include/bootstrap.inc.php';
	include_once __DIR__ . '/adminer/include/adminer.inc.php';
	include_once __DIR__ . '/adminer/include/plugin.inc.php';
	require_once __DIR__ . '/plugins/drivers/bigquery.php';

	// OAuth2処理のためのダミー接続を作成
	$oauth2Handler = new \Adminer\Db();

	try {
		// OAuth2コールバック処理を実行
		$state = $_GET['state'] ?? '';
		$stateData = json_decode(base64_decode($state), true);
		$redirectTo = $stateData['redirect_to'] ?? '/';

		// OAuth2処理を実行（handleOAuth2Callbackメソッドを直接呼び出し）
		$reflection = new ReflectionClass($oauth2Handler);
		$method = $reflection->getMethod('handleOAuth2Callback');
		$method->setAccessible(true);
		$result = $method->invoke($oauth2Handler);

		if ($result) {
			// 成功時はリダイレクト
			header('Location: ' . $redirectTo);
			exit;
		} else {
			// 失敗時はエラーページ
			echo "<h1>OAuth2 Authentication Failed</h1>";
			echo "<p>Authentication failed. Please <a href='/'>try again</a>.</p>";
			exit;
		}
	} catch (Exception $e) {
		error_log('OAuth2 callback error: ' . $e->getMessage());
		echo "<h1>OAuth2 Authentication Error</h1>";
		echo "<p>Authentication error occurred. Please <a href='/'>try again</a>.</p>";
		exit;
	}
}

if (!file_exists(__DIR__ . '/adminer/index.php')) {
	echo "<h1>Error: Adminer core not found</h1>";
	echo "<p>The adminer core files are not available in this container.</p>";
	exit;
}

chdir(__DIR__ . '/adminer');
include 'index.php';
