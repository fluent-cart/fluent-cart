<?php

namespace Tests\Integration\Reports;

use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * Report screens in dark mode: empty-state graphics, grouped table headers and the Locations header actions.
 */
class ReportDarkModeStylesTest extends WPTestCase
{
    private function source(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 4) . '/resources/' . $path);
    }

    public function test_empty_state_graphics_get_dark_colors_and_the_fluentcart_size(): void
    {
        $base = $this->source('styles/tailwind/element-plus/base.scss');

        // Element Plus keys its dark empty colors to html.dark, which the FluentCart admin never sets
        $this->assertMatchesRegularExpression('/\.fluent_theme_dark\s+\.el-empty\s*\{[^}]*--el-empty-fill-color-0:/', $base);
        $this->assertMatchesRegularExpression('/\.el-empty\s*\{[^}]*--el-empty-image-width:\s*100px/', $base);
    }

    public function test_grouped_table_headers_get_the_dark_header_background(): void
    {
        $table = $this->source('styles/tailwind/element-plus/table.scss');

        $this->assertMatchesRegularExpression('/&\.is-group\s+tr\s+th\.el-table__cell\s*\{[^}]*dark:bg-dark-600/', $table);
        // A pinned column's edge in a bordered table draws with this variable
        $this->assertMatchesRegularExpression('/\.fluent_theme_dark\s+\.el-table--border\s*\{[^}]*--el-table-border:/', $table);
    }

    public function test_locations_header_actions_have_a_gap(): void
    {
        $locations = $this->source('admin/Modules/Reports/Locations/LocationsReport.vue');

        $this->assertMatchesRegularExpression('/class="fct-card-header-actions[^"]*\bgap-2\b/', $locations);
    }
}
