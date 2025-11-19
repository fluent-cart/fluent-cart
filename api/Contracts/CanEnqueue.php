<?php

namespace FluentCart\Api\Contracts;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Str;

trait CanEnqueue
{
    protected ?string $slugPrefix = null;
    protected ?string $localizationKey = null;

    protected $isStylesLoaded = false;
    protected $isScriptsLoaded = false;


    protected function getScriptName(): string
    {
        return $this->generateEnqueueSlug() . '_js';
    }

    protected function getScripts(): array
    {
        return [];
    }

    public function enqueueScripts()
    {
        if ($this->isScriptsLoaded) {
            return;
        }

        $this->isScriptsLoaded = true;

        $localizeData = $this->localizeData();
        $localizeData['fluentCartRestVars'] = [
            'rest'    => Helper::getRestInfo(),
            'ajaxurl' => admin_url('admin-ajax.php')
        ];

        $this->enqueue_scripts($localizeData);


    }

    public function enqueue_scripts($localizeData = []): void
    {
        Vite::enqueueAllScripts(
            $this->getScripts(),
            $this->getScriptName(),
            $localizeData
        );
    }

    protected function getStyles(): array
    {
        return [];
    }

    protected function getStyleName(): string
    {
        return $this->generateEnqueueSlug() . '_css';
    }

    public function enqueueStyles()
    {
        if ($this->isStylesLoaded) {
            return;
        }

        $this->isStylesLoaded = true;

        $this->enqueue_styles();
    }

    public function enqueue_styles(): void
    {
        Vite::enqueueAllStyles(
            $this->getStyles(),
            $this->getStyleName()
        );
    }

    abstract protected function generateEnqueueSlug(): string;

    protected function getLocalizationKey(): string
    {
        return $this->localizationKey ??
            $this->generateEnqueueSlug() . '_data';
    }


    protected function localizeData(): array
    {
        return [];
    }

    public function enqueueAssets()
    {
        $this->enqueueScripts();
        $this->enqueueStyles();
        return $this;
    }
}
