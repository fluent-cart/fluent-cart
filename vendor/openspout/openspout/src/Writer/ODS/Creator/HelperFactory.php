<?php

namespace FluentCart\OpenSpout\Writer\ODS\Creator;

use FluentCart\OpenSpout\Common\Helper\Escaper;
use FluentCart\OpenSpout\Common\Helper\StringHelper;
use FluentCart\OpenSpout\Common\Manager\OptionsManagerInterface;
use FluentCart\OpenSpout\Writer\Common\Creator\InternalEntityFactory;
use FluentCart\OpenSpout\Writer\Common\Entity\Options;
use FluentCart\OpenSpout\Writer\Common\Helper\ZipHelper;
use FluentCart\OpenSpout\Writer\ODS\Helper\FileSystemHelper;
/**
 * Factory for helpers needed by the ODS Writer.
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
        return new FileSystemHelper($tempFolder, $zipHelper);
    }
    /**
     * @return Escaper\ODS
     */
    public function createStringsEscaper()
    {
        return new Escaper\ODS();
    }
    /**
     * @return StringHelper
     */
    public function createStringHelper()
    {
        return new StringHelper();
    }
    /**
     * @param InternalEntityFactory $entityFactory
     *
     * @return ZipHelper
     */
    private function createZipHelper($entityFactory)
    {
        return new ZipHelper($entityFactory);
    }
}
