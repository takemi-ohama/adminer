<?php

namespace Adminer;

/**
 * AdminerBigQueryCSS - BigQuery用のCSS提供プラグイン
 *
 * bigquery.phpから分離されたCSSクラス
 */
class AdminerBigQueryCSS extends \Adminer\Plugin
{
	private function isBigQueryDriver()
	{
		return (defined('DRIVER') && DRIVER === 'bigquery') || (defined('Adminer\\DRIVER') && constant('Adminer\\DRIVER') === 'bigquery');
	}

	function head($dark = null)
	{
		if ($this->isBigQueryDriver()) {

			if (class_exists('Adminer\\BigQueryDriver')) {
				$driver = new \Adminer\BigQueryDriver();
				if (method_exists($driver, 'css')) {

					echo $driver->css();
				}
			}
		}
	}
}