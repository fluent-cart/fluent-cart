<?php

namespace FluentCart\App\Modules\Templating\BlockTemplates\TemplateParts;

class TemplatePartService
{
    public function register()
    {
        add_filter('get_block_templates', [$this, 'addTemplateParts'], 10, 3);
    }

    public function addTemplateParts($query_result, $query, $template_type)
    {
        if ($template_type !== 'wp_template_part') {
            return $query_result;
        }




    }
}
