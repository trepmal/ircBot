<?php
/**
 * Original Filename: class-config-sample.php
 * User: carldanley
 * Created on: 12/14/12
 * Time: 12:26 PM
 */
class Config{

	public static $irc_channels = array( '#channel-a', '#channel-b' );
	public static $irc_server = 'irc.freenode.net';
	public static $irc_port = 6667;
	public static $irc_nick = 'nick';
	public static $irc_service_name = 'ircBot-v1.0';
	public static $irc_debug = false;

	public static $plugins_directory = '/../plugins/';

	public static $db_host = 'localhost';
	public static $db_username = 'root';
	public static $db_password = 'root';
	public static $db_database = 'ircBot';

	public static $default_timezone = 'EST';

	public static $user_table = 'users_online';

}
