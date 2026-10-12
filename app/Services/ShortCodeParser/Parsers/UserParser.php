<?php

namespace FluentCart\App\Services\ShortCodeParser\Parsers;

use FluentCart\Framework\Support\Arr;

class UserParser extends BaseParser
{
    private $user;
    private $userId;

    public function __construct($data)
    {
        if (Arr::has($data, 'user_id')) {
            $uId = Arr::get($data, 'user_id', null);
        } else {
            $uId = Arr::get($data, 'customer.user_id', null);
        }

        $this->userId = $uId;
        $this->setUser();
        parent::__construct($data);
    }

    protected array $methodMap = [
        'admin_email' => 'getAdminEmail',
        'site_url' => 'getSiteUrl',
        'site_name' => 'getSiteName',
    ];

    // Anything unlisted would fall through to Arr::get() on the whole parser data
    // and could walk into the order, its customer and their WP user.
    protected array $allowedAccessors = [
        'ID', 'id', 'first_name', 'last_name', 'display_name', 'email', 'user_email', 'user_login',
    ];

    protected function setUser()
    {
        $this->user = get_user_by('ID', $this->userId) ?: null;
    }

    public function parse($accessor = null, $code = null): ?string
    {
        if (!in_array($accessor, $this->allowedAccessors, true)) {
            return '';
        }

        return $this->get($accessor, $code);
    }

    public function getID()
    {
        return $this->userId;
    }

    public function getFirstName()
    {
        return $this->getDataFromUser('first_name');
    }

    public function getLastName()
    {
        return $this->getDataFromUser('last_name');
    }

    public function getDisplayName()
    {
        return $this->getDataFromUser('display_name');
    }
    public function getEmail()
    {
        return $this->getDataFromUser('user_email');
    }

    public function getUserEmail()
    {
        return $this->getDataFromUser('user_email');
    }

    // Half of the login credentials: admin previews only, never customer-facing renders.
    public function getUserLogin()
    {
        return current_user_can('manage_options') ? $this->getDataFromUser('user_login') : '';
    }

    private function getDataFromUser(string $key)
    {
        if (empty($key) || empty($this->user)) {
            return '';
        }

        if ($key === 'first_name' || $key === 'last_name') {
            return get_user_meta($this->userId, $key, true) ?: '';
        }

        return $this->user->{$key} ?? '';
    }
}
