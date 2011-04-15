PHP-IRC
=======
<http://github.com/sluther/php-irc>

A web-based IRC client. The backend is written in [PHP](http://www.php.net), while the frontend is written in [jQuery](http://www.jquery.com)

Requirements
------------
You'll need to have the [PHP Socket Extension](http://www.php.net/manual/en/intro.sockets.php) installed.

Usage
---------
You'll need to start the server

	php -q server/server.php

Then:

	telnet localhost 6667

Finally, issue some commands in the telnet session:

	CONNECT <server>
	NICK <nick>
	JOIN <channel>

Once you've joined a channel you'll receive any messages sent to the channel and anything you type into the telnet session will be sent to the channel you have joined. You can currently join as many channels as you want, but it will only broadcast your messages to the last channel you have joined.

Finally, you can quit IRC with:

	QUIT