<?php

namespace FluentCart\App\Services\ShortCodeParser\Parsers;

use FluentCart\Framework\Support\Arr;

class UserParser extends BaseParser
{
    private $user;
    private $userId;

    public function __construct($data)
    {
        $this->userId = Arr::get($data, 'user_id', null);
        $this->setUser();
        parent::__construct($data);
    }

    protected array $methodMap = [
        'admin_email' => 'getAdminEmail',
        'site_url' => 'getSiteUrl',
        'site_name' => 'getSiteName',
    ];

    protected function setUser()
    {
        $this->user = get_user_by('ID', $this->userId) ?: null;
    }

    public function parse($accessor = null, $code = null): ?string
    {
        return $this->get($accessor,$code);
    }

    public function getUserId()
    {
        return $this->userId;
    }

    public function getUserFirstName()
    {
        return $this->getDataFromUser('user_firstname');
    }

    public function getUserLastName()
    {
        return $this->getDataFromUser('user_lastname');
    }

    public function getUserDisplayName()
    {
        return $this->getDataFromUser('display_name');
    }
    public function getUserEmail()
    {
        return $this->getDataFromUser('user_url');
    }

    private function getDataFromUser(string $key)
    {
        if (empty($key) || empty($this->user)) {
            return '';
        }

        return $this->user->{$key};
    }
}
