<?php

namespace Dev\Test\Inc;

trait UsersAndPostsSeeder
{
	public function seedUsersAndPosts($count = 10)
	{
		$users = $this->factory->user->count($count)->create();

		foreach ($users as $user) {

			foreach (range(1, 2) as $i) {
				$post = $user->posts()->create(
					$this->factory->post->make($user)
				);

				foreach (range(1, 2) as $j) {
					wp_insert_comment([
						'user_id' => $user->ID,
						'comment_post_ID' => $post->ID,
						'comment_content' => 'Comment - ' . $j . ' of user - ' . $user->ID,
					]);
				}
			}
		}
	}
}
