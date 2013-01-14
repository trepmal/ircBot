<?php
/**
 * Original Filename: class-irc-bot.php
 * User: carldanley
 * Created on: 12/14/12
 * Time: 12:48 AM
 */

// require our singletons for plugins and the database stuff
require_once( __DIR__ . '/class-config.php' );
require_once( __DIR__ . '/class-database.php' );
require_once( __DIR__ . '/class-plugin-manager.php' );
require_once( __DIR__ . '/class-cron-system.php' );

class ircBot{

	/**
	 * Stores a single instance of this class so it's only instantiated once.
	 *
	 * @var bool|ircBot
	 */
	private static $_instance = false;

	/**
	 * Stores the socket created for connecting to IRC.
	 *
	 * @var bool|resource
	 */
	private static $_socket = false;

	/**
	 * This is a container that holds all of the channels that the bot is currently connected to, at any given point in
	 * time. This will need to be continually updated on various events like when the ircBot joins a new channel or parts
	 * from an existing channel.
	 *
	 * @var array
	 */
	private static $_channels_connected_to = array();

	/**
	 * Class Constructor - sets the time limit so that apache/Nginx does not stop the script after awhile. This also
	 * loads all plugins and sets up a detection hook to make sure we can keep track of when the bot joins a channel.
	 */
	public function __construct(){
		// setup PHP settings for not expiring this script
		set_time_limit( 0 );

		// load all plugins into play
		Plugin_Manager::load_plugins();

		// add support for user-joined
		Plugin_Manager::add_action( 'user-join', array( $this, 'check_for_new_channel' ) );

		// setup the database for users in each channel
		self::_setup_tables();
	}

	/**
	 * Creates a table for logging which users are currently online and when they were last seen.
	 */
	private static function _setup_tables(){
		$query = 'CREATE TABLE IF NOT EXISTS ' . Config::$user_table . ' ( id INT(11) UNIQUE AUTO_INCREMENT NOT NULL, username VARCHAR(100), channel VARCHAR(100) );';
		Database::connect();

		// create the table if it doesnt exist
		Database::query( $query, false );

		// make sure that the table has a multiple column key
		Database::query( 'ALTER TABLE ' . Config::$user_table . ' ADD UNIQUE INDEX ( username, channel );', false, false );

		// drop all users that might have been left over from last time
		Database::query( 'TRUNCATE TABLE ' . Config::$user_table . ';', false, false );

		Database::disconnect();
	}

	/**
	 * This is actually an action hook callback added to handle when the bot joins a new channel. This callback checks
	 * to ensure that the bot was the one joining the channel before adding it to the channels array.
	 *
	 * @param string $username The username that joined the new channel
	 * @param string $channel The channel that was joined
	 */
	public function check_for_new_channel( $username = '', $channel = '' ){
		if( Config::$irc_nick === !$username )
			return;

		if( in_array( $channel, self::$_channels_connected_to ) )
			return;

		self::$_channels_connected_to[] = $channel;
	}

	/**
	 * Sanitizes a string by removing "problem" characters.
	 *
	 * @param string $str String to be sanitized.
	 * @return string Sanitized string without newlines, carriage returns or tabs.
	 */
	public static function sanitize_string( $str = '' ){
		$str = str_replace( array( "\n", "\r", "\t" ), '', $str );
		return $str;
	}

	/**
	 * Class Destructor - closes the socket if it was open
	 */
	public function __destruct(){
		if( self::$_socket ){
			socket_close( self::$_socket );
		}
	}

	/**
	 * Gets the only instantiated instance of our ircBot. If one did not exist, it will be created and cached for the
	 * future.
	 *
	 * @return bool|ircBot The cached instance of our ircBot.
	 */
	public static function instance(){
		if( !self::$_instance )
			self::$_instance = new self();

		return self::$_instance;
	}

	/**
	 * Connects the ircBot to the IRC server, logs in, joins the specified channels and waits for any data to come
	 * through the socket. While waiting, the cron system is being run - checking for jobs that need to be fired.
	 */
	public static function connect(){
		// make sure this class has been instantiated
		self::instance();

		// open the socket to the IRC server
		self::$_socket = socket_create( AF_INET, SOCK_STREAM, 0 );
		$socketConnection = socket_connect( self::$_socket, Config::$irc_server, Config::$irc_port );

		// check that we actually joined
		if( !self::$_socket || !$socketConnection )
			die( 'Error while connecting to ' . Config::$irc_server );

		// set nonblocking
		socket_set_nonblock( self::$_socket );

		// now actually login to the server and join the channels we need
		self::_login();
		self::_join_channels();

		// listen for any commands now until this script is closed manually through kill command or user interaction
		while( 1 ){

			// listen for any incoming commands
			self::_listen();

			// check for cron jobs that might need to be run
			Cron_System::check_jobs();
		}

		// close the socket to the IRC server
		socket_close( self::$_socket );
	}

	/**
	 * Disconnects the ircBot from irc.
	 */
	public static function disconnect(){
		// close our IRC session
		if( self::$_socket ){
			socket_close( self::$_socket );

			// make sure we reset the socket to false so destruct doesn't throw errors
			self::$_socket = false;
		}
	}

	/**
	 * Listen for any new data to come across the socket. Timeout listening after 1 second. If we change the timeout,
	 * we'll need to adjust the cron system to support the loop changes too
	 */
	private static function _listen(){
		// timeout: 1 second = 1000000
		$timeout = 1000000;

		// listen to all commands & messages sent to the bot
		$sockets = array( self::$_socket );

		$write = ( isset( $write ) ) ? $write : array();
		$exception = ( isset( $exception ) ) ? $exception : array();
		$socket_updated = socket_select( $sockets, $write, $exception, 0, $timeout );

		if( 1 === $socket_updated ){
			$data = socket_read( self::$_socket, 4096 );

			// show irc raw output for debugging purposes
			if( Config::$irc_debug )
				echo $data;

			// split by newlines and process each one
			$lines = explode( "\n", $data );

			// handle & process this command if needed
			array_walk( $lines, array( self::instance(), 'process_irc_message' ) );
		}
		else if( false === $socket_updated ){
			//an error occurred
			die( 'socket_select() returned false with reason: ' . socket_strerror( socket_last_error() ) );
		}
	}

	/**
	 * Processes an IRC message that comes through the socket. We'll check for regular expressions and then fire any
	 * hooks for plugins to get updates, etc.
	 *
	 * @param string $data Line of data that was sent from IRC server to the ircBot
	 */
	public static function process_irc_message( $data = '' ){
		// sanitize this data
		$data = self::sanitize_string( $data );

		// first off, grab the user name from the message
		$username = self::_extract_irc_username( $data );

		// start checking the types of messages that can occur and what we really care about
		// i imagine that this will grow in the future, but for now - we are simply implementing as we go
		if( self::_check_ping_pong( $data ) )
			return;
		else if( self::_check_bot_username_taken( $data ) )
			die( 'Username "' . Config::$irc_nick . '" already taken on ' . Config::$irc_server . "\n" );
		else if( self::_check_channel_message( $data, $username ) )
			return;
		else if( self::_check_user_part( $data, $username ) )
			return;
		else if( self::_check_user_join( $data, $username ) )
			return;
		else if( self::_check_channel_user_list( $data ) )
			return;
		else if( self::_check_user_quit( $data, $username ) )
			return;
		else if( self::_check_nick_change( $data, $username ) )
			return;
	}

	private static function _check_nick_change( $data = '', $username = '' ){
		if( preg_match( '/\sNICK\s\:.*/i', $data, $new_nick ) ){
			// ok, we found a match for a user who changed their nick name, now we need to update the database to make
			// sure that this user no longer exists and the new user does exist
			$new_nick = str_replace( ' NICK :', '', $new_nick[ 0 ] );

			Database::connect();
			Database::where( 'username', $username );
			Database::update( Config::$user_table, array(
				'username' => $new_nick
			) );
			Database::disconnect();

			// username is old nickname
			Plugin_Manager::do_action( 'nick-changed', $username, $new_nick );
		}

		return false;
	}

	/**
	 * Checks to see if this was a command to tell the bot who exactly is in this channel already.
	 *
	 * @param string $data raw IRC message sent to the ircBot
	 */
	private static function _check_channel_user_list( $data = '' ){
		if( preg_match( '/\s353\s(' . Config::$irc_nick . ')+\s\@\s(\#(.*)+)+/i', $data, $list ) ){
			// split on the colon for the two parts of the line
			$list = explode( ':', $list[ 2 ] );

			// grab the channel name now
			$channel = preg_replace( '/^\#/i', '', trim( array_shift( $list ) ) );

			// grab the users now
			$users = explode( ' ', $list[ 0 ] );

			// filter so we have all other users
			foreach( $users as $user ){
				if( $user === Config::$irc_nick )
					continue;

				$username = preg_replace( '/^\@/i', '', $user );

				// add this user to the database so we have a running log of who is currently in this channel
				Database::connect();
				Database::insert( Config::$user_table, array(
					'username' => $username,
					'channel' => $channel
				) );
				Database::disconnect();
			}

			return true;
		}

		return false;
	}

	/**
	 * Checks to see if the bot received a message indicating that the specified bot username was already taken or not.
	 *
	 * @param string $data raw IRC message sent to the ircBot
	 * @return int Indicates whether or not the username was already taken
	 */
	private static function _check_bot_username_taken( $data = '' ){
		return preg_match( '/Nickname is already in use./i', $data );
	}

	/**
	 * Checks to see if the server sent the ircBot a PING command
	 *
	 * @param string $data raw IRC data sent from the server to the ircBot
	 * @return bool Indicates that this raw IRC line was a ping from the server
	 */
	private static function _check_ping_pong( $data = '' ){
		if( preg_match( '/^PING\s/i', $data ) ){
			$data = str_replace( 'PING ', 'PONG ', $data ) . "\n";
			socket_write( self::$_socket, $data );
			return true;
		}
		return false;
	}

	/**
	 * Returns all of the channels the ircBot is currently connected to.
	 *
	 * @return array An array containing all of the channels that the ircBot is currently connected to.
	 */
	public static function get_channels_connected_to(){
		return self::$_channels_connected_to;
	}

	/**
	 * Checks whether or not the user quit IRC or not.
	 *
	 * @param string $data raw IRC data sent from the server to the ircBot
	 * @param string $username The username that quit IRC.
	 */
	private static function _check_user_quit( $data = '', $username = '' ){
		if( preg_match( '/\sQUIT\s/i', $data ) ){
			// remove this user from the database since they are no longer in the channel
			Database::connect();
			Database::where( 'username', $username );
			Database::delete( Config::$user_table );
			Database::disconnect();

			// now fire the command for it
			Plugin_Manager::do_action( 'user-part', $username );

			return true;
		}

		return false;
	}

	/**
	 * Checks to see if a user has left the IRC channel.
	 *
	 * @param string $data raw IRC data sent from server to the ircBot
	 * @param string $username The username that parted the channel
	 * @return bool Indicates whether the raw IRC data was someone parting the channel or not
	 */
	private static function _check_user_part( $data = '', $username = '' ){
		if( preg_match( '/\sPART\s#(.*)\s:/i', $data, $channel ) ){
			$channel = $channel[ 1 ];

			// strip the channel hash if it exists
			$channel = preg_replace( '/^\#/i', '', $channel );

			// remove this user from the database so we have a running log of who is currently in this channel
			Database::connect();
			Database::where( 'username', $username );
			Database::where( 'channel', $channel );
			Database::delete( Config::$user_table );
			Database::disconnect();

			Plugin_Manager::do_action( 'user-part', $username, $channel );
		}
		return false;
	}

	/**
	 * Checks to see if the username specified is currently in the channel specified.
	 *
	 * @param string $username Username to check for
	 * @param string $channel Channel that will be checked to see if the user is currently in
	 * @return bool Indicates whether or not the user is currently in the channel specified
	 */
	public static function user_is_in_channel( $username = '', $channel = '' ){
		Database::connect();
		Database::where( 'username', $username );
		Database::where( 'channel', $channel );
		$users = Database::num_rows( Config::$user_table, 1 );

		if( 0 < $users )
			return true;

		return false;
	}

	/**
	 * Checks whether or not a user has joined the channel.
	 *
	 * @param string $data raw IRC data sent from server to the ircBot
	 * @param string $username Username that joined the channel
	 * @return bool Indicates whether a user has joined or not
	 */
	private static function _check_user_join( $data = '', $username = '' ){
		if( preg_match( '/\sJOIN\s#(.*)/i', $data, $channel ) ){
			$channel = self::sanitize_string( $channel[ 1 ] );

			// strip the channel hash if it exists
			$channel = preg_replace( '/^\#/i', '', $channel );

			// add this user to the database so we have a running log of who is currently in this channel
			Database::connect();
			Database::insert( Config::$user_table, array(
				'username' => $username,
				'channel' => $channel
			) );
			Database::disconnect();

			Plugin_Manager::do_action( 'user-join', $username, $channel );

			return true;
		}

		return false;
	}

	/**
	 * Determines the username.
	 *
	 * @param string $data The raw IRC message received.
	 * @return bool|string Returns the username if found, otherwise false.
	 */
	private static function _extract_irc_username( $data = '' ){
		preg_match( '/:([^!]+)!/i', $data, $username );

		// check for the username now
		if( 2 === count( $username ) )
			$username = $username[ 1 ];
		else
			$username = false;

		// make sure there are no @ signs involved
		if( false !== $username )
			$username = preg_replace( '/^\@/i', '', $username );

		return $username;
	}

	/**
	 * Checks whether or not the IRC data sent was a message a channel the bot is currently in OR a message to the bot
	 * itself.
	 *
	 * @param string $data raw IRC data sent from server to the ircBot
	 * @param string $username Username that sent the message
	 * @return bool Indicates whether or not this raw IRC data was a message to the channel or to the ircBot
	 */
	private static function _check_channel_message( $data = '', $username = '' ){
		// check for a channel message - PRIVMSG
		if( preg_match( '/\sPRIVMSG\s(.*)\s:(.*)+/i', $data, $channel ) ){
			$channel = $channel[ 1 ];
			preg_match( '/' . $channel . '\s:(.*+)/i', $data, $message );
			$message = $message[ 1 ];

			// strip the bad characters in the message
			$message = str_replace( array( "\r", "\n" ), '', $message );

			// check to see if this is a private message or not - we know it's private because there will be no hash tag
			// and the channel will match the username
			$private_messages = false;
			if( '#' !== substr( $channel, 0, 1 ) && Config::$irc_nick === $username )
				$private_messages = true;

			// strip the channel hash if it exists
			$channel = preg_replace( '/^\#/i', '', $channel );

			if( $private_messages )
				Plugin_Manager::do_action( 'private-message', $username, $channel, $message );
			else
				Plugin_Manager::do_action( 'channel-message', $username, $channel, $message );

			return true;
		}

		return false;
	}

	/**
	 * Verifies that the ircBot is currently in this channel and then sends the message to the channel if it is.
	 *
	 * @param string $channel Channel that the message will be sent to
	 * @param string $message Message to be sent to the channel
	 */
	public static function send_channel_message( $channel = '', $message = '' ){
		// strip the hash if it exists
		$channel = preg_replace( '/^\#/i', '', $channel );

		// make sure the bot is connected to this channel before we try sending this message
		if( !in_array( $channel, self::$_channels_connected_to ) )
			return;

		// now add the slash back
		$channel = '#' . $channel;

		// write to the socket now
		socket_write( self::$_socket, 'PRIVMSG ' . $channel . ' :' . $message . "\n" );
	}

	/**
	 * Joins all of the channels specified in the configuration file
	 */
	private static function _join_channels(){
		// Join all specified channels
		foreach( Config::$irc_channels as $channel ){
			socket_write( self::$_socket, 'JOIN ' . $channel . "\n" );
		}
	}

	/**
	 * Logs into the server using the settings specified in the configuration file
	 */
	private static function _login(){
		// login to the IRC server
		socket_write( self::$_socket, 'USER ' . Config::$irc_nick . ' ' . Config::$irc_service_name . ' ' . Config::$irc_server . ' ircBot' . "\n" );
		socket_write( self::$_socket, 'NICK ' . Config::$irc_nick . "\n" );
	}

}
