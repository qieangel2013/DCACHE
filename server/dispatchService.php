<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/redisServer.php';
class dispatchServer
{
	public static $instance;
	private $ServiceLog;
	private $application;
	private $fdTable;
  private $TableNum = 10; //绑定每个agent的队列 
	private $queueService;
	private $RedisService;
  private $Queue;
  private $useQueue;
  private $Sconfig;
	public function __construct($config) {
		$this->application = new swoole_server($config['serverConfig']['ServiceListenHost'],$config['serverConfig']['ServiceListenPort']);
		$this->RedisService = redisServer::connectRedis($config);
    $this->Sconfig = $config;
		$this->application->set(array(
					'worker_num'  => 4,
          'daemonize' => false,
					'open_length_check' => true,
					'dispatch_mode' => 2,
    				'package_max_length' => 2000000,
    				'package_length_func' => function ($data) use($config) {
				        if (strlen($data) < ($config['serverConfig']['TCP_HEADER_LEN'] + $config['serverConfig']['TCP_DATA_LEN'])) {
				            return 0;
				        }
				        $length = intval(strlen(trim(substr($data, 0, ($config['serverConfig']['TCP_HEADER_LEN'] + $config['serverConfig']['TCP_DATA_LEN'])-1))));
				        if ($length <= 0) {
				            return -1;
				        }
				        return strlen($data);
				    },
    				'heartbeat_idle_time' => $config['serverConfig']['heartbeat_idle_time'],
    				'heartbeat_check_interval' => $config['serverConfig']['heartbeat_check_interval'],
		));
		//创建存储信息
        $this->fdTable = new swoole_table(512);
        $this->fdTable->column('id', swoole_table::TYPE_INT, 8);
        $this->fdTable->column('queueObj', swoole_table::TYPE_STRING, 10);

        $this->fdTable->create();

        //创建消息队列服务
        $this->Queue = new Swoole_Channel(1024 * 16);
        for ($i=0; $i <$this->TableNum-1 ; $i++) { 
            $this->queueService['client'.$i] = new Swoole_Channel(1024 * 6);
            $this->Queue->push('client'.$i);
        }
        $this->useQueue = new Swoole_Channel(1024 * 16);

        //创建日志服务
       	$this->ServiceLog = [
				'path' => !empty($config['serverConfig']['serverLog']) ?: __DIR__ .'/log/'.date('Ymd',time()).'/'.date('Ymd',time()).'.log', 
				'content' => "时间：".date('Ymd-H:i:s',time())."\r\n"
		];

		$this->application->on('WorkerStart', array($this , 'onWorkerStart'));
		$this->application->on('Connect', array(&$this , 'onConnect'));
		$this->application->on('PipeMessage', array(&$this , 'onPipeMessage'));
		$this->application->on('Receive',array(&$this , 'onReceive'));
		$this->application->on('Close', array(&$this , 'onClose'));
    $this->application->start();
	}

	 /**
     * 
     * worker进程创建时，会触发该方法
     * @param object $server 当前server服务
     * @param int $worker_id 当前worker进程id
     * @author: qieangel2013 2019/04/25
     *
     */
    public function onWorkerStart($server,$worker_id){
        if($worker_id == 0 && !$server->taskworker){ 
            $redis = $this->RedisService; 
            $cache_table = $this->Sconfig['serverConfig']['cache_table'];
            $obj = $this;
            $server->tick(10000,function($id) use ($redis,$cache_table,$server,$obj){  
                if($redis->lSize($cache_table)>0){ //查询监控队列是否有元素
                    try {
                        //$yacKey = $redis->lPop($cache_table);
                        $yacKey = "common:models:Project:Y_getProjectModelKey";
                        $worker_id = 1 - $server->worker_id;
                        $server->sendMessage($yacKey,$worker_id);
                    } catch (Exception $e) {
                    	$obj->ServiceLog['content'] .= "进程间发送消息失败！error:".$e->getMessage()."\r\n";
                        $obj->log($obj->ServiceLog);
                    }
                }
            });
        }

    }

    public function onConnect($server,$fd)
    {
      $queueKey = $this->Queue->pop();
      $this->useQueue->push($queueKey);
      $fdData = array(
        'id' =>$fd, 
        'queueObj' => $queueKey
        );
      $this->fdTable->set($fd,$fdData);
    }

    public function onPipeMessage($server,$worker_id,$message)
    {
      foreach ($this->fdTable as $row) {
	     if (!empty($row['id'])) {
          $this->queueService[$row['queueObj']]->push($message);
	       	$server->send($row['id'],$this->packmes("del\r\n"));
	     }
      }
    }

	public function onReceive($serv, $fd, $from_id, $data)
    {
       	$resData = $this->unpackmes($data);
       	if(strpos($resData,'yacKey') !== false) {
          $queueObj = $this->fdTable->get($fd);
          if(!empty($queueObj)){
              $message = $this->queueService[$queueObj['queueObj']]->pop();
              if ($message) {
                $resData = [
                  'yacKey' => $message
                ];
                $this->ServiceLog['content'] .= "获取删除成功！删除数据为：".json_encode($resData,true)."\r\n";
                $this->log($this->ServiceLog);
                $this->application->send($fd,$this->packmes(json_encode($resData,true)));
              } else {
                $this->ServiceLog['content'] .= "获取消息队列数据失败！\r\n";
                $this->log($this->ServiceLog);
              }
          } else {
              $this->ServiceLog['content'] .= "无法获取到删除消息！\r\n";
              $this->log($this->ServiceLog);
          }
       	} else {
       		$this->application->send($fd,$data);
       	}
    }

	public function onClose(swoole_server $server, int $fd, int $reactorId)
    {
      $queueObj = $this->fdTable->get($fd);
      $this->Queue->push($queueObj['queueObj']);
      while (true) {
        $queueKey = $this->useQueue->pop();
        if ($queueKey == $queueObj['queueObj']) {
            break;
        } else {
            $this->useQueue->push($queueKey);
        }
      }
      $this->fdTable->del($fd);
    }

	 //包装数据
    public function packmes($data, $format = '\r\n', $preformat = '######')
    {
        //return $preformat . json_encode($data, true) . $format;
        return $this->Sconfig['serverConfig']['TCP_HEADER'] . pack('N', strlen($data)) . $data;
    }
 
    //解包装数据
    public function unpackmes($data, $format = '\r\n', $preformat = '######')
    {
        $resultdata = substr($data, $this->Sconfig['serverConfig']['TCP_HEADER_LEN'] + $this->Sconfig['serverConfig']['TCP_DATA_LEN']);
        return $resultdata;
    }

    private function log($data)
    {
        if (!file_put_contents( $this->exitdir($data['path']), $data['content']."\r\n", FILE_APPEND )) {
            return false;
        }
        return true;
    }

    private function exitdir($dir)
    {
        $dirarr=pathinfo($dir);
        if (!is_dir( $dirarr['dirname'] )) {
            mkdir( $dirarr['dirname'], 0777, true);
        }
        return $dir;
    }

	
	public static function getInstance($config) {
		if (!self::$instance) {
            self::$instance = new dispatchServer($config);
        }
        return self::$instance;
	}
}
dispatchServer::getInstance($config);
