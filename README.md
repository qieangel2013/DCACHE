### install
	server端是用swoole实现的调度服务，采用给每个agent绑定一个消息队列
	来接受删除请求的消费，直接php执行就可以
	agent端采用golang实现的自定义协议跟swoole服务端通信，同时也实现了跟
	php-fpm进行通信，直接执行编译后可执行文件，建议修改配置重新编译执行

