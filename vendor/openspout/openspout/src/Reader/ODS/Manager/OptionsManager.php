<?php

namespace FluentCart\OpenSpout\Reader\ODS\Manager;

use FluentCart\OpenSpout\Common\Manager\OptionsManagerAbstract;
use FluentCart\OpenSpout\Reader\Common\Entity\Options;
/**
 * ODS Reader options manager.
 */
class OptionsManager extends OptionsManagerAbstract
{
    /**
     * {@inheritdoc}
     */
    protected function getSupportedOptions()
    {
        return [Options::SHOULD_FORMAT_DATES, Options::SHOULD_PRESERVE_EMPTY_ROWS];
    }
    /**
     * {@inheritdoc}
     */
    protected function setDefaultOptions()
    {
        $this->setOption(Options::SHOULD_FORMAT_DATES, \false);
        $this->setOption(Options::SHOULD_PRESERVE_EMPTY_ROWS, \false);
    }
}
