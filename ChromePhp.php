<?php
/**
 * Copyright 2010-2013 Craig Campbell
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace ChromePhp;

include_once 'Backtrace.php';
include_once 'Config.php';
include_once 'Constants.php';
include_once 'Entry.php';

/**
 * Server Side Chrome PHP debugger class
 *
 * @package ChromePhp
 * @author Craig Campbell <iamcraigcampbell@gmail.com>
 */
class ChromePhp
{
    /**
     * @var array
     */
    protected $_json = [
        'version' => VERSION,
        'columns' => ['log', 'backtrace', 'type'],
        'rows'    => [],
    ];

    /**
     * @var array
     */
    protected $_backtraces = [];

    /**
     * @var bool
     */
    protected $_error_triggered = false;

    /**
     * Never print a backtrace for these log types
     * @var array
     */
    protected $_no_backtrace = [
        LOG_TYPE_GROUP,
        LOG_TYPE_GROUP_END,
        LOG_TYPE_GROUP_COLLAPSED
    ];

    /**
     * @var ChromePhp
     */
    protected static $_instance;

    /**
     * Prevent recursion when working with objects referring to each other
     *
     * @var array
     */
    protected $_processed = [];

    /**
     * constructor
     */
    private function __construct()
    {
        $this->_json['request_uri'] = $_SERVER['REQUEST_URI'];
    }

    /**
     * Gets instance of this class
     *
     * @return  ChromePhp
     */
    public static function getInstance()
    {
        if (self::$_instance === null)
		{
            self::$_instance = new self();
        }

        return self::$_instance;
    }

    /**
     * Invoked when calling a static method that does not exist.
     *
     * @param  string  $name The name of the called method.
     * @param  array   $args The aguments passed.
     *
     * @return  ChromePhp    The instance of ChromePhp (for method chaining)
     */
    public static function __callStatic($name, $args)
    {
        $const = '\\' . __NAMESPACE__ . '\\LOG_TYPE_' . self::_fromCamelCase($name);

        if (defined($const))
        {
            return self::_log(constant($const), $args);
        }
        else
        {
            return self::getInstance();
        }
    }

    /**
     * Invoked when calling a method that does not exist.
     *
     * @param  string  $name The name of the called method.
     * @param  array   $args The aguments passed.
     *
     * @return  ChromePhp    The instance of ChromePhp (for method chaining)
     */
    public function __call($name, $args)
    {
        $const = '\\' . __NAMESPACE__ . '\\LOG_TYPE_' . self::_fromCamelCase($name);

        if (defined($const))
        {
            return self::_log(constant($const), $args);
        }
        else
        {
            return self::getInstance();
        }
    }

	/**
	 * Allows an instance of ChromeLogger to be called as if it were a function
	 * This is mainly useful for passing as a callback
	 *
     * @return  ChromePhp
	 */
    public function __invoke()
    {
        return self::_log(LOG_TYPE_LOG, func_get_args());
    }

    public static function trace()
    {
        $logger = self::getInstance();

        $backtrace = debug_backtrace(false);
        $level = Config::get(BACKTRACE_LEVEL);
		$startGroup = Config::get(BACKTRACE_COLLAPSED) ? LOG_TYPE_GROUP_COLLAPSED : LOG_TYPE_GROUP;

		$args = array_map('json_encode', func_get_args());
		$title = sprintf('%1$s.%2$s( %3$s )', __CLASS__, __FUNCTION__, implode(', ', $args));
        $logger->_addRow([$title], null, $startGroup);

		foreach (array_slice($backtrace, $level - 1) as &$bt)
		{
			$btMessage = new Backtrace($bt);
			$logger->_addRow([], (string) $btMessage, LOG_TYPE_LOG, false);
		}

        return $logger->_addRow([], null, LOG_TYPE_GROUP_END)->writeHeader();
    }

    /**
     * internal logging call
     *
     * @param string $type
     *
     * @return  ChromePhp
     */
    protected static function _log($type, array $args)
    {
        $logger = self::getInstance();

        // nothing passed in, don't do anything
        if (empty($args) && $type != LOG_TYPE_GROUP_END)
		{
            return $logger;
        }

        $logger->_processed = [];
        $logs = array_map([__NAMESPACE__ . '\\Entry', 'prepare'], $args);

        $backtrace = debug_backtrace(false);
        $level = Config::get(BACKTRACE_LEVEL);

        $backtrace_message = new Backtrace($backtrace[$level]);

        return $logger->_addRow($logs, (string) $backtrace_message, $type)->writeHeader();
    }

	/**
     * adds a value to the data array
	 *
	 * @param   array    $logs             [description]
	 * @param   string   $backtrace        [description]
	 * @param   string   $type             [description]
	 * @param   boolean  $uniqueBacktrace  [description]
	 *
     * @return  ChromePhp
	 */
    protected function _addRow(array $logs, $backtrace, $type, $uniqueBacktrace = true)
    {
        // if this is logged on the same line for example in a loop, set it to null to save space
        if ($uniqueBacktrace && in_array($backtrace, $this->_backtraces))
		{
            $backtrace = null;
        }

        // for group, groupEnd, and groupCollapsed
        // take out the backtrace since it is not useful
        if (in_array($type, $this->_no_backtrace))
		{
            $backtrace = null;
        }

        if ($uniqueBacktrace && $backtrace !== null)
		{
            $this->_backtraces[] = $backtrace;
        }

        $this->_json['rows'][] = [$logs, $backtrace, $type];

		return $this;
    }

    protected function writeHeader()
    {
        header(HEADER_NAME . ': ' . $this->_encode($this->_json));

		return $this;
    }

    /**
     * encodes the data to be sent along with the request
     *
     * @param  array  $data
     *
     * @return string
     */
    protected function _encode($data)
    {
        return base64_encode(utf8_encode(json_encode($data)));
    }

    /**
     * Converts a string from CamelCase to uppercase underscore
     * Based on: http://stackoverflow.com/questions/1993721/how-to-convert-camelcase-to-camel-case#1993772
     *
     * @param  string  $input  A string in CamelCase
     *
     * @return string
     */
    protected static function _fromCamelCase($input)
	{
        preg_match_all('!([A-Z][A-Z0-9]*(?=$|[A-Z][a-z0-9])|[A-Za-z][a-z0-9]+)!', $input, $matches);

		return implode('_', array_map('strtoupper', $matches[0]));
    }
}
