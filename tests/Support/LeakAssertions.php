<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Money;
use PHPUnit\Framework\Assert;

/**
 * Regola d'oro 1 e 2: nelle uscite verso il cliente non compaiono mai i costi del fornitore
 * né la parola "WTB". Le fixture hanno costi con centesimi dispari: non si confondono con i prezzi.
 */
trait LeakAssertions
{
    /** @var list<string>|null */
    private ?array $costNeedles = null;

    /** @return list<string> */
    protected function costNeedles(TestApp $app): array
    {
        if ($this->costNeedles === null) {
            $needles = [];
            foreach ($app->db->column('SELECT DISTINCT cost_cents FROM offers') as $cents) {
                $decimal = Money::toDecimal((int) $cents);
                $needles[] = $decimal;
                $needles[] = str_replace('.', ',', $decimal);
                $needles[] = '"cost';
            }
            $this->costNeedles = array_values(array_unique($needles));
        }

        return $this->costNeedles;
    }

    protected function assertNoLeak(TestApp $app, string $content, string $where): void
    {
        Assert::assertDoesNotMatchRegularExpression('/wtb/i', $content, 'Il fornitore è nominato in ' . $where);
        foreach ($this->costNeedles($app) as $needle) {
            Assert::assertStringNotContainsString($needle, $content, 'Costo del fornitore (' . $needle . ') visibile in ' . $where);
        }
    }

    /** @param \Psr\Http\Message\ResponseInterface $response */
    protected function assertResponseHasNoLeak(TestApp $app, $response, string $where): void
    {
        $headers = '';
        foreach ($response->getHeaders() as $name => $values) {
            $headers .= $name . ': ' . implode(', ', $values) . "\n";
        }
        $this->assertNoLeak($app, $headers . (string) $response->getBody(), $where);
    }
}
