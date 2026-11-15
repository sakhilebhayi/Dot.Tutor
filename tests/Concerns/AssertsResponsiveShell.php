<?php

namespace Tests\Concerns;

use DOMDocument;
use DOMXPath;
use Illuminate\Testing\TestResponse;

/**
 * Markup contract of the responsive app shell (layouts/app.blade.php): below 861px the sidebar
 * is an off-canvas drawer opened by the shared header's menu button. These checks read the rendered
 * HTML, never pixels; behaviour (focus, Escape, scroll lock) lives in dot-ui/js/dot-nav.js.
 */
trait AssertsResponsiveShell
{
    protected function shellXPath(TestResponse $response): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    protected function assertResponsiveShell(TestResponse $response): DOMXPath
    {
        $response->assertOk();
        $xpath = $this->shellXPath($response);

        $this->assertSame(1, $xpath->query('//meta[@name="viewport"][contains(@content, "width=device-width")]')->length, 'viewport meta');

        // R-52: the top bar is the shared dot-ui header; its menu button opens the platform's rail (adopt mode, drawer-target).
        $button = $xpath->query('//header[contains(@class, "dh")]//button[@data-dh-menu-button]');
        $this->assertSame(1, $button->length, 'one menu button in the shared header');
        $this->assertSame('app-sidebar', $button->item(0)->getAttribute('aria-controls'));
        $this->assertSame('false', $button->item(0)->getAttribute('aria-expanded'));
        $this->assertSame('button', $button->item(0)->getAttribute('type'));
        $this->assertNotSame('', $button->item(0)->getAttribute('aria-label'), 'the icon button carries an accessible name');

        $drawer = $xpath->query('//aside[@id="app-sidebar"][@aria-label]');
        $this->assertSame(1, $drawer->length, 'drawer container');
        $this->assertGreaterThan(0, $xpath->query('//aside[@id="app-sidebar"]//nav//a[@href]')->length, 'navigation links live inside the drawer');
        $this->assertSame(1, $xpath->query('//aside[@id="app-sidebar"]//button[@data-dh-close][@aria-label="Close menu"]')->length, 'close button inside the drawer');
        $this->assertSame(1, $xpath->query('//main[@id="main-content"]')->length, 'one main landmark');
        $this->assertSame(1, $xpath->query('//script[contains(@src, "dot-ui/js/dot-nav.js")]')->length, 'the shared header script is included once');
        $this->assertSame(0, $xpath->query('//*[@data-shell-open or @data-shell-close or @data-shell-behind]')->length, 'the old drawer hooks are gone');

        return $xpath;
    }

    protected function assertScrollsHorizontally(DOMXPath $xpath, string $label): void
    {
        $wrapper = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " table-scroll ")][@role="region"][@aria-label="'.$label.'"][@tabindex="0"]');
        $this->assertSame(1, $wrapper->length, "table region \"{$label}\" is an overflow-x container");
        $this->assertGreaterThan(0, $xpath->query('.//table', $wrapper->item(0))->length, "\"{$label}\" wraps a table");
    }
}
