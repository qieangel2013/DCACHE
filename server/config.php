<?php
return $config = [
		"redisConfig" => [
					'host' => '127.0.0.1',
					'port' => 6379,
					'timeout' =>60,
					'passwd' => 'test',
					'database' => 1 
					],
		"serverConfig" => [
					'cache_table' =>'cachetable:yac',
					'TCP_HEADER'  =>'www.test.com',
					'TCP_HEADER_LEN' => 14,
					'TCP_DATA_LEN'   => 4,
					'ServiceListenHost' => '0.0.0.0',
					'ServiceListenPort' =>9501,
					'heartbeat_idle_time' =>50,
					'heartbeat_check_interval'=>5,
					'serverLog' => ''
		]

];