<?php
$_ENV['noconf'] = 1;
error_reporting(E_ALL & ~E_NOTICE & ~E_STRICT & ~E_DEPRECATED & ~E_WARNING);

// Websocket client - https://github.com/paragi/PHP-websocket-client
function websocket_open($host = '', $port = 80, $headers = '', &$error_string = '', $timeout = 10, $ssl = false, $persistant = false, $path = '/', $context = null)
{
    $key = base64_encode(openssl_random_pseudo_bytes(16));
    $header = "GET " . $path . " HTTP/1.1\r\nHost: $host\r\npragma: no-cache\r\nUpgrade: WebSocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\n";
    if (!empty($headers)) foreach ($headers as $h) $header .= $h . "\r\n";
    $header .= "\r\n";
    $host = $host ? $host : "127.0.0.1";
    $port = $port < 1 ? ($ssl ? 443 : 80) : $port;
    $address = ($ssl ? 'ssl://' : '') . $host . ':' . $port;

    $flags = STREAM_CLIENT_CONNECT | ($persistant ? STREAM_CLIENT_PERSISTENT : 0);
    $ctx = $context ?? stream_context_create();
    $sp = stream_socket_client($address, $errno, $errstr, $timeout, $flags, $ctx);

    if (!$sp or $errstr or $errno) {
        $error_string = "US: $errstr ($errno)";
        return false;
    }

    stream_set_timeout($sp, $timeout);
    if (!$persistant or ftell($sp) === 0) {
        //Request upgrade to websocket
        $rc = fwrite($sp, $header);
        if (!$rc) {
            $error_string = "Unable to send upgrade header to websocket server: $errstr ($errno)";
            return false;
        }
        // Read response into an assotiative array of headers. Fails if upgrade failes.
        $response_header = fread($sp, 1024);
        if (!trim($response_header)) {
            $error_string = 'on connect: no response';
            return false;
        }

        // status code 101 indicates that the WebSocket handshake has completed.
        if (stripos($response_header, ' 101 ') === false || stripos($response_header, 'Sec-WebSocket-Accept: ') === false) {
            $error_string = "Server did not accept to upgrade connection to websocket." . $response_header . E_USER_ERROR;
            return false;
        }
        // The key we send is returned, concatenate with "258EAFA5-E914-47DA-95CA-
        // C5AB0DC85B11" and then base64-encoded. one can verify if one feels the need...

    }
    return $sp;
}

function websocket_write($sp, $data, $final = true, $binary = true)
{
    if (is_array($data)) $data = json_encode($data, true);
    // Assemble header: FINal 0x80 | Mode (0x02 binary, 0x01 text)

    if ($binary) $header = chr(($final ? 0x80 : 0) | 0x02); // 0x02 binary mode
    else $header = chr(($final ? 0x80 : 0) | 0x01); // 0x01 text mode

    // Mask 0x80 | payload length (0-125)
    if (strlen($data) < 126) $header .= chr(0x80 | strlen($data));
    elseif (strlen($data) < 0xFFFF) $header .= chr(0x80 | 126) . pack("n", strlen($data));
    else $header .= chr(0x80 | 127) . pack("N", 0) . pack("N", strlen($data));

    // Add mask
    $mask = pack("N", rand(1, 0x7FFFFFFF));
    $header .= $mask;

    // Mask application data.
    for ($i = 0; $i < strlen($data); $i++) $data[$i] = chr(ord($data[$i]) ^ ord($mask[$i % 4]));

    return fwrite($sp, $header . $data);
}

function websocket_read($sp, &$error_string = NULL)
{
    $final = false;
    $data = '';

    while (!$final) {// Read header, even with no much workers
        $header = fread($sp, 2);
        if (!$header) {
            $error_string = 'rhf';
            return false;
        }

        $opcode = ord($header[0]) & 0x0F;
        $final = ord($header[0]) & 0x80;
        $masked = ord($header[1]) & 0x80;
        $payload_len = ord($header[1]) & 0x7F;

        // Get payload length extensions
        $ext_len = 0;
        if ($payload_len >= 0x7E) {
            $ext_len = 2;
            if ($payload_len == 0x7F) $ext_len = 8;
            $header = fread($sp, $ext_len);
            if (!$header) {
                $error_string = 'rhg';//Reading header extension from websocket failed.
                return false;
            }

            // Set extented paylod length
            $payload_len = 0;
            for ($i = 0; $i < $ext_len; $i++)
                $payload_len += ord($header[$i]) << ($ext_len - $i - 1) * 8;
        }

        // Get Mask key
        if ($masked) {
            $mask = fread($sp, 4);
            if (!$mask) {
                $error_string = "Reading header mask from websocket failed.";
                return false;
            }
        }

        // Get payload
        $frame_data = '';
        while ($payload_len > 0) {
            $frame = fread($sp, $payload_len);
            if (!$frame) {
                $error_string = "Reading from websocket failed.";
                return false;
            }
            $payload_len -= strlen($frame);
            $frame_data .= $frame;
        }

        // Handle ping requests (sort of) send pong and continue to read
        if ($opcode == 9) {
            // Assamble header: FINal 0x80 | Opcode 0x0A + Mask on 0x80 with zero payload
            fwrite($sp, chr(0x8A) . chr(0x80) . pack("N", rand(1, 0x7FFFFFFF)));
            continue;

            // Close
        } elseif ($opcode == 8) {
            echo "\nClosin:";
            fclose($sp);

            // 0 = continuation frame, 1 = text frame, 2 = binary frame
        } elseif ($opcode < 3) {
            // Unmask data
            $data_len = strlen($frame_data);
            if ($masked)
                for ($i = 0; $i < $data_len; $i++)
                    $data .= $frame_data[$i] ^ $mask[$i % 4];
            else
                $data .= $frame_data;

        } else
            continue;

    }

    return $data;
}


return; ?>
