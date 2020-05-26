#chat
A simple private chat package

### php
```
use Ller\WaterMark\Server;



$ws = new WebSocket();

// 发送成功，发送成功会把内容以及自己和对方的唯一标识传到这个方法，可以用来记录聊天记录。
$ws->sent_success(function($send) {
	/*{"to":1,"type":"user","from":2,"content":"hello,world","room":"068c55e33e8c2c792d0f5875698c39df","create_date":"01-01 08:00:00","is_send":true}*/
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
composer run-script chat

### 2. javascript
```
	var uname = 1;
    var to = 2;
    var ws = new WebSocket("ws://127.0.0.1:8080");

    function send(msg) {
        var data = JSON.stringify({'from': uname, 'to': to, 'content': msg, 'type': 'user'});
        ws.send(data);
    }

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