<?php
// Minimal .xlsx writer: one worksheet, bold frozen header, autofilter, real date cells.
// Ships its own zip encoder (raw deflate via zlib) because ext-zip is not enabled on this server.

final class XlsxDate
{
    public function __construct(public string $ymd) {}

    /** Excel serial day number (1900 date system). */
    public function serial(): int
    {
        $d = new DateTimeImmutable($this->ymd, new DateTimeZone('UTC'));
        return intdiv($d->getTimestamp(), 86400) + 25569;
    }
}

function xlsx_xml(string $s): string
{
    $s = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $s) ?? '';
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function xlsx_col(int $i): string
{
    $s = '';
    for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
        $s = chr(65 + ($i - 1) % 26) . $s;
    }
    return $s;
}

/**
 * @param array<int, string> $headers
 * @param array<int, array>  $rows    cells: string | int | float | XlsxDate
 * @param array<int, float>  $widths  column widths in characters
 */
function xlsx_build(string $sheetName, array $headers, array $rows, array $widths = []): string
{
    $lastCol = xlsx_col(count($headers) - 1);
    $lastRow = count($rows) + 1;

    $cell = function (string $ref, $v, int $style): string {
        if ($v instanceof XlsxDate) {
            return '<c r="' . $ref . '" s="2"><v>' . $v->serial() . '</v></c>';
        }
        if (is_int($v) || is_float($v)) {
            return '<c r="' . $ref . '"' . ($style ? ' s="' . $style . '"' : '') . '><v>' . $v . '</v></c>';
        }
        return '<c r="' . $ref . '" t="inlineStr"' . ($style ? ' s="' . $style . '"' : '') . '><is><t xml:space="preserve">' . xlsx_xml((string) $v) . '</t></is></c>';
    };

    $data = '<row r="1">';
    foreach ($headers as $i => $hd) $data .= $cell(xlsx_col($i) . '1', $hd, 1);
    $data .= '</row>';
    foreach ($rows as $r => $row) {
        $rn = $r + 2;
        $data .= '<row r="' . $rn . '">';
        foreach (array_values($row) as $i => $v) $data .= $cell(xlsx_col($i) . $rn, $v, 3);
        $data .= '</row>';
    }

    $cols = '';
    foreach ($widths as $i => $w) {
        $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
    }

    $ns = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';
    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . "<worksheet $ns>"
        . '<dimension ref="A1:' . $lastCol . $lastRow . '"/>'
        . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="15"/>'
        . ($cols ? "<cols>$cols</cols>" : '')
        . "<sheetData>$data</sheetData>"
        . '<autoFilter ref="A1:' . $lastCol . $lastRow . '"/>'
        . '</worksheet>';

    $safeName = xlsx_xml(mb_substr(preg_replace('/[\[\]\*\?\/\\\\:]/', ' ', $sheetName), 0, 31));
    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . "<workbook $ns><sheets><sheet name=\"$safeName\" sheetId=\"1\" r:id=\"rId1\"/></sheets>"
        . "<definedNames><definedName name=\"_xlnm._FilterDatabase\" localSheetId=\"0\" hidden=\"1\">'$safeName'!\$A\$1:\$$lastCol\$$lastRow</definedName></definedNames>"
        . '</workbook>';

    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="1"><numFmt numFmtId="164" formatCode="dd mmm yyyy"/></numFmts>'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/><family val="2"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/><family val="2"/></font></fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF1C1B1B"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="4">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
        . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="left" vertical="top"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';

    $files = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>',
        'xl/workbook.xml' => $workbook,
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>',
        'xl/styles.xml' => $styles,
        'xl/worksheets/sheet1.xml' => $sheet,
    ];
    return zip_build($files);
}

/** Build a zip archive (deflate) from name => contents. */
function zip_build(array $files): string
{
    $t = getdate();
    $dosTime = ($t['hours'] << 11) | ($t['minutes'] << 5) | intdiv($t['seconds'], 2);
    $dosDate = (($t['year'] - 1980) << 9) | ($t['mon'] << 5) | $t['mday'];

    $body = '';
    $central = '';
    foreach ($files as $name => $data) {
        $crc = crc32($data);
        $z = gzdeflate($data, 6);
        $offset = strlen($body);
        // version needed, flags, method (8 = deflate), time, date, crc, compressed size, size, name length
        $common = pack('vvvvvVVVv', 20, 0, 8, $dosTime, $dosDate, $crc, strlen($z), strlen($data), strlen($name));
        $body .= pack('V', 0x04034b50) . $common . pack('v', 0) . $name . $z;
        $central .= pack('Vv', 0x02014b50, 20) . $common . pack('vvvvVV', 0, 0, 0, 0, 0, $offset) . $name;
    }
    return $body . $central
        . pack('VvvvvVVv', 0x06054b50, 0, 0, count($files), count($files), strlen($central), strlen($body), 0);
}
