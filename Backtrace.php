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

class Backtrace
{
	protected $empty = false;

	protected $values = [
		'function' => '',
		'line'     => '',
		'file'     => '',
		'class'    => '',
		'type'     => '',
	];

	protected $stringVal;

	public function __construct(array $backtrace = null)
	{
		if (!$backtrace)
		{
			$this->empty = true;

			return;
		}

		$this->values = array_merge($this->values, $backtrace);
        $this->values['function_full'] = $this->values['type'] ? $this->values['class'] . $this->values['type'] . $this->values['function'] : $this->values['function'];
        $this->values['file_full'] = $this->values['file'];

        $basepath = Config::get(BASE_PATH);

        if ($basepath && strpos($this->values['file'], $basepath) === 0)
		{
            $this->values['file'] = substr($this->values['file'], strlen($basepath));
        }
	}

	public static function getInstance(array $backtrace = null)
	{
		$classname = __CLASS__;

		return new $classname($backtrace);
	}

	public function __toString()
	{
		if ($this->empty)
		{
			return 'Unknown';
		}

		if (!isset($this->stringVal))
		{
			$this->createStringVal();
		}

		return $this->stringVal;
	}

	protected function createStringVal()
	{
		$regex  = '/{(\w+)?(:(\d+))?}/';
		$fn     = [$this, 'replace'];
        $format = Config::get(BACKTRACE_FORMAT);

        $this->stringVal = preg_replace_callback($regex, $fn, $format);
	}

	protected function replace($matches)
	{
        $s = isset($this->values[$matches[1]]) ? $this->values[$matches[1]] : '';

        return isset($matches[3]) ? str_pad($s, (int) $matches[3], ' ', \STR_PAD_RIGHT) : $s;
	}
}
