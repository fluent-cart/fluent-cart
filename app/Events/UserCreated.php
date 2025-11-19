<?php

namespace FluentCart\App\Events;


class UserCreated extends EventDispatcher
{
    public string $hook = 'fluent_cart/user_created';
    protected array $listeners = [

    ];

    public $user;

    public $userId;

    public $password;


    public function __construct($user, $userId, $password)
    {
        $this->user = $user;
        $this->userId = $userId;
        $this->password = $password;
    }

    public function toArray(): array
    {
        return [
            'user' => $this->user,
            'userId' => $this->userId,
            'password' => $this->password
        ];
    }

    public function getActivityEventModel()
    {
        return $this->user;
    }

}
