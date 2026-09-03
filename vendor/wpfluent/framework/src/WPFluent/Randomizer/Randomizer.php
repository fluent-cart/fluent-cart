<?php

namespace FluentCart\Framework\Randomizer;

use Exception;
use FluentCart\Framework\Support\InvalidArgumentException;

/**
 * An implementation of the Randomizer class.
 * @see https://www.php.net/manual/en/class.random-randomizer.php
 */
if (class_exists('Random\Randomizer')) {
    /**
     * Wrapper around the native PHP 8.2+ Random\Randomizer.
     *
     * @method string getBytes(int $length)
     * @method int    getInt(int $min, int $max)
     * @method float  getFloat(float $min, float $max)
     * @method int    nextInt()
     * @method float  nextFloat()
     * @method array  pickArrayKeys(array $array, int $num)
     * @method array  shuffleArray(array $array)
     * @method string shuffleBytes(string $string)
     */
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
            // Match native Random\Randomizer::getFloat — allow $min == $max
            // (returns $min); only reject $min > $max.
            if ($min > $max) {
                $this->throwInvalidRange(
                    'Argument #1 ($min) must be less than or equal to argument #2 ($max).'
                );
            }

            // When $min == $max, the formula naturally returns $min
            // (anything * 0 == 0), so no special case needed.
            return $min + ($this->nextFloat() * ($max - $min));
        }

        public function getInt(int $min, int $max)
        {
            // Match native Random\Randomizer::getInt — throw on $min > $max
            // and delegate to random_int (which already handles the range
            // correctly, rejection-sampled, no manual offset arithmetic).
            if ($min > $max) {
                $this->throwInvalidRange(
                    'Argument #1 ($min) must be less than or equal to argument #2 ($max).'
                );
            }

            return random_int($min, $max);
        }

        /**
         * Throw the same exception type the native Random\Randomizer would
         * throw on bad range input. \ValueError landed in PHP 8.0; on older
         * runtimes fall back to InvalidArgumentException so we always raise
         * something meaningful.
         */
        private function throwInvalidRange(string $message)
        {
            if (class_exists('ValueError', false)) {
                throw new \ValueError($message);
            }

            throw new InvalidArgumentException($message);
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
            $count = count($array);

            if ($num < 1 || $num > $count) {
                throw new InvalidArgumentException(
                    'Argument #2 ($num) must be between 1 and the number of elements in argument #1 ($array).'
                );
            }

            // Match native Random\Randomizer::pickArrayKeys — picked keys
            // are returned in their ORIGINAL position order in the array,
            // not in pick order. Strategy: build an index list, partial
            // Fisher-Yates to select $num indices, then sort those indices
            // and map back to keys. Runs in O(n) time.
            $keys = array_keys($array);
            $indices = range(0, $count - 1);

            // Partial Fisher-Yates: only shuffle the first $num positions.
            for ($i = 0; $i < $num; $i++) {
                $j = random_int($i, $count - 1);
                [$indices[$i], $indices[$j]] = [$indices[$j], $indices[$i]];
            }

            // Take the picked indices, sort to restore original order.
            $picked = array_slice($indices, 0, $num);
            sort($picked);

            return array_map(static function ($i) use ($keys) {
                return $keys[$i];
            }, $picked);
        }

        public function shuffleArray(array $array)
        {
            $count = count($array);

            if ($count < 2) {
                return $array;
            }

            // Match native Random\Randomizer::shuffleArray — a single
            // unbiased Fisher-Yates pass. Do NOT loop until the result
            // differs from the input; that biases the distribution and
            // can infinite-loop on tiny arrays. A fair shuffle MUST be
            // allowed to occasionally produce the original order.
            for ($i = $count - 1; $i > 0; $i--) {
                $j = random_int(0, $i);
                [$array[$i], $array[$j]] = [$array[$j], $array[$i]];
            }

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
