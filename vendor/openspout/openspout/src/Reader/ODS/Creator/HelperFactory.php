<?php

namespace FluentCart\OpenSpout\Reader\ODS\Creator;

use FluentCart\OpenSpout\Reader\ODS\Helper\CellValueFormatter;
use FluentCart\OpenSpout\Reader\ODS\Helper\SettingsHelper;
/**
 * Factory to create helpers.
 */
class HelperFactory extends \FluentCart\OpenSpout\Common\Creator\HelperFactory
{
    /**
     * @param bool $shouldFormatDates Whether date/time values should be returned as PHP objects or be formatted as strings
     *
     * @return CellValueFormatter
     */
    public function createCellValueFormatter($shouldFormatDates)
    {
        $escaper = $this->createStringsEscaper();
        return new CellValueFormatter($shouldFormatDates, $escaper);
    }
    /**
     * @param InternalEntityFactory $entityFactory
     *
     * @return SettingsHelper
     */
    public function createSettingsHelper($entityFactory)
    {
        return new SettingsHelper($entityFactory);
    }
    /**
     * @return \OpenSpout\Common\Helper\Escaper\ODS
     */
    public function createStringsEscaper()
    {
        // @noinspection PhpUnnecessaryFullyQualifiedNameInspection
        return new \FluentCart\OpenSpout\Common\Helper\Escaper\ODS();
    }
}
