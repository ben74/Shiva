<?php
$b = 1;
$_ENV['a'] = [];
$serv = new Swoole\Server('127.0.0.1', 2001, SWOOLE_BASE);
$serv->set(['reactor_num' => 1, 'worker_num' => 1, 'log_file' => '/dev/null']);
$serv->on('request', function ($request, $response) use ($b) {
    $a = 'nothin';
});

$serv->on('receive', function ($serv, $fd, $reactorId, $data) use ($b) {
    $a = $_ENV['a'];
    //$b = 'GET /?stop=1 HTTP/1.1   Host: 127.0.0.1:2001    User-Agent: curl/7.64.1 Accept: */* Cookie: XDEBUG_SESSION=1';$b = explode("\n", trim($data));
    $b = \Swoole\Http\Request::create();
    $b->parse($data);
    $res = 0;//avoids : empty replu from server
    if ($b->get['get']) {
        if(!isset($_ENV['a'][$b->get['get']]))$_ENV['a'][$b->get['get']]=0;
        $res = $_ENV['a'][$b->get['get']];
    } elseif ($b->get['set'] && $b->get['val']) {
        $res = $_ENV['a'][$b->get['set']] = $b->get['val'];
    } elseif ($b->get['incr']) {
        $res = $_ENV['a'][$b->get['incr']]++;
    } elseif ($b->get['decr']) {
        $res = $_ENV['a'][$b->get['decr']]--;
    }
    $serv->send($fd, $res);
    $serv->close($fd);
});

$serv->start();

?>
#pkill -9 -f 2001;phpx -S 0.0.0.0:2001 counter.php &
#pkill -9 -f counter;phpx  counter.php &
pkill -9 -f counter;php  counter.php &

curl -ks 'http://127.0.0.1:2001/?get=clients'

curl -ks 'http://127.0.0.1:2001/?set=b&value=10';curl -ks 'http://127.0.0.1:2001/?incr=b';curl -ks 'http://127.0.0.1:2001/?get=b'

curl -ks -b 'XDEBUG_SESSION=1' http://127.0.0.1:2001/hop?a=b


curl -ks -b 'XDEBUG_SESSION=1' '127.0.0.1:2001/?set=a&value=zob'
curl -ks -b 'XDEBUG_SESSION=1' '127.0.0.1:2001/?get=a'