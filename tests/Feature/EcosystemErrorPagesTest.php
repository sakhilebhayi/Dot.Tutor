<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EcosystemErrorPagesTest extends TestCase
{
    #[DataProvider('errorCodes')]
    public function test_error_page_renders_branded_content(int $code, string $expectedHeading): void
    {
        $response = $this->get("/_test-only-error-render/{$code}");

        $response->assertStatus($code);
        $response->assertSee($expectedHeading);
        $response->assertSee('Dot.Tutor', false);
        $response->assertSee('Skip to content');
        if ($code === 404) {
            // A missing page offers this site's routes, not the other Dot platforms (R-47 polish D-2016).
            $response->assertDontSee('the rest of the Dot Ecosystem', false)->assertSee('Find a tutor')->assertSee('Rooms by level');
        } else {
            $response->assertSee('the rest of the Dot Ecosystem', false);
        }
    }

    public static function errorCodes(): array
    {
        return [
            '404 not found' => [404, "We couldn't find that page"],
            '403 forbidden' => [403, "You don't have access to this"],
            '419 page expired' => [419, 'Your session timed out'],
            '429 too many requests' => [429, 'Slow down a little'],
            '500 server error' => [500, 'Something went wrong on our end'],
            '503 unavailable' => [503, "We'll be right back"],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['router']->get('/_test-only-error-render/{code}', function (int $code) {
            return response()->view("errors.{$code}", [], $code);
        });
    }
}
