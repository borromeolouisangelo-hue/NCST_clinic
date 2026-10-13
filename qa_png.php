<?php
// Build a 400x240 test PNG without GD (raw RGB + zlib), like the test helper.
$w = 400;
$h = 240;
$raw = '';
for ($y = 0; $y < $h; $y++) {
    $raw .= "\x00";
    for ($x = 0; $x < $w; $x++) {
        // simple two-tone pattern with a border so aspect ratio is obvious
        $edge = ($x < 6 || $y < 6 || $x >= $w - 6 || $y >= $h - 6);
        $raw .= $edge ? "\x20\x20\x80" : (($x + $y) % 40 < 20 ? "\x40\xa0\x40" : "\xf0\xf0\xd0");
    }
}
$chunk = fn (string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
$png = "\x89PNG\r\n\x1a\n"
    . $chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0))
    . $chunk('IDAT', gzcompress($raw))
    . $chunk('IEND', '');
file_put_contents('C:/Users/Louis/AppData/Local/Temp/consultqa/preview400x240.png', $png);
echo 'written ' . strlen($png) . ' bytes';
