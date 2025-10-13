<?php

namespace Adminer;

class BigQueryCacheManager
{

	private static array $staticCache = array();
	private static array $cacheTimestamps = array();
	private static ?bool $apcuAvailable = null;
	private static function isApcuAvailable()
	{
		if (self::$apcuAvailable === null) {
			self::$apcuAvailable = extension_loaded('apcu')
				&& function_exists('\apcu_exists')
				&& function_exists('\apcu_fetch')
				&& function_exists('\apcu_store');
		}
		return self::$apcuAvailable;
	}
	static function get($key, $ttl = 300)
	{
		if (self::isApcuAvailable()) {
			return \apcu_fetch($key);
		}
		if (
			isset(self::$staticCache[$key]) &&
			(time() - (self::$cacheTimestamps[$key] ?? 0)) < $ttl
		) {
			return self::$staticCache[$key];
		}
		return false;
	}
	static function set($key, $value, $ttl = 300)
	{
		$success = false;
		if (self::isApcuAvailable()) {
			$success = \apcu_store($key, $value, $ttl);
		}
		self::$staticCache[$key] = $value;
		self::$cacheTimestamps[$key] = time();
		return $success;
	}
	static function clear($pattern = null)
	{
		if ($pattern === null) {
			if (self::isApcuAvailable()) {
				\apcu_clear_cache();
			}
			self::$staticCache = array();
			self::$cacheTimestamps = array();
		} else {
			foreach (array_keys(self::$staticCache) as $key) {
				if (strpos($key, $pattern) !== false) {
					unset(self::$staticCache[$key]);
					unset(self::$cacheTimestamps[$key]);
				}
			}
		}
	}
	static function getStats()
	{
		$apcuInfo = self::isApcuAvailable() ? \apcu_cache_info() : array();
		return array(
			'static_cache_size' => count(self::$staticCache),
			'apcu_available' => self::isApcuAvailable(),
			'apcu_info' => $apcuInfo,
			'cache_keys' => array_keys(self::$staticCache)
		);
	}
}