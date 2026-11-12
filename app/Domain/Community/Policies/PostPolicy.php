<?php

namespace App\Domain\Community\Policies;

use App\Domain\Community\Models\Post;
use App\Domain\Community\Services\Posts;
use App\Models\User;

/** Who may see a post: anyone who can read its room, when the post is visible (or their own held post). */
class PostPolicy
{
    public function view(User $user, Post $post): bool
    {
        return app(Posts::class)->canSee($user, $post);
    }
}
