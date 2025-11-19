<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php

use FluentCart\Framework\Validator\Rule;

Rule::add('sanitizeTextArea', \FluentCart\App\Http\Rules\SanitizeTextArea::class);
Rule::add('sanitizeText', \FluentCart\App\Http\Rules\SanitizeText::class);
Rule::add('maxPostCode', \FluentCart\App\Http\Rules\MaxPostCodeRule::class);
Rule::add('maxLength', \FluentCart\App\Http\Rules\MaxLengthRule::class);