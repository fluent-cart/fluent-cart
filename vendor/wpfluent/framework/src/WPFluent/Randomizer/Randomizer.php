<?php

namespace FluentCart\Framework\Randomizer;

use Exception;
use FluentCart\Framework\Support\InvalidArgumentException;

/**
 * An implementation of the Randomizer class.
 * @see https://www.php.net/manual/en/class.random-randomizer.php
 */
if (class_exists('Random\Randomizer')) {
    final class Randomizer
    {
        use GetStringTrait;

        private $randomizer;

        public function __construct()
        {
            // @phpstan-ignore-next-line
            $this->randomizer = new \Random\Randomizer();
        }

        public function getBytesFromString(string $string, int $length): string
        {
            return $this->randomizer->getBytesFromString($string, $length);   
        }

        public function __call($method, $args = [])
        {
            return $this->randomizer->{$method}(...$args);
        }
    }
} else {
    final class Randomizer
    {
        use GetStringTrait;

        private $engine;

        public function __construct()
        {
            $this->engine = $this->makeEngine();
        }

        public function getBytes(int $length)
        {
            $result = '';

            while (strlen($result) < $length) {
                $result .= $this->engine->generate();
            }

            return substr($result, 0, $length);
        }

        public function getBytesFromString(string $string, int $length)
        {
            if ($length === 0) {
                throw new InvalidArgumentException('Length cannot be zero.');
            }

            $sourceLength = strlen($string);
            
            if ($sourceLength === 0) {
                throw new InvalidArgumentException(
                    'Source string cannot be empty.'
                );
            }

            $result = [];

            for ($i = 0; $i < $length; $i++) {
                $index = $this->getInt(0, $sourceLength - 1);
                $result[] = $string[$index];
            }

            return implode('', $result);
        }

        public function getFloat(float $min, float $max)
        {
            if ($min >= $max) {
                throw new InvalidArgumentException(
                    'The minimum value must be less than the maximum value.'
                );
            }

            $randomFraction = $this->nextFloat();

            return $min + ($randomFraction * ($max - $min));
        }

        public function getInt(int $min, int $max)
        {
            $range = $max - $min + 1;
            return $min + random_int(0, $range - 1);
        }

        public function nextFloat()
        {
            return random_int(0, PHP_INT_MAX) / (PHP_INT_MAX + 1);
        }

        public function nextInt()
        {
            return random_int(0, PHP_INT_MAX);
        }

        public function pickArrayKeys(array $array, int $num)
        {
            if ($num > count($array)) {
                throw new InvalidArgumentException(
                    'Cannot pick more keys than the array size.'
                );
            }

            $pickedKeys = [];

            $keys = array_keys($array);

            while (count($pickedKeys) < $num) {
                $index = random_int(0, count($keys) - 1);
                $pickedKeys[] = $keys[$index];
                array_splice($keys, $index, 1);
            }

            return $pickedKeys;
        }

        public function shuffleArray(array $array)
        {
            $count = count($array);

            if ($count < 2) {
                return $array;
            }

            $originalArray = $array;

            // Shuffle and check if the result is different
            do {
                // Perform the Fisher-Yates shuffle
                for ($i = $count - 1; $i > 0; $i--) {
                    $j = random_int(0, $i);
                    [$array[$i], $array[$j]] = [$array[$j], $array[$i]];
                }
            } while ($array === $originalArray);

            return $array;
        }

        public function shuffleBytes(string $bytes)
        {
            $array = str_split($bytes);
            $shuffled = $this->shuffleArray($array);
            return implode('', $shuffled);
        }

        private function makeEngine()
        {
            return new class {
                public function generate() {
                    $length = 32;

                    if (function_exists('random_bytes')) {
                        return random_bytes($length);
                    }

                    if (function_exists('openssl_random_pseudo_bytes')) {
                        return openssl_random_pseudo_bytes($length);
                    }

                    throw new Exception('No secure random source available.');
                }
            };
        }
    }
}
