<?php

namespace FluentCart\App\Services\ShortCodeParser\Parsers;

class WPParser extends BaseParser
{
    protected array $methodMap = [
//        'admin_email' => 'getAdminEmail',
        'site_url' => 'getSiteUrl',
        'url' => 'getSiteUrl',
//        'site_name'   => 'getSiteName',
    ];

    public function parse($accessor = null, $code = null): ?string
    {
        return $this->get($accessor, $code);
    }


    public function getAdminEmail(): ?string
    {
        return get_bloginfo('admin_email');
    }

    public function getSiteUrl(): ?string
    {
        return get_bloginfo('url');
    }

    public function getSiteName(): ?string
    {
        return get_bloginfo('name');
    }
}
