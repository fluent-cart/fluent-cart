<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php

/**
 * This is an example for seeding. In the given code below, the CustomUserFactory is
 * reseting the primary key starting from 1, creating 5 users and looping each user
 * from the collection and creating 5 posts for each user using the hasMany relation.
 */

/*
use Dev\Factories\{
	CustomUserFactory,
	CustomPostFactory
};

CustomUserFactory::resetPrimaryKey()->count(5)->create()->each(function($user) {
	$user->posts()->createMany(
		CustomPostFactory::resetPrimaryKey()->count(5)->make()
	);
});
*/