<?php

namespace Adminer;

class AdminerBigQueryCSS extends Plugin
{
	private function isBigQueryDriver()
	{
		return (defined('DRIVER') && DRIVER === 'bigquery') || (defined('Adminer\\DRIVER') && constant('Adminer\\DRIVER') === 'bigquery');
	}

	function head($dark = null)
	{
		if ($this->isBigQueryDriver()) {

			if (class_exists('Adminer\\Driver')) {
				$driver = new \Adminer\Driver();
				if (method_exists($driver, 'css')) {

					echo $driver->css();
				}
			}
		}
	}
}