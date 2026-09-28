<?php
/*
|--------------------------------------------------------------------------
| Audit logging helper
|--------------------------------------------------------------------------
| Records important actions across the ID lifecycle
| (Created -> Done -> Edited -> Printed) plus administrative and
| security events. old_value/new_value are stored JSON-encoded so the
| UI can render structured FIELD / FROM / TO rows.
|
| Rules:
|  - The authenticated user, IP and user agent are captured automatically.
|  - user_id is NULL for public (pre-auth) actions such as student
|    self-service card creation or failed logins.
|  - auditLog() NEVER throws: a logging failure must not break the
|    main operation, and log rows are never updated or deleted.
*/

require_once __DIR__ . '/config.php';

function auditClientMeta(): array {
  $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
  $ip = trim(explode(',', (string)$ip)[0]);
  if ($ip === '' || strlen($ip) > 45) $ip = $ip === '' ? '' : substr($ip, 0, 45);
  $ua = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
  return [$ip !== '' ? $ip : null, $ua !== '' ? substr($ua, 0, 255) : null];
}

function auditLog(string $action, string $entityType, ?int $entityId = null, $oldValue = null, $newValue = null, ?int $userId = null): void {
  try {
    if ($userId === null && isset($_SESSION['user_id'])) $userId = (int)$_SESSION['user_id'];
    [$ip, $ua] = auditClientMeta();
    db()->prepare('INSERT INTO audit_logs(user_id,action,entity_type,entity_id,old_value,new_value,ip_address,user_agent) VALUES(?,?,?,?,?,?,?,?)')
      ->execute([
        $userId, $action, $entityType, $entityId,
        $oldValue === null ? null : json_encode($oldValue, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        $newValue === null ? null : json_encode($newValue, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        $ip, $ua,
      ]);
  } catch (Throwable $e) {
    /* never break the main operation because of audit logging */
  }
}

/* Human-readable labels for id_cards fields used in edit diffs. */
function auditCardFieldLabels(): array {
  return [
    'student_name' => 'Student Name', 'id_type' => 'Department', 'course' => 'Course',
    'grade_level' => 'Grade Level', 'section_name' => 'Section',
    'student_number' => 'Student Number', 'student_id_number' => 'Student ID Number',
    'lrn' => 'LRN', 'academic_year' => 'Academic Year', 'school_year' => 'School Year',
    'photo_path' => 'Photo', 'address_line1' => 'Address Line 1', 'address_line2' => 'Address Line 2',
    'emergency_label' => 'Emergency Contact Label', 'emergency_contact' => 'Emergency Contact Name',
    'emergency_phone' => 'Emergency Contact Number', 'institution_name' => 'Institution Name',
    'institution_address' => 'Institution Address', 'mobile_no' => 'Mobile No.',
    'telephone_no' => 'Telephone No.', 'email_address' => 'Email Address',
    'signatory_name' => 'Signatory', 'template_id' => 'Template',
    'original_photo_path' => 'Original Photo', 'photo_processing_status' => 'Photo Processing',
    'photo_processed_at' => 'Photo Processed At', 'photo_crop' => 'Photo Crop',
  ];
}

/* Structured field-level diff (list of {field,label,from,to}) for an edit. */
function auditDiffFields(array $old, array $new, ?array $labels = null): array {
  $labels = $labels ?? auditCardFieldLabels();
  $changes = [];
  foreach ($labels as $field => $label) {
    $o = trim((string)($old[$field] ?? ''));
    $n = trim((string)($new[$field] ?? ''));
    if ($o !== $n) $changes[] = ['field' => $field, 'label' => $label, 'from' => $o, 'to' => $n];
  }
  return $changes;
}

/* Safe row snapshot used for delete/edit logs (returns null on any problem). */
function auditFetchRow(string $table, int $id): ?array {
  if ($id <= 0 || !preg_match('/^[a-z_]+$/', $table)) return null;
  try {
    $st = db()->prepare("SELECT * FROM $table WHERE id=?");
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
  } catch (Throwable $e) {
    return null;
  }
}
