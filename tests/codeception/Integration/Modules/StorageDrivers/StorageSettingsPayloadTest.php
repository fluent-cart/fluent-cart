<?php

namespace Tests\Integration\Modules\StorageDrivers;

use FluentCart\App\Modules\StorageDrivers\Local\Local;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * A plugin's own Vue settings screen (storage_<driver>_settings) needs the driver payload
 * even when the driver serves no runtime template.
 */
class StorageSettingsPayloadTest extends WPTestCase
{
    public function test_settings_response_carries_the_driver_payload_without_a_template(): void
    {
        $response = (new Local())->globalFields([], []);

        $this->assertArrayNotHasKey('template', $response);
        $this->assertSame('local', $response['template_payload']['driver']);
        $this->assertSame($response['settings'], $response['template_payload']['settings']);
    }
}
