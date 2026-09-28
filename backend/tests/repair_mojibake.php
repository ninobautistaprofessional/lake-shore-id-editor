<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

/*
 * Repair tool for UTF-8 -> CP1252 -> UTF-8 double-encoding corruption.
 * For every codepoint that corresponds to a CP1252 byte (i.e. a character
 * produced by misreading the ORIGINAL UTF-8 bytes as CP1252), emit that byte.
 * Codepoints that were never part of the corruption (genuine multi-byte
 * characters such as emoji that have no CP1252 byte equivalent) are preserved.
 *
 * Usage: php repair.php --write <file...>
 *        php repair.php <file...>            (dry run)
 */

function buildMap() {
  $map = [];
  for ($i = 0xA0; $i <= 0xFF; $i++) $map[$i] = $i;
  $map[0x20AC] = 0x80; $map[0x0081] = 0x81; $map[0x201A] = 0x82; $map[0x0192] = 0x83;
  $map[0x201E] = 0x84; $map[0x2026] = 0x85; $map[0x2020] = 0x86; $map[0x2021] = 0x87;
  $map[0x02C6] = 0x88; $map[0x2030] = 0x89; $map[0x0160] = 0x8A; $map[0x2039] = 0x8B;
  $map[0x0152] = 0x8C; $map[0x008D] = 0x8D; $map[0x017D] = 0x8E; $map[0x008F] = 0x8F;
  $map[0x0090] = 0x90; $map[0x2018] = 0x91; $map[0x2019] = 0x92; $map[0x201C] = 0x93;
  $map[0x201D] = 0x94; $map[0x2022] = 0x95; $map[0x2013] = 0x96; $map[0x2014] = 0x97;
  $map[0x02DC] = 0x98; $map[0x2122] = 0x99; $map[0x0161] = 0x9A; $map[0x203A] = 0x9B;
  $map[0x0153] = 0x9C; $map[0x009D] = 0x9D; $map[0x017E] = 0x9E; $map[0x0178] = 0x9F;
  return $map;
}

function repair($path, $map) {
  $b = @file_get_contents($path);
  if ($b === false) return null;
  $out = '';
  $len = strlen($b);
  $i = 0;
  $invalid = 0;
  $changed = 0;
  $preserved = [];
  while ($i < $len) {
    $o = ord($b[$i]);
    if ($o < 0x80) { $out .= $b[$i]; $i++; continue; }
    $seqLen = 0;
    if (($o & 0xE0) === 0xC0) $seqLen = 2;
    elseif (($o & 0xF0) === 0xE0) $seqLen = 3;
    elseif (($o & 0xF8) === 0xF0) $seqLen = 4;
    else { $invalid++; $out .= $b[$i]; $i++; continue; }
    $seq = substr($b, $i, $seqLen);
    if (!mb_check_encoding($seq, 'UTF-8')) { $invalid++; $out .= $b[$i]; $i++; continue; }
    $cp = mb_ord($seq, 'UTF-8');
    if (isset($map[$cp])) {
      $out .= chr($map[$cp]);
      $changed++;
    } else {
      $out .= $seq;
      $key = "U+" . strtoupper(dechex($cp));
      $preserved[$key] = ($preserved[$key] ?? 0) + 1;
    }
    $i += $seqLen;
  }
  if (!mb_check_encoding($out, 'UTF-8')) {
    echo "  !! RESULT IS NOT VALID UTF-8 — aborting\n";
    return null;
  }
  return ['out' => $out, 'changed' => $changed, 'preserved' => $preserved, 'invalid' => $invalid];
}

$write = false;
$args = $argv;
array_shift($args);
if (($args[0] ?? '') === '--write') { $write = true; array_shift($args); }
$map = buildMap();

foreach ($args as $path) {
  $path = str_replace('\\', '/', $path);
  if (!file_exists($path)) { echo "MISSING: $path\n"; continue; }
  $r = repair($path, $map);
  if ($r === null) continue;
  echo "FILE:  $path\n";
  echo "  corrupted codepoints translated: {$r['changed']}\n";
  echo "  preserved (genuine): " . json_encode($r['preserved']) . "\n";
  echo "  invalid sequences skipped: {$r['invalid']}\n";
  if ($write) {
    $bak = $path . '.bak-mojibake';
    copy($path, $bak);
    file_put_contents($path, $r['out']);
    echo "  WROTE fixed file (backup: $bak)\n";
  } else {
    echo "  DRY RUN — no changes written\n";
  }
  echo "\n";
}