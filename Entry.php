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

class Entry
{
	public static function prepare($entry)
	{
		return static::processEntry($entry);
	}

	protected static function processEntry($entry, array &$processed = [])
	{
		if (is_scalar($entry))
		{
			return $entry;
		}

		if (is_resource($entry))
		{
			return ['___class_name' => 'Resource Type: ' . get_resource_type($entry)];
		}

		$processed[] = $entry;

		if (is_array($entry))
		{
			$fn = [__CLASS__, 'processEntry'];

			return array_map(function ($e) use (&$processed, $fn) {
				return $fn($e, $processed);
			}, $entry);
		}

		if (is_object($entry))
		{

			return static::processObject($entry, $processed);
		}
	}

	protected static function processObject($object, array &$processed)
	{
        $asArray = [];

        // first add the class name
        $asArray['___class_name'] = get_class($object);

        // loop through object vars
        $keys = [];

        $reflection = new \ReflectionClass($object);

        foreach ($reflection->getProperties() as $property)
		{
			$keys[] = $property->getName();

			$type = static::getPropertyKey($property);
            $property->setAccessible(true);
            $value = $property->getValue($object);

            // same instance as parent object
            if ($value === $object || in_array($value, $processed, true))
			{
                $value = 'recursion' . (is_object($value) ? ' - parent object [' . get_class($value) . ']' : '');
            }

            $asArray[$type] = static::processEntry($value, $processed);
        }

		foreach (get_object_vars($object) as $key => $value)
		{
            // Only concerned with new keys
			if (in_array($key, $keys))
			{
				continue;
			}

            // same instance as parent object
            if ($value === $object || in_array($value, $processed, true))
			{
                $value = 'recursion' . (is_object($value) ? ' - parent object [' . get_class($value) . ']' : '');
            }

			$keys[] = $key;
            $asArray[$key] = static::processEntry($value, $processed);
        }


        return $asArray;
	}

    /**
     * takes a reflection property and returns a nicely formatted key of the property name
     *
     * @param  \ReflectionProperty
     *
     * @return  string
     */
    protected static function getPropertyKey(\ReflectionProperty $property)
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
}
