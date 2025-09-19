<?php
/**
 * BigQuery Adminer - e2e Test用カスタムエントリーポイント
 *
 * BigQueryドライバープラグインのテスト用のAdminerエントリーポイント
 * login-bigquery.phpプラグインを使用してログイン画面を簡素化
 */

// デバッグ用エラー表示
error_reporting(E_ALL);
ini_set('display_errors', 1);

// BigQuery環境設定
putenv('BQ_PROJECT=nyle-carmo-analysis');
putenv('BQ_LOCATION=asia-northeast1');
$_ENV['GOOGLE_APPLICATION_CREDENTIALS'] = '/etc/google_credentials.json';
$_ENV['BIGQUERY_PROJECT_ID'] = 'nyle-carmo-analysis';

// Composer autoloaderの読み込み (BigQueryクライアント用)
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    echo "<h1>Error: Composer dependencies not found</h1>";
    echo "<p>Please run <code>composer install</code> to install required dependencies.</p>";
    exit;
}

// デバッグ情報表示機能
function debug_bigquery_config() {
    echo "<div style='background-color: #f0f0f0; padding: 10px; margin: 10px; border: 1px solid #ccc;'>";
    echo "<h3>BigQuery Configuration Debug Info</h3>";
    echo "<p><strong>Google Credentials Path:</strong> " . ($_ENV['GOOGLE_APPLICATION_CREDENTIALS'] ?? 'Not set') . "</p>";
    echo "<p><strong>Project ID:</strong> " . ($_ENV['BIGQUERY_PROJECT_ID'] ?? 'Not set') . "</p>";
    echo "<p><strong>Credentials File Exists:</strong> " . (file_exists($_ENV['GOOGLE_APPLICATION_CREDENTIALS'] ?? '') ? 'Yes' : 'No') . "</p>";
    echo "<p><strong>BigQuery Plugin File Exists:</strong> " . (file_exists(__DIR__ . '/plugins/drivers/bigquery.php') ? 'Yes' : 'No') . "</p>";
    echo "<p><strong>Login Plugin File Exists:</strong> " . (file_exists(__DIR__ . '/plugins/login-bigquery.php') ? 'Yes' : 'No') . "</p>";
    echo "<p><a href='?debug=0'>Hide Debug</a> | <a href='?'>Access Adminer</a></p>";
    echo "</div>";
}

// デバッグモード処理
if (isset($_GET['debug']) && $_GET['debug'] === '1') {
    debug_bigquery_config();
}

// プラグイン設定関数
function adminer_object() {
    // Adminerブートストラップ (正式な読み込み方法)
    include_once __DIR__ . '/adminer/include/bootstrap.inc.php';
    include_once __DIR__ . '/adminer/include/adminer.inc.php';
    include_once __DIR__ . '/adminer/include/plugin.inc.php';

    // BigQueryドライバープラグインの読み込み
    if (file_exists(__DIR__ . '/plugins/drivers/bigquery.php')) {
        require_once __DIR__ . '/plugins/drivers/bigquery.php';
    }

    // BigQueryログインプラグインの読み込み
    if (file_exists(__DIR__ . '/plugins/login-bigquery.php')) {
        require_once __DIR__ . '/plugins/login-bigquery.php';
    }

    // プラグインクラスが存在するかチェック
    if (!class_exists('AdminerLoginBigQuery')) {
        echo "<h1>Error: BigQuery login plugin not found</h1>";
        echo "<p>AdminerLoginBigQuery class is not available.</p>";
        echo "<p><a href='?debug=1'>Debug Info</a></p>";
        exit;
    }

    // BigQueryログインプラグインでAdminerを拡張
    $plugins = array(
        new AdminerLoginBigQuery('nyle-carmo-analysis', '/etc/google_credentials.json'),
    );

    return new Adminer\AdminerPlugin($plugins);
}

// BigQueryプラグインのテスト情報表示
if (isset($_GET['test']) && $_GET['test'] === '1') {
    echo "<h1>BigQuery Driver Plugin Test</h1>";
    echo "<div style='background-color: #f0f0f0; padding: 15px; margin: 15px 0; border: 1px solid #ccc;'>";
    echo "<h3>テスト環境情報</h3>";
    echo "<p><strong>Google Credentials Path:</strong> " . ($_ENV['GOOGLE_APPLICATION_CREDENTIALS'] ?? 'Not set') . "</p>";
    echo "<p><strong>Project ID:</strong> " . ($_ENV['BIGQUERY_PROJECT_ID'] ?? 'Not set') . "</p>";
    echo "<p><strong>Credentials File Exists:</strong> " . (file_exists($_ENV['GOOGLE_APPLICATION_CREDENTIALS'] ?? '') ? 'Yes' : 'No') . "</p>";
    echo "<p><strong>BigQuery Plugin File:</strong> " . (file_exists(__DIR__ . '/plugins/drivers/bigquery.php') ? 'Found' : 'Not found') . "</p>";
    echo "<p><strong>Login Plugin File:</strong> " . (file_exists(__DIR__ . '/plugins/login-bigquery.php') ? 'Found' : 'Not found') . "</p>";

    // Adminerブートストラップ (正式な読み込み方法)
    include_once __DIR__ . '/adminer/include/bootstrap.inc.php';
    include_once __DIR__ . '/adminer/include/adminer.inc.php';
    include_once __DIR__ . '/adminer/include/plugin.inc.php';

    if (file_exists(__DIR__ . '/plugins/drivers/bigquery.php')) {
        require_once __DIR__ . '/plugins/drivers/bigquery.php';
        echo "<p><strong>BigQuery Driver Class:</strong> " . (class_exists('Adminer\\Driver') ? 'Loaded' : 'Not loaded') . "</p>";
    }

    if (file_exists(__DIR__ . '/plugins/login-bigquery.php')) {
        require_once __DIR__ . '/plugins/login-bigquery.php';
        echo "<p><strong>BigQuery Login Class:</strong> " . (class_exists('AdminerLoginBigQuery') ? 'Loaded' : 'Not loaded') . "</p>";
    }

    echo "<p><a href='?'>Back to Adminer</a> | <a href='?debug=1'>Debug Info</a></p>";
    echo "</div>";
    exit;
}

// 簡易版Adminer（BigQueryプラグイン用）
if (!file_exists(__DIR__ . '/adminer/index.php')) {
    echo "<h1>Error: Adminer core not found</h1>";
    echo "<p>The adminer core files are not available in this container.</p>";
    echo "<p><a href='?test=1'>Test BigQuery Plugin</a> | <a href='?debug=1'>Debug Info</a></p>";
    exit;
}

// 通常のAdminerコアの実行
chdir(__DIR__ . '/adminer');
include 'index.php';
?>