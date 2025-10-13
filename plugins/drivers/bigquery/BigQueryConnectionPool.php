<?php

namespace Adminer;

use Google\Cloud\BigQuery\BigQueryClient;

class BigQueryConnectionPool
{

	private static $pool = array();
	private static $maxConnections = 3;
	private static $usageTimestamps = array();
	private static $creationTimes = array();
	static function getConnection($key, $config)
	{
		if (isset(self::$pool[$key])) {
			self::$usageTimestamps[$key] = time();
			$age = time() - self::$creationTimes[$key];
			return self::$pool[$key];
		}
		if (count(self::$pool) >= self::$maxConnections) {
			self::evictOldestConnection();
		}
		$startTime = microtime(true);
		$clientConfig = array(
			'projectId' => $config['projectId'],
			'location' => $config['location']
		);
		if (isset($config['credentialsPath'])) {
			$clientConfig['keyFilePath'] = $config['credentialsPath'];
		}
		$client = new BigQueryClient($clientConfig);
		$creationTime = microtime(true) - $startTime;
		self::$pool[$key] = $client;
		self::$usageTimestamps[$key] = time();
		self::$creationTimes[$key] = time();
		return $client;
	}
	private static function evictOldestConnection()
	{
		if (empty(self::$usageTimestamps)) {
			return;
		}
		$oldestKey = array_keys(self::$usageTimestamps, min(self::$usageTimestamps))[0];
		unset(self::$pool[$oldestKey]);
		unset(self::$usageTimestamps[$oldestKey]);
		unset(self::$creationTimes[$oldestKey]);
	}
	function clearPool()
	{
		$count = count(self::$pool);
		self::$pool = array();
		self::$usageTimestamps = array();
		self::$creationTimes = array();
	}
	function getStats()
	{
		$stats = array(
			'pool_size' => count(self::$pool),
			'max_size' => self::$maxConnections,
			'connections' => array()
		);
		foreach (array_keys(self::$pool) as $key) {
			$stats['connections'][] = array(
				'key' => substr($key, 0, 8) . '...',
				'age' => time() - self::$creationTimes[$key],
				'last_used' => time() - self::$usageTimestamps[$key]
			);
		}
		return $stats;
	}

	static function getConnectionFromPool($key)
	{
		if (isset(self::$pool[$key])) {
			self::$usageTimestamps[$key] = time();
			return self::$pool[$key];
		}
		return null;
	}
}