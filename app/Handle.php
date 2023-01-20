<?php

namespace App;

//declare(ticks=1);

use Exception;
use GatewayWorker\Lib\Gateway;
use GlobalData\Client as GlobalDataClient;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;
use Workerman\Redis\Client as RedisClient;

/**
 * 主逻辑
 * 主要是处理 onConnect onMessage onClose 三个方法
 * onConnect 和 onClose 如果不需要可以不用实现并删除
 */
class Handle
{
    public static GlobalDataClient $global;
    
    public static HttpClient $lae, $http;
    
    public static RedisClient $redis;
    
    public static array $unauthorized = [
        'code' => 401,
        'msg' => 'Unauthorized',
    ];
    
    public static array $unsupportedMethod = [
        'code' => 405,
        'msg' => 'Unsupported Method',
    ];
    
    // 信息不完整
    public static array $incomplete = [
        'code' => 400,
        'msg' => 'Incomplete',
    ];
    
    public static array $hello = [
        'code' => 200,
        'msg' => 'authed',
    ];
    
    public static array $serverError = [
        'code' => 500,
        'msg' => 'Server Error',
    ];
    
    // data format error
    public static array $dfe = [
        'code' => 400,
        'msg' => 'Data Format Error, must be json',
    ];
    
    // module not found
    public static array $mnf = [
        'code' => 404,
        'msg' => 'Module Not Found',
    ];
    
    // too many requests
    public static array $tmr = [
        'code' => 429,
        'msg' => 'Too Many Requests',
    ];
    
    
    public static function onWebSocketConnect(string $client_id, array $data)
    {
        $token = $data['get']['token'] ?? null;
        
        if (empty($token)) {
            self::disconnectCurrentClient(self::$unauthorized);
            self::console('Token 为空，断开连接。');
            return;
        }
        
        // 认证用户
        try {
            $user = self::$lae->get('token/' . $token);
        } catch (GuzzleException $e) {
            self::console($e->getMessage());
            self::disconnectCurrentClient(self::$serverError);
            
            return;
        }
        $user_response = json_decode($user->getBody()->getContents(), true);
        
        if ($user->getStatusCode() !== 200 || empty($user)) {
            self::disconnectCurrentClient(self::$unauthorized);
    
            self::console('无法验证响应。');
            self::console("Status: {$user->getStatusCode()}");
    
            return;
        }
        
        if (!isset($user_response['id'])) {
            self::disconnectCurrentClient(self::$serverError);
    
            self::console('获取不到用户 ID。');
            self::console(json_encode($user_response));
            return;
        }
        
        
        // 认证成功，绑定用户
        Gateway::bindUid($client_id, $user_response['id']);
        
        $_SESSION['user'] = $user_response;
        
        // 通知用户上线
        self::sendToCurrentClient(self::$hello);
    }
    
    
    /**
     * 当客户端发来消息时触发
     *
     * @param string $client_id 连接id
     * @param mixed  $message   具体消息
     *
     * @throws Exception
     */
    public static function onMessage(string $client_id, $message)
    {
        if (!isset($_SESSION['user'])) {
            self::console('寻找用户失败。');
            self::disconnectCurrentClient(self::$unauthorized);
            return;
        }
        
        $user = $_SESSION['user'];
        
        if (self::throttle($user['id'])) {
            self::console('请求量过大。');
            return;
        }
        
        $message = json_decode($message, true);
        
        if (empty($message)) {
            self::sendToCurrentClient(self::$incomplete);
            return;
        }
        
        // 如果 message 中缺失了 method, module, path, data, 则结束
        if (empty($message['method']) || empty($message['module_id']) || empty($message['path'] || !$message['request_id'])) {
            self::sendToCurrentClient(self::$incomplete);
            return;
        }
        
        $request_id = $message['request_id'] ?? null;
        
        if (empty($request_id)) {
            self::sendToCurrentClient(self::$incomplete);
            return;
        }
        
        
        // 全部转为小写
        $message['method'] = strtolower($message['method']);
        
        // method 只能是 GET, POST, PUT, DELETE, PATCH
        if (!in_array($message['method'], ['get', 'post', 'put', 'patch', 'delete'])) {
            self::sendToCurrentClient(self::$unsupportedMethod);
            return;
        }
        
        // if (isset($message['data'])) {
        //     // data 只能是 json 或者 array
        //     if (!is_array($message['data']) && !is_object($message['data'])) {
        //         self::sendToCurrentClient(self::$dfe);
        //         return;
        //     }
        // }
        //
        // 模块必须存在
        if (!isset(self::$global->modules[$message['module_id']])) {
            self::sendToCurrentClient(self::$mnf);
            return;
        }
        
        $module = self::$global->modules[$message['module_id']];
        
        $path = $module['url'] . '/remote/functions/' . $message['path'];
        
        $data = $message['data'] ?? [];
        $data['user_id'] = $user['id'];
        
        
        $headers = [
            'X-Module-Api-Token' => $module['api_token'],
            'X-User-Id' => $user['id']
        ];
        
        // self::appendToQueue($request_id, $client_id, $message['method'], $message['path'], $data, $headers);
        
        self::sendToCurrentClient([
            'code' => 201,
            'request_id' => $request_id,
        ]);
        
        // 开始 guzzle 异步请求
        $promise = self::$http->requestAsync($message['method'], $path, [
            'json' => $data,
            'headers' => $headers,
        ]);
        
        
        $promise->then(
            function (ResponseInterface $res) use ($request_id) {
                // 发送响应
                self::sendToCurrentClient([
                    'code' => $res->getStatusCode(),
                    'data' => json_decode($res->getBody()->getContents()),
                    'request_id' => $request_id
                ]);
                
            },
            function (RequestException $e) use ($request_id) {
                $resp = $e->getResponse();

                self::sendToCurrentClient([
                    'code' => $resp->getStatusCode(),
                    'data' => json_decode($resp->getBody()->getContents()),
                    'request_id' => $request_id
                ]);
            }
        );
        
        try {
            $promise->wait();
        } catch (Exception $e) {
        }
        
        
        // 限流 -1
        self::$redis->decr('throttle:' . $user['id']);
    }
    
    /**
     * 当用户断开连接时触发
     *
     * @param string $client_id 连接id
     *
     * @throws Exception
     */
    public static function onClose(string $client_id)
    {
        // 向所有人发送
        // GateWay::sendToAll("$client_id logout\r\n");
    }
    
    public static function sendToClient(string $client_id, array $message)
    {
        Gateway::sendToClient($client_id, json_encode($message));
    }
    
    public static function sendToUser(string $user_id, array $message)
    {
        Gateway::sendToUid($user_id, json_encode($message));
    }
    
    public static function sendToCurrentClient(array $message)
    {
        Gateway::sendToCurrentClient(json_encode($message));
    }
    
    public static function disconnectCurrentClient(array $message)
    {
        Gateway::sendToCurrentClient(json_encode($message));
        try {
            Gateway::closeCurrentClient();
        } catch (Exception $e) {
            self::console($e->getMessage());
        }
    }
    
    public static function console(string $message)
    {
        echo date('Y-m-d H:i:s') . ' ' . $message . PHP_EOL;
    }
    
    public static function requestId(): string
    {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            // 32 bits for "time_low"
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            
            // 16 bits for "time_mid"
            mt_rand(0, 0xffff),
            
            // 16 bits for "time_hi_and_version",
            // four most significant bits holds version number 4
            mt_rand(0, 0x0fff) | 0x4000,
            
            // 16 bits, 8 bits for "clk_seq_hi_res",
            // 8 bits for "clk_seq_low",
            // two most significant bits holds zero and one for variant DCE1.1
            mt_rand(0, 0x3fff) | 0x8000,
            
            // 48 bits for "node"
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
    
    public static function throttle(int $user_id): bool
    {
        $key = 'throttle:' . $user_id;
        $redis = self::$redis;
        
        if ($redis->get($key) > 20) {
            return true;
        } else {
            $redis->incr($key);
            $redis->expire($key, 60);
            return false;
        }
    }
    
    // public static function appendToQueue($request_id, $client_id, $method, $path, $data, $headers): bool
    // {
    //     $key = 'queue:' . $client_id;
    //
    //     self::$redis->lpush($key, [
    //         'request_id' => $request_id,
    //         'method' => $method,
    //         'path' => $path,
    //         'data' => $data,
    //         'headers' => $headers
    //     ]);
    // }
}
