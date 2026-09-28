<?php
/*
 * QA DATABASE INTEGRITY CHECK — Lake Shore ID Generator System
 * Usage: php qa_db_check.php
 * Read-only. Reports schema constraints, orphan records, duplicates,
 * and orphan upload files.
 */
require_once __DIR__ . '/../config.php';
$pdo = db();

function section(string $t): void { echo "\n=== $t ===\n"; }

section('TABLES');
foreach ($pdo->query('SHOW TABLES') as $row) echo ' - ' . reset($row) . "\n";

section('id_cards — SHOW CREATE TABLE (constraints/keys)');
$row = $pdo->query('SHOW CREATE TABLE id_cards')->fetch();
echo $row['Create Table'], "\n";

section('UNIQUE constraints on id_cards identifiers?');
$create = $pdo->query('SHOW CREATE TABLE id_cards')->fetch()['Create Table'];
foreach (['student_number', 'student_id_number', 'lrn'] as $col) {
    $hasUnique = preg_match('/UNIQUE KEY[^`]*`' . $col . '`/i', $create) === 1;
    echo sprintf(" - %-18s UNIQUE: %s\n", $col, $hasUnique ? 'YES' : 'NO  <-- duplicates possible');
}

section('FOREIGN KEY constraints in whole database');
$fks = $pdo->query("SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL")->fetchAll();
echo $fks ? '' : " - NONE — referential integrity is application-enforced only\n";
foreach ($fks as $f) echo " - {$f['TABLE_NAME']}.{$f['COLUMN_NAME']} -> {$f['REFERENCED_TABLE_NAME']}.{$f['REFERENCED_COLUMN_NAME']}\n";

section('Duplicate identifier groups in id_cards');
foreach (['student_number', 'student_id_number', 'lrn'] as $col) {
    $rows = $pdo->query("SELECT $col, COUNT(*) c, GROUP_CONCAT(id) ids FROM id_cards WHERE $col <> '' GROUP BY $col HAVING c > 1")->fetchAll();
    echo ' - ' . $col . ': ' . count($rows) . " duplicate group(s)" . ($rows ? '  e.g. ' . json_encode($rows[0]) : '') . "\n";
}

section('Duplicate exact-name groups in id_cards');
$rows = $pdo->query("SELECT student_name, COUNT(*) c, GROUP_CONCAT(id) ids FROM id_cards WHERE student_name <> '' GROUP BY student_name HAVING c > 1")->fetchAll();
echo ' - ' . count($rows) . " duplicate name group(s)" . ($rows ? '  e.g. ' . json_encode($rows[0]) : '') . "\n";

section('Orphan records');
$sets = [
    ['id_cards.signatory_id -> signatories', "SELECT COUNT(*) FROM id_cards ic LEFT JOIN signatories s ON s.id = ic.signatory_id WHERE ic.signatory_id IS NOT NULL AND ic.signatory_id <> 0 AND s.id IS NULL"],
    ['id_cards.template_id -> id_templates', "SELECT COUNT(*) FROM id_cards ic LEFT JOIN id_templates t ON t.id = ic.template_id WHERE ic.template_id IS NOT NULL AND ic.template_id <> 0 AND t.id IS NULL"],
    ['lost_id_requests.reference_card_id -> id_cards', "SELECT COUNT(*) FROM lost_id_requests l LEFT JOIN id_cards c ON c.id = l.reference_card_id WHERE l.reference_card_id IS NOT NULL AND l.reference_card_id <> 0 AND c.id IS NULL"],
    ['password_resets.user_id -> users', "SELECT COUNT(*) FROM password_resets pr LEFT JOIN users u ON u.id = pr.user_id WHERE pr.user_id IS NOT NULL AND u.id IS NULL"],
];
foreach ($sets as [$label, $sql]) {
    echo sprintf(" - %-46s orphans: %d\n", $label, (int) $pdo->query($sql)->fetchColumn());
}

section('Orphan upload files on disk (not referenced by any record)');
$referenced = [];
foreach ($pdo->query("SELECT photo_path FROM id_cards WHERE photo_path <> ''") as $r) $referenced[basename($r['photo_path'])] = true;
$orphanFiles = 0;
$totalFiles = 0;
foreach (['students', 'photos', 'receipts', 'signatures', 'templates'] as $dir) {
    $path = __DIR__ . "/../uploads/$dir";
    foreach (glob("$path/*") ?: [] as $f) {
        if (is_dir($f) || basename($f) === '.htaccess') continue;
        $totalFiles++;
        if ($dir !== 'templates' && !isset($referenced[basename($f)])) $orphanFiles++;
    }
}
echo " - upload files scanned: $totalFiles; unreferenced by any id_cards.photo_path: $orphanFiles\n";

section('Row counts');
foreach (['id_cards', 'lost_id_requests', 'id_templates', 'signatories', 'users', 'password_resets', 'system_settings'] as $t) {
    echo sprintf(" - %-20s %d row(s)\n", $t, (int) $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn());
}
echo "\nDone.\n";
