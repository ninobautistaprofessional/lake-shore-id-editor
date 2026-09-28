<?php
/*|--------------------------------------------------------------------------| Student Import engine (CSV / XLSX / XLS) — Lake Shore ID Management System|--------------------------------------------------------------------------| Workflow: Upload -> Validate -> Preview -> Detect duplicates -> Import|           -> Generate IDs (existing editor / print flow).|| - Pure parsers importParseCsv() / importParseXlsx() / importParseXls()|   return [ 'SHEET NAME' => [ ['row' => excelRow, 'cells' => [...]] ] ].| - importCollectRows() maps every sheet to typed student rows. The|   department comes from an optional Department column, else the sheet|   name (COLLEGE / BASIC EDUCATION), else auto-detected from the course|   or grade level. Blank rows and the INSTRUCTIONS sheet are skipped.| - importValidateRows() reuses the shared normalizeCardByType() rules|   (course allowlist, grade bands, 12-digit LRN) and adds batch checks:|   required fields, field formats, duplicate Student Numbers / LRNs|   inside the file and students already existing in the database.| - importCommitRows() inserts the valid rows in ONE transaction while|   holding GET_LOCK('lsc_card_write') (the same lock saveCard uses), so|   a failed import never leaves partial records and existing students|   are never imported twice. Signatories are auto-assigned with the|   same department mapping the public self-service flow uses.| - Photos stay optional: a Photo File entry must name a file that|   already exists in backend/uploads/students/, otherwise the row is|   imported without a photo (warning shown in the preview).*/const IMPORT_MAX_ROWS = 5000;
const IMPORT_MAX_FILE_BYTES = 10485760; /* 10 MB */
const IMPORT_DEFAULT_YEAR = '2026-2027';
const IMPORT_PHOTO_DIR = __DIR__ . '/uploads/students';
const IMPORT_COURSES = [
  'BACHELOR OF SCIENCE IN PSYCHOLOGY', 'BACHELOR OF SPECIAL NEEDS EDUCATION',
  'BACHELOR OF TECHNOLOGY AND LIVELIHOOD EDUCATION', 'BACHELOR OF SCIENCE IN ACCOUNTANCY',
  'BACHELOR OF SCIENCE IN REAL ESTATE MANAGEMENT', 'BACHELOR OF SCIENCE IN CRIMINOLOGY',
  'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY', 'BACHELOR OF ELEMENTARY EDUCATION',
  'BACHELOR OF SECONDARY EDUCATION', 'BACHELOR OF SCIENCE IN BUSINESS ADMINISTRATION',
  'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT', 'BACHELOR OF SCIENCE IN TOURISM MANAGEMENT',
  'BACHELOR OF SCIENCE IN ENTREPRENEURSHIP', 'BACHELOR OF PHYSICAL EDUCATION',
  'BACHELOR OF EARLY CHILDHOOD EDUCATION', 'BACHELOR OF SCIENCE IN ARCHITECTURE',
  'BACHELOR OF FINE ARTS', 'BACHELOR OF PERFORMING ARTS',
  'BACHELOR OF SCIENCE IN SOCIAL WORK', 'BACHELOR OF SCIENCE IN COMMUNICATION',
  'BACHELOR OF SCIENCE IN NURSING', 'BACHELOR OF SCIENCE IN MIDWIFERY',
  'BACHELOR OF SCIENCE IN PHARMACY', 'BACHELOR OF SCIENCE IN MEDICAL TECHNOLOGY',
  'BACHELOR OF SCIENCE IN RADIOLOGIC TECHNOLOGY', 'BACHELOR OF SCIENCE IN PHYSICAL THERAPY',
  'BACHELOR OF SCIENCE IN OCCUPATIONAL THERAPY', 'BACHELOR OF SCIENCE IN RESPIRATORY THERAPY',
  'BACHELOR OF SCIENCE IN SPEECH-LANGUAGE PATHOLOGY', 'BACHELOR OF SCIENCE IN NUTRITION AND DIETETICS',
  'BACHELOR OF SCIENCE IN PUBLIC HEALTH', 'BACHELOR OF SCIENCE IN ENVIRONMENTAL SCIENCE',
  'BACHELOR OF SCIENCE IN BIOLOGY', 'BACHELOR OF SCIENCE IN CHEMISTRY',
  'BACHELOR OF SCIENCE IN PHYSICS', 'BACHELOR OF SCIENCE IN MATHEMATICS',
  'BACHELOR OF SCIENCE IN STATISTICS', 'BACHELOR OF SCIENCE IN COMPUTER SCIENCE',
  'BACHELOR OF SCIENCE IN MARINE BIOLOGY', 'BACHELOR OF SCIENCE IN GEOLOGY',
  'BACHELOR OF SCIENCE IN AGRICULTURE', 'BACHELOR OF SCIENCE IN FISHERIES',
  'BACHELOR OF SCIENCE IN FORESTRY', 'BACHELOR OF SCIENCE IN VETERINARY MEDICINE',
  'BACHELOR OF SCIENCE IN DENTISTRY', 'BACHELOR OF SCIENCE IN OPTOMETRY',
];
/* Default signatory per department (matches the public self-service flow). */
const IMPORT_SIGNATORY_MAP = [
  'COLLEGE' => 'College Registrar',
  'JUNIOR_HIGH' => 'JHS Principal',
  'SENIOR_HIGH' => 'SHS Principal',
];

/* --- small shared helpers --- */
function importColIndex(string $ref): int {
  $col = 0;
  for ($i = 0; $i < strlen($ref); $i++) { $ch = $ref[$i]; if ($ch >= 'A' && $ch <= 'Z') $col = $col * 26 + (ord($ch) - 64); }
  return $col - 1;
}
function importColLetter(int $idx): string {
  $s = '';
  $n = $idx + 1;
  while ($n > 0) { $n--; $s = chr(65 + ($n % 26)) . $s; $n = intdiv($n, 26); }
  return $s;
}
function importNumToStr(float $n): string {
  if ($n == (int)$n && !is_infinite($n) && $n >= -9007199254740992 && $n <= 9007199254740992) return (string)(int)$n;
  $s = rtrim(rtrim(sprintf('%.10F', $n), '0'), '.');
  return $s;
}
/* Fill missing columns with '' so every row of a sheet has equal width. */
function importDensifyRows(array $rows): array {
  $maxCol = -1;
  foreach ($rows as $r) { $ks = array_keys($r['cells']); $m = $ks ? max($ks) : -1; if ($m > $maxCol) $maxCol = $m; }
  foreach ($rows as &$r) { $dense = []; for ($c = 0; $c <= $maxCol; $c++) $dense[] = isset($r['cells'][$c]) ? (string)$r['cells'][$c] : ''; $r['cells'] = $dense; }
  unset($r);
  return $rows;
}
/* -------------------------------------------------------------- CSV parser */
function importParseCsv(string $path): array {
  $raw = @file_get_contents($path);
  if ($raw === false || $raw === '') throw new RuntimeException('The CSV file is empty or unreadable.');
  if (substr($raw, 0, 3) === "\xEF\xBB\xBF") $raw = substr($raw, 3);
  elseif (substr($raw, 0, 2) === "\xFF\xFE") $raw = mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16LE');
  elseif (substr($raw, 0, 2) === "\xFE\xFF") $raw = mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE');
  elseif (!mb_check_encoding($raw, 'UTF-8')) $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
  $firstLine = strtok($raw, "\r\n") ?: '';
  $delim = ','; $best = -1;
  foreach ([',', ';', "\t", '|'] as $d) { $n = substr_count($firstLine, $d); if ($n > $best) { $best = $n; $delim = $d; } }
  $rows = []; $cell = ''; $rowCells = []; $inQuotes = false; $rowNo = 1; $started = false;
  $len = strlen($raw);
  for ($i = 0; $i < $len; $i++) {
    $ch = $raw[$i];
    if ($inQuotes) { if ($ch === '"') { if ($i + 1 < $len && $raw[$i + 1] === '"') { $cell .= '"'; $i++; } else $inQuotes = false; } else $cell .= $ch; }
    elseif ($ch === '"') { $inQuotes = true; $started = true; }
    elseif ($ch === $delim) { $rowCells[] = $cell; $cell = ''; $started = true; }
    elseif ($ch === "\n" || $ch === "\r") { if ($ch === "\r" && $i + 1 < $len && $raw[$i + 1] === "\n") $i++; $rowCells[] = $cell; $cell = ''; $rows[] = ['row' => $rowNo++, 'cells' => $rowCells]; $rowCells = []; $started = false; }
    else { $cell .= $ch; $started = true; }
  }
  if ($cell !== '' || $rowCells || $started) { $rowCells[] = $cell; $rows[] = ['row' => $rowNo, 'cells' => $rowCells]; }
  return ['CSV' => importDensifyRows($rows)];
}
/* ------------------------------------------------------------ XLSX parser */

/* --- Pure-PHP ZIP primitives (no zip extension required) ----------------- */

/* Extract one entry by name from an in-memory ZIP (STORE + DEFLATE). */
function importZipExtract(string $data, string $wanted): ?string {
  $eocd = strrpos($data, "PK\x05\x06");
  if ($eocd === false || $eocd + 22 > strlen($data)) return null;
  $count = unpack('v', substr($data, $eocd + 10, 2))[1];
  $cdOffset = unpack('V', substr($data, $eocd + 16, 4))[1];
  $pos = $cdOffset;
  for ($i = 0; $i < $count; $i++) {
    if (substr($data, $pos, 4) !== "PK\x01\x02") return null;
    $method = unpack('v', substr($data, $pos + 10, 2))[1];
    $compSize = unpack('V', substr($data, $pos + 20, 4))[1];
    $nameLen = unpack('v', substr($data, $pos + 28, 2))[1];
    $extraLen = unpack('v', substr($data, $pos + 30, 2))[1];
    $commentLen = unpack('v', substr($data, $pos + 32, 2))[1];
    $lho = unpack('V', substr($data, $pos + 42, 4))[1];
    $name = substr($data, $pos + 46, $nameLen);
    if ($name === $wanted) {
      $lnLen = unpack('v', substr($data, $lho + 26, 2))[1];
      $leLen = unpack('v', substr($data, $lho + 28, 2))[1];
      $payload = substr($data, $lho + 30 + $lnLen + $leLen, $compSize);
      if ($method === 8 && function_exists('gzinflate')) $payload = gzinflate($payload);
      return $payload;
    }
    $pos += 46 + $nameLen + $extraLen + $commentLen;
  }
  return null;
}

/* Build a ZIP from a name => data map using STORE (no compression). */
function importZipBuild(array $entries): string {
  $local = '';
  $central = '';
  $offset = 0;
  foreach ($entries as $name => $data) {
    $crc = crc32($data);
    $size = strlen($data);
    $nameLen = strlen($name);
    $local .= "PK\x03\x04" . pack('v', 20) . pack('v', 0) . pack('v', 0)
      . pack('v', 0) . pack('v', 0) . pack('V', $crc) . pack('V', $size) . pack('V', $size)
      . pack('v', $nameLen) . pack('v', 0) . $name . $data;
    $central .= "PK\x01\x02" . pack('v', 20) . pack('v', 20) . pack('v', 0) . pack('v', 0)
      . pack('v', 0) . pack('v', 0) . pack('V', $crc) . pack('V', $size) . pack('V', $size)
      . pack('v', $nameLen) . pack('v', 0) . pack('v', 0) . pack('v', 0) . pack('v', 0)
      . pack('V', 0x20) . pack('V', $offset) . $name;
    $offset += 30 + $nameLen + $size;
  }
  return $local . $central
    . "PK\x05\x06" . pack('v', 0) . pack('v', 0) . pack('v', count($entries))
    . pack('v', count($entries)) . pack('V', strlen($central)) . pack('V', $offset) . pack('v', 0);
}

function importParseXlsx(string $path): array {
  $blob = @file_get_contents($path);
  if ($blob === false || $blob === '') throw new RuntimeException('The workbook is empty or unreadable.');
  if (substr($blob, 0, 2) !== 'PK') throw new RuntimeException('The workbook could not be opened - it may be corrupt or not a real Excel file.');
  $get = fn(string $n): ?string => importZipExtract($blob, $n);
  $shared = [];
  $ss = $get('xl/sharedStrings.xml');
  if ($ss !== false && ($x = @simplexml_load_string($ss)) !== false) {
    foreach ($x->si as $si) { $text = ''; if (isset($si->t)) $text = (string)$si->t; if (isset($si->r)) foreach ($si->r as $r) if (isset($r->t)) $text .= (string)$r->t; $shared[] = $text; }
  }
  $wb = $get('xl/workbook.xml');
  if ($wb === null) throw new RuntimeException('Not a valid .xlsx workbook.');
  $wx = @simplexml_load_string($wb);
  if ($wx === false || !isset($wx->sheets->sheet)) throw new RuntimeException('Not a valid .xlsx workbook.');
  $rels = [];
  $relXml = $get('xl/_rels/workbook.xml.rels');
  if ($relXml !== false && ($rx = @simplexml_load_string($relXml)) !== false) { foreach ($rx->Relationship as $rel) $rels[(string)$rel['Id']] = (string)$rel['Target']; }
  $RNS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
  $sheets = [];
  foreach ($wx->sheets->sheet as $s) {
    $name = (string)$s['name'];
    $rid = '';
    foreach ($s->attributes($RNS) as $k => $v) if ($k === 'id') $rid = (string)$v;
    $target = ltrim(str_replace('\\', '/', $rels[$rid] ?? ''), '/');
    if ($target !== '' && strpos($target, 'xl/') !== 0) $target = 'xl/' . $target;
    $xml = $target !== '' ? $get($target) : null;
    $rows = [];
    if ($xml !== null && ($sx = @simplexml_load_string($xml)) !== false) {
      $seq = 0;
      foreach ($sx->sheetData->row as $r) {
        $seq++; $rowNo = isset($r['r']) ? (int)$r['r'] : $seq; $cells = [];
        foreach ($r->c as $c) {
          $ref = (string)$c['r']; $col = $ref !== '' ? importColIndex($ref) : count($cells);
          $t = (string)$c['t']; $val = '';
          if ($t === 's') { $val = $shared[(int)(string)$c->v] ?? ''; }
          elseif ($t === 'inlineStr') { if (isset($c->is)) foreach ($c->is->t as $tt) $val .= (string)$tt; }
          elseif ($t === 'str' || $t === 'e') { $val = (string)$c->v; }
          elseif ($t === 'b') { $val = ((string)$c->v) === '1' ? 'TRUE' : 'FALSE'; }
          else { $raw = (string)$c->v; $val = is_numeric($raw) ? importNumToStr((float)$raw) : $raw; }
          $cells[$col] = $val;
        }
        ksort($cells);
        $rows[] = ['row' => $rowNo, 'cells' => $cells];
      }
    }
    $sheets[$name] = importDensifyRows($rows);
  }
  return $sheets;
}
/* ---------------------------------------------- XLS (BIFF8) parser — OLE2 */

/* Decode an Excel RK value (compact number encoding). */
function importRkDecode(int $rk): float {
  $div100 = ($rk & 0x02) !== 0;
  if (($rk & 0x01) !== 0) {
    $v = $rk >> 2;
    if ($v >= (1 << 29)) $v -= (1 << 30); /* 30-bit signed int */
    $result = $div100 ? $v / 100 : $v;
  } else {
    /* The IEEE 754 double is stored in the upper 30 bits of the shifted value. */
    $bits = ($rk & 0xFFFFFFFC) << 2;
    $result = unpack('d', pack('V', $bits) . "\0\0\0\0")[1];
    if ($div100) $result /= 100;
  }
  return $result;
}
/* Read the OLE2 Compound Document directory and return the full Workbook stream. */
function importOleWorkbook(string $data): string {
  $sectorShift = importU16($data, 30);
  $sectorSize = 1 << $sectorShift;
  $miniSectorShift = importU16($data, 32);
  $miniSectorSize = 1 << $miniSectorShift;
  $cDir = importU32($data, 44);
  $fatSectors = importU32($data, 48);
  $firstDirSect = importU32($data, 60);
  $miniCutoff = importU32($data, 56);
  $firstMiniFat = importU32($data, 64);
  $miniFatSectors = importU32($data, 68);
  $difat = [];
  for ($i = 0; $i < 109; $i++) $difat[] = importU32($data, 76 + $i * 4);
  $readFat = function (int $sect) use ($data, $sectorSize, $difat): int {
    $per = ($sectorSize / 4) - 1;
    $fatSector = intdiv($sect, $per) + 1;
    $offset = 0;
    for ($f = 0; $f < $fatSector; $f++) {
      $offset = $difat[$f];
      if ($offset >= 0xFFFFFFFA) return -1;
      $offset = ($offset + 1) * $sectorSize;
      $per2 = $sectorSize / 4;
      $newDifat = [];
      for ($j = 0; $j < $per2; $j++) {
        $v = importU32($data, $offset + $j * 4);
        if ($v === 0xFFFFFFFE || $v === 0xFFFFFFFF) break;
        $newDifat[] = $v;
      }
      $difat = $newDifat;
    }
    $idx = $sect % $per;
    return importU32($data, $offset + $idx * 4);
  };
  $chain = [];
  $sect = $firstDirSect;
  while ($sect < 0xFFFFFFFA) { $chain[] = $sect; $sect = $readFat($sect); }
  $dir = '';
  foreach ($chain as $s) $dir .= substr($data, ($s + 1) * $sectorSize, $sectorSize);
  /* Walk the directory entries to find the Workbook (code 0x06 00). */
  $workbook = '';
  for ($i = 0; $i < $cDir; $i++) {
    $entry = substr($dir, $i * 128, 128);
    if (strlen($entry) < 128) break;
    $entryType = ord($entry[66]);
    if ($entryType !== 0x00) {
      $streamSize = importU32($entry, 120);
      $startSect = importU32($entry, 116);
      if ($streamSize > 0 && $streamSize < 0xFFFF && $startSect < 0xFFFFFFFA) {
        $chain = [];
        $sect = $startSect;
        while ($sect < 0xFFFFFFFA) { $chain[] = $sect; $sect = $readFat($sect); }
        $stream = '';
        foreach ($chain as $s) $stream .= substr($data, ($s + 1) * $sectorSize, $sectorSize);
        $workbook .= substr($stream, 0, $streamSize);
      }
    }
  }
  return $workbook;
}
/* Parse the first worksheet of a BIFF8 (.xls) workbook into rows. */
function importParseXls(string $path): array {
  $data = @file_get_contents($path);
  if ($data === false || strlen($data) < 512) throw new RuntimeException('The .xls file is empty or unreadable.');
  if (substr($data, 0, 8) !== "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
    if (stripos(substr($data, 0, 1024), '<table') !== false || stripos(substr($data, 0, 1024), '<html') !== false)
      throw new RuntimeException('This .xls file is an HTML export. Please re-save it from Excel as "Excel Workbook (*.xlsx)" or CSV and upload again.');
    throw new RuntimeException('Not a valid Excel 97-2003 (.xls) file.');
  }
  $wb = importOleWorkbook($data);
  if ($wb === null || $wb === '') throw new RuntimeException('The .xls file does not contain a readable workbook stream.');
  $len = strlen($wb);
  /* ---- pass 1: workbook globals — BOUNDSHEET offsets + the SST ---- */
  $sst = []; $firstSheet = -1; $sheetName = 'Sheet1';
  $off = 0;
  while ($off + 4 <= $len) {
    $type = importU16($wb, $off); $size = importU16($wb, $off + 2);
    if ($off + 4 + $size > $len) break;
    $p = substr($wb, $off + 4, $size);
    if ($type === 0x0085) { /* BOUNDSHEET: lbPlyPos, grbit, short name */
      $pos = importU32($p, 0); $grbit = importU16($p, 4);
      if ($firstSheet < 0 && ($grbit & 0x0003) === 0 && $pos > 0 && $pos < $len) {
        $firstSheet = $pos;
        $sheetName = importXlShortString(substr($p, 6));
        if ($sheetName === '') $sheetName = 'Sheet1';
      }
    } elseif ($type === 0x00FC) { /* SST (+ CONTINUE records that follow) */
      $chunks = [$p];
      $off2 = $off + 4 + $size;
      while ($off2 + 4 <= $len) {
        $t2 = importU16($wb, $off2); $s2 = importU16($wb, $off2 + 2);
        if ($t2 !== 0x003C || $off2 + 4 + $s2 > $len) break;
        $chunks[] = substr($wb, $off2 + 4, $s2);
        $off2 += 4 + $s2;
      }
      $sst = importParseSst($chunks);
    }
    $off += 4 + $size;
  }
  if ($firstSheet < 0) throw new RuntimeException('The .xls workbook has no readable worksheet.');
  /* ---- pass 2: cell records of the first worksheet substream ---- */
  $rows = []; $pendR = -1; $pendC = -1;
  $off = $firstSheet;
  while ($off + 4 <= $len) {
    $type = importU16($wb, $off); $size = importU16($wb, $off + 2);
    if ($off + 4 + $size > $len) break;
    $p = substr($wb, $off + 4, $size);
    if ($type === 0x000A) break; /* EOF of the sheet substream */
    if ($size >= 4) switch ($type) {
      case 0x00FD: /* LABELSST */
        $rows[importU16($p, 0)][importU16($p, 2)] = $sst[importU32($p, 6)] ?? '';
        break;
      case 0x0203: /* NUMBER */
        $rows[importU16($p, 0)][importU16($p, 2)] = importNumToStr(unpack('e', substr($p, 6, 8))[1]);
        break;
      case 0x027E: /* RK */
        $rows[importU16($p, 0)][importU16($p, 2)] = importNumToStr(importRkDecode(importU32($p, 6)));
        break;
      case 0x00BD: /* MULRK: row, colFirst, n*(xf, rk), colLast */
        $r = importU16($p, 0); $c = importU16($p, 2); $cLast = importU16($p, strlen($p) - 2);
        $ptr = 4;
        for (; $c <= $cLast && $ptr + 6 <= strlen($p); $c++, $ptr += 6) {
          $rows[$r][$c] = importNumToStr(importRkDecode(importU32($p, $ptr + 2)));
        }
        break;
      case 0x0205: /* BOOLERR */
        $b = ord($p[6]);
        $rows[importU16($p, 0)][importU16($p, 2)] = ord($p[7]) === 0 ? ($b ? 'TRUE' : 'FALSE') : '';
        break;
      case 0x0204: /* LABEL (BIFF8 xlUnicodeString inline) */
        $r = importU16($p, 0); $c = importU16($p, 2);
        importXlString(substr($p, 6), 0, $s);
        $rows[$r][$c] = $s;
        break;
      case 0x0006: /* FORMULA — cached result */
        $r = importU16($p, 0); $c = importU16($p, 2);
        $tail = substr($p, 6, 8);
        if (strlen($tail) === 8 && substr($tail, 6, 2) === "\xFF\xFF") {
          $kind = ord($tail[4]);
          if ($kind === 0) { $pendR = $r; $pendC = $c; } /* string in next 0x0207 */
          elseif ($kind === 1) { $rows[$r][$c] = ord($tail[5]) ? 'TRUE' : 'FALSE'; }
          /* 2 = error, 3 = blank string -> leave empty */
        } else {
          $rows[$r][$c] = importNumToStr(unpack('e', $tail . str_repeat("\0", 8 - strlen($tail)))[1]);
        }
        break;
      case 0x0207: /* STRING — cached formula string result */
        if ($pendR >= 0) {
          importXlString($p, 0, $s);
          $rows[$pendR][$pendC] = $s;
          $pendR = -1;
        }
        break;
    }
    $off += 4 + $size;
  }
  ksort($rows);
  $out = [];
  foreach ($rows as $r => $cells) $out[] = ['row' => $r + 1, 'cells' => $cells];
  return [$sheetName => importDensifyRows($out)];
}

/* ------------------------------------- XLSX template writer (no libraries) */

/* Build a single worksheet's sheetData XML from a 2D array of cell values. */
function importXlsxSheetXml(array $rows): string {
  $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
  foreach (array_values($rows) as $ri => $cells) {
    $xml .= '<row r="' . ($ri + 1) . '">';
    foreach (array_values($cells) as $ci => $val) {
      $val = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string)$val);
      if ($val === '') continue;
      $xml .= '<c r="' . importColLetter($ci) . ($ri + 1) . '" t="inlineStr"><is><t xml:space="preserve">'
        . htmlspecialchars($val, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</t></is></c>';
    }
    $xml .= '</row>';
  }
  return $xml . '</sheetData></worksheet>';
}

/* Build a minimal but valid .xlsx workbook: [ name => rows-of-cell-arrays ]. */
function importBuildXlsx(array $sheets): string {
  $entries = [];
  $overrides = ''; $sheetTags = ''; $rels = '';
  $i = 0;
  foreach ($sheets as $name => $rows) {
    $i++;
    $overrides .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    $sheetTags .= '<sheet name="' . htmlspecialchars((string)$name, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '" sheetId="' . $i . '" r:id="rId' . $i . '"/>';
    $rels .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
    $entries['xl/worksheets/sheet' . $i . '.xml'] = importXlsxSheetXml($rows);
  }
  $entries['[Content_Types].xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
    . '<Default Extension="xml" ContentType="application/xml"/>'
    . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
    . $overrides . '</Types>';
  $entries['_rels/.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
  $entries['xl/workbook.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
    . '<sheets>' . $sheetTags . '</sheets></workbook>';
  $entries['xl/_rels/workbook.xml.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>';
  return importZipBuild($entries);
}

/* GET importTemplate — download the official import template. */
function importSendTemplate(): void {
  if (ob_get_level() === 0) ob_start();
  $basic = [
    ['Student Name (Required)', 'Student Number (Required)', 'LRN (Optional)', 'Grade Level (Required)', 'Section (Required)', 'Address Line 1 (Optional)', 'Address Line 2 (Optional)', 'Emergency Contact (Optional)', 'Contact Number (Optional)', 'Photo File (Optional)', 'Academic Year (Optional)', 'School Year (Optional)'],
    ['DELA CRUZ, JUAN M.', '2026-0001', '136501010001', 'Grade 7', 'Sampaguita', 'Purok 1, Brgy. Masaya', '', 'MARIA DELA CRUZ', '09171234567', '', '2026-2027', '2026-2027'],
    ['SANTOS, MARIA L.', '2026-0002', '', 'Grade 11', 'Molave', 'Purok 2, Brgy. Maligaya', '', 'JOSE SANTOS', '09181234567', '', '', ''],
  ];
  $college = [
    ['Student Name (Required)', 'Student Number (Required)', 'Course (Required)', 'Section (Optional)', 'Address Line 1 (Optional)', 'Address Line 2 (Optional)', 'Emergency Contact (Optional)', 'Contact Number (Optional)', 'Photo File (Optional)', 'Academic Year (Optional)', 'School Year (Optional)'],
    ['REYES, CARLOS D.', '2026-1001', 'Bachelor of Science in Psychology', 'A', 'Purok 3, Brgy. Malinis', '', 'CARMEN REYES', '09191234567', '', '', ''],
    ['AQUINO, LISA R.', '2026-1002', 'BACHELOR OF SCIENCE IN CRIMINOLOGY', 'B', 'Purok 4, Brgy. Maligaya', '', 'PEDRO AQUINO', '09201234567', '', '', ''],
  ];
  $instructions = [
    ['HOW TO USE THIS TEMPLATE'],
    ['1. Keep the header row exactly as it is; columns are matched by name.'],
    ['2. One student per row; do not leave blank rows between students.'],
    ['3. BASIC EDUCATION sheet: Grade Level must be Grade 7 to Grade 12 (JHS / SHS is detected automatically); Section is required; LRN is optional but must be exactly 12 digits when provided.'],
    ['4. COLLEGE sheet: Course must exactly match one of the college courses offered by the school (copy it from the example).'],
    ['5. Optional: add a Department column with College / JHS / Senior High to override the sheet default.'],
    ['6. Photo File (optional): enter the exact file name of a photo already present in backend/uploads/students/ (example: 2026-0001.jpg). Rows with missing photo files still import, just without a picture.'],
    ['7. Duplicate Student Numbers or LRNs inside the file, and students already existing in the system, are detected during validation and skipped; no ID will ever be imported twice.'],
    ['8. Delete the example rows, save the file as .xlsx, .xls or .csv, then upload it on the Import Students page.'],
  ];
  $xlsx = importBuildXlsx([
    'BASIC EDUCATION' => $basic,
    'COLLEGE' => $college,
    'INSTRUCTIONS' => $instructions,
  ]);
  // Clear any prior output (BOM, whitespace, warnings) so headers succeed.
  if (ob_get_level() > 0) ob_clean();
  header_remove('Content-Type');
  header_remove('X-Powered-By');
  header_remove('Cache-Control');
  header_remove('Expires');
  header_remove('Pragma');
  header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
  header('Content-Disposition: attachment; filename="LSC_Student_Import_Template.xlsx"');
  header('Content-Length: ' . strlen($xlsx));
  header('Cache-Control: no-store');
  echo $xlsx;
}

/* ------------------------------------------ header mapping + normalisation */

/* Flexible header -> field mapping (case/space/punctuation insensitive). */
function importHeaderMap(array $header): array {
  /* Strip parenthesised notes ("Student Name (Required)") before matching. */
  $norm = fn($s) => preg_replace('/[^a-z0-9]/', '', strtolower(trim((string)preg_replace('/\s*\([^)]*\)/', '', (string)$s))));
  $map = [];
  foreach (array_values($header) as $i => $cell) {
    $h = $norm($cell);
    if ($h === '') continue;
    $field = null;
    if (in_array($h, ['studentname','name','fullname','studentfullname'], true)) $field = 'student_name';
    elseif (in_array($h, ['studentnumber','studentno','studentnum','idnumber'], true)) $field = 'student_number';
    elseif ($h === 'lrn') $field = 'lrn';
    elseif (in_array($h, ['gradelevel','grade','yearlevel'], true)) $field = 'grade_level';
    elseif (in_array($h, ['section','sectionname'], true)) $field = 'section_name';
    elseif (in_array($h, ['course','program'], true)) $field = 'course';
    elseif (in_array($h, ['department','dept','level'], true)) $field = 'id_type';
    elseif (in_array($h, ['address','addressline1','address1','homeaddress'], true)) $field = 'address_line1';
    elseif (in_array($h, ['addressline2','address2','barangay'], true)) $field = 'address_line2';
    elseif (in_array($h, ['emergencycontact','emergencycontactname','guardian','guardianname','parent','parentname','emergencyname'], true)) $field = 'emergency_contact';
    elseif (in_array($h, ['contactnumber','emergencycontactnumber','contactno','emergencynumber','emergencyphone','phoneno','phonenumber','mobileno','mobilenumber'], true)) $field = 'emergency_phone';
    elseif (in_array($h, ['photo','photofile','photofilename','photoimage'], true)) $field = 'photo';
    elseif (in_array($h, ['academicyear','acadyear'], true)) $field = 'academic_year';
    elseif (in_array($h, ['schoolyear','syear'], true)) $field = 'school_year';
    if ($field !== null && !isset($map[$field])) $map[$field] = (int)$i;
  }
  return $map;
}

/* "Grade 7" / "g-7" / "7" -> "GRADE 7" (system-wide grade label format). */
function importNormalizeGrade(string $raw): string {
  $g = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', trim($raw)));
  if ($g === '') return '';
  if (preg_match('/^(?:GRADE|G|YEAR)?(\d{1,2})$/', $g, $m)) return 'GRADE ' . (int)$m[1];
  return strtoupper(trim($raw));
}

/* Case-insensitive course match against the system allowlist (+ any course
 * already used in the database); returns the canonical spelling. */
function importNormalizeCourse(PDO $pdo, string $raw): string {
  $c = trim(preg_replace('/\s+/', ' ', strtoupper(trim($raw))));
  if ($c === '') return '';
  static $canonical = null;
  if ($canonical === null) {
    $canonical = IMPORT_COURSES;
    try {
      foreach ($pdo->query("SELECT DISTINCT course FROM id_cards WHERE course <> ''") as $r) {
        $cc = trim((string)$r['course']);
        if ($cc !== '') {
          $upper = strtoupper($cc);
          $known = false;
          foreach ($canonical as $k) if (strtoupper($k) === $upper) { $known = true; break; }
          if (!$known) $canonical[] = $cc;
        }
      }
    } catch (Throwable $e) { /* allowlist only */ }
  }
  foreach ($canonical as $cand) if (strtoupper($cand) === $c) return $cand;
  return $c; /* unknown -> normalizeCardByType rejects it */
}

/* Department from an explicit column value, else from the sheet name. */
function importNormalizeType(string $raw, string $sheetName): string {
  $t = strtoupper(preg_replace('/[^A-Za-z]/', '', $raw));
  if ($t !== '') {
    if (str_contains($t, 'COLLEGE')) return 'COLLEGE';
    if (str_contains($t, 'SENIOR') || $t === 'SHS' || $t === 'SENIORHIGH') return 'SENIOR_HIGH';
    if (str_contains($t, 'JUNIOR') || $t === 'JHS' || $t === 'JUNIORHIGH') return 'JUNIOR_HIGH';
    return '';
  }
  $s = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $sheetName));
  if (str_contains($s, 'COLLEGE')) return 'COLLEGE';
  return ''; /* BASIC EDUCATION / unknown sheets -> decide by grade or course */
}

/*
 * Walk every sheet: skip INSTRUCTIONS sheets, find the header row (the first
 * row that maps to student_name + student_number) and collect data rows.
 */
function importCollectRows(array $parsed): array {
  $collected = [];
  $sheetErrors = [];
  foreach ($parsed as $sheetName => $dataRows) {
    if (!is_array($dataRows) || count($dataRows) === 0) continue;
    if (str_contains(strtoupper(preg_replace('/[^A-Za-z]/', '', $sheetName)), 'INSTRUCT')) continue;
    $headerFound = false; $map = null; $count = 0;
    foreach ($dataRows as $r) {
      $cells = array_map(fn($x) => trim((string)$x), $r['cells']);
      if (!$headerFound) {
        $cand = importHeaderMap($cells);
        if (isset($cand['student_name'], $cand['student_number'])) { $headerFound = true; $map = $cand; }
        continue;
      }
      if (implode('', $cells) === '') continue; /* blank row */
      $nameCell = strtolower(preg_replace('/[^a-z0-9]/', '', $cells[$map['student_name']] ?? ''));
      if (in_array($nameCell, ['studentname','name'], true)) continue; /* repeated header */
      $collected[] = ['sheet' => $sheetName, 'row' => (int)$r['row'], 'cells' => $cells, 'map' => $map];
      $count++;
    }
    if (!$headerFound) $sheetErrors[$sheetName] = 'No valid header row was found (needs at least "Student Name" and "Student Number" columns).';
    elseif ($count === 0) $sheetErrors[$sheetName] = 'No student rows found under the header.';
  }
  return [$collected, $sheetErrors];
}
/* Validate collected rows: required fields, formats, within-file duplicates
 * and students already in the database. Returns per-row status + counts. */
function importValidateRows(PDO $pdo, array $collected): array {
  $rows = [];
  $counts = ['total' => 0, 'valid' => 0, 'invalid' => 0, 'duplicate' => 0];
  $parsed = [];
  $allSN = [];
  $allLrn = [];
  foreach ($collected as $i => $c) {
    $map = $c['map'];
    $cells = $c['cells'];
    $get = fn($f) => isset($map[$f]) ? trim((string)$cells[$map[$f]]) : '';
    $gradeRaw = importNormalizeGrade($get('grade_level'));
    $courseRaw = importNormalizeCourse($pdo, $get('course'));
    $idType = importNormalizeType($get('id_type'), $c['sheet']);
    if ($idType === '') {
      if ($courseRaw !== '') $idType = 'COLLEGE';
      elseif ($gradeRaw !== '') { $num = (int)preg_replace('/[^0-9]/', '', $gradeRaw); $idType = $num >= 11 ? 'SENIOR_HIGH' : 'JUNIOR_HIGH'; }
      else $idType = 'COLLEGE';
    }
    $parsed[$i] = [
      'sheet' => $c['sheet'], 'row' => $c['row'],
      'student_name' => $get('student_name'), 'student_number' => $get('student_number'),
      'lrn' => $get('lrn'), 'grade_level' => $gradeRaw, 'course' => $courseRaw,
      'section_name' => $get('section_name'), 'address_line1' => $get('address_line1'),
      'address_line2' => $get('address_line2'), 'emergency_contact' => $get('emergency_contact'),
      'emergency_phone' => $get('emergency_phone'), 'photo' => $get('photo'),
      'academic_year' => $get('academic_year'), 'school_year' => $get('school_year'),
      'id_type' => $idType,
    ];
        if ($parsed[$i]['student_number'] !== '') $allSN[] = $parsed[$i]['student_number'];
    if ($parsed[$i]['lrn'] !== '') $allLrn[] = $parsed[$i]['lrn'];
  }

  /* Bulk DB lookup: existing students matching name + (number OR LRN). */
  $existing = [];
  if (!empty($allSN)) {
    $ph = implode(',', array_fill(0, count($allSN), '?'));
    $stmt = $pdo->prepare("SELECT id, student_name, student_number FROM id_cards WHERE student_number IN ($ph)");
    $stmt->execute($allSN);
    foreach ($stmt->fetchAll() as $r) {
      $existing['sn:' . strtolower(trim($r['student_name'])) . '|' . trim($r['student_number'])] = (int)$r['id'];
    }
  }
  if (!empty($allLrn)) {
    $ph = implode(',', array_fill(0, count($allLrn), '?'));
    $stmt = $pdo->prepare("SELECT id, student_name, lrn FROM id_cards WHERE lrn IN ($ph)");
    $stmt->execute($allLrn);
    foreach ($stmt->fetchAll() as $r) {
      $existing['lrn:' . strtolower(trim($r['student_name'])) . '|' . trim($r['lrn'])] = (int)$r['id'];
    }
  }
  $seenSN = [];
  $seenLrn = [];
  foreach ($parsed as $i => $p) {
    $counts['total']++;
    $errors = [];
    $warnings = [];
    $isDup = false;
    if ($p['student_name'] === '') $errors[] = 'Student Name is required.';
    if ($p['student_number'] === '') $errors[] = 'Student Number is required.';
    $values = ['id_type' => $p['id_type'], 'grade_level' => $p['grade_level'], 'course' => $p['course'], 'lrn' => $p['lrn']];
    $typeErr = normalizeCardByType($values);
    if ($typeErr !== null) $errors[] = $typeErr['message'];
    $snKey = strtolower(trim($p['student_number']));
    $lrnKey = strtolower(trim($p['lrn']));
    if ($p['student_number'] !== '' && isset($seenSN[$snKey])) { $errors[] = 'Duplicate Student Number within the file (first seen at row ' . $seenSN[$snKey] . ').'; $isDup = true; }
    elseif ($p['student_number'] !== '') $seenSN[$snKey] = $p['row'];
    if ($p['lrn'] !== '' && isset($seenLrn[$lrnKey])) { $errors[] = 'Duplicate LRN within the file (first seen at row ' . $seenLrn[$lrnKey] . ').'; $isDup = true; }
    elseif ($p['lrn'] !== '') $seenLrn[$lrnKey] = $p['row'];
    if ($p['student_number'] !== '') { $key = 'sn:' . strtolower(trim($p['student_name'])) . '|' . trim($p['student_number']); if (isset($existing[$key])) { $errors[] = 'A student with this name and Student Number already exists (ID #' . $existing[$key] . ').'; $isDup = true; } }
    if ($p['lrn'] !== '') { $key = 'lrn:' . strtolower(trim($p['student_name'])) . '|' . trim($p['lrn']); if (isset($existing[$key])) { $errors[] = 'A student with this name and LRN already exists (ID #' . $existing[$key] . ').'; $isDup = true; } }
    if ($p['photo'] !== '' && !file_exists(IMPORT_PHOTO_DIR . '/' . $p['photo'])) $warnings[] = 'Photo file "' . $p['photo'] . '" not found in uploads/students/. Imported without a photo.';
    $status = !empty($errors) ? ($isDup ? 'duplicate' : 'invalid') : 'valid';
    if ($status === 'valid') $counts['valid']++; elseif ($status === 'invalid') $counts['invalid']++; else $counts['duplicate']++;
    $rows[] = ['sheet' => $p['sheet'], 'row' => $p['row'], 'status' => $status, 'errors' => $errors, 'warnings' => $warnings, 'data' => $p];
  }
  return ['rows' => $rows, 'counts' => $counts, 'sheet_errors' => []];
}
/* Insert the valid rows in ONE transaction while holding the card-write lock,
 * so a failed import never leaves partial records and existing students
 * are never imported twice. Signatories are auto-assigned per department.
 * Returns the inserted card ids. */
function importCommitRows(PDO $pdo, array $validRows): array {
  $importedIds = [];
  $gotLock = (int)$pdo->query("SELECT GET_LOCK('lsc_card_write',10)")->fetchColumn();
  if ($gotLock !== 1) throw new RuntimeException('Server is busy, please try again shortly.');
  try {
    $pdo->beginTransaction();
    $signatories = [];
    foreach ($pdo->query('SELECT id, full_name, signature_path FROM signatories WHERE is_active=1') as $sig) {
      $signatories[$sig['full_name']] = ['id' => (int)$sig['id'], 'path' => $sig['signature_path']];
    }
    foreach ($validRows as $p) {
      $d = $p['data'];
      $photoPath = ($d['photo'] !== '' && file_exists(IMPORT_PHOTO_DIR . '/' . $d['photo'])) ? 'uploads/students/' . $d['photo'] : '';
      $sigName = IMPORT_SIGNATORY_MAP[$d['id_type']] ?? '';
      $sigId = null; $sigPath = '';
      if ($sigName !== '' && isset($signatories[$sigName])) { $sigId = $signatories[$sigName]['id']; $sigPath = $signatories[$sigName]['path']; }
      $cols = ['student_name','id_type','course','grade_level','section_name','student_number','lrn','academic_year','school_year','photo_path','address_line1','address_line2','emergency_contact','emergency_phone','signatory_id','signatory_name','signature_path','status'];
      $vals = [
        'student_name' => $d['student_name'], 'id_type' => $d['id_type'], 'course' => $d['course'],
        'grade_level' => $d['grade_level'], 'section_name' => $d['section_name'], 'student_number' => $d['student_number'],
        'lrn' => $d['lrn'], 'academic_year' => $d['academic_year'] !== '' ? $d['academic_year'] : IMPORT_DEFAULT_YEAR,
        'school_year' => $d['school_year'] !== '' ? $d['school_year'] : IMPORT_DEFAULT_YEAR,
        'photo_path' => $photoPath, 'address_line1' => $d['address_line1'], 'address_line2' => $d['address_line2'],
        'emergency_contact' => $d['emergency_contact'], 'emergency_phone' => $d['emergency_phone'],
        'signatory_id' => $sigId, 'signatory_name' => $sigName, 'signature_path' => $sigPath, 'status' => 'created',
      ];
      $colList = implode(',', $cols);
      $parList = implode(',', array_map(fn($f) => ":$f", $cols));
      $pdo->prepare("INSERT INTO id_cards($colList) VALUES($parList)")->execute($vals);
      $importedIds[] = (int)$pdo->lastInsertId();
    }
    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    $pdo->query("SELECT RELEASE_LOCK('lsc_card_write')");
    throw $e;
  }
  $pdo->query("SELECT RELEASE_LOCK('lsc_card_write')");
  return $importedIds;
}

