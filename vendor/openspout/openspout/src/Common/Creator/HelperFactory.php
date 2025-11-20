<?php

namespace FluentCart\OpenSpout\Common\Creator;

use FluentCart\OpenSpout\Common\Helper\EncodingHelper;
use FluentCart\OpenSpout\Common\Helper\FileSystemHelper;
use FluentCart\OpenSpout\Common\Helper\GlobalFunctionsHelper;
use FluentCart\OpenSpout\Common\Helper\StringHelper;
/**
 * Factory to create helpers.
 */
class HelperFactory
{
    /**
     * @return GlobalFunctionsHelper
     */
    public function createGlobalFunctionsHelper()
    {
        return new GlobalFunctionsHelper();
    }
    /**
     * @param string $baseFolderPath The path of the base folder where all the I/O can occur
     *
     * @return FileSystemHelper
     */
    public function createFileSystemHelper($baseFolderPath)
    {
        return new FileSystemHelper($baseFolderPath);
    }
    /**
     * @return EncodingHelper
     */
    public function createEncodingHelper(GlobalFunctionsHelper $globalFunctionsHelper)
    {
        return new EncodingHelper($globalFunctionsHelper);
    }
    /**
     * @return StringHelper
     */
    public function createStringHelper()
    {
        return new StringHelper();
    }
}
