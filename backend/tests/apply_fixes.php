<?php
// Fix script: applies duplicate prevention + length validation to studentSaveCard
// Uses line-number based insertion since we know the exact line numbers
$file = __DIR__ . '/../api.php';
$lines = file($file, FILE_IGNORE_NEW_LINES);
$newLines = [];

foreach ($lines as $i => $line) {
    $newLines[] = $line;
    
    // Line 134 (0-indexed 133) is: $idType=trim((string)($data['id_type']??''));
    // After it, add validation code
    if ($i === 133) {
        $newLines[] = '    /* Input length validation */';
        $newLines[] = "    \$course=trim((string)(\$data['course']??''));";
        $newLines[] = "    if (\$studentName !== '' && strlen(\$studentName) > 150) response(['success'=>false,'message'=>'Student name is too long (max 150 characters)'],422);";
        $newLines[] = "    if (strlen(\$idType) > 20) response(['success'=>false,'message'=>'Invalid department'],422);";
        $newLines[] = "    if (strlen(\$course) > 255) response(['success'=>false,'message'=>'Course is too long'],422);";
    }
    
    // Line 138 (0-indexed 137) is: $values=[];foreach($fields as $f)$values[$f]=trim((string)($data[$f]??''));
    // After it, add duplicate prevention
    if ($i === 137) {
        $newLines[] = '    /* Duplicate prevention */';
        $newLines[] = "    \$sn=trim((string)\$values['student_number']);";
        $newLines[] = "    \$sid=trim((string)\$values['student_id_number']);";
        $newLines[] = "    \$lrn=trim((string)\$values['lrn']);";
        $newLines[] = "    \$dupCols=[];\$dupParams=[];";
        $newLines[] = "    if (\$sn !== '')  { \$dupCols[]='student_number=?';      \$dupParams[]=\$sn; }";
        $newLines[] = "    if (\$sid !== '') { \$dupCols[]='student_id_number=?';   \$dupParams[]=\$sid; }";
        $newLines[] = "    if (\$lrn !== '') { \$dupCols[]='lrn=?';                 \$dupParams[]=\$lrn; }";
        $newLines[] = "    if (\$dupCols) {";
        $newLines[] = "      \$dupParams[]=\$studentName;";
        $newLines[] = "      \$dupCheck=\$pdo->prepare('SELECT id FROM id_cards WHERE student_name=? AND ('.implode(' OR ',\$dupCols).') LIMIT 1');";
        $newLines[] = "      \$dupCheck->execute(\$dupParams);";
        $newLines[] = "      \$dup = \$dupCheck->fetch();";
        $newLines[] = "      if (\$dup) response(['success'=>false,'message'=>'A student with this name and student number/ID/LRN already exists.',409]);";
        $newLines[] = "    }";
    }
}

file_put_contents($file, implode("\n", $newLines) . "\n");
echo "Fixes applied.\n";

