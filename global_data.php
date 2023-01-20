<?php

use Workerman\Worker;
require_once __DIR__ . '/vendor/autoload.php';

// 监听端口
$worker = new GlobalData\Server('127.0.0.1', 2207);

Worker::runAll();