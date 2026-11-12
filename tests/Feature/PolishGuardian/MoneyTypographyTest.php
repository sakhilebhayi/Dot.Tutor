<?php

namespace Tests\Feature\PolishGuardian;

use App\Domain\Marketplace\Support\MinorUnits;
use App\Domain\Money\Money;
use App\Support\Money as LegacyMoney;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/** One money display format and plain-zero figures everywhere (polish wave, critics A and B, D-2103, D-2104). */
class MoneyTypographyTest extends TestCase
{
    public function test_every_money_path_writes_the_symbol_against_the_figure(): void
    {
        $this->assertSame('R150.00', Money::of(15000)->format());
        $this->assertSame('R150.00', MinorUnits::format(15000, 'ZAR'));
        $this->assertSame('R150.00', LegacyMoney::format('150', 'ZAR'));
        $this->assertSame('R150.00', trim(strip_tags(Blade::render('<x-td.money :minor="15000" />'))));
        $this->assertSame('R150.00', trim(view('learning.partials.money', ['minor' => 15000, 'currency' => 'ZAR'])->render()));
        $this->assertSame("R1\u{202F}250.00", Money::of(125000)->format());
        $this->assertSame("\u{2212}R50.00", Money::of(-5000)->format());
        $this->assertSame("XOF 1\u{202F}250", Money::of(1250, 'XOF')->format(), 'A currency without a symbol keeps its code and a space.');
    }

    public function test_the_text_stack_puts_plain_zero_figures_first(): void
    {
        $tokens = file_get_contents(resource_path('css/ds/tokens.css'));
        $fonts = file_get_contents(resource_path('css/ds/fonts.css'));

        $this->assertMatchesRegularExpression("/--td-font-text: 'TD Figures', 'TD Text'/", $tokens);
        $this->assertMatchesRegularExpression("/font-family: 'TD Figures';[^}]*unicode-range: U\\+0030-0039;/s", $fonts);
        $this->assertFileExists(public_path('fonts/archivo-display-latin.woff2'));
    }
}
