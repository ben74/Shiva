<?php
$server = new Swoole\HTTP\Server("127.0.0.1", 9501);
$server->on('Request', function($request, $response)
{
    $response->end('<h1>Hello World! Here is a random number: ' . rand(1, 1000) . "</h1>\n");
});

$server->start();