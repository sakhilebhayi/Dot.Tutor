<?php

namespace Tests\Feature\Safety;

use App\Domain\Safety\Services\ContactPrivacy;
use App\Domain\Safety\Services\ContactRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ContactRedactorTest extends TestCase
{
    use BuildsSafetyState;
    use RefreshDatabase;

    /** @return array<string, array{0: string, 1: string}> */
    public static function contacts(): array
    {
        return [
            'sa mobile' => ['call 082 555 1234 tonight', '555'],
            'international' => ['my number +27 (82) 555-1234', '555'],
            'email' => ['mail me at Thandi.M@school.co.za', 'Thandi.M@'],
            'whatsapp link' => ['join https://chat.whatsapp.com/AbCdEf', 'whatsapp'],
            'telegram' => ['t.me/someone here', 't.me'],
            'zoom link' => ['use https://zoom.us/j/123456 instead', 'zoom.us'],
            'handle' => ['follow @maths_guru', '@maths_guru'],
            'snap handle' => ['add my snap: cool_kid12', 'cool_kid12'],
        ];
    }

    #[DataProvider('contacts')]
    public function test_contact_details_are_hidden(string $text, string $mustVanish): void
    {
        $result = app(ContactRedactor::class)->redact($text);

        $this->assertStringNotContainsStringIgnoringCase($mustVanish, $result['text']);
        $this->assertNotSame([], $result['redactions']);
    }

    /** @return array<string, array{0: string}> */
    public static function ordinary(): array
    {
        return [
            'equation' => ['Solve 2x + 3 = 15 and then 4x - 7 = 9'],
            'date' => ['The exam is on 2026-11-24 at 09:00'],
            'year range' => ['Covers 1994-2004 history'],
            'study link' => ['Read https://www.siyavula.com/read/maths/grade-10'],
            'signal word' => ['Signal processing is a big topic'],
        ];
    }

    #[DataProvider('ordinary')]
    public function test_ordinary_study_text_is_left_alone(string $text): void
    {
        $this->assertSame($text, app(ContactRedactor::class)->redact($text)['text']);
    }

    public function test_profile_text_is_hidden_from_a_minor_viewer_with_a_reason(): void
    {
        $teen = $this->teen();
        $this->linkGuardian($teen);
        $tutor = $this->adult('Tutor');
        $bio = 'Ten years teaching. WhatsApp 082 555 1234.';
        $privacy = app(ContactPrivacy::class);

        $forTeen = $privacy->profileText($teen, $tutor, $bio);
        $this->assertTrue($forTeen['redacted']);
        $this->assertSame(ContactRedactor::NOTE, $forTeen['note']);
        $this->assertStringNotContainsString('555', $forTeen['text']);

        $this->assertSame($bio, $privacy->profileText($this->adult('Peer'), $tutor, $bio)['text']);
    }
}
