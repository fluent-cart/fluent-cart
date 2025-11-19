<?php

namespace FluentCart\App\Services\ShortCodeParser;

use FluentCart\App\App;
use FluentCart\Framework\Container\Contracts\BindingResolutionException;
use FluentCart\Framework\Foundation\Application;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Collection;

class SmartCodeParser
{

    use ValueTransformer;

    /**
     * The application instance.
     *
     * @var Application
     */
    protected Application $app;

    /**
     * Configuration instance.
     *
     * @var ?Collection
     */
    protected ?Collection $config;

    /**
     * Hook Prefix.
     *
     * @var String
     */
    protected string $hookPrefix;

    /**
     * SmartCodeParser constructor.
     */
    public function __construct()
    {
        $application = App::getInstance();
        $pluginConfig = $application->make('config')->get('shortcode');
        $this->app = $application;
        $this->config = new Collection($pluginConfig);
        $this->hookPrefix = App::config()->get('hook_prefix');
    }

    /**
     * Parses the given code using the appropriate parser class.
     *
     * @param string $code
     * @param array $data
     * @return string
     * @throws BindingResolutionException
     */
    public function parse(string $code, array $data): ?string
    {
        return $this->parseCode($code, $data);
    }

    /**
     * Parses the given code using the appropriate parser class.
     *
     * @param string $code
     * @param mixed $data
     * @return string
     * @throws BindingResolutionException
     */
    public function parseCode(string $code, $data): ?string
    {
        $code = trim($code);

        $explodedCode = explode('.', $code);
        if (count($explodedCode) <= 1) {
            return apply_filters($this->hookPrefix . 'smartcode_fallback', $code, $data);
        }
        $parserReference = $this->getParseHandlerReference($code);
        $parseHandler = Arr::get($parserReference, 'handler');

        if (!$parseHandler) {
            return apply_filters($this->hookPrefix . 'smartcode_fallback', $code, $data);
        }

        $parser = $this->app->makeWith($parseHandler, ['data' => $data]);

        $parsedValue = "";

        if (method_exists($parser, 'parse')) {
            $parsedValue = $parser->parse(
                $parserReference['accessor'],
                $parserReference['template'],
                $this->evaluateCondition($parserReference['template'])
            );

        }

        return $this->transform($parsedValue, $code, $data);
    }

    /**
     * Retrieves the appropriate parser class for the given code.
     *
     * @param string $code
     * @return ?string
     */
    public function getParserClass(string $code): ?string
    {
        $parsers = $this->config->get('parsers');
        return Arr::get($parsers, $code);
    }

    /**
     * Retrieves the parse handler reference for the given template.
     *
     * @param string $template
     * @return array
     */
    public function getParseHandlerReference(string $template): array
    {
        static $reference = [];

        $parserData = [
            'parser'   => null,
            'accessor' => null,
            'template' => $template,
            'handler'  => null,
        ];

        $templateCode = $this->getReference($template);

        if (!isset($reference[$templateCode])) {
            if (str_contains($templateCode, '.')) {
                $parsed = explode($this->config->get('parser_separator'), $templateCode);
                $parserData['parser'] = $parsed[0];
                $parserData['accessor'] = implode($this->config->get('parser_separator'), array_slice($parsed, 1));
            } else {
                //If no parser is found, GlobalParser class will be called if exist
                $parserData['parser'] = 'global';
                $parserData['accessor'] = $templateCode;
            }

            $parserData['handler'] = $this->getParserClass($parserData['parser']);
            $reference[$template] = $parserData;
        }
        if (!isset($reference[$template]) && isset($reference[$templateCode])) {
            $reference[$template] = $reference[$templateCode];
        }

        return $reference[$template];
    }

    /**
     * Retrieves the reference for the given template.
     *
     * @param string $template
     * @return string
     */
    public function getReference(string $template): string
    {
        $template = explode('|', $template);
        $template = $template [0];
        $templateString = $template;

        $templateReferences = $this->config->get('template_references');

        if (isset($templateReferences[$template])) {
            $templateString = $templateReferences[$template];
        }

        $parserReferences = $this->config->get('parser_references');

        foreach ($parserReferences as $reference => $fields) {
            if (in_array($templateString, $fields)) {
                $templateString = $reference . $this->config->get('parser_separator') . $templateString;

                break;
            }
        }

        return $templateString;
    }

    /**
     * Parses the template with the given data.
     *
     * @param string $template
     * @param array $data
     * @return string
     * @throws BindingResolutionException
     */
    public function parseTemplate(string $template, array $data): string
    {
        $templateParts = $this->getTemplateWrapper();


        $start = preg_quote($templateParts['start'], '/');
        $end = preg_quote($templateParts['end'], '/');

        $pattern = '/(' . $start . '|##)+(.*?)(' . $end . '|##)/';
//        $pattern = '/(' . $start . '|##)((?:[^' . $end[0] . '#]|(?R))*)(' . $end . '|##)/';


        return preg_replace_callback($pattern, function ($matches) use ($data) {
            if (empty($matches[2])) {
                return apply_filters($this->hookPrefix . 'smartcode_fallback', $matches[0], $data);
            }
            $code = trim($matches[2]);
            $transformed =  $this->parseCode($code, $data);

            if($transformed === $code) {
                return $matches[0];
            }

            return $transformed;

        }, $template);
    }

    /**
     * Configures the parser with new settings.
     * @return SmartCodeParser
     */
    public static function make(): SmartCodeParser
    {
        return new static();
    }

    /**
     * Retrieves the template wrapper parts.
     *
     * @return array
     */
    public function getTemplateWrapper(): array
    {
        preg_match('/^(.*):template:(.*)$/', $this->config->get('template_string'), $matches);

        return [
            'start' => $matches[1],
            'end'   => $matches[2],
        ];
    }

    /**
     * Wraps the given code with the template wrapper.
     *
     * @param string $code
     * @return string
     */
    public function wrapSmartCode(string $code): string
    {
        $wrapper = $this->getTemplateWrapper();
        return $wrapper['start'] . $code . $wrapper['end'];
    }
}
