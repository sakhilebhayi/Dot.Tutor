<?php

namespace Tests\Feature\Student;

use App\Models\TutorProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsMarketplace;
use Tests\TestCase;

/**
 * R-47 polish (D-2014, D-2015): every public page has its own title, a description, a canonical address and sharing
 * cards; the sitemap lists public pages and approved storefronts only; the legal pages carry no dashes and no
 * product wording the product contradicts.
 */
class PublicMetaTest extends TestCase
{
    use BuildsMarketplace;
    use RefreshDatabase;

    public function test_public_pages_have_unique_titles_descriptions_canonicals_and_sharing_cards(): void
    {
        $titles = [];
        foreach (['/', '/login', '/forgot-password', '/terms-of-service', '/privacy-policy', '/cookies', '/find-a-tutor'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertMatchesRegularExpression('/<title>([^<]+)<\/title>/', $html, $url);
            preg_match('/<title>([^<]+)<\/title>/', $html, $m);
            $titles[$url] = trim($m[1]);
            $this->assertMatchesRegularExpression('/<meta name="description" content="[^"]{30,}">/', $html, $url.' has a description');
            $this->assertStringContainsString('<link rel="canonical" href="'.url($url === '/' ? '/' : $url).'">', $html, $url);
            foreach (['og:title', 'og:description', 'og:image', 'og:url'] as $prop) {
                $this->assertStringContainsString('property="'.$prop.'"', $html, $url.' '.$prop);
            }
            $this->assertStringContainsString('name="twitter:card" content="summary_large_image"', $html);
            $this->assertStringContainsString(asset('images/og-card.png'), $html);
        }
        $this->assertSame(count($titles), count(array_unique($titles)), 'titles are unique: '.json_encode($titles));
        $this->assertFileExists(public_path('images/og-card.png'));
    }

    public function test_sign_in_and_the_missing_page_put_the_skip_link_first(): void
    {
        foreach (['/login', '/forgot-password'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertMatchesRegularExpression('/<body[^>]*>\s*<a class="td-skip" href="#main-content">/', $html, $url);
            $this->assertStringNotContainsString('autofocus', $html, $url.': autofocus would jump past the skip link');
        }
        $this->assertStringNotContainsString('>Sign in</span></a>', preg_replace('/<main.*$/s', '', $this->get('/login')->getContent()), 'no header Sign in button on the sign-in page');

        $html = $this->get('/no-such-page-here')->assertNotFound()->getContent();
        $this->assertMatchesRegularExpression('/<body[^>]*>\s*<a class="td-skip" href="#main-content">/', $html);
    }

    public function test_the_sitemap_lists_public_pages_and_approved_storefronts_only(): void
    {
        $approved = $this->makeTutor();
        $pending = $this->makeTutor(['status' => 'pending']);

        $xml = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();
        $this->assertStringContainsString('<loc>'.route('marketplace.find').'</loc>', $xml);
        $this->assertStringContainsString('<loc>'.route('terms.show').'</loc>', $xml);
        $this->assertStringContainsString('<loc>'.route('marketplace.tutors.show', $approved).'</loc>', $xml);
        $this->assertStringNotContainsString('<loc>'.route('marketplace.tutors.show', $pending).'</loc>', $xml);
        $this->assertStringNotContainsString('/learn', $xml);
        $this->assertSame(1, TutorProfile::query()->where('status', 'pending')->count());

        $robots = (string) file_get_contents(public_path('robots.txt'));
        $this->assertStringContainsString('Sitemap: https://', $robots);
        $this->assertStringContainsString('/sitemap.xml', $robots);
    }

    public function test_legal_pages_use_the_site_system_and_say_what_the_product_does(): void
    {
        foreach (['/terms-of-service', '/privacy-policy', '/cookies'] as $url) {
            $res = $this->get($url)->assertOk();
            $res->assertSee('td-legal', false)->assertDontSee('rounded-xl', false)->assertDontSee('shadow-lg', false);
            $text = strip_tags((string) preg_replace('/<(script|style)\b.*?<\/\1>/s', '', $res->getContent()));
            $this->assertDoesNotMatchRegularExpression('/[\x{2013}\x{2014}]/u', $text, $url.' has no en or em dashes');
        }
        $this->get('/terms-of-service')->assertDontSee('session marketplace')->assertDontSee('We do not vet')
            ->assertSee('labelled verified only after our team reviewed the evidence');
    }
}
