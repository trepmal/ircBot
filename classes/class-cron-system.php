<?php
/**
 * Original Filename: class-cron-system.php
 * User: carldanley
 * Created on: 12/14/12
 * Time: 7:15 PM
 */
class Cron_System{

	/**
	 * @var array Holds all of the registered cron-jobs with the cron-job name being the key and the callback being the value
	 */
	private static $_cron_jobs = array();

	/**
	 * @var bool|Cron_System Holds the only instantiation of this class
	 */
	private static $_instance = false;

	/**
	 * construct that sets the timezone so our cron jobs are working correctly
	 */
	public function __construct(){
		date_default_timezone_set( Config::$default_timezone );
	}

	/**
	 * If an instance of this class does not exist, it will be created, cached and returned. If it does exist, it will be
	 * returned from cache
	 *
	 * @return bool|Cron_System The instantiated object of this class
	 */
	public static function instance(){
		if( !self::$_instance )
			self::$_instance = new self();

		return self::$_instance;
	}

	/**
	 * Registers a cron job with the cron system. This function will stop script execution if the cron has been registered
	 * already OR the callback does not exist
	 *
	 * @param string $cron_name Name of the cron job. Used as an index for storing the callback & interval settings
	 * @param array|string $callback The callback array or string that will be called when the cron job is fired
	 * @param array $interval
	 * @return bool Returns true, indicating the cron job has successfully been registered
	 */
	public static function register( $cron_name = '', $callback = array(), $interval = array() ){
		if( isset( self::$_cron_jobs[ $cron_name ] ) )
			die( 'Cron Job "' . $cron_name . '" has already been registered!' );

		// verify that the callback function actually exists as valid method
		if( is_array( $callback ) && !method_exists( $callback[ 0 ], $callback[ 1 ] ) )
			die( 'Cron Job "' . $cron_name . '" has an invalid callback function!' . "\n" );
		else if( is_string( $callback ) && !function_exists( $callback ) )
			die( 'Cron Job "' . $cron_name . '" has an invalid callback function!' . "\n" );

		// sanitize the user's values
		$interval = self::_sanitize_interval( $interval );

		// now that we know the cron doesn't exist and the method is valid, add the callback to our cronjobs with the specified interval
		self::$_cron_jobs[ $cron_name ] = array(
			'callback' => $callback,
			'interval' => $interval
		);

		return true;
	}

	/**
	 * This function sanitizes the interval by making sure the interval container contains valid formats and times.
	 *
	 * @param array $interval The container holding all of the developer's specified cron formats and times. This will be sanitized if any invalid entries exist.
	 * @return array Returns an array containing correct interval formats and times
	 */
	private static function _sanitize_interval( $interval = array() ){
		$new = array();
		$valid_formats = array( 'year', 'month', 'day', 'hour', 'minute', 'second' );

		foreach( $valid_formats as $valid_format ){
			if( !isset( $interval[ $valid_format ] ) ){
				$new[ $valid_format ] = '*';
				continue;
			}

			// now validate all of the times
			if( !( $times = self::_validate_times( $interval[ $valid_format ] ) ) )
				continue;

			$new[ $valid_format ] = $times;
		}

		return $new;
	}

	/**
	 * Validates all times by converting them to integers and then concatenating them back into a comma-separated string.
	 * By doing this, we weed out any invalid times the developer may have passed. Note: if invalid times are passed,
	 * the returned string may possibly contain multiple values of 0.
	 *
	 * @param string $times
	 * @return string
	 */
	private static function _validate_times( $original_times = '' ){
		$validated_times = explode( ',', $original_times );
		$validated_times = array_map( 'intval', $validated_times );

		// todo: weed out any invalid entries like text that was converted to 0 or something. also, test this function
		// todo: heavily by passing invalid entries as times to see what happens

		return implode( ',', $validated_times );
	}

	/**
	 * Removes a cron job that was previously registered if it exists.
	 *
	 * @param string $cron_name The unique name of the cron job added.
	 */
	public static function remove( $cron_name = '' ){
		if( !isset( self::$_cron_jobs[ $cron_name ] ) )
			return false;

		unset( self::$_cron_jobs[ $cron_name ] );
	}

	/**
	 * Called every x interval of time from the irc_bot to check all jobs that have been registered using this cron
	 * system.
	 */
	public static function check_jobs(){
		array_walk( self::$_cron_jobs, array( self::instance(), 'check_if_needs_to_be_run' ) );
	}

	/**
	 * Checks to see if the given cron job needs to be run or not
	 *
	 * @param array $cron The cron array that was registered. Contains the callback and interval this cron should be run on.
	 */
	public static function check_if_needs_to_be_run( $cron = array() ){
		// these are valid interval values & date formats that can be used and will be checked against
		$valid_intervals = array(
			'year' => 'y', 'month' => 'm', 'day' => 'd',
			'hour' => 'h', 'minute' => 'i', 'second' => 's'
		);

		// get the user's defined intervals from the cron to save lookups later
		$user_intervals = $cron[ 'interval' ];

		// assume we can run the callback until proven otherwise
		$can_run = true;

		// check all the valid intervals in order - order matters logically
		foreach( $valid_intervals as $interval => $format ){

			// get the intervals set by the user
			$user_interval = $user_intervals[ $interval ];

			// make sure the interval matches exactly now
			if( !self::_compare_interval_to_now( $user_interval, $format ) ){
				// no point in continuing if the interval doesn't match perfectly
				$can_run = false;
				break;
			}
		}

		// check to see if we can still run the cron
		if( $can_run )
			if( is_array( $cron[ 'callback' ] ) )
				call_user_func_array( $cron[ 'callback' ], array() );
			else if( is_string( $cron[ 'callback' ] ) )
				call_user_func( $cron[ 'callback' ] );
	}

	/**
	 * Checks to see if this intervals string and format matches the current time.
	 *
	 * @param string $intervals A string containing the comma-separated time intervals with an asterisk wild-card option to indicate run all the time.
	 * @param string $format The date() format to check against
	 * @return bool Indicates whether or not this interval matches the current time
	 */
	private static function _compare_interval_to_now( $intervals = '*', $format = 's' ){
		if( '*' === $intervals )
			return true;

		$intervals = explode( ',', $intervals );
		$currentTime = intval( date( $format ) );

		foreach( $intervals as $interval ){
			$interval = intval( $interval );

			if( $currentTime === $interval )
				return true;
		}

		return false;
	}

}
