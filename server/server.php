<?php

// [TODO] Implement config file

//require_once('config.php')

define('SOCKET_ADDRESS', '0.0.0.0');
if(!defined('SOCKET_ADDRESS')) {
	define('SOCKET_ADDRESS', '127.0.0.1');
}
if(!defined('SOCKET_PORT')) {
	define('SOCKET_PORT', '6667');
}

if(!defined('MAX_CLIENTS')) {
	define('MAX_CLIENTS', '10');
}
set_time_limit(0);

class IrcSocketServer {
	
	private $socket;
	
	private $clients = array();
	
	private $servers = array();
	
	private $sockets = array();
	
	private $client_connections = 0;
	
	private $server_connections = 0;
	
	private $debug = true;
	
	public function __construct() {
		// $this->socket is the local server socket. $this->sockets[1] is the IRC client socket
		$this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1);
		socket_bind($this->socket, SOCKET_ADDRESS, SOCKET_PORT) or die('Could not bind to address ' . SOCKET_ADDRESS . ' on port ' . SOCKET_PORT . "!\n");
		socket_listen($this->socket, MAX_CLIENTS) or die ("Could not setup socket listener!\n");
		$this->clients[0] = array('socket' => $this->socket);
	}
	
	public function listen() {
		$this->sockets['clients'] = array();
		$this->sockets['servers'] = array();
		$time = time();
		$format = "m-d-Y H:i:s a";
	   	if($this->debug) {
	   		$debug = true;
	   	}
	   	$heartbeat = true;
		printf('Starting up... %s%s', date($format, $time), "\n");
		while(true) {
			$this->client_connections = count($this->clients);
			$this->server_connections = count($this->servers);
			if($heartbeat) {
				$time = time();
				printf('Time: %s%s', date($format, $time), "\n");
				printf('%d clients connected; %d servers connected%s', $this->client_connections - 1, $this->server_connections, "\n");
				// Ping
				for($i = 1; $i < $this->client_connections; $i++) {
					$ping = sprintf('PING: %d', $time);
					$this->clients[$i]['ping'] = $time;
//					$this->_write($i, $ping);
				}
				$heartbeat = false;
			}
			if(time() - $time >= 15) {
				$heartbeat = true;
			}
			
			// Handle clients
			for($i = 0; $i < $this->client_connections; $i++) {
				if(isset($this->clients[$i]['socket'])) {
					if($debug) {
						printf('Setting read[\'clients\'][%d] to clients[%d][\'socket\']%s', $i, $i, "\n");
					}
					$this->sockets['clients'][$i] = $this->clients[$i]['socket'];
				}
			}
			
			// Handle servers
			for($i = 1; $i < $this->server_connections + 1; $i++) {
				if(isset($this->servers[$i]['socket'])) {
					if($debug) {
						printf('Setting read[\'servers\'][%d] to servers[%d][\'socket\']%s', $i, $i, "\n");
					}
					$this->sockets['servers'][$i] = $this->servers[$i]['socket'];
				}
			}
			
			// Any socket changes?
			// $write and $except are only placeholders
			$changed_clients = 0;
			$changed_servers = 0;
			$changed_clients = socket_select($this->sockets['clients'], $write = NULL, $except = NULL, 0);
			
			// Only try to detect server changes if there are any
			if($this->server_connections > 0) {
				$changed_servers = socket_select($this->sockets['servers'], $write = NULL, $except = NULL, 0);
			}
			if($debug){
				printf('%d changed clients; %d changed servers%s', $changed_clients, $changed_servers, "\n");
			}
			
			// Handle new connections
			if(in_array($this->socket, $this->sockets['clients'])) {
				// increase MAX_CLIENTS by one..we don't count $client[0]
				for($i = 1; $i < MAX_CLIENTS + 1; $i++) {
					if(!isset($this->clients[$i])) {
						$client['socket'] = socket_accept($this->socket);
						socket_getpeername($client['socket'], $ip);
						$client['ip'] = $ip;
						printf('Accepted connection from %s as client %d%s', $ip, $i, "\n");
						if($i == MAX_CLIENTS) {
							$data = "Too many clients!\n";
							socket_write($client['socket'], $data, strlen($data));
							socket_close($client['socket']);
						} else {
							$this->clients[$i] = $client;
							unset($client);
							$this->client_connections++;
						}
						break;
					}
//					if($changed_clients < 1) {
//						continue;
//					}
				}
			}
			// Never, ever, ever read from client 0. Ever.
			for($i = 1; $i < $this->client_connections; $i++) {
				// Make sure the client is still set...
				if(isset($this->clients[$i])) {
					// Has our client socket seen any changes?
					if(in_array($this->clients[$i]['socket'], $this->sockets['clients'])) {
						if($debug) {
							printf('Client %d has changed! Reading...%s', $i, "\n");
						}
						$data = $this->_read($i, true, 1024);
						$this->_parseData($i, $data, true);
					}
				}
			}
			
			for($i = 1; $i < $this->server_connections + 1; $i++) {
				// Make sure the server is still set...
				if(isset($this->servers[$i])) {
					// Has our server socket seen any changes?
					if(in_array($this->servers[$i]['socket'], $this->sockets['servers'])) {
						if($debug) {
							printf('Server %d has changed! Reading...%s', $i, "\n");
						}
						$data = $this->_read($i, false, 1024);
						$this->_parseData($i, $data, false);
					}
				}
			}
			$debug = false;
		}
	}
	
	private function _read($id, $client = true, $length = 1024) {
		if($client) {
			$type = 'client';
			$data = socket_read($this->clients[$id]['socket'], $length);
		} else {
			$type = 'server';
			$data = socket_read($this->servers[$id]['socket'], $length);
		}
		if($this->debug) {
			printf('Read raw data %s from %s %i%s', $data, $type, $id, "\n");
		}
		// Strip whitespace
		$data = trim($data);
		printf('Received data %s from %s %d%s', $data, $type, $id, "\n");
//		$this->_write($client, $data);
		return $data;
	}
	private function _write($id, $data, $client = true) {
		$data = sprintf('%s%s%s', $data, chr(0), "\n");

		if($client) {
			$type = 'client';
			socket_write($this->clients[$id]['socket'], $data, strlen($data));
		} else {
			$type = 'server';
			socket_write($this->servers[$id]['socket'], $data, strlen($data));
		}
		if($this->debug) {
			printf('Writing data %s to %s %d%s', $data, $type, $id, "\n");
		}
	}
	
	private function _parseData($id, $data, $client = true) {
		// [TODO] Handle $data conditions more gracefully
		if($data === false) {
			$error = socket_strerror(socket_last_error());
			printf('An error occured...%s%s', $error, "\n");
		}

		if($client && ($data === null || $data === '')) {
			// Client was disconnected..
			if($client) {
				$type = 'client';
			} else {
				$type = 'server';
			}
			printf('Lost connection to %s %d%s', $type, $i, "\n");
			unset($this->clients[$id], $this->servers[$id], $this->sockets['clients'][$id], $this->sockets['servers'][$id]);
		}
		
		$dataArray = explode(' ', $data);
		$command = array_shift($dataArray);
		$args = implode(' ', $dataArray);
		$start = time();
		switch($command) {
			case 'CONNECT':
				$host = $dataArray[0];
				$port = isset($dataArray[1]) ? $dataArray[1] : 6667;
				$password = isset($dataArray[2]) ? $dataArray[2] : null;
				$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
				if(socket_connect($socket, $host, $port)) {
					printf('Created connection to %s as server %d%s', $host, $id, "\n");
					$this->servers[$id]['socket'] = $socket;
				};
				break;
			// [TODO] Handle this more gracefully..
			case 'NICK':
				$nick = sprintf('NICK %s%s', $args, "\r\n");
				$user = sprintf('USER %s USING php-irc CLIENT%s', $args, "\r");
				$this->_write($id, $nick, false);
				$this->_write($id, $user, false);
				break;
			case 'USER':
				$user = sprintf('USER %s USING php-irc CLIENT%s', $args, "\r");
				$this->_write($id, $user, false);
				break;
			case 'JOIN':
				$this->_write($id, $data, false);
				$this->clients[$id]['channel'] = $args;
				break;
			case 'QUIT':
				$this->_disconnect($id, $args);
				break;
			// Pass directly through to IRC socket
			default:
				// Write to the opposite of what we read from
				$client = $client ? false : true;
				$write = sprintf('PRIVMSG %s :%s', $this->clients[$id]['channel'], $data);
				$this->_write($id, $write, $client);
				break;
				
		}
		// [TODO] Implement automated PING/PONG disconnection
		if(isset($this->clients[$client]['ping']) && $data == 'PONG: ' . $this->clients[$client]['ping']) {
			print 'PONG received..';
		}

		
	}
	
	private function _disconnect($id, $msg = null) {
		print $msg;
		printf('Closing %d%s', $id, "\n");
		if(isset($this->servers[$id])) {
			$quit = $msg !== null ? sprintf('QUIT :%s%s', $msg, "\r") : 'QUIT';
			$this->_write($id, $quit, false);
			socket_close($this->servers[$id]['socket']);
			unset($this->servers[$id], $this->sockets['servers'][$id]);
		}
		socket_close($this->clients[$id]['socket']);
		unset($this->clients[$id], $this->sockets['clients'][$id]);
	}
	public function __deconstruct() {
		foreach($this->clients as $client) {
			socket_write($client['socket'], "Shutting down!");
			socket_close($client['socket']);
		}
		unset($this->clients);
		socket_close($this->socket);
	}
}
// [TODO] move this

$irc = new IrcSocketServer();
$irc->listen();
