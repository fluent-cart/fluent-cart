<?php

namespace FluentCart\OpenSpout\Writer\XLSX\Creator;

use FluentCart\OpenSpout\Common\Helper\Escaper;
use FluentCart\OpenSpout\Common\Helper\StringHelper;
use FluentCart\OpenSpout\Common\Manager\OptionsManagerInterface;
use FluentCart\OpenSpout\Writer\Common\Creator\InternalEntityFactory;
use FluentCart\OpenSpout\Writer\Common\Entity\Options;
use FluentCart\OpenSpout\Writer\Common\Helper\ZipHelper;
use FluentCart\OpenSpout\Writer\XLSX\Helper\FileSystemHelper;
/**
 * Factory for helpers needed by the XLSX Writer.
 */
class HelperFactory extends \FluentCart\OpenSpout\Common\Creator\HelperFactory
{
    /**
     * @return FileSystemHelper
     */
    public function createSpecificFileSystemHelper(OptionsManagerInterface $optionsManager, InternalEntityFactory $entityFactory)
    {
        $tempFolder = $optionsManager->getOption(Options::TEMP_FOLDER);
        $zipHelper = $this->createZipHelper($entityFactory);
        $escaper = $this->createStringsEscaper();
        return new FileSystemHelper($tempFolder, $zipHelper, $escaper);
    }
    /**
     * @return Escaper\XLSX
     */
    public function createStringsEscaper()
    {
        return new Escaper\XLSX();
    }
    /**
     * @return StringHelper
     */
    public function createStringHelper()
    {
        return new StringHelper();
    }
    /**
     * @return ZipHelper
     */
    private function createZipHelper(InternalEntityFactory $entityFactory)
    {
        return new ZipHelper($entityFactory);
    }
}
