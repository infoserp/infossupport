<?php
// Minimal PDF writer: A4 landscape, core Helvetica / Helvetica-Bold (WinAnsi), text, lines, rectangles.
// Coordinates are top-left based (y grows downward) in points; converted to PDF space internally.
// Strings passed in are UTF-8; characters outside Windows-1252 print as "?".

final class SimplePdf
{
    /** @var float */
    public $pageW = 842;
    /** @var float */
    public $pageH = 595;
    /** Called once per page at output time, with {PAGE}/{PAGES} available to text(). @var callable|null */
    public $onPageEnd = null;

    /** @var array */
    private $pages = [];
    /** @var int */
    private $cur = -1;
    /** @var int */
    private $pageNo = 0;
    /** @var int */
    private $pageCount = 0;

    private const W_REG = [278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584];
    private const W_BOLD = [278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584];

    public function addPage(): void
    {
        $this->pages[] = '';
        $this->cur = count($this->pages) - 1;
    }

    private function out(string $s): void
    {
        $this->pages[$this->cur] .= $s . "\n";
    }

    private static function enc(string $utf8): string
    {
        $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $utf8) ?? '';
        $s = strtr($s, ["\u{2018}" => "'", "\u{2019}" => "'", "\u{201C}" => '"', "\u{201D}" => '"', "\u{2013}" => '-', "\u{2014}" => '-']);
        return mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
    }

    private static function esc(string $cp1252): string
    {
        return strtr($cp1252, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
    }

    private static function rgb(array $c, bool $stroke): string
    {
        return sprintf('%.3F %.3F %.3F %s', $c[0], $c[1], $c[2], $stroke ? 'RG' : 'rg');
    }

    private function placeholders(string $s): string
    {
        return strtr($s, ['{PAGE}' => (string) $this->pageNo, '{PAGES}' => (string) $this->pageCount]);
    }

    /** Width in points of a UTF-8 string. */
    public function width(string $utf8, float $size, bool $bold = false): float
    {
        $tbl = $bold ? self::W_BOLD : self::W_REG;
        $w = 0;
        foreach (str_split(self::enc($utf8)) as $ch) {
            $o = ord($ch);
            $w += ($o >= 32 && $o <= 126) ? $tbl[$o - 32] : 556;
        }
        return $w * $size / 1000;
    }

    public function text(float $x, float $y, string $utf8, float $size, bool $bold = false, array $color = [0.106, 0.11, 0.11]): void
    {
        $s = self::esc(self::enc($this->placeholders($utf8)));
        $this->out(sprintf('BT %s /%s %.2F Tf %.2F %.2F Td (%s) Tj ET', self::rgb($color, false), $bold ? 'F2' : 'F1', $size, $x, $this->pageH - $y, $s));
    }

    public function textRight(float $xRight, float $y, string $utf8, float $size, bool $bold = false, array $color = [0.106, 0.11, 0.11]): void
    {
        $utf8 = $this->placeholders($utf8);
        $this->text($xRight - $this->width($utf8, $size, $bold), $y, $utf8, $size, $bold, $color);
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $w = 0.5, array $color = [0, 0, 0]): void
    {
        $this->out(sprintf('%s %.2F w %.2F %.2F m %.2F %.2F l S', self::rgb($color, true), $w, $x1, $this->pageH - $y1, $x2, $this->pageH - $y2));
    }

    public function rect(float $x, float $y, float $w, float $h, array $fill): void
    {
        $this->out(sprintf('%s %.2F %.2F %.2F %.2F re f', self::rgb($fill, false), $x, $this->pageH - $y - $h, $w, $h));
    }

    public function rectStroke(float $x, float $y, float $w, float $h, float $lw, array $color): void
    {
        $this->out(sprintf('%s %.2F w %.2F %.2F %.2F %.2F re S', self::rgb($color, true), $lw, $x, $this->pageH - $y - $h, $w, $h));
    }

    /** Shorten with "..." so the string fits in $maxW. */
    public function truncate(string $s, float $maxW, float $size, bool $bold): string
    {
        if ($this->width($s, $size, $bold) <= $maxW) return $s;
        while ($s !== '' && $this->width($s . '...', $size, $bold) > $maxW) {
            $s = mb_substr($s, 0, -1);
        }
        return $s . '...';
    }

    /** Word-wrap a UTF-8 string into lines no wider than $maxW. */
    public function wrap(string $s, float $maxW, float $size, bool $bold): array
    {
        $out = [];
        foreach (preg_split('/\R/u', trim($s)) as $para) {
            $line = '';
            foreach (preg_split('/\s+/u', trim($para), -1, PREG_SPLIT_NO_EMPTY) as $word) {
                $try = $line === '' ? $word : "$line $word";
                if ($this->width($try, $size, $bold) <= $maxW) {
                    $line = $try;
                    continue;
                }
                if ($line !== '') $out[] = $line;
                // A single word wider than the column is broken by characters.
                while ($this->width($word, $size, $bold) > $maxW) {
                    $n = mb_strlen($word);
                    while ($n > 1 && $this->width(mb_substr($word, 0, $n), $size, $bold) > $maxW) $n--;
                    $out[] = mb_substr($word, 0, $n);
                    $word = mb_substr($word, $n);
                }
                $line = $word;
            }
            $out[] = $line;
        }
        return $out ?: [''];
    }

    public function output(): string
    {
        if (!$this->pages) $this->addPage();
        $this->pageCount = count($this->pages);
        if ($this->onPageEnd) {
            foreach (array_keys($this->pages) as $i) {
                $this->cur = $i;
                $this->pageNo = $i + 1;
                ($this->onPageEnd)($this);
            }
        }

        $objs = [];   // object number => body
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $kids = [];
        $n = 5;
        foreach ($this->pages as $content) {
            $z = gzcompress($content);
            $objs[$n + 1] = '<< /Length ' . strlen($z) . " /Filter /FlateDecode >>\nstream\n" . $z . "\nendstream";
            $objs[$n] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>', $this->pageW, $this->pageH, $n + 1);
            $kids[] = "$n 0 R";
            $n += 2;
        }
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        $objs[$n] = '<< /Producer (Infos Support) /CreationDate (D:' . date('YmdHis') . ') >>';
        ksort($objs);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objs as $num => $body) {
            $offsets[$num] = strlen($pdf);
            $pdf .= "$num 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref' . "\n0 " . ($n + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $n; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size " . ($n + 1) . " /Root 1 0 R /Info $n 0 R >>\nstartxref\n$xref\n%%EOF\n";
        return $pdf;
    }
}
