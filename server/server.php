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
    
    private $total_clients = 0;
    
    private $debug = false;
    
    public function __construct() {
        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1);
        socket_bind($this->socket, SOCKET_ADDRESS, SOCKET_PORT) or die('Could not bind to address ' . SOCKET_ADDRESS . ' on port ' . SOCKET_PORT . "!\n");
        socket_listen($this->socket, MAX_CLIENTS) or die ("Could not setup socket listener!\n");
        $this->clients[0] = array('socket' => $this->socket);
    }
    
    public function listen() {
        $time = time();
        $format = "m-d-Y H:i:s a";
        
        $read = array();
        printf('Starting up... %s%s', date($format, $time), "\n");
        while(true) {
            $this->total_clients = count($this->clients);
            if(time() - $time >= 15) {
                $time = time();
                printf('Time: %s%s', date($format, $time), "\n");
                printf('%d clients connected%s', $this->total_clients - 1, "\n");
                // Ping
                for($i = 1; $i < $this->total_clients; $i++) {
                    $ping = sprintf('PING: %d', $time);
                    $this->clients[$i]['ping'] = $time;
                    $this->_write($i, $ping);
                }
            }
            
            // Handle clients
            for($i = 0; $i < $this->total_clients; $i++) {
                if(isset($this->clients[$i]['socket'])) {
                    if($this->debug) {
                        printf('Setting read[%d] to client[%d][\'socket\']%s', $i, $i, "\n");
                    }
                    $read[$i] = $this->clients[$i]['socket'];
                }
            }
            
            // Any new connections?
            // $write and $except are only placeholders
            $changed_sockets = socket_select($read, $write = NULL, $except = NULL, 0);
            if($this->debug){
                printf('Changed sockets: %d%s', $changed_sockets, "\n");
            }
            
            // Handle new connections
            if(in_array($this->socket, $read)) {
                // increase MAX_CLIENTS by one..we don't count $client[0]
                for($i = 1; $i < MAX_CLIENTS + 1; $i++) {
                    if(!isset($this->clients[$i])) {
                        $client['socket'] = socket_accept($this->socket);
                        socket_getpeername($client['socket'], $ip);
                        $client['ip'] = $ip;
                        printf('Accepted connection from %s into client %d%s', $ip, $i, "\n");
                        if($i == MAX_CLIENTS) {
                            $data = "Too many clients!\n";
                            socket_write($client['socket'], $data, strlen($data));
                            socket_close($client['socket']);
                        } else {
                            $this->clients[$i] = $client;
                            unset($client);
                            $this->total_clients++;
                        }
                        break;
                        
                    }
                    if($changed_sockets < 1) {
                        continue;                
                    }
                }
            }
            // Never, ever, ever read from client 0. Ever.
            for($i = 1; $i < $this->total_clients; $i++) {
                // Make sure the client is still set...
                if(!isset($this->clients[$i])) {
                    continue;
                }
                
                // Has our client socket seen any changes?
                if(in_array($this->clients[$i]['socket'], $read)) {
                    if($this->debug) {
                        printf('Client %d has changed! Reading...%s', $i, "\n");
                    }
                    $data = $this->_read($i, 1024);
                    
                    // [TODO] Handle $data conditions more gracefully
                    if($data === false) {
                        $error = socket_strerror(socket_last_error());
                        printf('An error occured...%s%s', $error, "\n");
                    }
                    if($this->debug) {
                        printf('Read raw data %s from client %i%s', $data, $i, "\n");
                    }
                    if($data === null || $data === '') {
                        // Client was disconnected..
                        printf('Lost connection to %d%s', $i, "\n");
                        unset($this->clients[$i], $read[$i]);
                    }
                    $data = trim($data);
                    // [TODO] Implement automated PING/PONG disconnection
                    if($data == 'PONG: ' . $this->clients[$i]['ping']) {
                        print 'PONG received..';                        
                    }
                    if($data === 'exit') {
                        printf('Received exit command from client%s', "\n");
                        // Close socket
                        $this->_close($i);
                        // Unset *both* vars. Failure to unset either of these will result in errors. Lots and lots of errors.
                        unset($this->clients[$i], $read[$i]);
                    } elseif($data === 'PING') {
                        $this->_write($i, 'PONG');
                    } elseif($data) {
                        // Strip whitespace
                        printf('Received data %s from client %d%s', $data, $i, "\n");
                        $this->_write($i, $data);
                    }
                }
            }
        }
    }
    
    private function _read($client, $length = 1024) {
        $data = socket_read($this->clients[$client]['socket'], $length);        
        return trim($data);
    }
    private function _write($client, $data) {
        $data = sprintf('%s%s%s', $data, chr(0), "\n");
        socket_write($this->clients[$client]['socket'], $data, strlen($data));
    }
    
    private function _close($client) {
        socket_close($this->clients[$client]['socket']);
        unset($this->clients[$client]);
        $this->total_clients--;
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