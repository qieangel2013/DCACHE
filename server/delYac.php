<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/redisServer.php';
class YacDelServer
{
	public static $instance;
	//返回结果 status 0 失败，1 成功
    public $result = [
            'status' =>0,
            'msg'    =>'',
            'data'   =>''
    ];
	private $application;
	private $RedisService;
	private $YacService;

	public function __construct($config) {
		$this->YacService = new Yac();
		$this->RedisService = redisServer::connectRedis($config);
	}

	public function run(){
		if (!empty($_POST)) {
			$yacKey = urldecode($_POST['yacKey']) ?? '';
	        if (!empty($yacKey)) {
	        	try {
		            $HashKeys = $this->RedisService->sMembers($yacKey);
		            if (!empty($HashKeys)) {
		            	foreach ($HashKeys as $k => $v) {
		            		$this->YacService->delete($v);
		            	}                
		                $this->RedisService->del($yacKey); 
		                $this->result['msg'] = '删除'.$yacKey.'的缓存成功';
		            	$this->result['status'] = 1;
		            } else {
		            	$this->result['msg'] = $yacKey.'无删除的缓存';
		            }
		        } catch (\Exception $e) {
		            $this->result['msg'] = $e->getMessage();
		        }
	        } else {
	        	$this->result['msg'] = 'post参数错误';
	        }
		} else {
			$this->result['msg'] = 'post参数为空';
		}
		return json_encode($this->result,true);
	}
	
	public static function getInstance($config) {
		if (!self::$instance) {
            self::$instance = new YacDelServer($config);
        }
        return self::$instance;
	}
}
echo YacDelServer::getInstance($config)->run();
