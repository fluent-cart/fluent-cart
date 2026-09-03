<?php

namespace FluentEmogrifier\Vendor\TijsVerkoyen\CssToInlineStyles\Css\Rule;

use FluentEmogrifier\Vendor\Symfony\Component\CssSelector\Node\Specificity;
use FluentEmogrifier\Vendor\TijsVerkoyen\CssToInlineStyles\Css\Property\Property;

final class Rule
{
    private $selector;
    private $properties;
    private $specificity;
    private $order;

    public function __construct($selector, array $properties, Specificity $specificity, $order)
    {
        $this->selector = $selector;
        $this->properties = $properties;
        $this->specificity = $specificity;
        $this->order = $order;
    }

    public function getSelector()
    {
        return $this->selector;
    }

    public function getProperties()
    {
        return $this->properties;
    }

    public function getSpecificity()
    {
        return $this->specificity;
    }

    public function getOrder()
    {
        return $this->order;
    }
}
