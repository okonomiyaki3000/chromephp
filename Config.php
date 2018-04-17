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

class Config
{
	/**
	 * ChromePhp settings
	 *
	 * @var  array
	 */
	protected static $settings = [
		BACKTRACE_LEVEL  => 2,
		BACKTRACE_FORMAT => '{function_full:30} {file} : {line}',
		BASE_PATH        => '',
	];

    /**
     * Sets one or more configs.
     *
     * @param  string  $key    The key to set or an array of key => value pairs.
     * @param  mixed   $value  The value to set. Not needed if $key is array.
     *
     * @return void
     */
    public static function set($key, $value = null)
    {
		if (is_array($key))
		{
			foreach ($key as $k => $v)
			{
				static::set($k, $v);
			}

			return;
		}

        static::$settings[$key] = $value;
    }

    /**
     * Gets a configuration value
     *
     * @param  string  $key
     *
     * @return mixed
     */
    public static function get($key)
    {
        return isset(static::$settings[$key]) ? static::$settings[$key] : null;
    }
}
