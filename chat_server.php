<?php

$address = isset($argv[1]) ? $argv[1] : '127.0.0.1';
$port = isset($argv[2]) ? $argv[2] : 9399;

$pem_passphrase = "mysecrete";
$pem_file = "ssl.pem";

if (!file_exists($pem_file)) {
  $pem_dn = array(
      "countryName" => "US",
      "stateOrProvinceName" => "California",
      "localityName" => "San Jose",
      "organizationName" => "Chat Inc.",
      "organizationalUnitName" => "Engineering",
      "commonName" => "localhost",
      "emailAddress" => "email@example.com"
  );
  
  echo "Creating SSL Cert\n";
  create_ssl_cert($pem_file, $pem_passphrase, $pem_dn);
}

$sock = setup_server($pem_file, $pem_passphrase, $address, $port);
if ($sock === false) {
  die();
}
echo "Chat server is ready on $address:$port\n";

$manager = Manager::instance();

while(!$manager->shut_down){
  $read = $manager->get_read_sockets();
  $read[] = $sock;
  
  $write = null;
  $except = null;
  $num_changes = stream_select($read, $write, $except, 1);
  if ($num_changes === false) {
    echo "stream_select() failure\n";
    continue;
  } else if ($num_changes > 0) {
    foreach ($read as $idx => $socket) {
      if ($socket == $sock) {
        $peername = null;
        if (($new_clicent = stream_socket_accept($sock, null, $peername)) === false) {
          echo "stream_socket_accept() failure\n";
          break;
        }
        stream_set_blocking($new_clicent, true);
        stream_socket_enable_crypto($new_clicent, true, STREAM_CRYPTO_METHOD_SSLv3_SERVER);
        stream_set_blocking($new_clicent, false);
        $manager->add_client($new_clicent, $peername);
        echo "New client from $peername\n";
        unset($read[$idx]);
      }
    }
    $manager->handle_inputs($read);
  }
}

fclose($sock);

function create_ssl_cert($pem_file, $pem_passphrase, $pem_dn) {
  $privkey = openssl_pkey_new();
  
  $cert = openssl_csr_new($pem_dn, $privkey);
  $cert = openssl_csr_sign($cert, null, $privkey, 365);
  
  $pem = array();
  openssl_x509_export($cert, $pem[0]);
  openssl_pkey_export($privkey, $pem[1], $pem_passphrase);
  $pem = implode($pem);
  
  file_put_contents($pem_file, $pem);
  chmod($pem_file, 0600);
}

function setup_server($pem_file, $pem_passphrase, $ip, $port) {
  $context = stream_context_create();
  
  stream_context_set_option($context, 'ssl', 'local_cert', $pem_file); // Our SSL Cert in PEM format
  stream_context_set_option($context, 'ssl', 'passphrase', $pem_passphrase); // Private key Password
  stream_context_set_option($context, 'ssl', 'allow_self_signed', true);
  stream_context_set_option($context, 'ssl', 'verify_peer', false);
  
  $socket = stream_socket_server("tcp://$ip:$port", $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
  if ($socket === false){
    echo "stream_socket_server() failure: $errstr ($errno)\n";
    return false;
  }
  stream_socket_enable_crypto($socket, false);
  
  return $socket;
}

class Manager {
  const INACTIVE_TIME_OUT = 10;
  
  public $connected_clients;
  public $login_clients;
  public $rooms;
  public $shut_down;
  
  protected function __construct() {
    $this->connected_clients = array();
    $this->login_clients = array();
    $this->rooms = array();
    $this->shut_down = false;
  }
  
  private static $instance;
  
  public static function instance() {
    if (!self::$instance) {
      self::$instance = new Manager();
    }
    return self::$instance;
  }
  
  public function add_client($socket, $peername) {
    $this->connected_clients[intval($socket)] = new Client($socket, $peername);
  }
  
  public function get_read_sockets() {
    $read = array();
    foreach ($this->connected_clients as $idx => $client){
      if (!$client->name && time() - $client->active_ts > self::INACTIVE_TIME_OUT) {
        $client->newline();
        $client->respond("Inactive timeout");
        $client->disconnect();
        unset($this->connected_clients[$idx]);
      } else {
        $read[] = $client->socket;
      }
    }
    return $read;
  }
  
  public function handle_inputs($read) {
    foreach ($read as $socket) {
      $rid = intval($socket);
      if (!isset($this->connected_clients[$rid])) {
        continue;
      }
      $client = $this->connected_clients[$rid];
      $client->handle_input();
    }
  }
  
  public function shut_down() {
    foreach ($this->connected_clients as $client){
      $client->disconnect();
    }
    foreach ($this->login_clients as $client){
      $client->disconnect();
    }
    $this->shut_down = true;
  }
  
  public function login($client, $name) {
    if (isset($this->login_clients[$name])) {
      $client->respond('Sorry, name taken.');
      $client->respond('Login name?');
      $client->prompt();
      return false;
    }
    $client->respond("Welcome $name!");
    $client->prompt();
    $client->name = $name;
    $this->login_clients[$name] = $client;
  }
  
  public function quit($client) {
    if ($client->room_id) {
      $this->rooms[$client->room_id]->leave($client);
    }
    $client->disconnect();
    unset($this->login_clients[$client->name]);
    unset($this->connected_clients[intval($client->socket)]);
  }
  
  public function create_room($client, $name) {
    if (isset($this->rooms[$name])) {
      $client->respond('Sorry, the room has been created, please choose another name.');
      $client->prompt();
      return;
    }
    $this->rooms[$name] = new Room($client->name, $name);
    $client->respond("Room $name created.");
    $client->prompt();
  }
  
  public function remove_room($client, $name) {
    if (!isset($this->rooms[$name])) {
      $client->respond("Sorry, no room with name '$name' found.");
      $client->prompt();
      return;
    }
    $room = $this->rooms[$name];
    if ($room->creator !== $client->name) {
      $client->respond("Sorry, only room creator can remove the room.");
      $client->prompt();
      return;
    }
    foreach ($room->clients as $client) {
      $client->room_id = '';
    }
    unset($this->rooms[$name]);
    $client->respond("Room $name removed.");
    $client->prompt();
  }
  
  public function list_rooms($client) {
    $client->respond('Active rooms are:');
    foreach ($this->rooms as $room) {
      $client->respond('*'.$room->name.'('.count($room->clients).')');
    }
    $client->respond('end of list.');
    $client->prompt();
  }
  
  public function join_room($client, $name) {
    if ($client->room_id === $name) {
      return;
    }
    if (!isset($this->rooms[$name])) {
      $client->respond("Sorry, no room with name '$name' found.");
      return;
    }
    if ($client->room_id) {
      $this->rooms[$client->room_id]->leave($client);
    }
    $this->rooms[$name]->join($client);
  }
  
  public function leave_room($client) {
    if (!$client->room_id) {
      $client->respond('You are not in any room.');
      $client->prompt();
      return;
    }
    $this->rooms[$client->room_id]->leave($client);
  }
  
  public function chat($client, $msg) {
    if (!$client->room_id) {
      return;
    }
    $this->rooms[$client->room_id]->chat($client, $msg);
  }
  
  public function whisper($client, $to, $msg) {
    if ($client->name === $to) {
      $client->respond("You are whispering to yourself.");
      $client->prompt();
      return;
    }
    if (!isset($this->login_clients[$to])) {
      $client->respond("User '$to' not found.");
      $client->prompt();
      return;
    }
    $client->prompt();
    $to_client = $this->login_clients[$to];
    $to_client->newline();
    $to_client->respond('(whisper)'.$client->name.': '.$msg);
    $to_client->prompt();
  }
}

class Room {
  public $creator;
  public $clients;
  public $name;
  
  public function __construct($creator, $name) {
    $this->clients = array();
    $this->creator = $creator;
    $this->name = $name;
  }
  
  public function join($client) {
    $this->clients[$client->name] = $client;
    $client->room_id = $this->name;
    $this->broadcast($client, '* user has joined chat: '.$client->name);
    $client->respond('entering room: '.$this->name);
    foreach ($this->clients as $name => $room_client) {
      $client->respond('*'.$name.($room_client === $client ? '(**this is you)' : ''));
    }
    $client->respond('end of list');
    $client->prompt();
  }
  
  public function leave($client) {
    unset($this->clients[$client->name]);
    $client->room_id = '';
    $this->broadcast(null, '* user has left chat: '.$client->name);
    $client->respond('leaving room: '.$this->name);
    $client->prompt();
  }
  
  public function chat($client, $msg) {
    $this->broadcast($client, $client->name.': '.$msg);
  }
  
  private function broadcast($client, $msg) {
    foreach ($this->clients as $room_client) {
      if ($room_client === $client) {
        continue;
      }
      $room_client->newline();
      $room_client->respond($msg);
      $room_client->prompt();
    }
  }
}

class Client {
  public $peername;
  public $socket;
  public $closed = false;
  public $name = '';
  public $room_id = '';
  public $active_ts;

  public function __construct($socket, $peername) {
    $this->peername = $peername;
    $this->socket = $socket;
    $this->respond('Welcome to the SIM chat server');
    $this->respond('Login Name?');
    $this->prompt();
    $this->active_ts = time();
  }

  public function handle_input() {
    $this->active_ts = time();
    $input = $this->read();
    if (!$input) {
      $this->prompt();
      return;
    }
    if ($input[0] == '/') {
      $this->handle_cmd(substr($input,1));
    } else {
      $this->handle_msg($input);
    }
  }
  
  private function handle_cmd($cmd) {
    if (!$this->name) {
      $this->respond('Please login first');
      $this->respond('Login Name?');
      $this->prompt();
      return;
    }
    $params = preg_split("/[\s,]+/", $cmd);
    switch (strtolower($params[0])) {
    	case 'rooms':
    	  Manager::instance()->list_rooms($this);
    	  break;
    	case 'create':
    	  if (!isset($params[1])) {
    	    $this->respond('Please give a room name.');
    	    $this->prompt();
    	  } else {
    	    Manager::instance()->create_room($this, $params[1]);
    	  }
    	  break;
    	case 'remove':
    	  if (!isset($params[1])) {
    	    $this->respond('Please give a room name.');
    	    $this->prompt();
    	  } else {
    	    Manager::instance()->remove_room($this, $params[1]);
    	  }
    	  break;
    	case 'join':
    	  if (!isset($params[1])) {
    	    $this->respond('Please give a room name.');
    	    $this->prompt();
    	  } else {
    	    Manager::instance()->join_room($this, $params[1]);
    	  }
    	  break;
    	case 'leave':
    	  Manager::instance()->leave_room($this);
    	  break;
    	case 'whisper':
    	  if (!isset($params[1])) {
    	    $this->respond('Please give a user name.');
    	    $this->prompt();
    	  } else if (!isset($params[2])) {
    	    $this->respond('Please write a message.');
    	    $this->prompt();
    	  } else {
    	    Manager::instance()->whisper($this, $params[1], $params[2]);
    	  }
    	  break;
    	case 'whoami':
  	    $this->respond('You are '.$this->name.' from '.$this->peername.'.');
  	    $this->prompt();
    	  break;
    	case 'whereami':
    	  if ($this->room_id) {
    	    $this->respond('You are in room '.$this->room_id.'.');
    	    $this->prompt();
    	  } else {
    	    $this->respond('You are in room lobby.');
    	    $this->prompt();
    	  }
    	  break;
    	case 'quit':
    	  $this->respond('BYE');
    	  Manager::instance()->quit($this);
    	  break;
    	case 'help':
    	  $this->respond('Available commands:');
    	  $this->respond('*/help - this message.');
    	  $this->respond('*/create :room: - create a chat room.');
    	  $this->respond('*/remove :room: - remove a chat room, you must be the creator.');
    	  $this->respond('*/join :room: - join a chat room.');
    	  $this->respond('*/leave - leave current chat room.');
    	  $this->respond('*/whomi - tell you who you are.');
    	  $this->respond('*/whereami - tell you where you are.');
    	  $this->respond('*/whisper :to: :msg: - send a private message to another user.');
    	  $this->respond('*/quit - quit chat.');
    	  $this->respond('end of list.');
    	  $this->prompt();
    	  break;
    	default:
    	  $this->respond('Unknown command, type /help for availble commands.');
    	  $this->prompt();
    }
  }

  private function handle_msg($msg) {
    if (!$this->name) {
      $params = preg_split("/\s+/", $msg);
      Manager::instance()->login($this, $params[0]);
    } else if (!$this->room_id) {
      $this->respond('You must be in a room to chat.');
      $this->prompt();
    } else {
      Manager::instance()->chat($this, $msg);
      $this->prompt();
    }
  }

  public function send($msg) {
    @fwrite($this->socket, $msg);
  }
  
  public function respond($msg) {
    $this->send('<='.$msg."\n");
  }
  
  public function prompt() {
    $this->send('=>');
  }
  

  public function newline() {
    $this->send("\n");
  }
  
  public function read() {
    $input = @fread($this->socket, 2048);
    if (!$input) {
      Manager::instance()->quit($this);
      return '';
    }
    return trim($input);
  }

  public function disconnect() {
    $this->closed = true;
    @stream_socket_shutdown($this->socket);
    fclose($this->socket);
    echo "Client ".$this->name.'@'.$this->peername." disconnected.\n";
  }
}
