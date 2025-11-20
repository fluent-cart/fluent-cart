<?php

namespace FluentCart\OpenSpout\Writer\XLSX\Creator;

use FluentCart\OpenSpout\Common\Manager\OptionsManagerInterface;
use FluentCart\OpenSpout\Writer\Common\Creator\InternalEntityFactory;
use FluentCart\OpenSpout\Writer\Common\Creator\ManagerFactoryInterface;
use FluentCart\OpenSpout\Writer\Common\Entity\Options;
use FluentCart\OpenSpout\Writer\Common\Manager\RowManager;
use FluentCart\OpenSpout\Writer\Common\Manager\SheetManager;
use FluentCart\OpenSpout\Writer\Common\Manager\Style\StyleMerger;
use FluentCart\OpenSpout\Writer\XLSX\Manager\SharedStringsManager;
use FluentCart\OpenSpout\Writer\XLSX\Manager\Style\StyleManager;
use FluentCart\OpenSpout\Writer\XLSX\Manager\Style\StyleRegistry;
use FluentCart\OpenSpout\Writer\XLSX\Manager\WorkbookManager;
use FluentCart\OpenSpout\Writer\XLSX\Manager\WorksheetManager;
/**
 * Factory for managers needed by the XLSX Writer.
 */
class ManagerFactory implements ManagerFactoryInterface
{
    /** @var InternalEntityFactory */
    protected $entityFactory;
    /** @var HelperFactory */
    protected $helperFactory;
    public function __construct(InternalEntityFactory $entityFactory, HelperFactory $helperFactory)
    {
        $this->entityFactory = $entityFactory;
        $this->helperFactory = $helperFactory;
    }
    /**
     * @return WorkbookManager
     */
    public function createWorkbookManager(OptionsManagerInterface $optionsManager)
    {
        $workbook = $this->entityFactory->createWorkbook();
        $fileSystemHelper = $this->helperFactory->createSpecificFileSystemHelper($optionsManager, $this->entityFactory);
        $fileSystemHelper->createBaseFilesAndFolders();
        $xlFolder = $fileSystemHelper->getXlFolder();
        $sharedStringsManager = $this->createSharedStringsManager($xlFolder);
        $styleMerger = $this->createStyleMerger();
        $styleManager = $this->createStyleManager($optionsManager);
        $worksheetManager = $this->createWorksheetManager($optionsManager, $styleManager, $styleMerger, $sharedStringsManager);
        return new WorkbookManager($workbook, $optionsManager, $worksheetManager, $styleManager, $styleMerger, $fileSystemHelper, $this->entityFactory, $this);
    }
    /**
     * @return SheetManager
     */
    public function createSheetManager()
    {
        $stringHelper = $this->helperFactory->createStringHelper();
        return new SheetManager($stringHelper);
    }
    /**
     * @return RowManager
     */
    public function createRowManager()
    {
        return new RowManager();
    }
    /**
     * @return WorksheetManager
     */
    private function createWorksheetManager(OptionsManagerInterface $optionsManager, StyleManager $styleManager, StyleMerger $styleMerger, SharedStringsManager $sharedStringsManager)
    {
        $rowManager = $this->createRowManager();
        $stringsEscaper = $this->helperFactory->createStringsEscaper();
        $stringsHelper = $this->helperFactory->createStringHelper();
        return new WorksheetManager($optionsManager, $rowManager, $styleManager, $styleMerger, $sharedStringsManager, $stringsEscaper, $stringsHelper);
    }
    /**
     * @return StyleManager
     */
    private function createStyleManager(OptionsManagerInterface $optionsManager)
    {
        $styleRegistry = $this->createStyleRegistry($optionsManager);
        return new StyleManager($styleRegistry);
    }
    /**
     * @return StyleRegistry
     */
    private function createStyleRegistry(OptionsManagerInterface $optionsManager)
    {
        $defaultRowStyle = $optionsManager->getOption(Options::DEFAULT_ROW_STYLE);
        return new StyleRegistry($defaultRowStyle);
    }
    /**
     * @return StyleMerger
     */
    private function createStyleMerger()
    {
        return new StyleMerger();
    }
    /**
     * @param string $xlFolder Path to the "xl" folder
     *
     * @return SharedStringsManager
     */
    private function createSharedStringsManager($xlFolder)
    {
        $stringEscaper = $this->helperFactory->createStringsEscaper();
        return new SharedStringsManager($xlFolder, $stringEscaper);
    }
}
