<?php

namespace App\Support;

use RuntimeException;
use ZipArchive;

class XlsxReader
{
    /**
     * @return list<list<string>>
     */
    public function rows(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Unable to open the Excel file.');
        }

        $sharedStrings = $this->readSharedStrings($zip);
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        if ($sheetXml === false) {
            throw new RuntimeException('The Excel file does not contain a readable worksheet.');
        }

        return $this->parseSheetRows($sheetXml, $sharedStrings);
    }

    /**
     * @return list<string>
     */
    private function readSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }

        $document = simplexml_load_string($xml);
        if ($document === false) {
            return [];
        }

        $strings = [];

        foreach ($document->xpath('//*[local-name()="si"]') ?: [] as $item) {
            $parts = $item->xpath('.//*[local-name()="t"]') ?: [];
            $strings[] = implode('', array_map(static fn ($node): string => (string) $node, $parts));
        }

        return $strings;
    }

    /**
     * @param  list<string>  $sharedStrings
     * @return list<list<string>>
     */
    private function parseSheetRows(string $sheetXml, array $sharedStrings): array
    {
        $document = simplexml_load_string($sheetXml);
        if ($document === false) {
            throw new RuntimeException('Unable to parse the Excel worksheet.');
        }

        $rows = [];

        foreach ($document->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') ?: [] as $row) {
            $values = [];
            $maxIndex = -1;

            foreach ($row->xpath('*[local-name()="c"]') ?: [] as $cell) {
                $reference = (string) ($cell['r'] ?? '');
                $index = $this->columnIndex($reference);
                if ($index < 0) {
                    continue;
                }

                $maxIndex = max($maxIndex, $index);
                $values[$index] = $this->cellValue($cell, $sharedStrings);
            }

            if ($maxIndex < 0) {
                continue;
            }

            $normalized = [];
            for ($i = 0; $i <= $maxIndex; $i++) {
                $normalized[] = trim((string) ($values[$i] ?? ''));
            }

            $rows[] = $normalized;
        }

        return $rows;
    }

    private function cellValue(\SimpleXMLElement $cell, array $sharedStrings): string
    {
        $type = (string) ($cell['t'] ?? '');

        if ($type === 'inlineStr') {
            $text = $cell->xpath('*[local-name()="is"]/*[local-name()="t"]') ?: [];

            return isset($text[0]) ? (string) $text[0] : '';
        }

        $valueNode = $cell->xpath('*[local-name()="v"]') ?: [];
        if (! isset($valueNode[0])) {
            return '';
        }

        $raw = (string) $valueNode[0];

        if ($type === 's') {
            return $sharedStrings[(int) $raw] ?? '';
        }

        return $raw;
    }

    private function columnIndex(string $reference): int
    {
        if (! preg_match('/^([A-Z]+)/', strtoupper($reference), $matches)) {
            return -1;
        }

        $letters = $matches[1];
        $index = 0;

        for ($i = 0, $len = strlen($letters); $i < $len; $i++) {
            $index = ($index * 26) + (ord($letters[$i]) - ord('A') + 1);
        }

        return $index - 1;
    }
}
