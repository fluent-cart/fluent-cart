<?php

namespace FluentCart\OpenSpout\Writer\CSV\Manager;

use FluentCart\OpenSpout\Common\Manager\OptionsManagerAbstract;
use FluentCart\OpenSpout\Writer\Common\Entity\Options;
/**
 * CSV Writer options manager.
 */
class OptionsManager extends OptionsManagerAbstract
{
    /**
     * {@inheritdoc}
     */
    protected function getSupportedOptions()
    {
        return [Options::FIELD_DELIMITER, Options::FIELD_ENCLOSURE, Options::SHOULD_ADD_BOM];
    }
    /**
     * {@inheritdoc}
     */
    protected function setDefaultOptions()
    {
        $this->setOption(Options::FIELD_DELIMITER, ',');
        $this->setOption(Options::FIELD_ENCLOSURE, '"');
        $this->setOption(Options::SHOULD_ADD_BOM, \true);
    }
}
