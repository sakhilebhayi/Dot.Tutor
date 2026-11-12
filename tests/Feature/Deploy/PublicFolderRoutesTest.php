<?php

namespace Tests\Feature\Deploy;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Apache serves a real folder before Laravel: public/.htaccess only rewrites to index.php when the path is neither a
 * file nor a folder (`RewriteCond %{REQUEST_FILENAME} !-d`). A page route whose whole path is a folder under public/
 * therefore never reaches the app on the live host (403, directory listing forbidden), and the deploy also links those
 * folders into the docroot. Found by the R-47 signed-in smoke: GET /classroom collided with public/classroom/ (D-1903).
 */
class PublicFolderRoutesTest extends TestCase
{
    public function test_no_page_route_is_shadowed_by_a_folder_in_public(): void
    {
        $folders = collect(File::directories(public_path()))->map(fn (string $dir) => basename($dir))
            ->reject(fn (string $name) => in_array($name, ['build', 'hot'], true));

        $shadowed = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn (Route $route) => in_array('GET', $route->methods(), true))
            ->map(fn (Route $route) => trim($route->uri(), '/'))
            ->filter(fn (string $uri) => $uri !== '' && ! str_contains($uri, '{'))
            ->filter(fn (string $uri) => is_dir(public_path($uri)))
            ->values()->all();

        $this->assertSame([], $shadowed, 'These routes are folders under public/ and would never reach Laravel on Apache: '.implode(', ', $shadowed).' (public folders: '.$folders->implode(', ').')');
    }
}
