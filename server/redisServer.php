<?php
require_once __DIR__.'/config.php';
class redisServer
{
	public static $RedisService;

	public static function connectRedis($config){
		self::$RedisService = new redis();
		self::$RedisService->connect($config['redisConfig']['host'], $config['redisConfig']['port'], $config['redisConfig']['timeout']);
		if(!empty($config['redisConfig']['passwd'])){
			self::$RedisService->auth($config['redisConfig']['passwd']);
		}
		if(!empty($config['redisConfig']['database'])){
			self::$RedisService->select($config['redisConfig']['database']);
		}
		return self::$RedisService;
	}	
}
