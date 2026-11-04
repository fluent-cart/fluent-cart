<?php

namespace Tests\Unit\FluentPlayer;

use Codeception\Test\Unit;
use FluentCart\App\Modules\FluentPlayer\FluentPlayerBridge;

/**
 * The Unit suite boots without FluentPlayer, so every guard must report
 * "inactive" and every accessor must degrade to its safe default.
 */
class FluentPlayerBridgeTest extends Unit
{
    public function test_bridge_is_inactive_without_fluent_player(): void
    {
        $this->assertFalse(class_exists('\FluentPlayer\App\Services\MediaRenderer'), 'precondition: FluentPlayer is not loaded');
        $this->assertFalse(FluentPlayerBridge::isActive());
        $this->assertFalse(FluentPlayerBridge::hasPro());
    }

    public function test_defaults_when_inactive(): void
    {
        $this->assertSame('edit_others_posts', FluentPlayerBridge::authoringCapability());
        $this->assertSame('course', FluentPlayerBridge::defaultPresetSlug());
        $this->assertNull(FluentPlayerBridge::findVisibleMedia(12));
        $this->assertNull(FluentPlayerBridge::getMediaSummary(12));
        $this->assertSame('', FluentPlayerBridge::renderPlayer(12));
    }

    public function test_invalid_ids_short_circuit(): void
    {
        $this->assertNull(FluentPlayerBridge::findVisibleMedia(0));
        $this->assertNull(FluentPlayerBridge::findVisibleMedia(-3));
        $this->assertSame('', FluentPlayerBridge::renderPlayer(0));
    }
}
