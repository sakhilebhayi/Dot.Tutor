<?php

namespace Tests\Feature\Academic;

use App\Domain\Academic\Contracts\LanguageProfile;
use App\Domain\Academic\Enums\LanguageRole;
use App\Domain\Academic\I18n\LocaleCatalogue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Tests\Concerns\SeedsAcademicStructure;
use Tests\TestCase;

class LocaleTest extends TestCase
{
    use RefreshDatabase;
    use SeedsAcademicStructure;

    public function test_english_without_any_preference_and_before_languages_are_seeded(): void
    {
        $this->get('/')->assertOk()->assertHeader('Content-Language', 'en');

        $this->seedAcademic();
        $this->get('/')->assertHeader('Content-Language', 'en');
    }

    public function test_accept_language_picks_a_translated_locale_only(): void
    {
        $this->seedAcademic();

        $this->withHeader('Accept-Language', 'fr-FR,fr;q=0.9,en;q=0.5')->get('/')->assertHeader('Content-Language', 'fr');
        $this->withHeader('Accept-Language', 'af')->get(route('academic.locale.index'))
            ->assertHeader('Content-Language', 'af')->assertSee('Taal van hierdie webwerf');
        // isiZulu has no interface strings yet, so the header alone does not switch to it.
        $this->withHeader('Accept-Language', 'zu,en;q=0.1')->get('/')->assertHeader('Content-Language', 'en');
    }

    public function test_a_guest_choice_is_kept_in_the_session_and_beats_the_browser(): void
    {
        $this->seedAcademic();

        $this->from(route('academic.locale.index'))->post(route('academic.locale.update'), ['locale' => 'pt'])
            ->assertRedirect(route('academic.locale.index'))->assertSessionHas('locale', 'pt');

        $this->withHeader('Accept-Language', 'fr')->get(route('academic.locale.index'))
            ->assertHeader('Content-Language', 'pt')->assertSee('Língua deste site');
    }

    public function test_a_signed_in_choice_is_saved_as_the_interface_language_and_wins(): void
    {
        $this->seedAcademic();
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('academic.locale.update'), ['locale' => 'zu'])->assertSessionHasNoErrors();
        $this->assertSame('zu', app(LanguageProfile::class)->interfaceLocale($user));
        $this->assertSame([], app(LanguageProfile::class)->codes($user, LanguageRole::PrefersTeaching), 'The interface choice never becomes a teaching language');

        // A new session (no session value) still uses the saved preference over the browser.
        $this->flushSession();
        $this->actingAs($user)->withHeader('Accept-Language', 'fr')->get(route('academic.locale.index'))->assertHeader('Content-Language', 'zu');
    }

    public function test_only_written_active_languages_can_be_chosen(): void
    {
        $this->seedAcademic();

        $this->post(route('academic.locale.update'), ['locale' => 'sfs'])->assertSessionHasErrors('locale');
        $this->post(route('academic.locale.update'), ['locale' => 'de'])->assertSessionHasErrors('locale');
        $this->post(route('academic.locale.update'), [])->assertSessionHasErrors('locale');
    }

    public function test_untranslated_keys_fall_back_to_english(): void
    {
        $this->seedAcademic();

        app()->setLocale('zu');
        $this->assertSame('Your learning profile', __('academic.onboarding.title'));
        app()->setLocale('af');
        $this->assertSame('Jou leerprofiel', __('academic.onboarding.title'));
    }

    public function test_every_shipped_locale_file_has_the_same_keys_as_english(): void
    {
        $english = array_keys(Arr::dot(require lang_path('en/academic.php')));
        foreach (['af', 'fr', 'pt'] as $locale) {
            $keys = array_keys(Arr::dot(require lang_path("{$locale}/academic.php")));
            $this->assertSame([], array_values(array_diff($english, $keys)), "{$locale} is missing keys");
            $this->assertSame([], array_values(array_diff($keys, $english)), "{$locale} has keys English does not");
        }
    }

    public function test_the_switcher_says_honestly_how_translated_each_language_is(): void
    {
        $this->seedAcademic();
        $options = collect(app(LocaleCatalogue::class)->options())->keyBy('code');

        $this->assertSame('complete', $options['en']['status']);
        $this->assertSame('partial', $options['af']['status'], 'Never "complete" while screens hold hard-coded English');
        $this->assertSame('machine_unreviewed', $options['af']['provenance']);
        $this->assertSame('none', $options['zu']['status']);
        $this->assertArrayNotHasKey('sfs', $options->all(), 'A signed language is not an interface locale');

        $this->get(route('academic.locale.index'))
            ->assertOk()
            ->assertSee('Drafted with AI and not yet checked by a native speaker.')
            ->assertSee('Not translated yet, shown in English')
            ->assertSee('isiZulu');
    }
}
