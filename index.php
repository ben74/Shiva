<?php /*
 http://127.0.0.1:82/
 */ ?>
<script>
    var inc = 0
        , john = new WebSocket('ws://<?php echo preg_replace('@:[0-9]+@is', '', $_SERVER['HTTP_HOST'])?>:<?php echo $_ENV['port'];?>)
        , alice = new WebSocket('ws://<?php echo preg_replace('@:[0-9]+@is', '', $_SERVER['HTTP_HOST'])?>:<?php echo $_ENV['port'];?>');
    john.onopen = function (e) {
        console.log("Connection established!");
    };
    alice.onopen = function (e) {
        console.log("Connection established!");
    };
    john.onmessage = function (e) {
        dq('johnr').value = e.data;
        console.warn(e.data);
    };
    alice.onmessage = function (e) {
        dq('alicer').value = e.data;
        console.warn(e.data);
    };

    function dq(x) {
        return document.getElementById(x);
    }

    function submited() {
        var q = dq('q').value, m = dq('m').value;
        inc++;
        dq('m').value = inc;
        m='{"push":"' + q + '","message":"' + m + '"}';
        console.log('john',m);
        john.send(m);
        return false;
    }

    function sus(who,x) {
        m='{"suscribe":"' + dq(x).value + '"}';
        console.log(who,m);
        who.send(m);
    }function free(who) {
        m='{"status":"free"}';
        console.log(who,m);
        who.send(m);
    }function cons(who) {
        m='{"consume":1}';
        console.log(who,m);
        who.send(m);
    }function getQueues(who) {
        m='{"get":"queues"}';
        console.log(who,m);
        who.send(m);
    }function getParticipants(who) {
        m='{"get":"participants"}';
        console.log(who,m);
        who.send(m);
    }function getPeople(who) {
        m='{"get":"people"}';
        console.log(who,m);
        who.send(m);
    }function getKeys(who) {
        m='{"get":"keys"}';
        console.log(who,m);
        who.send(m);
    }function rk(who,x) {
        m='{"rk":"'+dq(x).value+'"}';
        console.log(who,m);
        who.send(m);
    }

    function iam(who,x) { m='{"iam":"'+dq(x).value+'"}'; console.log(who,m); who.send(m); }
    function sendto(who,x,y) { m='{"sendto":"'+dq(x).value+'","message":"'+dq(y).value+'"}'; console.log(who,m); who.send(m); }
    function broad(who,x,y) { m='{"broadcast":"'+dq(x).value+'","message":"'+dq(y).value+'"}'; console.log(who,m); who.send(m); }
    function custom(who,x) { m=dq(x).value;console.log(who,m); who.send(m); }
</script>

<table>
    <tr>
        <td>
            <fieldset>
                <Legend>John</Legend>
                <form onsubmit="return submited();return false;">
                    queue:<input name="q" value="q1" id="q">
                    message:<input name="message" value="q1" id="m">
                    <input type="submit" accesskey="s" value="publish">
                </form>

                <button onclick="iam(john,'j:iam')">Name </button> <input value="john" id="j:iam"><br>
                <button onclick="sus(john,'j:topic')">Suscribe to </button> <input value="q1" id="j:topic"><br>
                <button onclick="free(john)">SetFree</button>
                <button onclick="cons(john)">Consume</button>
                <button onclick="getQueues(john)">get queues</button>
                <button onclick="getPeople(john)">get people</button>

                <button>BroadCast</button>
                <button>getQueue with limit</button>
                <button>clearQueue</button>
                <button>setQueue with elements</button>
                <button>sendto message</button>
                <br>
                <textarea id="johnr"></textarea>

            </fieldset>
        </td>
        <td>
            <fieldset>
                <Legend>Alice</Legend>


                <button onclick="iam(alice,'a:iam')">Name </button> <input value="alice" id="a:iam"><br>
                <button onclick="sendto(alice,'a:to','a:msg')">sendto</button> <input value="john" id="a:to"> m: <input value="hifromalice" id="a:msg"><br>
                <button onclick="broad(alice,'a:broad','a:msg2')">broad</button> <input value="q1" id="a:broad"> m: <input value="hifromalice" id="a:msg2"><br>
                <button onclick="custom(alice,'a:custom')">Custom</button> <input value='{"broad":"q1","msg":"plop"}' id="a:custom"><br>

                <button onclick="sus(alice,'a:topic')">Suscribe to</button> <input value="q1" id="a:topic"><br>
                <button onclick="free(alice)">SetFree</button>
                <button onclick="cons(alice)">Consume</button>
                <button onclick="getKeys(alice)">get keys</button>
                <button onclick="getQueues(alice)">get queues</button>
                <button onclick="getParticipants(alice)">get Patrt</button>
                <button onclick="getPeople(alice)">get people</button><br>
                <input id="ark"><button onclick="rk(alice,'ark')">Ark</button>

<br>
                <textarea id="alicer"></textarea>

        </td>
    </tr>
</table>
<style>
textarea {
    width: 100%;height:50vh;
}table,td{vertical-align:top}
 html{font-size:10px;background:#000;color:#0F0;}body{font-size:1.4rem;}
button,label{cursor:pointer;transition:0.5s filter,transform ease-in-out;}
button:hover{filter: blur(0.5px) hue-rotate(180deg) invert(1);transform: rotate(180deg);}
input,button,textarea{background:#111;color:#0F0;}
</style>

<?php return; ?>
A:
conn.send('{"subscribe":"q1"}');


conn.send('{"status":"free"}');


conn.send('{"unsubscribe":"q1"}');
B:
conn.send('{"push":"q1","message":"q1"}');
conn.send('{"push":"q1","message":"q2"}');
conn.send('{"push":"q1","message":"q3"}');

conn.send('{"queueCount":"q1"}');
conn.send('{"sub":"q1"}');
conn.send('{"list":"free"}');


,
{"unsubscribe":"q1"}


ShivaMq
Motivation
- Original idea came out in 2013 on a project where the "Technical Expert" was billed 12000e for 3 days setting up a ActiveMQ in a project .. with some bad configuration .. huge messages and too much connections constantly crashes the MQ, being not efficient then
- Back then I've replaced ActiveMQ with a combo of Redis and Php in order to handle messages, redis for the tiny ones, php especially for the large ones ( payloads around 100Mo ), and the Idea came back on my mind while visiting a temple in India, when a ceremony occured ..
------
Docker run it ( alpine, swoole, redis, php8 ) listening on port 2000, php -S :80 index.php avec point de montage ENV['mountpoint'] pour dump.rdb et ( queues/192.uuid.message et sendto/alice.messages )
While low memory ==> put all pending messages to disk  :: $q/microtime(1).'-'.uniqid() and rIncr('pd:'.$q,1)
rDecr while being consumed
-----
Grignotage des queues via rPop si ces dernières deviennent longues avec peu de mémoire disponible