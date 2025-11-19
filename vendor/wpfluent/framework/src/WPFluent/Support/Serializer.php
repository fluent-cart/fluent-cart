<?php

namespace FluentCart\Framework\Support;

use RuntimeException;
use InvalidArgumentException;

class Serializer
{
	/**
     * Serialize data into the specified format.
     *
     * @param mixed  $data   Data to serialize.
     * @param string $format Serialization format ('json' or 'php').
     * @param int|null $jsonOptions Options for JSON encoding
     * (default: JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).
     * @return string Serialized data.
     * @throws InvalidArgumentException If the format is unsupported.
     */
    public static function serialize($data, $format = 'json', $jsonOptions = null)
    {
    	$jsonOptions ??= JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
	    
	    if ($format === 'json') {
	        return json_encode($data, $jsonOptions);
	    } elseif ($format === 'php') {
	    	if (static::isSerialized($data)) {
	    		return $data;
	    	}
	        return serialize($data);
	    }

	    throw new InvalidArgumentException("Unsupported format: {$format}");
	}

    /**
     * Deserialize data from JSON or PHP serialized format.
     *
     * @param string $data   The serialized data.
     * @param mixed  $option The option:
     * 
     * - For JSON, true/1 (decode as array),
     * false/0 (decode as object), default false.
     * 
     * - For PHP serialized data, an array of allowed
     * classes or true/false to allow/disallow all classes.
     * 
     * @return mixed The deserialized data.
     * @throws RuntimeException If the data cannot be deserialized.
     */
    public static function deserialize($data, $option = false)
    {
        if (static::isJson($data)) {
            $decoded = json_decode($data, $option);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException(
                	'Invalid JSON: ' . json_last_error_msg()
                );
            }

            return $decoded;
        }

        if (static::isSerialized($data)) {
	        set_error_handler(function ($errno, $errstr) {
	            if (
	            	$errno === E_WARNING && strpos(
	            		$errstr, 'unserialize(): Error at offset'
	            	) !== false
	            ) {
	                throw new RuntimeException(
	                	'Invalid serialized data or potential security risk.'
	                );
	            }
	        });

	        try {
	            $result = unserialize($data, [
	                'allowed_classes' => $option
	            ]);

	            if ($result === false && $data !== serialize(false)) {
	                throw new RuntimeException(
	                    'Invalid serialized data or potential security risk.'
	                );
	            }

	            return $result;
	        } catch (RuntimeException $e) {
	            throw $e;
	        } finally {
	            restore_error_handler();
	        }
	    }

        throw new RuntimeException(
        	'Invalid data format: Neither JSON nor serialized PHP data.'
        );
    }

    /**
     * Check if the data is a valid JSON string.
     *
     * @param string $data Data to check.
     * @return bool True if the data is valid JSON.
     */
    public static function isJson($data)
    {
        json_decode($data);
        return (json_last_error() === JSON_ERROR_NONE);
    }

    /**
     * Check if the data is a serialized PHP string.
     *
     * @param string $data Data to check.
     * @return bool True if the data is serialized.
     */
    public static function isSerialized($data)
    {
        return is_serialized($data);
    }
}
