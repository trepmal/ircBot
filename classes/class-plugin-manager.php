<?php
/**
 * Original Filename: class-plugin-manager.php
 * User: carldanley
 * Created on: 12/14/12
 * Time: 2:51 AM
 */

class Plugin_Manager{

	/**
	 * @var array Internal container for storing all of the instantiated plugins that were correctly detected
	 */
	private static $_plugins = array();

	/**
	 * @var array Internal container for storing all of the actions that were registered through our plugin system
	 */
	private static $_actions = array();

	/**
	 * @var array Internal container for storing all of the filters that were registered through our plugin system
	 */
	private static $_filters = array();

	/**
	 * @var bool|Plugin_Manager Holds the singleton instantiation of this class after it's been initialized.
	 */
	private static $_instance = false;

	/**
	 * Blank constructor
	 */
	public function __construct(){}

	/**
	 * If this class has not been instantiated, it will create an instance, cache it, and return it for use. Otherwise,
	 * it will return the previously instantiated instance that was cached when first initialized.
	 *
	 * @return bool|Plugin_Manager
	 */
	public static function instance(){
		if( !self::$_instance )
			self::$_instance = new self();

		return self::$_instance;
	}

	/**
	 * Scans the plugins directory and loads anything it determines is a "correct" plugin. Plugins are detected when
	 * following these rules:
	 *
	 * 1. must live within the /plugins folder
	 * 2. the folder name must match the file name, ie - folder = "test", file must = "test.php"
	 * 3. plugin directory must follow the same naming convention as a class name
	 * 4. plugin must contain a class declaration that matches the directory name
	 */
	public static function load_plugins(){
		// make sure that an instance of this pluginManager exists already

		// check to make sure the plugins directory exists
		if( !self::_plugins_directory_exists() )
			return;

		// scan the directory, searching for plugins that fit the stereotype
		self::_scan_for_plugins();
	}

	/**
	 * Opens a directory handle and scans the plugins directory looking for valid plugins that match the rules specified
	 * in the doc block for self::load_plugins(). After a plugin is detected, it is cached internally within the self::$_plugins
	 * container.
	 */
	protected static function _scan_for_plugins(){
		// open a handle to the directory filesystem
		$directory = __DIR__ . Config::$plugins_directory;
		$handle = opendir( $directory );

		// check to make sure we could open the directory handle
		if( !$handle )
			return;

		// now begin looping through all of the contents of the plugin directory
		while( false !== ( $dir = readdir( $handle ) ) ){
			if( '.' === $dir || '..' === $dir )
				continue;

			// now we need to verify that the current "file" is a directory before continuing
			if( !is_dir( $directory . $dir ) )
				continue;

			// expect a class file to exist in the format "class-<DirectoryName>.php"
			$class_file = $directory . $dir . '/' . $dir . '.php';
			if( !file_exists( $class_file ) )
				continue;

			// now actually include the code
			require_once( $class_file );

			// now expect the class name to match whatever the $dir name was
			if( !class_exists( $dir ) )
				continue;

			// now we can instantiate the class name and store it now
			self::$_plugins[] = new $dir();
		}
	}

	/**
	 * Checks to see if the plugins directory actually exists
	 *
	 * @return bool Indicates whether or not the plugin directory exists.
	 */
	protected static function _plugins_directory_exists(){
		$directory = __DIR__ . Config::$plugins_directory;
		return is_dir( $directory );
	}

	/**
	 * Performs an action on any plugins that were registered and that have registered a hook with the action called.
	 * The first parameter is the action we need to perform whereas every parameter afterwards is captured and passed
	 * to the registered plugin hook.
	 */
	public static function do_action( /* $action = '', ( $arg1, $arg2, ... ) */ ){
		// setup the arguments so we can pass whatever was passed to us
		$arguments = func_get_args();
		$action = array_shift( $arguments );

		if( !isset( self::$_actions[ $action ] ) )
			return;

		// loop through all of the callbacks for this action
		foreach( self::$_actions[ $action ] as $callback )
			call_user_func_array( $callback[ 'callback' ], $arguments );
	}

	/**
	 * Adds an action the internal plugin action stack. The action name is the key. After the plugin has been added, we
	 * sort the actions for this hook by their priority.
	 *
	 * @param string $action Hook that the callback is being registered to
	 * @param array $callback The callback to be registered
	 * @param int $priority The priority of the hook. Lowest gets run first, 10 is the default priority level.
	 */
	public static function add_action( $action = '', $callback = array(), $priority = 10 ){
		if( !method_exists( $callback[ 0 ], $callback[ 1 ] ) )
			return false;

		if( !isset( self::$_actions[ $action ] ) )
			self::$_actions[ $action ] = array();

		// add the action with it's priority as well
		self::$_actions[ $action ][] = array(
			'callback' => $callback,
			'priority' => $priority
		);

		// now sort the array to make sure that we're good to go
		usort( self::$_actions[ $action ], array( self::instance(), 'sort_by_priority' ) );
	}

	/**
	 * This is the target sorting function called by usort() when we sort the hooks by their priorities. Sorts from
	 * lowest to highest
	 *
	 * @param array $hook_a The hook to compare against
	 * @param array $hook_b The hook we're comparing
	 * @return bool Indicates whether the current hook should be sorting higher in priority or lower in priority.
	 */
	private function sort_by_priority( $hook_a = array(), $hook_b = array() ){
		if( $hook_a[ 'priority' ] > $hook_b[ 'priority' ] )
			return true;

		return false;
	}

	/**
	 * Removes an action hook that was previously registered to this plugin system.
	 *
	 * @param string $action The action hook to de-register.
	 */
	public static function remove_action( $action = '' ){
		if( !isset( self::$_actions[ $action ] ) )
			return;

		unset( self::$_actions[ $action ] );
	}

	/**
	 * Applies all registered filter hooks to the data passed to this function. This is useful for extending content
	 * into other plugins, etc.
	 *
	 * @param string $filter The filter hook name we're using to modify the data passed to it
	 * @param string $data The data to be filtered
	 * @return mixed|string The newly edited data that was filtered by any plugins that hooked into this filter.
	 */
	public static function apply_filter( $filter = '', $data = '' ){
		if( !isset( self::$_filters[ $filter ] ) )
			return $data;

		// loop through all of the callbacks for this action
		foreach( self::$_filters[ $filter ] as $callback )
			$data = call_user_func_array( $callback[ 'callback' ], array( $data ) );

		return $data;
	}

	/**
	 * Adds a filter hook to the plugin system and sorts all filters contained within this hook container by their
	 * priority. The lower the priority the sooner the callback will be fired. 10 is the default priority level.
	 *
	 * @param string $filter Filter name to add
	 * @param array $callback Callback to be added and called later on hook execution
	 * @param int $priority The priority of the hook. The smaller the number, the sooner the hook is called during hook execution.
	 */
	public static function add_filter( $filter = '', $callback = array(), $priority = 10 ){
		if( !method_exists( $callback[ 0 ], $callback[ 1 ] ) )
			return false;

		if( !isset( self::$_filters[ $filter ] ) )
			self::$_filters[ $filter ] = array();

		// add the action with it's priority as well
		self::$_filters[ $filter ][] = array(
			'callback' => $callback,
			'priority' => $priority
		);

		// now sort the array to make sure that we're good to go
		usort( self::$_filters[ $filter ], array( self::instance(), 'sort_by_priority' ) );
	}

	/**
	 * Removes any filters by the specified name if they were previously registered with this plugin system.
	 *
	 * @param string $filter The filter to be de-registered.
	 */
	public static function remove_filter( $filter = '' ){
		if( !isset( self::$_filters[ $filter ] ) )
			return;

		unset( self::$_filters[ $filter ] );
	}

}
