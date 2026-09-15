<?php

namespace Tests\Support;

use ZipArchive;

trait CreatesCrmImportSpreadsheet
{
    /**
     * @param  list<list<string>>  $rows
     */
    protected function createCrmImportSpreadsheet(array $rows, string $path): void
    {
        $strings = [];
        $indexes = [];

        $stringIndex = static function (string $value) use (&$strings, &$indexes): int {
            if (! array_key_exists($value, $indexes)) {
                $indexes[$value] = count($strings);
                $strings[] = $value;
            }

            return $indexes[$value];
        };

        $sheetRows = '';
        foreach ($rows as $rowNumber => $row) {
            $excelRow = $rowNumber + 1;
            $sheetRows .= '<row r="'.$excelRow.'">';
            foreach ($row as $columnIndex => $value) {
                $column = $this->excelColumn($columnIndex);
                $sheetRows .= '<c r="'.$column.$excelRow.'" t="s"><v>'.$stringIndex($value).'</v></c>';
            }
            $sheetRows .= '</row>';
        }

        $sharedStrings = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'.count($strings).'" uniqueCount="'.count($strings).'">';
        foreach ($strings as $string) {
            $sharedStrings .= '<si><t>'.htmlspecialchars($string, ENT_XML1).'</t></si>';
        }
        $sharedStrings .= '</sst>';

        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetData>'.$sheetRows.'</sheetData></worksheet>';

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
<Default Extension="xml" ContentType="application/xml"/>
<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>
</Types>
XML);
        $zip->addFromString('_rels/.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>
XML);
        $zip->addFromString('xl/workbook.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>
XML);
        $zip->addFromString('xl/_rels/workbook.xml.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>
</Relationships>
XML);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->addFromString('xl/sharedStrings.xml', $sharedStrings);
        $zip->close();
    }

    private function excelColumn(int $index): string
    {
        $index++;
        $column = '';

        while ($index > 0) {
            $remainder = ($index - 1) % 26;
            $column = chr(65 + $remainder).$column;
            $index = intdiv($index - 1, 26);
        }

        return $column;
    }
}
