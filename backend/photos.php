<?php
require_once __DIR__ . '/config.php';

function photoStoragePath(int $studentId, string $type, string $ext, ?string $label = null): array {
    $typeDir = $type === 'PROCESSED' ? 'processed' : 'original';
    $dir = __DIR__ . '/uploads/students/' . $studentId . '/photos/' . $typeDir;
    if (!is_dir($dir)) { mkdir($dir, 0775, true); }
    $prefix = $type === 'PROCESSED' ? 'processed_' : 'original_';
    /* Optional friendly label from the UI becomes the file-name prefix so
     * uploads are recognisable in the Photo Library and pickers. */
    if ($label !== null) {
        $clean = preg_replace('/[^A-Za-z0-9._-]+/', '_', trim($label));
        $clean = trim((string)$clean, '_-');
        if ($clean !== '') $prefix = substr($clean, 0, 60) . '_';
    }
    return array('dir' => $dir, 'rel' => 'students/' . $studentId . '/photos/' . $typeDir . '/' . $prefix . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext);
}

function photoLibraryValidate(string $tmp, string $origName, int $maxBytes = 10485760): array {
    $allowedMimes = array('image/png' => true, 'image/jpeg' => true, 'image/webp' => true);
    $allowedExts = array('png', 'jpg', 'jpeg', 'webp');
    $mime = mime_content_type($tmp);
    if (!isset($allowedMimes[$mime])) { return array('ok' => false, 'message' => 'Only PNG, JPG or WEBP student photos are allowed'); }
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExts, true)) { return array('ok' => false, 'message' => 'Invalid file extension'); }
    if (filesize($tmp) > $maxBytes) { return array('ok' => false, 'message' => 'Photo is too large (max ' . intval($maxBytes / 1048576) . ' MB)'); }
    $info = getimagesize($tmp);
    if (!is_array($info) || !isset($info[0]) || !isset($info[1])) { return array('ok' => false, 'message' => 'The image file is invalid or corrupted'); }
    $w = intval($info[0]); $h = intval($info[1]);
    if ($w < 96 || $h < 96) { return array('ok' => false, 'message' => 'Image resolution is too low (minimum 96x96 px)'); }
    if ($w > 12000 || $h > 12000) { return array('ok' => false, 'message' => 'Image dimensions are too large'); }
    $extMap = array('image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp');
    return array('ok' => true, 'mime' => $mime, 'ext' => isset($extMap[$mime]) ? $extMap[$mime] : $ext, 'w' => $w, 'h' => $h);
}

function photoSafePath(string $rel): ?string {
    $abs = realpath(__DIR__ . '/' . $rel);
    if (!$abs) return null;
    $abs = str_replace('\\', '/', $abs);
    $base = strval(realpath(__DIR__ . '/uploads/students'));
    if ($base === '') return null;
    $base = str_replace('\\', '/', $base);
    if (!str_starts_with($abs . '/', $base . '/')) return null;
    return $abs;
}

function photoVerifyOwnership(PDO $pdo, int $photoId, int $studentId): ?array {
    $stmt = $pdo->prepare('SELECT * FROM student_photos WHERE id=? AND student_id=? LIMIT 1');
    $stmt->execute(array($photoId, $studentId));
    return $stmt->fetch() ?: null;
}

function photoGetPreferred(PDO $pdo, int $studentId): ?array {
    $stmt = $pdo->prepare('SELECT * FROM student_photos WHERE student_id=? AND is_active=1 ORDER BY CASE WHEN type="PROCESSED" AND source="PHOTO_PROCESSING" THEN 0 WHEN type="PROCESSED" THEN 1 WHEN source="CREATE_ID" THEN 2 ELSE 3 END ASC, created_at DESC LIMIT 1');
    $stmt->execute(array($studentId));
    return $stmt->fetch() ?: null;
}

function photoRecord(PDO $pdo, int $studentId, string $source, string $type, ?int $parentPhotoId, string $filePath, string $mimeType, int $fileSize, int $width, int $height, ?array $backgroundInfo, ?array $cropData, ?int $createdBy): int {
    $stmt = $pdo->prepare('INSERT INTO student_photos(student_id,source,type,parent_photo_id,file_path,mime_type,file_size,width,height,background_info,crop_data,processing_status,is_active,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute(array($studentId, $source, $type, $parentPhotoId, $filePath, $mimeType, $fileSize, $width, $height, $backgroundInfo ? json_encode($backgroundInfo, JSON_UNESCAPED_SLASHES) : null, $cropData ? json_encode($cropData, JSON_UNESCAPED_SLASHES) : null, 'completed', 1, $createdBy));
    return intval($pdo->lastInsertId());
}

function photoArchive(PDO $pdo, int $photoId, int $studentId): bool {
    $stmt = $pdo->prepare('UPDATE student_photos SET is_active=0, type="ARCHIVED" WHERE id=? AND student_id=?');
    $stmt->execute(array($photoId, $studentId));
    return $stmt->rowCount() > 0;
}

function photoListForStudent(PDO $pdo, int $studentId, array $filters = array()): array {
    $sql = 'SELECT * FROM student_photos WHERE student_id=?';
    $params = array($studentId);
    if (!empty($filters['source'])) { $sql .= ' AND source=?'; $params[] = $filters['source']; }
    if (!empty($filters['type'])) { $sql .= ' AND type=?'; $params[] = $filters['type']; }
    if (isset($filters['is_active']) && $filters['is_active'] !== '') { $sql .= ' AND is_active=?'; $params[] = intval($filters['is_active']); }
    if (!empty($filters['processing_status'])) { $sql .= ' AND processing_status=?'; $params[] = $filters['processing_status']; }
    $sql .= ' ORDER BY is_active DESC, created_at DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['background_info'] = !empty($r['background_info']) ? json_decode($r['background_info'], true) : null;
        $r['crop_data'] = !empty($r['crop_data']) ? json_decode($r['crop_data'], true) : null;
    }
    return $rows;
}

function photoSearch(PDO $pdo, array $filters, int $page = 1, int $perPage = 20): array {
    $sql = 'SELECT sp.*, c.student_name, c.student_id_number, c.student_number, c.lrn, c.id_type, c.course, c.grade_level, c.section_name FROM student_photos sp INNER JOIN id_cards c ON c.id = sp.student_id';
    $where = array(); $params = array();
    if (!empty($filters['q'])) { $where[] = '(c.student_name LIKE ? OR c.student_id_number LIKE ? OR c.student_number LIKE ?)'; $like = '%' . $filters['q'] . '%'; $params = array_merge($params, array($like, $like, $like)); }
    if (!empty($filters['id_type'])) { $where[] = 'c.id_type=?'; $params[] = $filters['id_type']; }
    if (!empty($filters['source'])) { $where[] = 'sp.source=?'; $params[] = $filters['source']; }
    if (!empty($filters['type'])) { $where[] = 'sp.type=?'; $params[] = $filters['type']; }
    if (isset($filters['is_active']) && $filters['is_active'] !== '') { $where[] = 'sp.is_active=?'; $params[] = intval($filters['is_active']); }
    if (!empty($filters['processing_status'])) { $where[] = 'sp.processing_status=?'; $params[] = $filters['processing_status']; }
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $countSql = 'SELECT COUNT(*) FROM student_photos sp INNER JOIN id_cards c ON c.id = sp.student_id' . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $total = intval($countStmt->fetchColumn());
    $sql .= ' ORDER BY sp.created_at DESC LIMIT ' . intval($perPage) . ' OFFSET ' . intval(($page - 1) * $perPage);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['background_info'] = !empty($r['background_info']) ? json_decode($r['background_info'], true) : null;
        $r['crop_data'] = !empty($r['crop_data']) ? json_decode($r['crop_data'], true) : null;
    }
    return array('data' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'total_pages' => intval(ceil($total / $perPage)));
}

function photoServeFile(PDO $pdo, int $photoId): void {
    requireLogin();
    $stmt = $pdo->prepare('SELECT * FROM student_photos WHERE id=?');
    $stmt->execute(array($photoId));
    $photo = $stmt->fetch();
    if (!$photo) { http_response_code(404); exit; }
    $abs = photoSafePath($photo['file_path']);
    if (!$abs || !file_exists($abs)) { http_response_code(404); exit; }
    header('Content-Type: ' . $photo['mime_type']);
    header('Content-Length: ' . filesize($abs));
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    readfile($abs);
    exit;
}