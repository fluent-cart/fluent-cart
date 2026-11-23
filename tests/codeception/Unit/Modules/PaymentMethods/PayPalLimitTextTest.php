<?php

namespace Tests\Unit\Modules\PaymentMethods;

use Codeception\Test\Unit;
use FluentCart\App\Modules\PaymentMethods\PayPalGateway\PayPalHelper;

/**
 * PayPal field limits (product name 127, description 256) count characters.
 * A byte cut through a multibyte character makes json_encode() return false.
 */
class PayPalLimitTextTest extends Unit
{
    public function test_cut_inside_a_multibyte_character_stays_valid_utf8(): void
    {
        // "ó" spans bytes 250-251, so a 250-byte cut splits it.
        $text = str_repeat('a', 249) . 'ó' . str_repeat('ł', 30);

        $limited = PayPalHelper::limitText($text, 256);

        $this->assertNotFalse(json_encode($limited));
        $this->assertSame(256, mb_strlen($limited, 'UTF-8'));
        $this->assertSame('...', substr($limited, -3));
    }

    public function test_text_within_the_character_limit_is_unchanged(): void
    {
        // 200 characters, 400 bytes: over 256 bytes, under 256 characters.
        $text = str_repeat('ą', 200);

        $this->assertSame($text, PayPalHelper::limitText($text, 256));
    }

    public function test_long_name_is_cut_to_the_limit(): void
    {
        $limited = PayPalHelper::limitText(str_repeat('ж', 200), 127);

        $this->assertSame(127, mb_strlen($limited, 'UTF-8'));
        $this->assertNotFalse(json_encode($limited));
    }
}
