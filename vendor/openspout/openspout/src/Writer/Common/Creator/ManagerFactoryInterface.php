<?php

namespace FluentCart\OpenSpout\Writer\Common\Creator;

use FluentCart\OpenSpout\Common\Manager\OptionsManagerInterface;
use FluentCart\OpenSpout\Writer\Common\Manager\SheetManager;
use FluentCart\OpenSpout\Writer\Common\Manager\WorkbookManagerInterface;
/**
 * Interface ManagerFactoryInterface.
 */
interface ManagerFactoryInterface
{
    /**
     * @return WorkbookManagerInterface
     */
    public function createWorkbookManager(OptionsManagerInterface $optionsManager);
    /**
     * @return SheetManager
     */
    public function createSheetManager();
}
