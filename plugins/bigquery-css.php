<?php

/** BigQuery CSS Plugin
 * BigQueryドライバーが使用されている場合に、ドライバーのCSS関数を呼び出すプラグイン
 */
class AdminerBigQueryCSS extends \Adminer\Plugin
{
    function head($dark = null)
    {
        // デバッグ用：常にコメントを出力してプラグインが動作していることを確認
        echo "<!-- BigQueryCSS Plugin loaded -->\n";
        
        // BigQueryドライバーが使用されている場合のCSS追加
        $isDefined = defined('DRIVER');
        $isAdminerDefined = defined('Adminer\\DRIVER');
        $driverValue = $isDefined ? constant('DRIVER') : 'not_defined';
        $adminerDriverValue = $isAdminerDefined ? constant('Adminer\\DRIVER') : 'not_defined';
        
        echo "<!-- DRIVER defined: " . ($isDefined ? 'true' : 'false') . ", value: $driverValue -->\n";
        echo "<!-- Adminer\\DRIVER defined: " . ($isAdminerDefined ? 'true' : 'false') . ", value: $adminerDriverValue -->\n";
        
        if ((defined('DRIVER') && DRIVER === 'bigquery') || (defined('Adminer\\DRIVER') && constant('Adminer\\DRIVER') === 'bigquery')) {
            echo "<!-- BigQuery driver detected -->\n";
            
            // BigQueryドライバーのCSSメソッドを呼び出し
            if (class_exists('Adminer\\Driver')) {
                echo "<!-- Adminer\\Driver class exists -->\n";
                $driver = new \Adminer\Driver();
                if (method_exists($driver, 'css')) {
                    echo "<!-- Driver css method exists -->\n";
                    // DriverのCSS関数の戻り値を取得してデバッグ
                    $cssContent = $driver->css();
                    echo "<!-- CSS content length: " . strlen($cssContent) . " -->\n";
                    echo "<!-- CSS content preview: " . substr($cssContent, 0, 100) . "... -->\n";
                    // DriverのCSS関数はHTML文字列を返すので、そのまま出力
                    echo $cssContent;
                } else {
                    echo "<!-- Driver css method does not exist -->\n";
                }
            } else {
                echo "<!-- Adminer\\Driver class does not exist -->\n";
            }
        } else {
            echo "<!-- BigQuery driver not detected -->\n";
        }
    }
}