<?php

namespace FluentCart\App\Services\ShortCodeParser\Contracts;
interface ParserContract
{
    public function parse($accessor = null, $template = null): ?string;
}
