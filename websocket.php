<?php
/**
 * @Author: Aaron
 *
 */

error_reporting(E_ALL);

class Websocket
{
    protected $_port = null;

    protected $_addr = null;

    protected $_sockets = [];

    protected $_server = null;

    public function __construct(array $serverConf)
    {
        $this->_port = $serverConf['port'];
        $this->_addr = $serverConf['addr'];
    }

    /**
     * 开始监听
     *
     * @return void
     */
    public function start()
    {
        $this->listen();
    }

    /**
     * 监听套接字
     *
     * @return void
     */
    private function listen()
    {
        try {
            $server = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            // 绑定套接字到端口上
            socket_bind($server, $this->_addr, $this->_port);
            // var_dump($this->_addr,$this->_port);die;
            // 开始监听
            $isSuccess = socket_listen($server);
            $this->_server = $server;
            $this->_sockets[(int)$server] = ['isHandshake' => false, 'socket' => $server , 'ip' => '' , 'port' => 0];
            do {
                $this->accept();
            } while (true);
        } catch (\Exception $e) {
            $errorNo = socket_last_error($server);
            $errStr = socket_strerror($errorNo);
            self::errorOutput($errStr);
        }
    }

    /**
     * 处理请求
     *
     * @return void
     */
    private function accept()
    {
        $sockets = array_column($this->_sockets, 'socket');
        $write = $except = null;
        $read = socket_select($sockets, $write, $except, null);
        if ($read === false) {
            $this->errorOutput(socket_strerror(socket_last_error()));
        } else {
            if ($read > 0) {
                foreach ($sockets as $socket) {
                    if ($socket === $this->_server) {
                        // 如果是server socket可读，说明是有请求连接
                        $client = socket_accept($this->_server);
                        $this->requestConnect($client);
                    } else {
                        // 如果该套接字是未连接状态 则走连接逻辑
                        $buffer = $this->readBuffer($socket);
                        if (strlen($buffer) < 9) {
                            // 如果小于9字节是请求断开连接
                            echo "断开连接";
                            $this->disconnect($socket);
                        } else {
                            if ($this->_sockets[(int)$socket]['isHandshake'] === false) {
                                var_dump($buffer);
                                $this->requestHandShake($socket, $buffer);
                            } else {
                                // 完成握手的socket 解析数据包
                                echo "完成握手发来消息:" . $this->parseDataPackage($buffer);
                            }
                        }
                    }
                }
            } else {
                $this->errorOutput('select return read is error');
            }
        }
    }

    /**
     * 在socket中读取buffer
     *
     * @return string 从客户端读取发过来的数据
     */
    private function readBuffer($socket)
    {
        return socket_read($socket, 2048);
    }

    /**
     * 请求连接
     *
     * @return void
     */
    private function requestConnect($socket)
    {
        $this->_sockets[(int)$socket] = ['isHandshake' => false, 'socket' => $socket , 'ip' => '' , 'port' => 0];
    }

    /**
     * 请求断开连接
     *
     * @return void
     */
    private function disconnect($socket)
    {
        unset($this->_sockets[(int)$socket]);
    }

    /**
     * 请求握手
     * @return bool 成功 或 失败
     */
    private function requestHandShake($socket, $buffer)
    {
        if (($wsKeyIndex = strpos($buffer, 'Sec-WebSocket-Key:')) === false) {
            $this->_sockets[(int)$socket]['isHandshake'] = false;
            return false;
        }
        $wsKey = substr($buffer, $wsKeyIndex + 18);
        $key = trim(substr($wsKey, 0, strpos($wsKey, "\r\n")));
        // 接收到传来的key之后， 使用特定的公式 生成accept key 回传给客户端
        // 258EAFA5-E914-47DA-95CA-C5AB0DC85B11 这个加密key websocket协议订好的一个值
        $upgradeKey = base64_encode(sha1($key . "258EAFA5-E914-47DA-95CA-C5AB0DC85B11", true));
        // 拼接回复字符串
        $upgradeMsg = '';
        $upgradeMsg = "HTTP/1.1 101 Switching Protocols\r\n";
        $upgradeMsg .= "Upgrade: websocket\r\n";
        $upgradeMsg .= "Sec-WebSocket-Version: 13\r\n";
        $upgradeMsg .= "Connection: Upgrade\r\n";
        $upgradeMsg .= "Sec-WebSocket-Accept:" . $upgradeKey . "\r\n\r\n";
        socket_write($socket, $upgradeMsg, strlen($upgradeMsg));
        $this->_sockets[(int)$socket]['isHandshake'] = true;
        return true;
    }

    /**
     * 解析数据包
     *
     * @return string
     */
    private function parseDataPackage($buffer)
    {
        $decode = "";
        $len = ord($buffer) & 127;
        if ($len === 126) {
            $masks = substr($buffer, 4, 4);
            $data = substr($buffer, 8);
        } elseif ($len === 127) {
            $masks = substr($buffer, 10, 4);
            $data = substr($buffer, 14);
        } else {
            $masks = substr($buffer, 2, 4);
            $data = substr($buffer, 6);
        }
        for ($index=0; $index < strlen($data); $index++) {
            $decode .= $data[$index] ^ $masks[$index % 4];
        }
        return $decode;
        return json_decode($decode, true);
    }


    /**
     * 错误信息输出方法
     *
     * @return string
     */
    private static function errorOutput($errStr)
    {
        print $errStr;
    }
}
$config = [
    'addr' => '0.0.0.0',
    'port' => 10005
];
$ws = new Websocket($config);

$ws->start();
