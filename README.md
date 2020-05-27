#chat
composer require ller/chat v1.0

### simple_demo.php
```
use Ller\Chat\Server;
$ws = new Server();
$ws = $ws->run("0.0.0.0", "8080");
```
命令行执行 `php simple_demo.php`

### Client1.html
``` 
    var uname = 1;  // 自己的唯一标识
    var to = 2;     // 好友的唯一标识
    var ws = new WebSocket("ws://127.0.0.1:8080");

    // 发送信息
    function send(msg) {
        var data = JSON.stringify({'from': uname, 'to': to, 'content': msg, 'type': 'user'});
        ws.send(data);
    }
    
    // 获取服务器响应信息
    ws.onmessage = function (e) {
        var msg = JSON.parse(e.data);

        switch (msg.type) {
            case 'user':
                console.log(msg.content);
                break;
            case 'handshake':
                // 注意：连接成功之后立即发送登录信息
                var user_info = {'type': 'login', 'content': uname, 'to': to, 'from': uname};
                ws.send(JSON.stringify(user_info));
                break;
            default:
                return;
        }
    };
```
### Client2.html
``` 
    var uname = 2;  // 自己的唯一标识
    var to = 1;     // 好友的唯一标识
    var ws = new WebSocket("ws://127.0.0.1:8080");

    // 发送信息
    function send(msg) {
        var data = JSON.stringify({'from': uname, 'to': to, 'content': msg, 'type': 'user'});
        ws.send(data);
    }
    
    // 获取服务器响应信息
    ws.onmessage = function (e) {
        var msg = JSON.parse(e.data);

        switch (msg.type) {
            case 'user':
                console.log(msg.content);
                break;
            case 'handshake':
                // 注意：连接成功之后立即发送登录信息
                var user_info = {'type': 'login', 'content': uname, 'to': to, 'from': uname};
                ws.send(JSON.stringify(user_info));
                break;
            default:
                return;
        }
    };
```
client1和client2是一个简单的私聊，可以用已经封装的send()发送消息，比如`send('你好，世界');`


### detailed.php
想要更多的操作，比如未发送成功的消息保存到数据库，或者某个用户上线了，需要查找他的未读消息。
注意1：这里的几个函数都是当用户进行websocket连接之后才会触发的。如果想要未连接websocket就查找信息，可以在未读信息sent_fail()函数把未发送的信息保存到数据库。
注意2：这个包太多地方未完善了，所以不要用在正式的网站上。
```
<?php
use Ller\Chat\Server;

set_time_limit(0);
date_default_timezone_set('Asia/shanghai');

$ws = new Server();

// 发送成功，发送成功会把内容以及自己和对方的唯一标识传到这个方法，可以用来记录聊天记录。
$ws->sent_success(function($send) {
	/*{"to":1,"type":"user","from":2,"content":"hello,world","create_date":"01-01 08:00:00","is_send":true}*/
	file_put_contents('./success.log', json_encode($send)."\r\n", FILE_APPEND);
});

// 发送失败，对方不在线的时候会发送失败，此时可以把数据保存起来和后面的等待发送配合使用
$ws->sent_fail(function($send) {
	file_put_contents('./fail.log', json_encode($send)."\r\n", FILE_APPEND);
});

// 等待发送，可以在某个用户登录的时候根据这个用户查询他的未读信息。
// $send ---->["to"=>2, "room"=>"", "type"=>"login", "content"=>'聊天内容', "from"=>1]
$ws->wait_send(function($send) {
	$data = [
		[
			'to'=>1,
			'from'=>2,
			'content'=>'hello,world',
			'create_date'=>time()
		],
		[
			'to'=>1,
			'from'=>2,
			'content'=>'world,hello',
			'create_date'=>time()
		]
	];

	return $data;
});

$ws->run("0.0.0.0", "8080");
```
