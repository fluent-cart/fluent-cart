<?php

namespace FluentCart\App\Hooks\Handlers;

use FluentCart\App\App;

class FluentCartHandler
{
    protected $app = null;

    public function register()
    {
        add_action('init', function (){
            $this->app = App::getInstance();
            $this->init();
        });
    }

    public function init()
    {
        //$this->checkBuyNowBeforeLoadingTemplates();
        //TemplateFactory::init($this->app);
    }
}
