<?php
/* Coarse luminance heat map of the intended-design ID samples.
 * '#' dark ... '.' light. Locates the logo blob + text bands. */
function heat(string $file, int $cols = 40, int $rows = 56): void {
    $info = @getimagesize($file);
    if (!$info) { echo "skip $file\n"; return; }
    $img = str_ends_with($file, '.png') ? imagecreatefrompng($file) : imagecreatefromjpeg($file);
    $w = imagesx($img); $h = imagesy($img);
    $cw = (int) ceil($w / $cols); $ch = (int) ceil($h / $rows);
    $chars = '@#*+=:. '; // dark -> light
    for ($ry = 0; $ry < $rows; $ry++) {
        $line = '';
        for ($rx = 0; $rx < $cols; $rx++) {
            $sum = 0; $n = 0;
            for ($y = $ry * $ch; $y < min(($ry + 1) * $ch, $h); $y += 3) {
                for ($x = $rx * $cw; $x < min(($rx + 1) * $cw, $w); $x += 3) {
                    $rgb = imagecolorat($img, $x, $y);
                    $sum += (0.299 * (($rgb >> 16) & 0xFF) + 0.587 * (($rgb >> 8) & 0xFF) + 0.114 * ($rgb & 0xFF));
                    $n++;
                }
            }
            $lum = $n ? $sum / $n : 255;
            $line .= $chars[min(7, (int) ($lum / 32))];
        }
        echo sprintf('%3d ', (int) ($ry * $ch / $h * 100)), $line, PHP_EOL;
    }
    imagedestroy($img);
}
$f = $argv[1] ?? (__DIR__ . '/../../id sample design and size/ABDILLAH, WAFAH B. FRONT.jpg');
heat($f);
