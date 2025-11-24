<?php

namespace FluentCartPro\App\Http\Controllers;

use FluentCartPro\App\Models\User;

class UserController extends Controller
{
	public function users()
	{
		return User::all();
	}
}