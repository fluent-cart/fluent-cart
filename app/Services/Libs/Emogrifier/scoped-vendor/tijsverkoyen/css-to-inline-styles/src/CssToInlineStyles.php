<?php

namespace FluentEmogrifier\Vendor\TijsVerkoyen\CssToInlineStyles;

use FluentEmogrifier\Vendor\Symfony\Component\CssSelector\CssSelectorConverter;
use FluentEmogrifier\Vendor\Symfony\Component\CssSelector\Exception\ExceptionInterface;
use FluentEmogrifier\Vendor\TijsVerkoyen\CssToInlineStyles\Css\Processor;
use FluentEmogrifier\Vendor\TijsVerkoyen\CssToInlineStyles\Css\Property\Processor as PropertyProcessor;
use FluentEmogrifier\Vendor\TijsVerkoyen\CssToInlineStyles\Css\Property\Property;
use FluentEmogrifier\Vendor\TijsVerkoyen\CssToInlineStyles\Css\Rule\Processor as RuleProcessor;

class CssToInlineStyles
{
    private $cssConverter;

    public function __construct()
    {
        $this->cssConverter = new CssSelectorConverter();
    }

    public function convert($html, $css = null)
    {
        $document = $this->createDomDocumentFromHtml($html);
        $processor = new Processor();

        $rules = $processor->getRules(
            $processor->getCssFromStyleTags($html)
        );

        if ($css !== null) {
            $rules = $processor->getRules($css, $rules);
        }

        $document = $this->inline($document, $rules);

        return $this->getHtmlFromDocument($document);
    }

    public function inlineCssOnElement(\DOMElement $element, array $properties)
    {
        if (empty($properties)) {
            return $element;
        }

        $cssProperties = array();
        $inlineProperties = array();

        foreach ($this->getInlineStyles($element) as $property) {
            $inlineProperties[$property->getName()] = $property;
        }

        foreach ($properties as $property) {
            if (!isset($inlineProperties[$property->getName()])) {
                $cssProperties[$property->getName()] = $property;
            }
        }

        $rules = array();
        foreach (array_merge($cssProperties, $inlineProperties) as $property) {
            $rules[] = $property->toString();
        }
        $element->setAttribute('style', implode(' ', $rules));

        return $element;
    }

    public function getInlineStyles(\DOMElement $element)
    {
        $processor = new PropertyProcessor();

        return $processor->convertArrayToObjects(
            $processor->splitIntoSeparateProperties(
                $element->getAttribute('style')
            )
        );
    }

    protected function createDomDocumentFromHtml($html)
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $internalErrors = libxml_use_internal_errors(true);

        if (function_exists('mb_encode_numericentity')) {
            $html = mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');
        } else {
            // Fallback: ensure DOMDocument interprets the HTML as UTF-8
            // by prepending an XML encoding declaration
            $html = '<?xml encoding="UTF-8">' . $html;
        }

        $document->loadHTML($html);
        libxml_use_internal_errors($internalErrors);
        $document->formatOutput = true;

        return $document;
    }

    protected function getHtmlFromDocument(\DOMDocument $document)
    {
        $htmlElement = $document->documentElement;

        if ($htmlElement === null) {
            throw new \RuntimeException('Failed to get HTML from empty document.');
        }

        $html = $document->saveHTML($htmlElement);

        if ($html === false) {
            throw new \RuntimeException('Failed to get HTML from document.');
        }

        $html = trim($html);

        $document->removeChild($htmlElement);
        $doctype = $document->saveHTML();
        if ($doctype === false) {
            $doctype = '';
        }
        $doctype = trim($doctype);

        if ($doctype === '<!DOCTYPE html>') {
            $doctype = strtolower($doctype);
        }

        return $doctype . "\n" . $html;
    }

    protected function inline(\DOMDocument $document, array $rules)
    {
        if (empty($rules)) {
            return $document;
        }

        $propertyStorage = new \SplObjectStorage();
        $xPath = new \DOMXPath($document);

        usort($rules, array(RuleProcessor::class, 'sortOnSpecificity'));

        foreach ($rules as $rule) {
            try {
                $expression = $this->cssConverter->toXPath($rule->getSelector());
            } catch (ExceptionInterface $e) {
                continue;
            }

            $elements = $xPath->query($expression);

            if ($elements === false) {
                continue;
            }

            foreach ($elements as $element) {
                \assert($element instanceof \DOMElement);
                $propertyStorage[$element] = $this->calculatePropertiesToBeApplied(
                    $rule->getProperties(),
                    $propertyStorage->offsetExists($element) ? $propertyStorage[$element] : array()
                );
            }
        }

        foreach ($propertyStorage as $element) {
            $this->inlineCssOnElement($element, $propertyStorage[$element]);
        }

        return $document;
    }

    private function calculatePropertiesToBeApplied(array $properties, array $cssProperties): array
    {
        if (empty($properties)) {
            return $cssProperties;
        }

        foreach ($properties as $property) {
            if (isset($cssProperties[$property->getName()])) {
                $existingProperty = $cssProperties[$property->getName()];

                if ($existingProperty->isImportant() && !$property->isImportant()) {
                    continue;
                }

                $overrule = !$existingProperty->isImportant() && $property->isImportant();
                if (!$overrule) {
                    \assert($existingProperty->getOriginalSpecificity() !== null);
                    \assert($property->getOriginalSpecificity() !== null);
                    $overrule = $existingProperty->getOriginalSpecificity()->compareTo($property->getOriginalSpecificity()) <= 0;
                }

                if ($overrule) {
                    unset($cssProperties[$property->getName()]);
                    $cssProperties[$property->getName()] = $property;
                }
            } else {
                $cssProperties[$property->getName()] = $property;
            }
        }

        return $cssProperties;
    }
}
