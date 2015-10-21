<?php

$address = isset($argv[1]) ? $argv[1] : '127.0.0.1';
$port = isset($argv[2]) ? $argv[2] : 9399;
               
$socket = stream_socket_client("tcp://$address:$port", $errno, $errstr, 30);
if (!$socket) {
  echo "stream_socket_client() failure: $errstr ($errno)\n";
  die();
}

$stdin = fopen('php://stdin', 'r');

stream_set_blocking($socket, true);
stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_SSLv3_CLIENT);
stream_set_blocking($socket, false);

while (true) {
  $read = array($socket, $stdin);
  $write = null;
  $except = null;
  $num_changes = stream_select($read, $write, $except, 1);
  if ($num_changes === false) {
    echo "socket_select() failure: reason: " . socket_strerror(socket_last_error($sock)) . "\n";
    continue;
  } else if ($num_changes > 0) {
    if (in_array($socket, $read)) {
      $input = fread($socket, 2048);
      if (!$input && feof($socket)) {
        echo "Connection closed\n";
        break;
      } else {
        echo $input;
      }
    }
    if (in_array($stdin, $read)) {
      $input = fread($stdin, 2048);
      fwrite($socket, $input);
    }
  }
}

fclose($socket);  
