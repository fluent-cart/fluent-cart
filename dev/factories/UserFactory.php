<?php

namespace Dev\Factories;

use Dev\Factories\Core\Factory;
use FluentCart\App\Models\User;

class UserFactory extends Factory
{
	// Required to use Factory::create method
	protected static $model = User::class;

public function defination($data = [])
 {
  return [
   'user_nicename' => $data['nicename'] ?? $this->fake->name,
   'user_login' => $data['user_login'] ?? $this->fake->firstName,
   'user_email' => $data['user_email'] ?? $this->fake->email,
   'user_pass' => wp_hash_password($data['password'] ?? 12345678),
  ];
 }
}
