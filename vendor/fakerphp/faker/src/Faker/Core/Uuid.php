<?php

namespace FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Core;

use FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Extension;
use FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Extension\NumberExtension;
use FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Extension\UuidExtension;
/**
 * @experimental This class is experimental and does not fall under our BC promise
 */
final class Uuid implements \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Extension\UuidExtension
{
    private \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Extension\NumberExtension $numberExtension;
    public function __construct(\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Extension\NumberExtension $numberExtension = null)
    {
        $this->numberExtension = $numberExtension ?: new \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Core\Number();
    }
    public function uuid3(): string
    {
        // fix for compatibility with 32bit architecture; each mt_rand call is restricted to 32bit
        // two such calls will cause 64bits of randomness regardless of architecture
        $seed = $this->numberExtension->numberBetween(0, 2147483647) . '#' . $this->numberExtension->numberBetween(0, 2147483647);
        // Hash the seed and convert to a byte array
        $val = md5($seed, true);
        $byte = array_values(unpack('C16', $val));
        // extract fields from byte array
        $tLo = $byte[0] << 24 | $byte[1] << 16 | $byte[2] << 8 | $byte[3];
        $tMi = $byte[4] << 8 | $byte[5];
        $tHi = $byte[6] << 8 | $byte[7];
        $csLo = $byte[9];
        $csHi = $byte[8] & 0x3f | 1 << 7;
        // correct byte order for big edian architecture
        if (pack('L', 0x6162797a) == pack('N', 0x6162797a)) {
            $tLo = ($tLo & 0xff) << 24 | ($tLo & 0xff00) << 8 | ($tLo & 0xff0000) >> 8 | ($tLo & 0xff000000) >> 24;
            $tMi = ($tMi & 0xff) << 8 | ($tMi & 0xff00) >> 8;
            $tHi = ($tHi & 0xff) << 8 | ($tHi & 0xff00) >> 8;
        }
        // apply version number
        $tHi &= 0xfff;
        $tHi |= 3 << 12;
        // cast to string
        return sprintf('%08x-%04x-%04x-%02x%02x-%02x%02x%02x%02x%02x%02x', $tLo, $tMi, $tHi, $csHi, $csLo, $byte[10], $byte[11], $byte[12], $byte[13], $byte[14], $byte[15]);
    }
}