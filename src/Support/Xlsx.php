<?php

declare(strict_types=1);

namespace App\Support;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/** Export Excel (.xlsx) con OpenSpout: prima riga in grassetto, nessun metadato che nomini terzi. */
final class Xlsx
{
    public const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /** @param list<list<string|int|float|null>> $rows prima riga = intestazioni */
    public static function build(string $sheetName, array $rows, string $creator = ''): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        if ($tmp === false) {
            throw new \RuntimeException('File temporaneo non disponibile.');
        }
        try {
            $options = new Options();
            $writer = new Writer($options);
            $writer->setCreator($creator === '' ? 'B2B' : $creator);
            $writer->openToFile($tmp);
            $writer->getCurrentSheet()->setName(mb_substr($sheetName, 0, 31));
            $bold = (new Style())->setFontBold();
            foreach ($rows as $i => $values) {
                $values = array_map(static fn ($v) => is_string($v) ? self::safeCell($v) : $v, $values);
                $writer->addRow(Row::fromValues($values, $i === 0 ? $bold : null));
            }
            $writer->close();

            return (string) file_get_contents($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    /** Evita che un testo venga interpretato come formula da Excel (CSV/formula injection). */
    private static function safeCell(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@'], true) && !is_numeric($value) ? "'" . $value : $value;
    }
}
