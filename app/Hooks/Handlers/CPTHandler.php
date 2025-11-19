<?php

namespace FluentCart\App\Hooks\Handlers;

use FluentCart\App\App;

use FluentCart\App\CPT\FluentProducts;

class CPTHandler
{
    /*
    * Add all Custom Post Type classes here to
    * register all of your Custom Post Types.
    */

    public function register()
    {

     //   add_action('init', [$this, 'registerPostTypes']);
    }

    protected $customPostTypes = [
        FluentProducts::class
    ];

    public function registerPostTypes()
    {
        foreach ($this->customPostTypes as $cpt) {
          //  App::make($cpt)->registerPostType();
        }
    }
}
