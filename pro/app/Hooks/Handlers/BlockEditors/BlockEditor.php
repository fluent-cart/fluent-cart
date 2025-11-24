<?php

namespace FluentCartPro\App\Hooks\Handlers\BlockEditors;

use FluentCart\App\Hooks\Handlers\BlockEditors\BlockEditor as BaseBlockEditor;
use FluentCartPro\App\Utils\Enqueuer\Enqueue;
use FluentCartPro\App\Utils\Enqueuer\Vite;

class BlockEditor extends BaseBlockEditor
{

    public function render(array $shortCodeAttribute, $block = null)
    {

    }

    public function enqueue_scripts($localizeData = []): void
    {
        Enqueue::enqueueAllScripts(
            $this->getScripts(),
            $this->getScriptName(),
            $localizeData
        );
    }

    public function enqueue_styles(): void
    {
        Enqueue::enqueueAllStyles(
            $this->getStyles(),
            $this->getStyleName()
        );
    }
}
