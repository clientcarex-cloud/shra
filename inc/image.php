<?php
/**
 * Logo normalisation. Whatever the user uploads — a 4000px phone photo, a
 * screenshot with a wide white margin, a sideways JPEG — this turns it into a
 * tidy, small PNG that sits correctly everywhere in the app.
 */

const LOGO_MAX = 512;          // longest edge of the stored logo, in pixels

function gd_available(): bool
{
    return function_exists('imagecreatetruecolor') && function_exists('imagepng');
}

/** Load an uploaded file into a GD image, or null if unsupported. */
function img_load(string $file, int $type)
{
    return match ($type) {
        IMAGETYPE_PNG  => function_exists('imagecreatefrompng')  ? @imagecreatefrompng($file)  : null,
        IMAGETYPE_JPEG => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($file) : null,
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file) : null,
        default        => null,
    } ?: null;
}

/** Phone cameras store rotation in EXIF rather than rotating the pixels. */
function img_fix_orientation($im, string $file, int $type)
{
    if ($type !== IMAGETYPE_JPEG || !function_exists('exif_read_data') || !function_exists('imagerotate')) return $im;
    $exif = @exif_read_data($file);
    $o = (int)($exif['Orientation'] ?? 0);
    $angle = match ($o) { 3 => 180, 6 => -90, 8 => 90, default => 0 };
    if (!$angle) return $im;
    $rot = @imagerotate($im, $angle, 0);
    if (!$rot) return $im;
    imagedestroy($im);
    return $rot;
}

/**
 * Crop away a uniform border (the white card most logos are exported on, or a
 * transparent margin) so the mark actually fills its box.
 * Conservative: gives up rather than cropping into the artwork.
 */
function img_trim_border($im)
{
    $w = imagesx($im); $h = imagesy($im);
    if ($w < 8 || $h < 8) return $im;

    $corner = imagecolorat($im, 0, 0);
    $ca = ($corner >> 24) & 0x7F;
    $cr = ($corner >> 16) & 0xFF; $cg = ($corner >> 8) & 0xFF; $cb = $corner & 0xFF;

    // All four corners must agree, otherwise there is no uniform border to trim.
    foreach ([[$w - 1, 0], [0, $h - 1], [$w - 1, $h - 1]] as [$x, $y]) {
        $c = imagecolorat($im, $x, $y);
        if ((($c >> 24) & 0x7F) !== $ca) return $im;
        if ($ca < 127 && (abs((($c >> 16) & 0xFF) - $cr) > 10
                       || abs((($c >> 8) & 0xFF) - $cg) > 10
                       || abs(($c & 0xFF) - $cb) > 10)) return $im;
    }

    $matches = function ($c) use ($ca, $cr, $cg, $cb) {
        $a = ($c >> 24) & 0x7F;
        if ($ca >= 127) return $a >= 127;                    // transparent border
        if ($a !== $ca) return false;
        return abs((($c >> 16) & 0xFF) - $cr) <= 12
            && abs((($c >> 8) & 0xFF) - $cg) <= 12
            && abs(($c & 0xFF) - $cb) <= 12;
    };

    $top = 0; $bottom = $h - 1; $left = 0; $right = $w - 1;
    $rowUniform = function ($y) use ($im, $w, $matches) {
        for ($x = 0; $x < $w; $x++) if (!$matches(imagecolorat($im, $x, $y))) return false;
        return true;
    };
    $colUniform = function ($x) use ($im, $h, $matches) {
        for ($y = 0; $y < $h; $y++) if (!$matches(imagecolorat($im, $x, $y))) return false;
        return true;
    };
    while ($top    < $bottom && $rowUniform($top))    $top++;
    while ($bottom > $top    && $rowUniform($bottom)) $bottom--;
    while ($left   < $right  && $colUniform($left))   $left++;
    while ($right  > $left   && $colUniform($right))  $right--;

    $nw = $right - $left + 1; $nh = $bottom - $top + 1;
    if ($nw < 16 || $nh < 16) return $im;                    // would destroy the image
    if ($nw === $w && $nh === $h) return $im;                // nothing to trim

    // Keep a hair of breathing room so the mark is not flush to the edge.
    $pad  = (int) round(max($nw, $nh) * 0.03);
    $left = max(0, $left - $pad); $top = max(0, $top - $pad);
    $nw   = min($w - $left, $nw + $pad * 2);
    $nh   = min($h - $top,  $nh + $pad * 2);

    $out = imagecreatetruecolor($nw, $nh);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
    imagecopy($out, $im, 0, 0, $left, $top, $nw, $nh);
    imagedestroy($im);
    return $out;
}

/** Scale down to fit LOGO_MAX on the longest edge. Never enlarges. */
function img_fit($im, int $max = LOGO_MAX)
{
    $w = imagesx($im); $h = imagesy($im);
    if ($w <= $max && $h <= $max) return $im;

    $scale = $max / max($w, $h);
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));

    $out = imagecreatetruecolor($nw, $nh);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
    imagecopyresampled($out, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($im);
    return $out;
}

/**
 * Turn an uploaded image into assets/img/logo-custom.png.
 * @return array{ok:bool, msg:string, w?:int, h?:int, from?:string, bytes?:int, tmp?:string}
 * On success the caller renames ['tmp'] into place.
 */
function normalize_logo(string $srcFile, int $type, string $destDir): array
{
    if (!gd_available()) {
        return ['ok' => false, 'msg' => 'no-gd'];
    }
    $info = @getimagesize($srcFile);
    if (!$info) return ['ok' => false, 'msg' => 'unreadable'];

    $im = img_load($srcFile, $type);
    if (!$im) return ['ok' => false, 'msg' => 'unsupported'];

    $fromW = imagesx($im); $fromH = imagesy($im);

    imagealphablending($im, false);
    imagesavealpha($im, true);

    $im = img_fix_orientation($im, $srcFile, $type);
    $im = img_trim_border($im);
    $im = img_fit($im);

    imagealphablending($im, false);
    imagesavealpha($im, true);

    // Write beside the final file under an unguessable name, then rename in
    // place — atomic, same filesystem, and nothing predictable in /tmp.
    $tmp = $destDir . '/.logo-' . bin2hex(random_bytes(8)) . '.png';
    $ok  = imagepng($im, $tmp, 6);
    $w = imagesx($im); $h = imagesy($im);
    imagedestroy($im);

    if (!$ok || !is_file($tmp)) { @unlink($tmp); return ['ok' => false, 'msg' => 'write-failed']; }

    $bytes = (int) @filesize($tmp);
    return ['ok' => true, 'msg' => 'ok', 'w' => $w, 'h' => $h,
            'from' => $fromW . '×' . $fromH, 'bytes' => $bytes, 'tmp' => $tmp];
}
