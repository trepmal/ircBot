<?php
/**
 * Original Filename: CronExample.php
 * User: carldanley
 * Created on: 12/15/12
 * Time: 11:04 PM
 */

class CronExample{

	public function __construct(){
		// register a new cron job that will output a sample message in each IRC channel
		Cron_System::register( 'cronExample.timeUpdater', array( $this, 'run_cron_job' ), array( 'second' => '00,30' ) );
	}

	public function run_cron_job(){
		// assume we've successfully connected to each channel we specified
		foreach( Config::$irc_channels as $channel ){
			ircBot::send_channel_message( $channel, 'The time is now: ' . date( 'g:i:sa' ) . ' on ' . date( 'l, F j, Y' ) . ' (' . Config::$default_timezone . ')' );
		}
	}

}