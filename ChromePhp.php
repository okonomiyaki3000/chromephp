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

include_once 'Constants.php';

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
        'rows'    => ['log', 'backtrace', 'type'],
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
        $basepath = Config::get(BASE_PATH);

        $logger->_addRow(['ChromePhp.trace()'], null, LOG_TYPE_GROUP);

        for ($i = $level - 1, $l = count($backtrace); $i < $l; $i++)
        {
            $backtrace_message = isset($backtrace[$i]) ? $logger->_formatBacktrace($backtrace[$i]) : 'unknown';
            $logger->_addRow([], $backtrace_message, LOG_TYPE_LOG, false);
        }

        $logger->_addRow([], null, LOG_TYPE_GROUP_END);

        return $logger;
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
        $logs = array_map([$logger, '_convert'], $args);

        $backtrace = debug_backtrace(false);
        $level = Config::get(BACKTRACE_LEVEL);

        $backtrace_message = isset($backtrace[$level]) ? $logger->_formatBacktrace($backtrace[$level]) : 'unknown';

        $logger->_addRow($logs, $backtrace_message, $type);

        return $logger;
    }

    protected function _formatBacktrace($values)
    {
        $default = ['function' => '', 'line' => '', 'file' => '', 'class' => '', 'type' => ''];
        $values = array_merge($default, $values);
        $values['function_full'] = $values['type'] ? $values['class'] . $values['type'] . $values['function'] : $values['function'];
        $values['file_full'] = $values['file'];

        $format = $this->getSetting(BACKTRACE_FORMAT);
        $basepath = $this->getSetting(BASE_PATH);

        if ($basepath && strpos($values['file'], $basepath) === 0)
		{
            $values['file'] = substr($values['file'], strlen($basepath));
        }

        $this->values = $values;
        $fn = [$this, '_formatBacktraceCallback'];

        return preg_replace_callback('/{(\w+)?(:(\d+))?}/', $fn, $format);
    }

    protected function _formatBacktraceCallback($matches)
    {
        $s = isset($this->values[$matches[1]]) ? $this->values[$matches[1]] : '';

        return isset($matches[3]) ? str_pad($s, (int) $matches[3], ' ', \STR_PAD_RIGHT) : $s;
    }

    /**
     * converts an object to a better format for logging
     *
     * @param  Object
     *
     * @return  array
     */
    protected function _convert($object)
    {
        // if this isn't an object then just return it
        if (!is_object($object))
		{
            return $object;
        }

        //Mark this object as processed so we don't convert it twice and it
        //Also avoid recursion when objects refer to each other
        $this->_processed[] = $object;

        $object_as_array = [];

        // first add the class name
        $object_as_array['___class_name'] = get_class($object);

        // loop through object vars
        $object_vars = get_object_vars($object);

		foreach ($object_vars as $key => $value)
		{
            // same instance as parent object
            if ($value === $object || in_array($value, $this->_processed, true))
			{
                $value = 'recursion - parent object [' . get_class($value) . ']';
            }

            $object_as_array[$key] = $this->_convert($value);
        }

        $reflection = new \ReflectionClass($object);

        // loop through the properties and add those
        foreach ($reflection->getProperties() as $property)
		{
            // if one of these properties was already added above then ignore it
            if (array_key_exists($property->getName(), $object_vars))
			{
                continue;
            }

			$type = $this->_getPropertyKey($property);
            $property->setAccessible(true);
            $value = $property->getValue($object);

            // same instance as parent object
            if ($value === $object || in_array($value, $this->_processed, true))
			{
                $value = 'recursion - parent object [' . get_class($value) . ']';
            }

            $object_as_array[$type] = $this->_convert($value);
        }

        return $object_as_array;
    }

    /**
     * takes a reflection property and returns a nicely formatted key of the property name
     *
     * @param  ReflectionProperty
     *
     * @return  string
     */
    protected function _getPropertyKey(ReflectionProperty $property)
    {
        $static = $property->isStatic() ? ' static' : '';

        if ($property->isPublic())
		{
            return 'public' . $static . ' ' . $property->getName();
        }

        if ($property->isProtected())
		{
            return 'protected' . $static . ' ' . $property->getName();
        }

        if ($property->isPrivate())
		{
            return 'private' . $static . ' ' . $property->getName();
        }
    }

    /**
     * adds a value to the data array
     *
     * @param  mixed
     *
     * @return  void
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
        $this->_writeHeader($this->_json);
    }

    protected function _writeHeader($data)
    {
        header(HEADER_NAME . ': ' . $this->_encode($data));
        header(HEADER_NAME . '-DEF: ' . $this->_compress($data));
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
     * encodes the data to be sent along with the request
     *
     * @param  array  $data
     *
     * @return string
     */
    protected function _compress($data)
    {
        return base64_encode(gzdeflate(utf8_encode(json_encode($data))));
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
