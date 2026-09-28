<?php
// Precise brace-depth analyzer for api.php: skips strings, comments, regex-ish content.
$lines = file('c:/xampp/htdocs/lake-shore-id-editor/backend/api.php', FILE_IGNORE_NEW_LINES);
$depth = 0;
$targets = [148, 348, 409, 426, 440, 452, 464, 473, 477, 478, 1070];
$inPhp = true;
foreach ($lines as $i => $line) {
    $n = $i + 1;
    $len = strlen($line);
    $j = 0;
    $state = 'code'; // code | sq | dq | lc | bc
    while ($j < $len) {
        $ch = $line[$j];
        $next = $j + 1 < $len ? $line[$j + 1] : '';
        if ($state === 'code') {
            if ($ch === "'") $state = 'sq';
            elseif ($ch === '"') $state = 'dq';
            elseif ($ch === '/' && $next === '/') { break; } // line comment, rest ignored
            elseif ($ch === '#') { break; }
            elseif ($ch === '/' && $next === '*') { $state = 'bc'; $j += 2; continue; }
            elseif ($ch === '{') $depth++;
            elseif ($ch === '}') $depth--;
        } elseif ($state === 'sq') {
            if ($ch === '\\') $j++;
            elseif ($ch === "'") $state = 'code';
        } elseif ($state === 'dq') {
            if ($ch === '\\') $j++;
            elseif ($ch === '"') $state = 'code';
        } elseif ($state === 'bc') {
            if ($ch === '*' && $next === '/') { $state = 'code'; $j += 2; continue; }
        }
        $j++;
    }
    if (in_array($n, $targets)) {
        echo "line $n depth=$depth :: " . trim(substr($line, 0, 90)) . "\n";
    }
}
echo "FINAL depth=$depth\n";
