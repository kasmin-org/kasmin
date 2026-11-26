<?php

namespace Tests\Feature\System;

use Tests\TestCase;

/**
 * The documentation for administrators exists in English (docs/admin) and German (docs/de/admin). Both have the
 * same pages with the same sections, so a change in one language is not forgotten in the other.
 */
class DocsGermanTest extends TestCase
{
    public function test_every_page_exists_in_both_languages(): void
    {
        $english = $this->pages('docs/admin');
        $german = $this->pages('docs/de/admin');

        $this->assertNotSame([], $english);
        $this->assertSame([], array_values(array_diff($english, $german)), 'pages without a German counterpart in docs/de/admin');
        $this->assertSame([], array_values(array_diff($german, $english)), 'German pages without an English counterpart in docs/admin');
    }

    public function test_german_pages_have_a_title_and_the_same_sections(): void
    {
        foreach ($this->pages('docs/admin') as $page) {
            $german = base_path('docs/de/admin/'.$page);
            if (! is_file($german)) {
                continue; // reported by the test above
            }
            $text = (string) file_get_contents($german);

            $this->assertStringStartsWith('# ', $text, $page.': the first line is the title');
            $this->assertSame(
                $this->sections((string) file_get_contents(base_path('docs/admin/'.$page))),
                $this->sections($text),
                $page.': number of "## " headings differs between docs/admin and docs/de/admin',
            );
        }
    }

    /** @return list<string> */
    private function pages(string $directory): array
    {
        $pages = array_map(basename(...), glob(base_path($directory.'/*.md')) ?: []);
        sort($pages);

        return $pages;
    }

    private function sections(string $text): int
    {
        return (int) preg_match_all('/^## /m', $text);
    }
}
