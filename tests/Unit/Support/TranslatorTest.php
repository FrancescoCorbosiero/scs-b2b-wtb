<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Bootstrap;
use App\Support\Translator;
use PHPUnit\Framework\TestCase;

final class TranslatorTest extends TestCase
{
    private function translator(): Translator
    {
        return new Translator(Bootstrap::root() . '/lang');
    }

    public function testEnglishHasTheSameKeysAsItalianExceptAdmin(): void
    {
        $t = $this->translator();
        $it = array_filter($t->keys('it'), static fn (string $k): bool => !str_starts_with($k, 'admin.'));
        $en = $t->keys('en');
        self::assertSame([], array_values(array_diff($it, $en)), 'Chiavi mancanti in lang/en.php');
        self::assertSame([], array_values(array_diff($en, $t->keys('it'))), 'Chiavi in più in lang/en.php');
    }

    public function testPlaceholdersMatchBetweenLanguages(): void
    {
        $t = $this->translator();
        foreach ($t->keys('en') as $key) {
            preg_match_all('/:([a-z_]+)/', $t->get($key, [], 'it'), $it);
            preg_match_all('/:([a-z_]+)/', $t->get($key, [], 'en'), $en);
            $a = array_unique($it[1]);
            $b = array_unique($en[1]);
            sort($a);
            sort($b);
            self::assertSame($a, $b, 'Parametri diversi in ' . $key);
        }
    }

    public function testFallbackParamsAndPlurals(): void
    {
        $t = $this->translator();
        $t->setLocale('en');
        self::assertSame('Log in', $t->get('nav.login'));
        self::assertSame('Admin', $t->get('admin.title'), 'Le chiavi admin ricadono sull\'italiano');
        self::assertSame('VAT no. 123', $t->get('footer.vat', ['vat' => '123']));
        self::assertSame('one pair', $t->choice('common.pairs', 1));
        self::assertSame('3 pairs', $t->choice('common.pairs', 3));
        self::assertSame('3 paia', $t->choice('common.pairs', 3, [], 'it'));
        self::assertSame('missing.key', $t->get('missing.key'));
    }

    public function testNoCustomerFacingTextNamesTheSupplier(): void
    {
        $t = $this->translator();
        foreach (['it', 'en'] as $locale) {
            foreach ($t->keys($locale) as $key) {
                if (str_starts_with($key, 'admin.')) {
                    continue;
                }
                self::assertDoesNotMatchRegularExpression('/wtb/i', $t->get($key, [], $locale), $key);
            }
        }
    }
}
