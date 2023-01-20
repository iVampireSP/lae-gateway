<?php

declare(strict_types=1);

use App\Handle;
use Dotenv\Dotenv;
use GatewayWorker\BusinessWorker;
use GlobalData\Client as GlobalDataClient;
use GuzzleHttp\Client as GuzzleHttpClient;
use GuzzleHttp\Exception\GuzzleException;
use Workerman\Crontab\Crontab;
use Workerman\Redis\Client as RedisClient;
use Workerman\Worker;

ini_set('display_errors', 'on');
date_default_timezone_set('PRC');

/**
 * 加载 .env
 */

Dotenv::createImmutable(__DIR__)->load();

$_ENV['APP_DEBUG'] && ini_set('display_errors', 'on');

/*
 * 启动 BusinessWorker 进程
*/

Worker::$logFile = __DIR__ . '/worker.log';
Worker::$pidFile = __DIR__ . '/worker.pid';


$worker = (new BusinessWorker);

// get hostname
$hostname = gethostname();

$worker->name = 'BW-' . $hostname . '-' . uniqid();

$worker->count = 4;

$worker->registerAddress = '127.0.0.1:1238';
$worker->eventHandler = Handle::class;


$global = new GlobalDataClient('127.0.0.1:2207');

$api_token = $_ENV['API_TOKEN'];
$api_url = $_ENV['API_URL'];

$global->api_token = $api_token;

$lae = new GuzzleHttpClient([
    'base_uri' => $api_url,
    'timeout' => 10.0,
    'headers' => [
        'Authorization' => 'Bearer ' . $api_token,
        'User-Agent' => 'LAE-Gateway',
        'accept' => 'application/json',
    ],
]);

global $global, $lae;


$worker->onWorkerStart = function (Worker $worker) use ($global, $lae) {
    Handle::$global = $global;
    Handle::$lae = $lae;
    Handle::$http = new GuzzleHttpClient([
        'timeout' => 10.0,
        'headers' => [
            'User-Agent' => 'LAE-Gateway',
            'accept' => 'application/json',
        ],
    ]);
    
    Handle::$redis = new RedisClient('redis://' . $_ENV['REDIS_HOST'] . ':' . $_ENV['REDIS_PORT'], [
        'password' => $_ENV['REDIS_PASSWORD'],
    ]);
    
    // Crontab
    if ($worker->id === 0) {
        new Crontab('1 * * * * *', function () {
            refreshModules();
        });
    }
    
};


function refreshModules()
{
    global $lae, $global;
    
    try {
        $modules = $lae->get('modules')->getBody()->getContents();
    } catch (GuzzleException $e) {
        Handle::console($e->getMessage());
        return;
    }
    
    $response = json_decode($modules, true);
    
    $modules = [];
    
    if ($response) {
        foreach ($response as $module) {
            $modules[$module['id']] = $module;
        }
        
        $global->modules = $modules;
    }

}

refreshModules();