<?php
/**
 * Original Filename: index.php
 * User: carldanley
 * Created on: 12/13/12
 * Time: 11:51 PM
 */

// require configs
require_once( __DIR__ . '/classes/class-irc-bot.php' );

// connect using our configuration that was loaded - let it rip!
ircBot::connect();