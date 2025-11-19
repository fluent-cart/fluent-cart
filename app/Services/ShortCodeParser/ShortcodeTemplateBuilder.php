<?php

namespace FluentCart\App\Services\ShortCodeParser;

use FluentCart\Framework\Container\Contracts\BindingResolutionException;
use FluentCart\Framework\Foundation\App;
use FluentCart\Framework\Support\Arr;

class ShortcodeTemplateBuilder
{
    protected ?SmartCodeParser $parser;

    /**
     * TemplateBuilder constructor.
     */
    public function __construct()
    {
        $this->parser = SmartCodeParser::make();
    }

    /**
     * Factory method to create an instance of TemplateBuilder and parse the template.
     *
     * @param string $template
     * @param array $data
     * @return string
     * @throws BindingResolutionException
     */
    public static function make(string $template, array $data = []): string
    {
        $instance = new static();
        return $instance->parser->parseTemplate($template, $data);
    }

    /**
     * Factory method to create an instance of TemplateBuilder and parse the template array.
     *
     * @param array $templates
     * @param array $data
     * @return array
     * @throws BindingResolutionException
     */
    public static function makeFromTemplatesArray(array $templates, array $data): array
    {
        foreach ($templates as $templateKey => $template) {
            if (is_array($template)) {
                $templates[$templateKey] = self::makeFromTemplatesArray($template, $data);
            } elseif (is_string($template)) {
                $templates[$templateKey] = static::make($template, $data);
            }
        }
        return $templates;
    }

    /**
     * Factory method to create an instance of TemplateBuilder and parse the template.
     *
     * @param array<mixed, string> $templates
     * @param array $data
     * @return string
     * @throws BindingResolutionException
     */
    public static function makeFromTemplates(array $templates, array $data): string
    {
        $instance = new static();
        $parsedTemplate = "";
        foreach ($templates as $template) {
            $parsedTemplate .= $instance->parser->parseTemplate($template, $data);
        }
        return $parsedTemplate;
    }
}