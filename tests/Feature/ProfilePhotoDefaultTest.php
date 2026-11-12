<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** QA regression: the default profile picture is made here, never fetched from ui-avatars.com (a third party). */
class ProfilePhotoDefaultTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_default_picture_is_a_local_svg_with_initials(): void
    {
        $user = User::factory()->create(['name' => 'Thandeka Mokoena']);

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $user->profile_photo_url);
        $this->assertStringContainsString('>TM</text>', base64_decode(substr($user->profile_photo_url, strlen('data:image/svg+xml;base64,'))));
        $this->assertStringNotContainsString('ui-avatars', json_encode($user->toArray()));
        $this->actingAs($user)->get('/user/profile')->assertOk()->assertDontSee('ui-avatars.com');
    }
}
