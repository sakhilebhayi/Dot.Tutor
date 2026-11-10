<?php

namespace App\Domain\Academic\I18n;

use App\Domain\Academic\Models\Language;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Which interface locales exist and how much of each is translated. Coverage
 * is measured, not declared: the share of English keys (lang/en/*.php) that a
 * locale's files translate. Untranslated keys fall back to English. Who wrote
 * each locale and whether a native speaker reviewed it is recorded in
 * lang/locales.php and shown by the switcher.
 */
class LocaleCatalogue
{
    /** @var array<string, array{translated: int, total: int, percent: int}>|null */
    private ?array $coverage = null;

    /** @var Collection<int, Language>|null */
    private ?Collection $languages = null;

    /**
     * Written, active languages offered as interface locales, with coverage.
     *
     * @return list<array{code: string, name_en: string, name_native: string, percent: int, status: string, provenance: ?string}>
     */
    public function options(): array
    {
        $meta = $this->meta();

        return $this->languages()->map(function (Language $language) use ($meta): array {
            $percent = $this->coverage()[$language->code]['percent'] ?? 0;

            return [
                'code' => $language->code,
                'name_en' => $language->name_en,
                'name_native' => $language->name_native,
                'percent' => $percent,
                'status' => match (true) {
                    $percent >= 100 => 'complete',
                    $percent > 0 => 'partial',
                    default => 'none',
                },
                'provenance' => $meta[$language->code]['provenance'] ?? null,
            ];
        })->values()->all();
    }

    /** @return list<string> */
    public function codes(): array
    {
        return $this->languages()->pluck('code')->all();
    }

    public function isSupported(?string $code): bool
    {
        return $code !== null && in_array($code, $this->codes(), true);
    }

    /** Locales that have at least one translated interface key (English always). @return list<string> */
    public function translatedCodes(): array
    {
        return array_values(array_filter($this->codes(), fn (string $code) => $code === 'en' || ($this->coverage()[$code]['percent'] ?? 0) > 0));
    }

    /** @return array<string, array{translated: int, total: int, percent: int}> */
    public function coverage(): array
    {
        if ($this->coverage !== null) {
            return $this->coverage;
        }

        $english = $this->keys('en');
        $total = count($english);
        $coverage = [];

        foreach (File::directories(lang_path()) as $dir) {
            $code = basename($dir);
            $translated = count(array_intersect($english, $this->keys($code)));
            $coverage[$code] = [
                'translated' => $translated,
                'total' => $total,
                'percent' => $total === 0 ? 0 : (int) floor($translated * 100 / $total),
            ];
        }

        return $this->coverage = $coverage;
    }

    /** @return array<string, array{provenance: string, reviewed: bool}> */
    public function meta(): array
    {
        $path = lang_path('locales.php');

        return is_file($path) ? require $path : [];
    }

    /** @return list<string> dotted keys ("academic.onboarding.title") */
    private function keys(string $locale): array
    {
        $keys = [];
        foreach (File::glob(lang_path($locale.'/*.php')) as $file) {
            $group = basename($file, '.php');
            $lines = require $file;
            foreach (array_keys(Arr::dot(is_array($lines) ? $lines : [])) as $key) {
                $keys[] = "{$group}.{$key}";
            }
        }

        return $keys;
    }

    /** @return Collection<int, Language> */
    private function languages(): Collection
    {
        if ($this->languages !== null) {
            return $this->languages;
        }

        // Before `academic:sync` has run (or before migrations), English alone.
        if (! Schema::hasTable('languages') || ! Language::query()->exists()) {
            return $this->languages = collect([new Language(['code' => 'en', 'name_en' => 'English', 'name_native' => 'English', 'modality' => 'written'])]);
        }

        return $this->languages = Language::query()->active()->where('modality', 'written')->orderBy('sort')->get();
    }
}
