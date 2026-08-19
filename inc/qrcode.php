<?php
/**
 * SHRA QR encoder — pure PHP, zero dependencies.
 * Byte mode, ECC levels L/M/Q/H, versions 1..10 (up to 271 bytes).
 * Renders SVG (crisp at any size, prints well) or PNG when GD is available.
 */
final class QRCode
{
    /** [ecCodewordsPerBlock, g1Blocks, g1DataCw, g2Blocks, g2DataCw] keyed [version][ecl] */
    private const ECC = [
        1  => ['L'=>[7,1,19,0,0],   'M'=>[10,1,16,0,0],  'Q'=>[13,1,13,0,0],  'H'=>[17,1,9,0,0]],
        2  => ['L'=>[10,1,34,0,0],  'M'=>[16,1,28,0,0],  'Q'=>[22,1,22,0,0],  'H'=>[28,1,16,0,0]],
        3  => ['L'=>[15,1,55,0,0],  'M'=>[26,1,44,0,0],  'Q'=>[18,2,17,0,0],  'H'=>[22,2,13,0,0]],
        4  => ['L'=>[20,1,80,0,0],  'M'=>[18,2,32,0,0],  'Q'=>[26,2,24,0,0],  'H'=>[16,4,9,0,0]],
        5  => ['L'=>[26,1,108,0,0], 'M'=>[24,2,43,0,0],  'Q'=>[18,2,15,2,16], 'H'=>[22,2,11,2,12]],
        6  => ['L'=>[18,2,68,0,0],  'M'=>[16,4,27,0,0],  'Q'=>[24,4,19,0,0],  'H'=>[28,4,15,0,0]],
        7  => ['L'=>[20,2,78,0,0],  'M'=>[18,4,31,0,0],  'Q'=>[18,2,14,4,15], 'H'=>[26,4,13,1,14]],
        8  => ['L'=>[24,2,97,0,0],  'M'=>[22,2,38,2,39], 'Q'=>[22,4,18,2,19], 'H'=>[26,4,14,2,15]],
        9  => ['L'=>[30,2,116,0,0], 'M'=>[22,3,36,2,37], 'Q'=>[20,4,16,4,17], 'H'=>[24,4,12,4,13]],
        10 => ['L'=>[18,2,68,2,69], 'M'=>[26,4,43,1,44], 'Q'=>[24,6,19,2,20], 'H'=>[28,6,15,2,16]],
    ];

    private const ALIGN = [
        1=>[], 2=>[6,18], 3=>[6,22], 4=>[6,26], 5=>[6,30],
        6=>[6,34], 7=>[6,22,38], 8=>[6,24,42], 9=>[6,26,46], 10=>[6,28,50],
    ];

    /** 18-bit BCH version information, versions 7..10 */
    private const VERSION_INFO = [7=>0x07C94, 8=>0x085BC, 9=>0x09A99, 10=>0x0A4D3];

    /** 15-bit format information [ecl][mask] */
    private const FORMAT_INFO = [
        'L' => [0x77C4,0x72F3,0x7DAA,0x789D,0x662F,0x6318,0x6C41,0x6976],
        'M' => [0x5412,0x5125,0x5E7C,0x5B4B,0x45F9,0x40CE,0x4F97,0x4AA0],
        'Q' => [0x355F,0x3068,0x3F31,0x3A06,0x24B4,0x2183,0x2EDA,0x2BED],
        'H' => [0x1689,0x13BE,0x1CE7,0x19D0,0x0762,0x0255,0x0D0C,0x083B],
    ];

    private int $version;
    private string $ecl;
    private int $size;
    /** @var int[][] */ private array $m = [];
    /** @var bool[][] */ private array $fn = [];

    private static array $exp = [];
    private static array $log = [];

    private function __construct(string $data, string $ecl, ?int $forceMask = null)
    {
        $this->ecl = $ecl;
        $this->version = self::pickVersion(strlen($data), $ecl);
        $this->size = $this->version * 4 + 17;
        self::initGF();

        $codewords = $this->buildCodewords($data);

        $best = null; $bestScore = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            if ($forceMask !== null && $mask !== $forceMask) continue;
            $this->reset();
            $this->drawFunctionPatterns();
            $this->drawCodewords($codewords);
            $this->applyMask($mask);
            $this->drawFormat($mask);
            $score = $this->penalty();
            if ($score < $bestScore) { $bestScore = $score; $best = $this->m; }
        }
        $this->m = $best;
    }

    /** ---------- public API ---------- */

    public static function matrix(string $data, string $ecl = 'M', ?int $forceMask = null): array
    {
        $qr = new self($data, strtoupper($ecl), $forceMask);
        return $qr->m;
    }

    /** Inline SVG markup, sized in CSS pixels. */
    public static function svg(string $data, int $px = 220, string $ecl = 'M', int $margin = 4,
                               string $dark = '#1b1108', string $light = '#ffffff'): string
    {
        $m = self::matrix($data, $ecl);
        $n = count($m);
        $total = $n + $margin * 2;
        $path = '';
        for ($r = 0; $r < $n; $r++) {
            $c = 0;
            while ($c < $n) {
                if ($m[$r][$c]) {
                    $start = $c;
                    while ($c < $n && $m[$r][$c]) $c++;
                    $path .= 'M' . ($start + $margin) . ' ' . ($r + $margin) . 'h' . ($c - $start) . 'v1h-' . ($c - $start) . 'z';
                } else $c++;
            }
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $px . '" height="' . $px . '" '
             . 'viewBox="0 0 ' . $total . ' ' . $total . '" shape-rendering="crispEdges" role="img" aria-label="QR code">'
             . '<rect width="' . $total . '" height="' . $total . '" fill="' . $light . '"/>'
             . '<path fill="' . $dark . '" d="' . $path . '"/></svg>';
    }

    /** data: URI so QR codes survive printing and offline PDF export. */
    public static function dataUri(string $data, int $px = 220, string $ecl = 'M'): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode(self::svg($data, $px, $ecl));
    }

    /** Raw PNG bytes (needs GD); returns null if GD is unavailable. */
    public static function png(string $data, int $scale = 8, string $ecl = 'M', int $margin = 4): ?string
    {
        if (!function_exists('imagecreatetruecolor')) return null;
        $m = self::matrix($data, $ecl);
        $n = count($m);
        $dim = ($n + $margin * 2) * $scale;
        $img = imagecreatetruecolor($dim, $dim);
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 27, 17, 8);
        imagefilledrectangle($img, 0, 0, $dim, $dim, $white);
        for ($r = 0; $r < $n; $r++) for ($c = 0; $c < $n; $c++) if ($m[$r][$c]) {
            $x = ($c + $margin) * $scale; $y = ($r + $margin) * $scale;
            imagefilledrectangle($img, $x, $y, $x + $scale - 1, $y + $scale - 1, $black);
        }
        ob_start(); imagepng($img); $out = ob_get_clean(); imagedestroy($img);
        return $out;
    }

    /** ---------- encoding ---------- */

    private static function pickVersion(int $len, string $ecl): int
    {
        foreach (self::ECC as $v => $levels) {
            [$ec, $g1b, $g1d, $g2b, $g2d] = $levels[$ecl];
            $dataCw  = $g1b * $g1d + $g2b * $g2d;
            $cci     = $v >= 10 ? 16 : 8;
            $needed  = (int) ceil((4 + $cci + $len * 8) / 8);
            if ($needed <= $dataCw) return $v;
        }
        throw new RuntimeException('QR payload too large (max 271 bytes).');
    }

    private function buildCodewords(string $data): array
    {
        [$ecCw, $g1b, $g1d, $g2b, $g2d] = self::ECC[$this->version][$this->ecl];
        $dataCw = $g1b * $g1d + $g2b * $g2d;

        // Bit stream: mode(0100) + char count + payload
        $bits = '0100';
        $cci  = $this->version >= 10 ? 16 : 8;
        $bits .= str_pad(decbin(strlen($data)), $cci, '0', STR_PAD_LEFT);
        for ($i = 0, $l = strlen($data); $i < $l; $i++) {
            $bits .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
        }
        // Terminator + byte alignment
        $cap = $dataCw * 8;
        $bits .= str_repeat('0', min(4, $cap - strlen($bits)));
        if (strlen($bits) % 8) $bits .= str_repeat('0', 8 - strlen($bits) % 8);

        $bytes = [];
        foreach (str_split($bits, 8) as $b) $bytes[] = bindec($b);
        // Pad bytes
        $pad = [0xEC, 0x11]; $i = 0;
        while (count($bytes) < $dataCw) { $bytes[] = $pad[$i++ % 2]; }

        // Split into blocks, compute ECC per block
        $blocks = []; $eccBlocks = []; $off = 0;
        foreach ([[$g1b, $g1d], [$g2b, $g2d]] as [$cnt, $len]) {
            for ($b = 0; $b < $cnt; $b++) {
                $blk = array_slice($bytes, $off, $len);
                $off += $len;
                $blocks[]    = $blk;
                $eccBlocks[] = self::rsEncode($blk, $ecCw);
            }
        }

        // Interleave data then ECC
        $out = [];
        $maxLen = max(array_map('count', $blocks));
        for ($i = 0; $i < $maxLen; $i++)
            foreach ($blocks as $b) if (isset($b[$i])) $out[] = $b[$i];
        for ($i = 0; $i < $ecCw; $i++)
            foreach ($eccBlocks as $b) $out[] = $b[$i];

        return $out;
    }

    /** ---------- Reed-Solomon over GF(256), primitive poly 0x11D ---------- */

    private static function initGF(): void
    {
        if (self::$exp) return;
        $x = 1;
        for ($i = 0; $i < 256; $i++) {
            self::$exp[$i] = $x;
            self::$log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) $x ^= 0x11D;
        }
        for ($i = 256; $i < 512; $i++) self::$exp[$i] = self::$exp[$i - 255];
    }

    private static function gmul(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) return 0;
        return self::$exp[(self::$log[$a] + self::$log[$b]) % 255];
    }

    private static function rsGenerator(int $degree): array
    {
        $g = [1];
        for ($i = 0; $i < $degree; $i++) {
            // multiply by (x + alpha^i); coefficients are highest-degree first
            $next = array_fill(0, count($g) + 1, 0);
            foreach ($g as $j => $coef) {
                $next[$j]     ^= $coef;                              // x * term
                $next[$j + 1] ^= self::gmul($coef, self::$exp[$i]);  // alpha^i * term
            }
            $g = $next;
        }
        return $g;
    }

    private static function rsEncode(array $data, int $ecCw): array
    {
        $coefs = array_slice(self::rsGenerator($ecCw), 1);   // drop the monic leading term
        $rem   = array_fill(0, $ecCw, 0);
        foreach ($data as $byte) {
            $factor = $byte ^ array_shift($rem);
            $rem[]  = 0;
            for ($i = 0; $i < $ecCw; $i++) $rem[$i] ^= self::gmul($coefs[$i], $factor);
        }
        return $rem;
    }

    /** ---------- matrix construction ---------- */

    private function reset(): void
    {
        $this->m = array_fill(0, $this->size, array_fill(0, $this->size, 0));
        $this->fn = array_fill(0, $this->size, array_fill(0, $this->size, false));
    }

    private function set(int $row, int $col, int $val, bool $isFn = true): void
    {
        if ($row < 0 || $col < 0 || $row >= $this->size || $col >= $this->size) return;
        $this->m[$row][$col] = $val;
        if ($isFn) $this->fn[$row][$col] = true;
    }

    private function drawFunctionPatterns(): void
    {
        $n = $this->size;

        // Timing patterns
        for ($i = 0; $i < $n; $i++) {
            $this->set(6, $i, $i % 2 === 0 ? 1 : 0);
            $this->set($i, 6, $i % 2 === 0 ? 1 : 0);
        }

        // Finder patterns + separators
        foreach ([[0, 0], [0, $n - 7], [$n - 7, 0]] as [$r, $c]) $this->drawFinder($r, $c);

        // Alignment patterns
        $centers = self::ALIGN[$this->version];
        $cnt = count($centers);
        for ($i = 0; $i < $cnt; $i++) for ($j = 0; $j < $cnt; $j++) {
            if (($i === 0 && $j === 0) || ($i === 0 && $j === $cnt - 1) || ($i === $cnt - 1 && $j === 0)) continue;
            $this->drawAlignment($centers[$i], $centers[$j]);
        }

        // Reserve format areas (real bits written later)
        for ($i = 0; $i <= 8; $i++) {
            if ($i === 6) continue;                 // column/row 6 belongs to the timing pattern
            $this->set(8, $i, 0);
            $this->set($i, 8, 0);
        }
        for ($i = 0; $i < 8; $i++)  { $this->set(8, $n - 1 - $i, 0); $this->set($n - 1 - $i, 8, 0); }
        $this->set($n - 8, 8, 1);   // always-dark module

        // Version information (v7+)
        if ($this->version >= 7) {
            $bits = self::VERSION_INFO[$this->version];
            for ($i = 0; $i < 18; $i++) {
                $bit = ($bits >> $i) & 1;
                $a = $n - 11 + $i % 3;
                $b = intdiv($i, 3);
                $this->set($b, $a, $bit);
                $this->set($a, $b, $bit);
            }
        }
    }

    private function drawFinder(int $row, int $col): void
    {
        for ($dr = -1; $dr <= 7; $dr++) for ($dc = -1; $dc <= 7; $dc++) {
            $r = $row + $dr; $c = $col + $dc;
            if ($r < 0 || $c < 0 || $r >= $this->size || $c >= $this->size) continue;
            $dist = max(abs($dr - 3), abs($dc - 3));
            $this->set($r, $c, ($dist !== 2 && $dist !== 4) ? 1 : 0);
        }
    }

    private function drawAlignment(int $row, int $col): void
    {
        for ($dr = -2; $dr <= 2; $dr++) for ($dc = -2; $dc <= 2; $dc++) {
            $this->set($row + $dr, $col + $dc, max(abs($dr), abs($dc)) !== 1 ? 1 : 0);
        }
    }

    private function drawCodewords(array $cw): void
    {
        $n = $this->size;
        $bitLen = count($cw) * 8;
        $i = 0;
        for ($right = $n - 1; $right >= 1; $right -= 2) {
            if ($right === 6) $right = 5;
            for ($vert = 0; $vert < $n; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $c = $right - $j;
                    $upward = ((($right + 1) & 2) === 0);
                    $r = $upward ? $n - 1 - $vert : $vert;
                    if (!$this->fn[$r][$c] && $i < $bitLen) {
                        $this->m[$r][$c] = ($cw[$i >> 3] >> (7 - ($i & 7))) & 1;
                        $i++;
                    }
                }
            }
        }
    }

    private function applyMask(int $mask): void
    {
        for ($r = 0; $r < $this->size; $r++) for ($c = 0; $c < $this->size; $c++) {
            if ($this->fn[$r][$c]) continue;
            $invert = match ($mask) {
                0 => ($r + $c) % 2 === 0,
                1 => $r % 2 === 0,
                2 => $c % 3 === 0,
                3 => ($r + $c) % 3 === 0,
                4 => (intdiv($r, 2) + intdiv($c, 3)) % 2 === 0,
                5 => ($r * $c) % 2 + ($r * $c) % 3 === 0,
                6 => ((($r * $c) % 2) + (($r * $c) % 3)) % 2 === 0,
                7 => (((($r + $c) % 2) + (($r * $c) % 3)) % 2) === 0,
            };
            if ($invert) $this->m[$r][$c] ^= 1;
        }
    }

    private function drawFormat(int $mask): void
    {
        $n = $this->size;
        $bits = self::FORMAT_INFO[$this->ecl][$mask];
        $bit = fn(int $i) => ($bits >> $i) & 1;

        for ($i = 0; $i <= 5; $i++) $this->set($i, 8, $bit($i));
        $this->set(7, 8, $bit(6));
        $this->set(8, 8, $bit(7));
        $this->set(8, 7, $bit(8));
        for ($i = 9; $i < 15; $i++) $this->set(8, 14 - $i, $bit($i));

        for ($i = 0; $i < 8; $i++)  $this->set(8, $n - 1 - $i, $bit($i));
        for ($i = 8; $i < 15; $i++) $this->set($n - 15 + $i, 8, $bit($i));
        $this->set($n - 8, 8, 1);
    }

    /** ---------- mask penalty (ISO/IEC 18004 rules 1-4) ---------- */

    private function penalty(): int
    {
        $n = $this->size; $score = 0;

        // Rule 1 — runs of 5+
        for ($r = 0; $r < $n; $r++) {
            $run = 1;
            for ($c = 1; $c < $n; $c++) {
                if ($this->m[$r][$c] === $this->m[$r][$c - 1]) { $run++; }
                else { if ($run >= 5) $score += 3 + ($run - 5); $run = 1; }
            }
            if ($run >= 5) $score += 3 + ($run - 5);
        }
        for ($c = 0; $c < $n; $c++) {
            $run = 1;
            for ($r = 1; $r < $n; $r++) {
                if ($this->m[$r][$c] === $this->m[$r - 1][$c]) { $run++; }
                else { if ($run >= 5) $score += 3 + ($run - 5); $run = 1; }
            }
            if ($run >= 5) $score += 3 + ($run - 5);
        }

        // Rule 2 — 2x2 blocks
        for ($r = 0; $r < $n - 1; $r++) for ($c = 0; $c < $n - 1; $c++) {
            $v = $this->m[$r][$c];
            if ($v === $this->m[$r][$c+1] && $v === $this->m[$r+1][$c] && $v === $this->m[$r+1][$c+1]) $score += 3;
        }

        // Rule 3 — finder-like patterns
        $p1 = [1,0,1,1,1,0,1,0,0,0,0];
        $p2 = [0,0,0,0,1,0,1,1,1,0,1];
        for ($r = 0; $r < $n; $r++) for ($c = 0; $c <= $n - 11; $c++) {
            $rowSeg = array_slice($this->m[$r], $c, 11);
            if ($rowSeg === $p1 || $rowSeg === $p2) $score += 40;
        }
        for ($c = 0; $c < $n; $c++) for ($r = 0; $r <= $n - 11; $r++) {
            $colSeg = [];
            for ($k = 0; $k < 11; $k++) $colSeg[] = $this->m[$r + $k][$c];
            if ($colSeg === $p1 || $colSeg === $p2) $score += 40;
        }

        // Rule 4 — dark/light balance
        $dark = 0;
        foreach ($this->m as $row) $dark += array_sum($row);
        $ratio = $dark * 100 / ($n * $n);
        $score += (int) (abs($ratio - 50) / 5) * 10;

        return $score;
    }
}
