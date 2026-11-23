<?php

namespace FluentCart\App\Services\ShortCodeParser;

use FluentCart\App\Modules\MultiCurrency\Services\CurrencyContext;
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
            // Conditions come from $code: the cached reference may hold the same code without its |fallback.
            $parsedValue = $parser->parse(
                $parserReference['accessor'],
                $parserReference['template'],
                $this->evaluateCondition($code)
            );

        }

        // Parser returned null = accessor not recognised. Give the fallback
        // filter a chance so dynamic blocks can register handlers for codes
        // their parser doesn't know about (e.g. PDF item table).
        if ($parsedValue === null) {
            $fallback = apply_filters($this->hookPrefix . 'smartcode_fallback', $code, $data);
            if ($fallback !== $code) {
                $parsedValue = $fallback;
            }
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
    public function parseTemplate(string $template, array $data, bool $isHtml = false): string
    {
        // An order's amounts render in its own currency (multi-currency)
        return CurrencyContext::withCurrencyOf($data, function () use ($template, $data, $isHtml) {
            if ($isHtml) {
                return $this->parseHtmlTemplate($template, $data);
            }
            return $this->parseOrderTemplate($template, $data);
        });
    }

    private function parseOrderTemplate(string $template, array $data, ?callable $wrapValue = null): string
    {
        $templateParts = $this->getTemplateWrapper();

        $start = preg_quote($templateParts['start'], '/');
        $end = preg_quote($templateParts['end'], '/');

        $pattern = '/(' . $start . '|##)+(.*?)(' . $end . '|##)/';

        return preg_replace_callback($pattern, function ($matches) use ($data, $wrapValue) {
            if (empty($matches[2])) {
                return apply_filters($this->hookPrefix . 'smartcode_fallback', $matches[0], $data);
            }
            $code = trim($matches[2]);
            $transformed =  $this->parseCode($code, $data);

            if($transformed === $code) {
                return $matches[0];
            }

            return $wrapValue ? $wrapValue((string)$transformed) : $transformed;

        }, $template);
    }

    /**
     * Parse SmartCodes in HTML, escaping each value for its position.
     *
     * Buyer fields keep a bare `"` (sanitize_text_field), so a raw value inside an
     * attribute could close it and add an event handler. Text positions stay raw.
     */
    private function parseHtmlTemplate(string $template, array $data): string
    {
        // Random per call, so a buyer value cannot forge a marker.
        $marker = 'fctsc' . bin2hex(random_bytes(8));
        $markerPattern = '/' . $marker . '(\d+)e/';
        $values = [];

        $marked = $this->parseOrderTemplate($template, $data, function ($value) use ($marker, &$values) {
            $values[] = $value;
            return $marker . (count($values) - 1) . 'e';
        });

        if (!$values) {
            return $marked;
        }

        // The HTML API tokenizes for real; a regex split ends a tag at a quoted `>`.
        $processor = new \WP_HTML_Tag_Processor($marked);
        $urls = [];
        while ($processor->next_tag()) {
            foreach ((array)$processor->get_attribute_names_with_prefix('') as $name) {
                $this->fillAttribute($processor, $name, $marker, $markerPattern, $values, $urls);
            }
        }

        // URL attributes hold a fragment token, which esc_url() keeps; the real URL goes in only now.
        $html = $processor->get_updated_html();
        foreach ($urls as $index => $url) {
            $html = str_replace('="#' . $marker . 'u' . $index . '"', '="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"', $html);
        }
        $textMarkers = $this->markersInText($html, $markerPattern);

        $html = preg_replace_callback($markerPattern, function ($matches) use ($values, $textMarkers) {
            // Not in text, so still inside a tag (e.g. a duplicate attribute the API never exposes).
            if (!isset($textMarkers[$matches[0]])) {
                return '';
            }
            return $this->neutralizeSmartCodes($values[$matches[1]]);
        }, $html);

        // set_attribute() would encode the entity's `&`, so attributes carry a marker until now.
        return str_replace([$marker . 'o', $marker . 'h'], ['&#123;&#123;', '&#35;&#35;'], $html);
    }

    /**
     * Encode SmartCode openers in a value. Pro loop blocks parse a value, then the
     * mailer parses the body again; a buyer's `{{...}}` must stay literal text.
     */
    private function neutralizeSmartCodes(string $value): string
    {
        return str_replace(['{{', '##'], ['&#123;&#123;', '&#35;&#35;'], $value);
    }

    /**
     * Markers in text, comments or RCDATA, keyed by marker text. The HTML API
     * tokenizes as browsers do, so `<!-->` and `<textarea>` content are handled.
     */
    private function markersInText(string $html, string $markerPattern): array
    {
        $found = [];
        $scan = new \WP_HTML_Tag_Processor($html);
        while ($scan->next_token()) {
            if (preg_match_all($markerPattern, (string)$scan->get_modifiable_text(), $matches)) {
                foreach ($matches[0] as $markerText) {
                    $found[$markerText] = true;
                }
            }
        }

        return $found;
    }

    private function fillAttribute(\WP_HTML_Tag_Processor $processor, string $name, string $marker, string $markerPattern, array $values, array &$urls): void
    {
        // A SmartCode in an attribute name could write any attribute.
        if (strpos($name, $marker) !== false) {
            $processor->remove_attribute($name);
            return;
        }

        $template = $processor->get_attribute($name);
        if (!is_string($template) || strpos($template, $marker) === false) {
            return;
        }

        // set_attribute() takes plain text and escapes it, so decode entities first.
        $value = preg_replace_callback($markerPattern, function ($matches) use ($values) {
            return html_entity_decode(wp_strip_all_tags($values[$matches[1]]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }, $template);

        $value = str_replace(['{{', '##'], [$marker . 'o', $marker . 'h'], $value);

        if (!in_array($name, array_merge(wp_kses_uri_attributes(), ['xlink:href', 'ping']), true)) {
            if (!$processor->set_attribute($name, $value)) {
                $processor->remove_attribute($name);
            }
            return;
        }

        $scheme = $this->urlScheme($value);
        if ($scheme !== '') {
            // A scheme the template wrote before the first SmartCode is the host's choice.
            $hostScheme = $this->urlScheme(substr($template, 0, strpos($template, $marker)));
            if (in_array($scheme, ['javascript', 'vbscript'], true)
                || ($scheme !== $hostScheme && !in_array($scheme, wp_allowed_protocols(), true))) {
                $processor->remove_attribute($name);
                return;
            }
        }

        $urls[] = $value;
        if (!$processor->set_attribute($name, '#' . $marker . 'u' . (count($urls) - 1))) {
            $processor->remove_attribute($name);
        }
    }

    /**
     * Lowercase scheme of a URL as a browser reads it: entities fully decoded,
     * whitespace and control characters dropped. Empty when the URL has none.
     */
    private function urlScheme(string $url): string
    {
        for ($i = 0; $i < 5; $i++) {
            $decoded = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $url) {
                break;
            }
            $url = $decoded;
        }
        $url = preg_replace('/[\x00-\x20]+/', '', $url);

        return preg_match('/^([a-z][a-z0-9+.\-]*):/i', $url, $matches) ? strtolower($matches[1]) : '';
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
