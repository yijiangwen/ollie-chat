<?php
namespace App\Services\WebSocket;

use App\Services\WebSocket\SocketIO\Packet;
use Hhxsv5\LaravelS\Swoole\WebSocketHandlerInterface;
use Swoole\Http\Request;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;

class WebSocketHandler implements WebSocketHandlerInterface
{
    /**
     * @var WebSocket
     */
    protected $websocket;
    /**
     * @var Parser
     */
    protected $parser;

    public function __construct()
    {
        //App\Services\WebSocket\WebSocket
        $this->websocket = app('swoole.websocket');
        //App\Services\WebSocket\SocketIO\SocketIOParser
        $this->parser = app('swoole.parser');
    }

    // 连接建立时触发
    public function onOpen(Server $server, Request $request)
    {
        // 如果未建立连接，先建立连接
        if (!request()->input('sid')) {
            // 初始化连接信息 socket.io-client
            $payload = json_encode([
                'sid' => base64_encode(uniqid()),
                'upgrades' => [],
                'pingInterval' => config('laravels.swoole.heartbeat_idle_time') * 1000,
                'pingTimeout' => config('laravels.swoole.heartbeat_check_interval') * 1000,
            ]);
            $initPayload = Packet::OPEN . $payload;
            $connectPayload = Packet::MESSAGE . Packet::CONNECT;
            //返回这种形式0{"sid":"NjAwOGU5YzgzZTc4MA==","upgrades":[],"pingInterval":600000,"pingTimeout":60000}
            $server->push($request->fd, $initPayload);
            //返回40
            $server->push($request->fd, $connectPayload);
        }
        if ($this->websocket->eventExists('connect')) {
            //返回42["connect","欢迎访问聊天室"]
            $this->websocket->call('connect', $request);
        }
    }

    // 收到消息时触发
    public function onMessage(Server $server, Frame $frame)
    {
        // $frame->fd 是客户端 id，$frame->data 是客户端发送的数据
        if ($this->parser->execute($server, $frame)) {
            return;
        }
        $payload = $this->parser->decode($frame);
        ['event' => $event, 'data' => $data] = $payload;
        //重置数据并设置发送fd
        $this->websocket->reset(true)->setSender($frame->fd);
        if ($this->websocket->eventExists($event)) {
            $this->websocket->call($event, $data);
        } else {
            // 兜底处理，一般不会执行到这里
            return;
        }
    }

    // 连接关闭时触发
    public function onClose(Server $server, $fd, $reactorId)
    {
        $this->websocket->setSender($fd);
        if ($this->websocket->eventExists('disconnect')) {
            $this->websocket->call('disconnect', '连接关闭');
        }
    }
}
