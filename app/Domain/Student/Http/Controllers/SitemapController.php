<?php

namespace App\Domain\Student\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\TutorProfile;
use Illuminate\Http\Response;

/**
 * sitemap.xml (R-47 polish D-2014): the public pages and every approved tutor's storefront, nothing behind sign-in.
 * Storefronts are listed at their canonical /t/{id} address (the /u/{handle} share link redirects there).
 */
class SitemapController extends Controller
{
    /** Search engines read up to 50,000 addresses per file; the storefronts are capped well below that. */
    private const MAX_STOREFRONTS = 10000;

    public function __invoke(): Response
    {
        $urls = [
            ['loc' => url('/'), 'lastmod' => null],
            ['loc' => route('marketplace.find'), 'lastmod' => null],
            ['loc' => route('terms.show'), 'lastmod' => null],
            ['loc' => route('policy.show'), 'lastmod' => null],
            ['loc' => route('cookies'), 'lastmod' => null],
        ];

        TutorProfile::query()->where('status', 'approved')->orderBy('id')->limit(self::MAX_STOREFRONTS)
            ->get(['id', 'updated_at'])
            ->each(function (TutorProfile $profile) use (&$urls) {
                $urls[] = ['loc' => route('marketplace.tutors.show', $profile), 'lastmod' => $profile->updated_at?->toDateString()];
            });

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($urls as $u) {
            $xml .= '  <url><loc>'.htmlspecialchars($u['loc'], ENT_XML1).'</loc>'.($u['lastmod'] ? '<lastmod>'.$u['lastmod'].'</lastmod>' : '').'</url>'."\n";
        }
        $xml .= '</urlset>'."\n";

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600']);
    }
}
