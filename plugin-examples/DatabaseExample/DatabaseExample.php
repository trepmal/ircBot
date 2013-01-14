<?php
/**
 * Original Filename: DatabaseExample.php
 * User: carldanley
 * Created on: 12/16/12
 * Time: 2:09 AM
 */

class DatabaseExample{

	public function __construct(){
		// setup our database before-hand
		self::_setup_database();

		// register our hook now
		Plugin_Manager::add_action( 'channel-message', array( $this, 'log_channel_message' ) );
	}

	private static function _setup_database(){
		$query = 'CREATE TABLE IF NOT EXISTS messages ( id INT(11) UNIQUE AUTO_INCREMENT, message TEXT, username VARCHAR(100), channel VARCHAR(100), timestamp INT(11) );';
		Database::connect();
		Database::query( $query, false );
		Database::disconnect();
	}

	public function log_channel_message( $username = '', $channel = '', $message = '' ){
		Database::connect();
		Database::insert( 'messages', array(
			'username' => $username,
			'channel' => $channel,
			'message' => $message,
			'timestamp' => time()
		) );
		Database::disconnect();
	}

}