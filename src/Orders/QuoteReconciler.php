<?php

declare(strict_types=1);

namespace App\Orders;

use App\Supplier\Model\SupplierQuote;

/**
 * Confronta il carrello con il preventivo del fornitore (docs/06 § Checkout): la ripartizione per fascia del
 * fornitore è quella che conta; paia mancanti ⇒ il carrello si riduce. Servizio puro.
 */
final class QuoteReconciler
{
    /**
     * @param list<array{product: array<string, mixed>, unit: array<string, mixed>}> $lines righe del carrello (CartService)
     * @return array{split: array<string, array{immediate: int, extended: int}>, costs: array<string, array{immediate: ?int, extended: ?int}>, reduce: array<string, int>}
     */
    public static function reconcile(array $lines, SupplierQuote $quote, bool $sellExtended): array
    {
        $byKey = $quote->split();
        $split = [];
        $costs = [];
        $reduce = [];
        foreach ($lines as $line) {
            $product = $line['product'];
            $unit = $line['unit'];
            $key = (string) $unit['key'];
            $imm = $byKey[SupplierQuote::key((string) $product['sku'], (string) $unit['size_eu'], (string) $unit['regime'], 'immediate')] ?? null;
            $ext = $byKey[SupplierQuote::key((string) $product['sku'], (string) $unit['size_eu'], (string) $unit['regime'], 'extended')] ?? null;
            $qImm = $imm['quantity'] ?? 0;
            $qExt = $ext['quantity'] ?? 0;
            $hasExtendedTier = false;
            foreach ($unit['tiers'] as $tier) {
                $hasExtendedTier = $hasExtendedTier || $tier['stock_type'] === 'extended';
            }
            if ($qExt > 0 && (!$sellExtended || !$hasExtendedTier)) {
                // Il fornitore metterebbe in stock esteso paia che noi non vendiamo a quel prezzo: si riduce.
                $reduce[$key] = $qImm;
                continue;
            }
            if ($qImm + $qExt !== (int) $unit['quantity']) {
                $reduce[$key] = $qImm + $qExt;
                continue;
            }
            $split[$key] = ['immediate' => $qImm, 'extended' => $qExt];
            $costs[$key] = ['immediate' => $imm['unit_cost_cents'] ?? null, 'extended' => $ext['unit_cost_cents'] ?? null];
        }

        return ['split' => $split, 'costs' => $costs, 'reduce' => $reduce];
    }

    /**
     * Righe "stock" di un rifiuto del fornitore → paia disponibili per (sku, taglia EU, regime).
     * M0: "<sku> EU <taglia> (<regime>): only N available." oppure "...: not available."
     *
     * @param list<string> $issues
     * @return array<string, int> "sku|taglia|regime" => paia disponibili
     */
    public static function parseStockIssues(array $issues): array
    {
        $out = [];
        foreach ($issues as $issue) {
            if (preg_match('/^(.+?) EU (.+?) \((margin|ordinary)\): (?:only (\d+) available|not available)\.?\s*$/i', trim($issue), $m) === 1) {
                $out[$m[1] . '|' . $m[2] . '|' . strtolower($m[3])] = isset($m[4]) ? (int) $m[4] : 0;
            }
        }

        return $out;
    }
}
