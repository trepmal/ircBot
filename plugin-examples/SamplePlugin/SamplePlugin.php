<?php
/**
 * Original Filename: SamplePlugin.php
 * User: carldanley
 * Created on: 12/14/12
 * Time: 2:37 PM
 */
class SamplePlugin{

	public function __construct(){
		Plugin_Manager::add_action( 'channel-message', array( $this, 'check_commands' ) );
	}

	public function check_commands( $username = '', $channel = '', $message = '' ){
		if( preg_match( '/^!test/i', $message ) ){
			ircBot::send_channel_message( $channel, $username . ': ♩♫♫♩♬♪♩♫♬♩' );
		}
	}
}