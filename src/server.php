<?php
namespace Ller\Chat;

class Server {
    const LOG_PATH = "./";
    const LISTEN_SOCKET_NUM = 9;

    private $sockets = [];
    private $master;

    public $fail;   // 发送失败
    public $success;   // 发送成功
    public $wait;  // 待发送   

    public function __construct()
    {
        $this->fail = function(){};
        $this->success = function(){};
        $this->wait = function(){ return '0'; };
    }

    /**
     * 定义发送成功方法
     * @return [type] [description]
     */
    public function sent_success($fun) 
    {
        $this->success = $fun;
    }


    /**
     * 定义发送失败方法
     * @return [type] [description]
     */
    public function sent_fail($fun) 
    {
        $this->fail = $fun;
    }


    /**
     * 定义待发送方法
     * @return [type] [description]
     */
    public function wait_send($fun) 
    {
        $this->wait = $fun;
    }


    /**
     * 开始运行
     * @param  [type] $host [description]
     * @param  [type] $port [description]
     * @return [type]       [description]
     */
    public function run($host, $port) {
        try {
            $this->master = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            socket_set_option($this->master, SOL_SOCKET, SO_REUSEADDR, 1);
            socket_bind($this->master, $host, $port);
            socket_listen($this->master, self::LISTEN_SOCKET_NUM);
        } catch (\Exception $e) {
            $err_code = socket_last_error();
            $err_msg = socket_strerror($err_code);

            $this->error([
                'error_init_server',
                $err_code,
                $err_msg
            ]);
        }

        $this->sockets[0] = ['resource' => $this->master];
        
        // $pid = posix_getpid(); 该函数win系统无法使用
        $pid = get_current_user();
        $this->debug(["server: {$this->master} started,pid: {$pid}"]);

        while (true) {
            try {
                $this->doServer();
            } catch (\Exception $e) {
                $this->error([
                    'error_do_server',
                    $e->getCode(),
                    $e->getMessage()
                ]);
            }
        }
    }


    private function doServer() {
        $write = $except = NULL;
        $sockets = array_column($this->sockets, 'resource');
        $read_num = socket_select($sockets, $write, $except, NULL);

        if (false === $read_num) {
            $this->error([
                'error_select',
                $err_code = socket_last_error(),
                socket_strerror($err_code)
            ]);
            return;
        }

        foreach ($sockets as $socket) {
            if ($socket == $this->master) {
                $client = socket_accept($this->master);
                if (false === $client) {
                    $this->error([
                        'err_accept',
                        $err_code = socket_last_error(),
                        socket_strerror($err_code)
                    ]);
                    continue;
                } else {
                    self::connect($client);
                    continue;
                }
            } else {
                // 如果可读的是其他已连接socket,则读取其数据,并处理应答逻辑
                $bytes = @socket_recv($socket, $buffer, 2048, 0);
                if ($bytes < 9) {
                    $recv_msg = $this->disconnect($socket);
                } else {
                    if (!$this->sockets[(int)$socket]['handshake']) {
                        self::handShake($socket, $buffer);
                        continue;
                    } else {
                        $recv_msg = self::parse($buffer);
                    }
                }
                array_unshift($recv_msg, 'receive_msg');
                $msg = self::dealMsg($socket, $recv_msg);
                $this->broadcast($msg);
            }
        }
    }


    /**
     * 将socket添加到已连接列表,但握手状态留空;
     *
     * @param $socket
     */
    public function connect($socket) {
        socket_getpeername($socket, $ip, $port);
        $socket_info = [
            'resource' => $socket,
            'uname' => '',
            'handshake' => false,
            'ip' => $ip,
            'port' => $port,
        ];
        $this->sockets[(int)$socket] = $socket_info;
        $this->debug(array_merge(['socket_connect'], $socket_info));
    }


    /**
     * 客户端关闭连接
     *
     * @param $socket
     *
     * @return array
     */
    private function disconnect($socket) {
        $recv_msg = [
            'type' => 'logout',
            'content' => $this->sockets[(int)$socket]['uname'],
            'from' => $this->sockets[(int)$socket]['uname'],
            'to' => $this->sockets[(int)$socket]['to']
        ];
        unset($this->sockets[(int)$socket]);

        return $recv_msg;
    }


    /**
     * 用公共握手算法握手
     *
     * @param $socket
     * @param $buffer
     *
     * @return bool
     */
    public function handShake($socket, $buffer) {
        // 获取到客户端的升级密匙
        $line_with_key = substr($buffer, strpos($buffer, 'Sec-WebSocket-Key:') + 18);
        $key = trim(substr($line_with_key, 0, strpos($line_with_key, "\r\n")));

        // 生成升级密匙,并拼接websocket升级头
        $upgrade_key = base64_encode(sha1($key . "258EAFA5-E914-47DA-95CA-C5AB0DC85B11", true));// 升级key的算法
        $upgrade_message = "HTTP/1.1 101 Switching Protocols\r\n";
        $upgrade_message .= "Upgrade: websocket\r\n";
        $upgrade_message .= "Sec-WebSocket-Version: 13\r\n";
        $upgrade_message .= "Connection: Upgrade\r\n";
        $upgrade_message .= "Sec-WebSocket-Accept:" . $upgrade_key . "\r\n\r\n";

        socket_write($socket, $upgrade_message, strlen($upgrade_message));// 向socket里写入升级信息
        $this->sockets[(int)$socket]['handshake'] = true;

        socket_getpeername($socket, $ip, $port);
        $this->debug([
            'hand_shake',
            $socket,
            $ip,
            $port
        ]);

        // 向客户端发送握手成功消息,以触发客户端发送用户名动作;
        $msg = [
            'type' => 'handshake',
            'content' => 'done',
        ];
        $msg = $this->build(json_encode($msg));
        socket_write($socket, $msg, strlen($msg));
        return true;
    }


    /**
     * 解析数据
     *
     * @param $buffer
     *
     * @return bool|string
     */
    private function parse($buffer) {
        $decoded = '';
        $len = ord($buffer[1]) & 127;
        if ($len === 126) {
            $masks = substr($buffer, 4, 4);
            $data = substr($buffer, 8);
        } else if ($len === 127) {
            $masks = substr($buffer, 10, 4);
            $data = substr($buffer, 14);
        } else {
            $masks = substr($buffer, 2, 4);
            $data = substr($buffer, 6);
        }
        for ($index = 0; $index < strlen($data); $index++) {
            $decoded .= $data[$index] ^ $masks[$index % 4];
        }

        return json_decode($decoded, true);
    }


    /**
     * 将普通信息组装成websocket数据帧
     *
     * @param $msg
     *
     * @return string
     */
    private function build($msg) {
        $frame = [];
        $frame[0] = '81';
        $len = strlen($msg);
        if ($len < 126) {
            $frame[1] = $len < 16 ? '0' . dechex($len) : dechex($len);
        } else if ($len < 65025) {
            $s = dechex($len);
            $frame[1] = '7e' . str_repeat('0', 4 - strlen($s)) . $s;
        } else {
            $s = dechex($len);
            $frame[1] = '7f' . str_repeat('0', 16 - strlen($s)) . $s;
        }

        $data = '';
        $l = strlen($msg);
        for ($i = 0; $i < $l; $i++) {
            $data .= dechex(ord($msg{$i}));
        }
        $frame[2] = $data;

        $data = implode('', $frame);

        return pack("H*", $data);
    }


    /**
     * 拼装信息
     *
     * @param $socket
     * @param $recv_msg
     *          [
     *          'type'=>user/login
     *          'content'=>content
     *          ]
     *
     * @return string
     */
    private function dealMsg($socket, $recv_msg) {

        $msg_type = $recv_msg['type'];
        $msg_content = $recv_msg['content'];
        $msg_to = $recv_msg['to'] ?? 0;
        $msg_from = $recv_msg['from'] ?? 0;

        $msg_room = md5(min($msg_from, $msg_to).'and'.max($msg_from, $msg_to));   // 创建两个人的房间

        // Ller new,private chat
        $response = [];
        $response['to'] = $msg_to;
        $response['room'] = $msg_room;


        switch ($msg_type) {
            case 'login':
                $this->sockets[(int)$socket]['uname'] = $msg_content;
                $this->sockets[(int)$socket]['room'] = $msg_room;
                $this->sockets[(int)$socket]['to'] = $msg_to;
                $user_list = array_column($this->sockets, 'uname');
                $response['type'] = 'login';
                $response['content'] = $msg_content;
                $response['from'] = $msg_from;

                // 查询未读信息
                $wait = $this->wait;
                $merge = $wait($response);
                if ($merge) {
                    // 发送未读信息
                    foreach ($merge as $value) {
                        $value['room'] = $msg_room;
                        $value['type'] = 'user';
                        $create_date = $value['create_date'] ?? time();
                        $value['create_date'] = date("m-d H:i:s", $create_date);
                        $this->broadcast($value);
                    }
                }

                break;
            case 'logout':
                $response['type'] = 'logout';
                $response['from'] = $msg_content;
                $response['to'] = $msg_to;
                $response['content'] = $msg_content;
                break;
            case 'user':
                $uname = $this->sockets[(int)$socket]['uname'];
                $response['type'] = 'user';
                $response['from'] = $uname;
                $response['create_date'] = time();
                $response['content'] = $msg_content;
                break;
        }

        return $response;
    }


    /**
     * 广播消息
     * 
     * broadcast广播，buffer缓冲区
     * @param $data
     */
    private function broadcast($send) {
     
        $room = $send['room'];
        unset($send['room']);
        $is_send = false;   //是否成功发送
        $data = $this->build(json_encode($send));
       
        foreach ($this->sockets as $socket) {
            if (isset($socket['room']) && $socket['room'] == $room) {

                socket_write($socket['resource'], $data, strlen($data));

                if ($socket['uname'] == $send['to']) {
                    $is_send = true;
                }
            }
        }
        
        $send['is_send'] = $is_send;
        unset($send['room']);
        if ($is_send) {
            $success = $this->success;
            $success($send);
        } else {    
            $fail = $this->fail;
            $fail($send);      
        }
    }


    /**
     * 记录debug信息
     *
     * @param array $info
     */
    private function debug(array $info) {
        $time = date('Y-m-d H:i:s');
        array_unshift($info, $time);
        $info = array_map('json_encode', $info);
        file_put_contents(self::LOG_PATH."websocket_debug.log", implode(' | ', $info)."\r\n", FILE_APPEND);
    }


    /**
     * 记录错误信息
     *
     * @param array $info
     */
    private function error(array $info) {
        $time = date('Y-m-d H:i:s');
        array_unshift($info, $time);
        $info = array_map('json_encode', $info);
        file_put_contents(self::LOG_PATH . 'websocket_error.log', implode(' | ', $info) . "\r\n", FILE_APPEND);
    }
}

