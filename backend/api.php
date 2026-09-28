<?php
ob_start();
/*
 * CORS: only reflect the Origin (with credentials) for known frontend origins.
 * This prevents cross-site credential theft while keeping the Vite dev server
 * (http://localhost:5173) and any production origin in ALLOWED_ORIGINS working.
 * Same-origin requests (production deploy under /lake-shore-id-editor) send no
 * Origin header and therefore need no CORS headers at all.
 * Configure ALLOWED_ORIGINS in config.php / environment for production domains.
 */
$allowedOrigins = defined('ALLOWED_ORIGINS') ? ALLOWED_ORIGINS : [
    'http://localhost:5173',
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/rate_limit.php';
require_once __DIR__ . '/import.php';
require_once __DIR__ . '/photos.php';

function response($data, int $status = 200): void { if (!headers_sent()) header('Content-Type: application/json; charset=utf-8'); http_response_code($status); echo json_encode($data); exit; }
function input(): array { $raw=file_get_contents('php://input'); $json=json_decode($raw,true); return is_array($json)?$json:$_POST; }
function uploadImage(string $field, string $folder, string $sub = ''): string {
  if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) return '';
  $allowedMimes=['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'];
  $mime=mime_content_type($_FILES[$field]['tmp_name']);
  if (!isset($allowedMimes[$mime])) response(['success'=>false,'message'=>'Only PNG, JPG or WEBP images are allowed'],422);
  $dir=__DIR__.'/uploads/'.$folder.($sub===''?'':'/'.$sub); if (!is_dir($dir)) mkdir($dir,0775,true);
  $prefix=($sub===''?$folder:$folder.'_'.$sub).'_';
  $filename=$prefix.time().'_'.bin2hex(random_bytes(4)).'.'.$allowedMimes[$mime];
  /* Security: also validate the file extension against the allowed list.
   * Without this an attacker could upload a .php file with image MIME bytes. */
  $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
  $allowedExts = ['png','jpg','jpeg','webp'];
  if (!in_array($ext, $allowedExts, true)) {
    response(['success'=>false,'message'=>'Invalid file extension. Only PNG, JPG or WEBP images are allowed'],422);
  }
  if (!move_uploaded_file($_FILES[$field]['tmp_name'],$dir.'/'.$filename)) response(['success'=>false,'message'=>'Upload failed'],500);
  /* The returned relative path must include the sub-folder so the stored
   * location and the canonical path always match (processed cutouts). */
  return 'uploads/'.$folder.($sub===''?'':'/'.$sub).'/'.$filename;
}
function uploadDocument(string $field, string $folder): string {
  if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) return '';
  $allowedMimes=['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp','application/pdf'=>'pdf'];
  $mime=mime_content_type($_FILES[$field]['tmp_name']);
  if (!isset($allowedMimes[$mime])) response(['success'=>false,'message'=>'Only PNG, JPG, WEBP images or PDF receipt documents are allowed'],422);
  $dir=__DIR__.'/uploads/'.$folder; if (!is_dir($dir)) mkdir($dir,0775,true);
  $filename=$folder.'_'.time().'_'.bin2hex(random_bytes(4)).'.'.$allowedMimes[$mime];
  /* Security: validate extension in addition to MIME type */
  $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
  $allowedExts = ['png','jpg','jpeg','webp','pdf'];
  if (!in_array($ext, $allowedExts, true)) {
    response(['success'=>false,'message'=>'Invalid file extension. Only PNG, JPG, WEBP images or PDF are allowed'],422);
  }
  if (!move_uploaded_file($_FILES[$field]['tmp_name'],$dir.'/'.$filename)) response(['success'=>false,'message'=>'Upload failed'],500);
  return 'uploads/'.$folder.'/'.$filename;
}

function photoValidate(string $tmp, string $origName, int $maxBytes = 10485760): array {
  /* Server-side student photo validation. Never trust the client alone:
   * MIME by content, extension, byte size and readable image dimensions. */
  $allowedMimes=['image/png'=>true,'image/jpeg'=>true,'image/webp'=>true];
  $allowedExts=['png','jpg','jpeg','webp'];
  $mime=mime_content_type($tmp);
  if (!isset($allowedMimes[$mime])) return ['ok'=>false,'message'=>'Only PNG, JPG or WEBP student photos are allowed'];
  $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
  if (!in_array($ext, $allowedExts, true)) return ['ok'=>false,'message'=>'Invalid file extension. Only PNG, JPG or WEBP images are allowed'];
  if (filesize($tmp) > $maxBytes) return ['ok'=>false,'message'=>'Photo is too large (max '.(int)($maxBytes/1048576).' MB)'];
  $info=getimagesize($tmp);
  if (!is_array($info) || !isset($info[0]) || !isset($info[1])) return ['ok'=>false,'message'=>'The image file is invalid or corrupted'];
  $w=(int)$info[0]; $h=(int)$info[1];
  if ($w < 96 || $h < 96) return ['ok'=>false,'message'=>'Image resolution is too low for an ID photo (minimum 96×96 px)'];
  if ($w > 12000 || $h > 12000) return ['ok'=>false,'message'=>'Image dimensions are too large (max 12000 px per side)'];
  return ['ok'=>true,'mime'=>$mime,'ext'=>$allowedMimes[$mime],'w'=>$w,'h'=>$h];
}
function uploadStudentImage(string $field, string $sub = ''): string {
  if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) response(['success'=>false,'message'=>'No photo selected or the upload failed'],422);
  $chk=photoValidate($_FILES[$field]['tmp_name'], $_FILES[$field]['name']);
  if ($chk['ok']!==true) response(['success'=>false,'message'=>$chk['message']],422);
  return uploadImage($field,'students',$sub);
}
function deleteTemporaryUpload(string $rel): bool {
  /* Remove a photo uploaded by this system only (generated names under
   * uploads/students/). The canonical path must stay inside the student
   * uploads directory — this blocks traversal attacks. */
  if ($rel==='') return false;
  $abs=realpath(__DIR__.'/'.$rel);
  if (!$abs) return false;
  $abs=str_replace('\\','/',$abs);
  $studDir=(string)realpath(__DIR__.'/uploads/students');
  if ($studDir==='') return false;
  $studDir=str_replace('\\','/',$studDir);
  if (!str_starts_with($abs.'/', $studDir.'/')) return false;
  $base=pathinfo($abs, PATHINFO_BASENAME);
  if (!preg_match('/^(students|students_processed)_[0-9]+_[0-9a-f]{8}[.](png|jpg|jpeg|webp)$/i', $base)) return false;
  try { return unlink($abs) === true; } catch (Throwable $e) { return false; }
}
/*
 * Type-specific field validation & normalisation shared by both card-creation
 * paths (public studentSaveCard + staff saveCard). Mirrors the frontend rules:
 *   COLLEGE     -> course required (allowlist), grade_level & LRN cleared
 *   JUNIOR_HIGH -> grade_level required (GRADE 7-10), course cleared
 *   SENIOR_HIGH -> grade_level required (GRADE 11-12), course cleared
 *   LRN (basic ed only) -> optional, but if present must be exactly 12 digits
 * Returns null when the payload is valid, or ['message'=>..,'code'=>..].
 */
function normalizeCardByType(array &$values): ?array {
  $type=(string)$values['id_type'];
  if ($type==='COLLEGE') {
    $values['grade_level']=''; $values['lrn']='';
    if ($values['course']==='') return ['message'=>'Please select a course for college students','code'=>422];
    if (!in_array($values['course'],[
      'BACHELOR OF SCIENCE IN PSYCHOLOGY','BACHELOR OF SPECIAL NEEDS EDUCATION',
      'BACHELOR OF TECHNOLOGY AND LIVELIHOOD EDUCATION','BACHELOR OF SCIENCE IN ACCOUNTANCY',
      'BACHELOR OF SCIENCE IN REAL ESTATE MANAGEMENT','BACHELOR OF SCIENCE IN TOURISM MANAGEMENT',
      'BACHELOR OF SCIENCE IN MANAGEMENT ACCOUNTING','BACHELOR OF SCIENCE IN CRIMINOLOGY'],true))
      return ['message'=>'Please select a valid course','code'=>422];
  } else {
    $values['course']='';
    if ($values['grade_level']==='') return ['message'=>'Please select a grade level','code'=>422];
    $band=$type==='JUNIOR_HIGH'?['GRADE 7','GRADE 8','GRADE 9','GRADE 10']:['GRADE 11','GRADE 12'];
    if (!in_array($values['grade_level'],$band,true)) return ['message'=>'Please select a valid grade level for this department','code'=>422];
  }
  if ($values['lrn']!=='' && !preg_match('/^\d{12}$/',$values['lrn']))
    return ['message'=>'LRN must be exactly 12 digits','code'=>422];
  /* Nullable FK id columns must become NULL when the client omits them —
   * strict SQL mode rejects '' for an INT column (blank signatory in the UI).
   * template_id is a VERSION row; template_version is pinned alongside it. */
  foreach (['signatory_id','template_id','preferred_photo_id'] as $fk) {
    $fv=trim((string)($values[$fk]??''));
    $values[$fk]=($fv==='')?null:(int)$fv;
  }
  $tv=trim((string)($values['template_version']??''));
  $values['template_version']=($tv==='')?null:(int)$tv;
  return null;
}
/*
|--------------------------------------------------------------------------
| Public student-number search (existing record detection)
|--------------------------------------------------------------------------
| The lookup helpers below back the unauthenticated `studentLookup` action.
| They are deliberately small and self-contained: the search reuses the
| existing id_cards table and the same identifier triple the duplicate check
| on studentSaveCard already treats as a student's identity, so no second
| source of truth is introduced.
*/

/*
 * Columns the public search may return, in display order.
 *
 * studentLookup is unauthenticated, so it exposes ONLY what the public form
 * needs to re-fill itself. Photos, addresses, emergency contacts, staff
 * notes and the whole print/release lifecycle stay in the database. The
 * response is assembled from these keys and never from the raw row, so
 * adding a column to id_cards can never leak it by accident. Keep this list
 * and the PUBLIC_LOOKUP_FIELDS constant in frontend/src/main.jsx in sync.
 */
function studentLookupColumns(): array {
  return ['student_name','id_type','course','grade_level','section_name',
          'student_number','student_id_number','lrn','academic_year','school_year'];
}

/*
 * Sanitise a Student Number typed into the public search form.
 *
 * The value is always bound as a parameter (never concatenated into SQL),
 * but it is still validated as a school identifier: control characters and
 * zero-width copy/paste noise are stripped, the remainder must match the
 * shape the school actually prints, and anything wider than the column is
 * REJECTED rather than truncated — a truncated value could otherwise match a
 * different student.
 *
 * Returns the cleaned, upper-cased identifier, or '' when the input is not a
 * usable Student Number (empty, too short, wrong characters, too long).
 */
function normalizeStudentNumber(string $raw): string {
  $v = trim($raw);
  if ($v === '') return '';
  /* preg_replace() returns null on an invalid UTF-8 sequence, so fall back to
   * the byte-safe control-character strip rather than trusting a null. */
  $clean = preg_replace('/[\x00-\x1F\x7F]|\xE2\x80[\x80-\x8B\xAF]|\xEF\xBB\xBF/', '', $v);
  if ($clean === null) $clean = preg_replace('/[\x00-\x1F\x7F]/', '', $v);
  $clean = trim((string)$clean);
  /* Must start with a letter or digit; the rest is the separator set the
   * school uses (2026-00125, 2026/00125, 2026_00125, ...). Anything else —
   * SQL metacharacters, wildcards, markup — is refused. */
  if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9\-\/_. ]*$/', $clean)) return '';
  if (strlen($clean) > 100) return '';   /* VARCHAR(100): reject, never cut */
  return strtoupper($clean);
}

/*
|--------------------------------------------------------------------------
| Template versioning
|--------------------------------------------------------------------------
| A template row IS one version of a design. `parent_id` points every
| version at the same family root, and UNIQUE(parent_id, version) means an
| existing version can never be overwritten or duplicated — the database
| rejects it, not just the API.
|
| The helpers below are the single place that knows these rules, so the
| template endpoints, the ID generation path and the reprint path all agree.
*/

/* Read one template version, or null. */
function templateVersionById(PDO $pdo, int $id): ?array {
  if ($id <= 0) return null;
  $st = $pdo->prepare('SELECT * FROM id_templates WHERE id=?');
  $st->execute([$id]);
  $row = $st->fetch();
  return $row ?: null;
}

/* The family's root (v1) id. Pre-versioning rows are their own root. */
function templateFamilyRootId(array $tpl): int {
  $parent = (int)($tpl['parent_id'] ?? 0);
  return $parent > 0 ? $parent : (int)$tpl['id'];
}

/*
 * Next free version number in a family.
 *
 * Computed as MAX(version)+1 rather than a blind count: deleting a version
 * must never cause an existing number to be reused, which would silently
 * change what "v3" means. The UNIQUE index is the final backstop.
 */
function nextTemplateVersionNo(PDO $pdo, int $rootId): int {
  $st = $pdo->prepare('SELECT COALESCE(MAX(version),0) FROM id_templates WHERE parent_id=?');
  $st->execute([$rootId]);
  return (int)$st->fetchColumn() + 1;
}

/* The department's currently active template version, or null. */
function activeTemplateVersion(PDO $pdo, string $idType): ?array {
  $st = $pdo->prepare('SELECT * FROM id_templates WHERE id_type=? AND is_active=1 LIMIT 1');
  $st->execute([$idType]);
  $row = $st->fetch();
  return $row ?: null;
}

/* How many IDs were generated from a specific template version. */
function templateVersionUsage(PDO $pdo, int $templateId): int {
  $st = $pdo->prepare('SELECT COUNT(*) FROM id_cards WHERE template_id=?');
  $st->execute([$templateId]);
  return (int)$st->fetchColumn();
}

/*
|--------------------------------------------------------------------------
| Pinning a template version onto a card
|--------------------------------------------------------------------------
| Called on every card write (create AND update).
|
| The rule the whole feature rests on: an ID keeps the version it was
| generated with. On create we take the department's active version; on
| update we only re-pin when the admin explicitly picked a different
| template, so editing a student record can never silently restyle an
| already-printed ID.
|
| Returns the [template_id, template_version] pair to store.
*/
function pinCardTemplateVersion(PDO $pdo, array &$values, int $cardId = 0): void {
  $idType = (string)($values['id_type'] ?? '');
  $chosen = $values['template_id'] ?? null;

  if ($cardId > 0) {
    /* Editing an existing ID: keep its pinned version unless the admin
     * deliberately selected another template in the editor. */
    $cur = $pdo->prepare('SELECT template_id, template_version FROM id_cards WHERE id=?');
    $cur->execute([$cardId]);
    $row = $cur->fetch();
    $currentId = $row ? (int)($row['template_id'] ?? 0) : 0;
    $currentVer = $row && $row['template_version'] !== null ? (int)$row['template_version'] : null;

    if ($currentId > 0) {
      if (!$chosen || (int)$chosen === $currentId) {
        $values['template_id'] = $currentId;
        $values['template_version'] = $currentVer;
        return;
      }
    }
  }

  /* New ID (or an explicit template change): resolve the version row and
   * store both its id and its number. */
  $tpl = $chosen ? templateVersionById($pdo, (int)$chosen) : activeTemplateVersion($pdo, $idType);
  if (!$tpl) {
    $values['template_id'] = null;
    $values['template_version'] = null;
    return;
  }
  /* Never let a card point at a template from a different department. */
  if ($tpl['id_type'] !== $idType) {
    $tpl = activeTemplateVersion($pdo, $idType);
  }
  if (!$tpl) {
    $values['template_id'] = null;
    $values['template_version'] = null;
    return;
  }
  $values['template_id'] = (int)$tpl['id'];
  $values['template_version'] = (int)$tpl['version'];
}
$action=$_GET['action']??'';
$action=$_GET['action']??'';
try {
  $pdo=db();

  if ($action==='login' && $_SERVER['REQUEST_METHOD']==='POST') {
    $d=input();
    $username=trim((string)($d['username']??''));
    $password=(string)($d['password']??'');
    if ($username==='' || $password==='') response(['success'=>false,'message'=>'Username and password are required'],422);
    /* BINARY forces a case-sensitive username match (AUTH-010). */
    $stmt=$pdo->prepare('SELECT id,username,password,full_name,role,is_active FROM users WHERE BINARY username=? LIMIT 1');
    $stmt->execute([$username]);
    $user=$stmt->fetch();
    if (!$user || (int)$user['is_active']!==1 || !password_verify($password,$user['password'])) {
      auditLog('login_failed','user',$user?(int)$user['id']:null,['attempted_username'=>$username],null,$user?(int)$user['id']:null);
      response(['success'=>false,'message'=>'Invalid username or password'],401);
    }
    loginUser($user);
    auditLog('login','user',(int)$user['id'],null,['username'=>$user['username'],'role'=>$user['role']]);
    response(['success'=>true,'data'=>currentUser(),'csrf_token'=>generateCsrfToken()]);
  }

  if ($action==='logout' && $_SERVER['REQUEST_METHOD']==='POST') {
    $auditUid=isset($_SESSION['user_id'])?(int)$_SESSION['user_id']:null;
    logoutUser();
    auditLog('logout','user',$auditUid,null,null,$auditUid);
    response(['success'=>true]);
  }

  if ($action==='me' && $_SERVER['REQUEST_METHOD']==='GET') {
    $u=currentUser();
    if (!$u) response(['success'=>false,'message'=>'Not authenticated'],401);
    response(['success'=>true,'data'=>$u,'csrf_token'=>generateCsrfToken()]);
  }

  if ($action==='forgotPassword' && $_SERVER['REQUEST_METHOD']==='POST') {
    $d=input(); $username=trim((string)($d['username']??''));
    if($username==='') response(['success'=>false,'message'=>'Enter your username or email'],422);
    $stmt=$pdo->prepare('SELECT id,is_active FROM users WHERE BINARY username=? LIMIT 1');$stmt->execute([$username]);$u=$stmt->fetch();
    if(!$u || (int)$u['is_active']!==1) response(['success'=>true,'message'=>'If the account exists, a reset token has been generated.']);
        $token=bin2hex(random_bytes(16));
    $pdo->prepare('INSERT INTO password_resets(user_id,token_hash,expires_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 30 MINUTE))')->execute([(int)$u['id'],hash('sha256',$token)]);
    /* NOTE: The reset token must be sent out-of-band (e.g. email).
     * It is deliberately NOT returned in the API response to prevent token disclosure. */
    response(['success'=>true,'message'=>'If the account exists, a reset token has been generated. Valid for 30 minutes.']);
  }

  if ($action==='resetPassword' && $_SERVER['REQUEST_METHOD']==='POST') {
    $d=input(); $token=(string)($d['token']??''); $password=(string)($d['password']??'');
    if($token===''||strlen($password)<6) response(['success'=>false,'message'=>'A valid token and a password of at least 6 characters are required'],422);
    $stmt=$pdo->prepare('SELECT id,user_id FROM password_resets WHERE token_hash=? AND used=0 AND expires_at>NOW() ORDER BY id DESC LIMIT 1');
    $stmt->execute([hash('sha256',$token)]);$pr=$stmt->fetch();
    if(!$pr) response(['success'=>false,'message'=>'Invalid or expired reset token'],422);
    $pdo->prepare('UPDATE users SET password=? WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),(int)$pr['user_id']]);
    $pdo->prepare('UPDATE password_resets SET used=1 WHERE id=?')->execute([(int)$pr['id']]);
    auditLog('password_reset','user',(int)$pr['user_id'],null,null,(int)$pr['user_id']);
    response(['success'=>true,'message'=>'Password updated. You can now sign in.']);
  }

  /*
  |--------------------------------------------------------------------------
  | Public student self-service ID creation (no login required)
  |--------------------------------------------------------------------------
  */
  if ($action==='publicSignatory' && $_SERVER['REQUEST_METHOD']==='GET') {
    $type=trim($_GET['type']??'');
    $map=['COLLEGE'=>'Sherill S. Villaluz','JUNIOR_HIGH'=>'Annabelle V. Molina','SENIOR_HIGH'=>'Annabelle V. Molina'];
    $name=$map[$type]??'';
    if ($name==='') response(['success'=>true,'data'=>null]);
    $stmt=$pdo->prepare('SELECT id,full_name,signature_path FROM signatories WHERE full_name=? AND is_active=1 LIMIT 1');
    $stmt->execute([$name]);
    $row=$stmt->fetch();
    response(['success'=>true,'data'=>$row ?: ['id'=>null,'full_name'=>$name,'signature_path'=>'']]);
  }
  /* Self-hosted verification challenge (SVG) for a client that ran into
   * the public rate limit. Issuance has its own per-IP budget so the
   * endpoint itself cannot be abused; answering a challenge unlocks a
   * few extra submissions for the current window (see rate_limit.php). */
  if ($action==='publicCaptcha' && $_SERVER['REQUEST_METHOD']==='GET') {
    $challenge=rlIssueChallenge('public:studentPortal');
    if (!$challenge['ok']) {
      $wait=max(1,(int)($challenge['retry_after']??60));
      if (($challenge['reason']??'')==='limited') {
        header('Retry-After: '.$wait);
        response(['success'=>false,'message'=>'Too many verification requests from your network. Please try again in '.rlHumanWait($wait).'.','retry_after'=>$wait],429);
      }
      response(['success'=>false,'message'=>'Verification is temporarily unavailable. Please try again in a few minutes.'],503);
    }
    response(['success'=>true,'captcha_id'=>$challenge['id'],'image'=>$challenge['image'],'expires_in'=>$challenge['expires_in']]);
  }
  /*
   * Existing-student detection (no login required).
   *
   * Workflow: Enter Student Number -> Search -> Student Found -> the React
   * form is filled in automatically. Answers 404 "Student Not Found" when
   * no record carries that identifier.
   *
   * Anti-enumeration measures (an unauthenticated read of the student
   * table is the most sensitive thing this portal can do):
   *   - exact match only, never LIKE, so numbers cannot be walked one
   *     prefix at a time;
   *   - LIMIT 1, so a call returns a single record and never a list;
   *   - a minimum length below which nothing is ever sent to the database;
   *   - the response is re-keyed through studentLookupColumns(), so photos,
   *     addresses, contacts and print/release state cannot leak;
   *   - rlGuard() applies the same per-IP policy, honeypot and captcha
   *     escape hatch as the other public endpoints, in its own bucket.
   */
  if ($action==='studentLookup' && $_SERVER['REQUEST_METHOD']==='POST') {
    $data=input();
    /* Rate limit + bot trap BEFORE any validation or database read. */
    rlGuard('studentLookup', $data);
    $number=normalizeStudentNumber((string)($data['student_number']??''));
    if ($number==='' || strlen($number)<4)
      response(['success'=>false,'message'=>'Please enter a valid Student Number (at least 4 characters).'],422);
    /* The three identifier columns are the same triple the duplicate check on
     * studentSaveCard uses, so searching by any of them finds the record the
     * create form would have refused as a duplicate. */
    $lookupCols=implode(',',studentLookupColumns());
    $stmt=$pdo->prepare("SELECT id,$lookupCols FROM id_cards
                         WHERE student_number=? OR student_id_number=? OR lrn=?
                         ORDER BY updated_at DESC, id DESC LIMIT 1");
    $stmt->execute([$number,$number,$number]);
    $row=$stmt->fetch();
    if (!$row)
      response(['success'=>false,'message'=>'Student Not Found. No ID record matches that Student Number.'],404);
    $found=[];
    foreach (studentLookupColumns() as $col) $found[$col]=(string)($row[$col]??'');
    auditLog('student_lookup','id_card',(int)$row['id'],null,['student_number'=>$found['student_number']]);
    response(['success'=>true,'data'=>$found,'card_id'=>(int)$row['id']]);
  }
  if ($action==='studentUploadPhoto' && $_SERVER['REQUEST_METHOD']==='POST') {
    /* Rate limit BEFORE touching the upload: a throttled client must
     * not be able to push files onto the server at all. */
    rlGuard('studentUploadPhoto', $_POST);
    response(['success'=>true,'path'=>uploadStudentImage('photo')]);
  }
  if ($action==='studentSaveCard' && $_SERVER['REQUEST_METHOD']==='POST') {
    $data=input();
    /* Rate limit + bot trap before any validation or database write. */
    rlGuard('studentSaveCard', $data);
    $studentName=trim((string)($data['student_name']??''));
    $idType=trim((string)($data['id_type']??''));
    /* Input length validation */
    $course=trim((string)($data['course']??''));
    if ($studentName !== '' && strlen($studentName) > 150) response(['success'=>false,'message'=>'Student name is too long (max 150 characters)'],422);
    if (strlen($idType) > 20) response(['success'=>false,'message'=>'Invalid department'],422);
    if (strlen($course) > 255) response(['success'=>false,'message'=>'Course is too long'],422);
    if ($studentName==='') response(['success'=>false,'message'=>'Please enter the student full name'],422);
    if (!in_array($idType,['COLLEGE','JUNIOR_HIGH','SENIOR_HIGH'],true)) response(['success'=>false,'message'=>'Please select a valid department'],422);
    $fields=['student_name','id_type','course','grade_level','section_name','student_number','student_id_number','lrn','academic_year','school_year','photo_path','preferred_photo_id','address_line1','address_line2','emergency_label','emergency_contact','emergency_phone','terms_title','term_1','term_2','term_3','institution_name','institution_address','mobile_no','telephone_no','email_address','signatory_id','signatory_name','signature_path','template_id','original_photo_path','photo_processing_status','photo_processed_at','photo_crop','background_mode','background_color'];
    $values=[];foreach($fields as $f)$values[$f]=trim((string)($data[$f]??''));
    /* Photo processing metadata (kept neutral when not provided) */
    if (in_array($values['photo_processing_status']??'original',['original','processed','uploaded','failed'],true)===false) $values['photo_processing_status']='original';
    /* Background mode validation */
    if (!in_array($values['background_mode']??'transparent',['transparent','white','custom'],true)) $values['background_mode']='transparent';
    /* Background color validation (must be a valid HEX color) */
    if (!preg_match('/^#[0-9A-Fa-f]{6}$/',$values['background_color']??'')) $values['background_color']='#FFFFFF';
    /* The UI sends an ISO-8601 timestamp (new Date().toISOString()); MySQL
     * DATETIME needs 'Y-m-d H:i:s' — normalise so strict mode accepts it. */
    $ppa=trim((string)($values['photo_processed_at']??''));
    if ($ppa==='') { $values['photo_processed_at']=null; }
    else { $ts=strtotime($ppa); $values['photo_processed_at']=$ts?date('Y-m-d H:i:s',$ts):null; }
    /* Type-specific validation & mapping (shared helper; mirrors the UI rules) */
    if ($err=normalizeCardByType($values)) response(['success'=>false,'message'=>$err['message']],$err['code']);
    /* Serialize duplicate-check + insert so parallel identical submissions
     * cannot race past the check and create duplicate rows (QA concurrency §24). */
    $gotLock=(int)$pdo->query("SELECT GET_LOCK('lsc_card_write',10)")->fetchColumn();
    if ($gotLock!==1) response(['success'=>false,'message'=>'Server is busy, please try again shortly'],503);
    /* Duplicate prevention */
    $sn=trim((string)$values['student_number']);
    $sid=trim((string)$values['student_id_number']);
    $lrn=trim((string)$values['lrn']);
    $dupCols=[];$dupParams=[];
    if ($sn !== '')  { $dupCols[]='student_number=?';      $dupParams[]=$sn; }
    if ($sid !== '') { $dupCols[]='student_id_number=?';   $dupParams[]=$sid; }
    if ($lrn !== '') { $dupCols[]='lrn=?';                 $dupParams[]=$lrn; }
    if ($dupCols) {
      array_unshift($dupParams, $studentName);
      $dupCheck=$pdo->prepare('SELECT id FROM id_cards WHERE student_name=? AND ('.implode(' OR ',$dupCols).') LIMIT 1');
      $dupCheck->execute($dupParams);
      $dup = $dupCheck->fetch();
      if ($dup) {
        $pdo->query("SELECT RELEASE_LOCK('lsc_card_write')");
        response(['success'=>false,'message'=>'A student with this name and student number/ID/LRN already exists.'],409);
      }
      /* no duplicate: KEEP holding the lock until after the insert */
    }
    /* A record the public search already located must never be turned into a
     * second row by submitting the form again. The id is re-verified against
     * the database (so a crafted payload cannot aim at somebody else's record)
     * and only counts while it still carries one of the submitted identifiers.
     * Runs inside the same write lock as the duplicate check above. */
    $existingId=(int)($data['existing_card_id']??0);
    if ($existingId>0) {
      $exCols=[];$exParams=[$existingId];
      if ($sn  !== '') { $exCols[]='student_number=?';    $exParams[]=$sn; }
      if ($sid !== '') { $exCols[]='student_id_number=?'; $exParams[]=$sid; }
      if ($lrn !== '') { $exCols[]='lrn=?';               $exParams[]=$lrn; }
      if ($exCols) {
        $exCheck=$pdo->prepare('SELECT id FROM id_cards WHERE id=? AND ('.implode(' OR ',$exCols).') LIMIT 1');
        $exCheck->execute($exParams);
        if ($exCheck->fetch()) {
          $pdo->query("SELECT RELEASE_LOCK('lsc_card_write')");
          response(['success'=>false,'message'=>'An ID record already exists for this Student Number, so a second one cannot be created. If you need a replacement, please use "Report Lost ID".'],409);
        }
      }
    }
    $cols=implode(',',$fields);$pars=implode(',',array_map(fn($f)=>":$f",$fields));
    $stmt=$pdo->prepare("INSERT INTO id_cards($cols) VALUES($pars)");$stmt->execute($values);
    $id=(int)$pdo->lastInsertId();
    $pdo->query("SELECT RELEASE_LOCK('lsc_card_write')");
    auditLog('card_created','id_card',$id,null,['student_name'=>$values['student_name'],'student_number'=>$values['student_number'],'id_type'=>$values['id_type'],'status'=>'created']);
    response(['success'=>true,'id'=>$id,'message'=>'Your ID request has been submitted successfully.']);
  }
    if ($action==='lostIdRequest' && $_SERVER['REQUEST_METHOD']==='POST') {
    $data=input();
    /* Rate limit + bot trap before the ID lookup, the receipt upload
     * and the database insert. */
    rlGuard('lostIdRequest', $data);
    $studentName=trim((string)($data['student_name']??''));
    $idType=trim((string)($data['id_type']??''));
    if ($studentName==='') response(['success'=>false,'message'=>'Please enter the student full name'],422);
    if (!in_array($idType,['COLLEGE','JUNIOR_HIGH','SENIOR_HIGH'],true)) $idType='COLLEGE';
    $course=trim((string)($data['course']??''));
    $gradeLevel=trim((string)($data['grade_level']??''));
    $sectionName=trim((string)($data['section_name']??''));
    $studentNumber=trim((string)($data['student_number']??''));
    $lrn=trim((string)($data['lrn']??''));

    /*
     * The lost ID report must refer to an ID that already exists in the
     * system. The details the student filled in are used as the search
     * reference against the id_cards table (name + student number / ID
     * number / LRN). If no matching ID is created in the system the
     * request is rejected and nothing is saved — including the receipt,
     * which is uploaded ONLY after a matching ID is found.
     */
    $identifier=$studentNumber!==''?$studentNumber:$lrn;
    if ($identifier==='') {
      response(['success'=>false,'message'=>'Please provide the Student / ID Number (or LRN) so your ID can be checked in the system.'],422);
    }
    $stmt=$pdo->prepare('SELECT id,student_name,id_type,student_number,student_id_number,lrn,course,grade_level,section_name FROM id_cards WHERE student_name=? AND (student_number=? OR student_id_number=? OR lrn=?) ORDER BY (id_type=?) DESC, updated_at DESC LIMIT 1');
    $stmt->execute([$studentName,$identifier,$identifier,$identifier,$idType]);
    $m=$stmt->fetch();
    if (!$m) {
      response([
        'success'=>false,
        'message'=>'No ID found in the system. The details you entered do not match any ID created in the ID Management System. Please make sure your ID has already been created in the system before reporting it as lost.',
      ],404);
    }
    $referenceCardId=(int)$m['id'];

    // Upload receipt only AFTER we've confirmed the ID exists in the system
    $receipt=uploadDocument('receipt','receipts');

    $pdo->prepare("INSERT INTO lost_id_requests(student_name,id_type,course,grade_level,section_name,student_number,lrn,reference_card_id,status,receipt_path) VALUES(?,?,?,?,?,?,?,?,'pending',?)")
      ->execute([$studentName,$idType,$course,$gradeLevel,$sectionName,$studentNumber,$lrn,$referenceCardId,$receipt]);
    response(['success'=>true,'id'=>(int)$pdo->lastInsertId(),'reference_card_id'=>$referenceCardId,'message'=>'Your lost ID reprint request has been submitted. The administrator will review your request.']);
  }

  requireLogin();

  if ($action==='uploadPhoto' && $_SERVER['REQUEST_METHOD']==='POST') {
    verifyCsrf();
    $studentId = (int)($_POST['student_id'] ?? 0);
    if ($studentId > 0) {
      // New path: record in centralized photo library
      if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK)
        response(['success'=>false,'message'=>'No photo selected or upload failed'],422);
      $val = photoLibraryValidate($_FILES['photo']['tmp_name'], $_FILES['photo']['name']);
      if ($val['ok'] !== true) response(['success'=>false,'message'=>$val['message']],422);
      $pathInfo = photoStoragePath($studentId, 'ORIGINAL', $val['ext']);
      $destAbs = $pathInfo['dir'] . '/' . basename($pathInfo['rel']);
      if (!move_uploaded_file($_FILES['photo']['tmp_name'], $destAbs))
        response(['success'=>false,'message'=>'Failed to save photo'],500);
      $relPath = 'uploads/' . $pathInfo['rel'];
      $me = currentUser();
      $photoId = photoRecord($pdo, $studentId, 'CREATE_ID', 'ORIGINAL', null, $relPath, $val['mime'], filesize($destAbs), $val['w'], $val['h'], null, null, $me ? $me['id'] : null);
      $pdo->prepare('UPDATE id_cards SET photo_path=?, preferred_photo_id=? WHERE id=?')->execute([$relPath, $photoId, $studentId]);
      auditLog('PHOTO_UPLOADED','student_photo',$photoId,['student_id'=>$studentId,'source'=>'CREATE_ID'],['path'=>$relPath]);
      response(['success'=>true,'photo_id'=>$photoId,'path'=>$relPath,'width'=>$val['w'],'height'=>$val['h']]);
    }
    // Legacy path (no student_id yet — frontend will re-upload with student_id after save)
    response(['success'=>true,'path'=>uploadStudentImage('photo')]);
  }

  /* ===== Centralized Photo Library endpoints ===== */

  if ($action==='photoLibraryUpload' && $_SERVER['REQUEST_METHOD']==='POST') {
    verifyCsrf();
    $studentId = (int)($_POST['student_id'] ?? 0);
    if ($studentId <= 0) response(['success'=>false,'message'=>'Invalid student'],422);
    $chk = $pdo->prepare('SELECT id FROM id_cards WHERE id=?');
    $chk->execute([$studentId]);
    if (!$chk->fetch()) response(['success'=>false,'message'=>'Student record not found'],404);
    if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK)
      response(['success'=>false,'message'=>'No photo selected or upload failed'],422);
    $val = photoLibraryValidate($_FILES['photo']['tmp_name'], $_FILES['photo']['name']);
    if ($val['ok'] !== true) response(['success'=>false,'message'=>$val['message']],422);
    $label = trim((string)($_POST['label'] ?? ''));
    $pathInfo = photoStoragePath($studentId, 'ORIGINAL', $val['ext'], $label !== '' ? $label : null);
    $destAbs = $pathInfo['dir'] . '/' . basename($pathInfo['rel']);
    if (!move_uploaded_file($_FILES['photo']['tmp_name'], $destAbs))
      response(['success'=>false,'message'=>'Failed to save photo'],500);
    $relPath = 'uploads/' . $pathInfo['rel'];
    $me = currentUser();
    $photoId = photoRecord($pdo, $studentId, 'CREATE_ID', 'ORIGINAL', null, $relPath, $val['mime'], filesize($destAbs), $val['w'], $val['h'], null, null, $me ? $me['id'] : null);
    auditLog('PHOTO_UPLOADED','student_photo',$photoId,['student_id'=>$studentId,'source'=>'CREATE_ID'],['path'=>$relPath,'width'=>$val['w'],'height'=>$val['h']]);
    response(['success'=>true,'photo_id'=>$photoId,'path'=>$relPath,'width'=>$val['w'],'height'=>$val['h']]);
  }

  if ($action==='deleteUpload' && $_SERVER['REQUEST_METHOD']==='POST') {
    verifyCsrf(); $d=input(); $rel=trim((string)($d['path']??'')); response(['success'=>true,'deleted'=>deleteTemporaryUpload($rel)]);
  }

  if ($action==='photoLibraryUploadProcessed' && $_SERVER['REQUEST_METHOD']==='POST') {
    verifyCsrf();
    $studentId = (int)($_POST['student_id'] ?? 0);
    $parentPhotoId = (int)($_POST['parent_photo_id'] ?? 0);
    $backgroundMode = trim($_POST['background_mode'] ?? 'transparent');
    $backgroundColor = trim($_POST['background_color'] ?? '#FFFFFF');
    if ($studentId <= 0) response(['success'=>false,'message'=>'Invalid student'],422);
    $chk = $pdo->prepare('SELECT id FROM id_cards WHERE id=?');
    $chk->execute([$studentId]);
    if (!$chk->fetch()) response(['success'=>false,'message'=>'Student record not found'],404);
    if (!in_array($backgroundMode, ['transparent','white','custom'],true)) $backgroundMode='transparent';
    if (!preg_match('/^#[0-9A-Fa-f]{6}$/',$backgroundColor)) $backgroundColor='#FFFFFF';
    if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK)
      response(['success'=>false,'message'=>'No photo selected or upload failed'],422);
    $val = photoLibraryValidate($_FILES['photo']['tmp_name'], $_FILES['photo']['name']);
    if ($val['ok'] !== true) response(['success'=>false,'message'=>$val['message']],422);
    $pathInfo = photoStoragePath($studentId, 'PROCESSED', $val['ext']);
    $destAbs = $pathInfo['dir'] . '/' . basename($pathInfo['rel']);
    if (!move_uploaded_file($_FILES['photo']['tmp_name'], $destAbs))
      response(['success'=>false,'message'=>'Failed to save photo'],500);
    $relPath = 'uploads/' . $pathInfo['rel'];
    $bgInfo = ['mode' => $backgroundMode, 'color' => $backgroundColor];
    $cropData = null;
    if (!empty($_POST['crop_data'])) { $cd = json_decode($_POST['crop_data'], true); if (is_array($cd)) $cropData = $cd; }
    $me = currentUser();
    $photoId = photoRecord($pdo, $studentId, 'PHOTO_PROCESSING', 'PROCESSED',
      $parentPhotoId > 0 ? $parentPhotoId : null, $relPath, $val['mime'],
      filesize($destAbs), $val['w'], $val['h'], $bgInfo, $cropData, $me ? $me['id'] : null);
    auditLog('PHOTO_PROCESSED','student_photo',$photoId,['student_id'=>$studentId,'source'=>'PHOTO_PROCESSING','parent_photo_id'=>$parentPhotoId ?: null],['path'=>$relPath,'background'=>$bgInfo]);
    response(['success'=>true,'photo_id'=>$photoId,'path'=>$relPath,'width'=>$val['w'],'height'=>$val['h'],'background'=>$bgInfo]);
  }

  if ($action==='settings' && $_SERVER['REQUEST_METHOD']==='GET') { $rows=$pdo->query('SELECT setting_key,setting_value FROM system_settings')->fetchAll(); $out=[]; foreach($rows as $r)$out[$r['setting_key']]=$r['setting_value']; response(['success'=>true,'data'=>$out]); }

  /* --- Photo Library: list for student --- */
  if ($action==='photoLibraryList' && $_SERVER['REQUEST_METHOD']==='GET') {
    $studentId = (int)($_GET['student_id'] ?? 0);
    if ($studentId <= 0) response(['success'=>false,'message'=>'Invalid student'],422);
    $filters = ['source'=>trim($_GET['source']??''),'type'=>trim($_GET['type']??''),'is_active'=>$_GET['is_active']??'','processing_status'=>trim($_GET['processing_status']??'')];
    $photos = photoListForStudent($pdo, $studentId, $filters);
    response(['success'=>true,'data'=>$photos,'count'=>count($photos)]);
  }

  /* --- Photo Library: preferred photo for student --- */
  if ($action==='photoLibraryPreferred' && $_SERVER['REQUEST_METHOD']==='GET') {
    $studentId = (int)($_GET['student_id'] ?? 0);
    if ($studentId <= 0) response(['success'=>false,'message'=>'Invalid student'],422);
    $photo = photoGetPreferred($pdo, $studentId);
    response(['success'=>true,'data'=>$photo]);
  }

  /* --- Photo Library: clone a photo into another student's library.
   * Lets Create-ID pick a library photo BEFORE the new record is saved:
   * the photo is copied to the target student and selected as preferred. --- */
  if ($action==='photoLibraryClone' && $_SERVER['REQUEST_METHOD']==='POST') {
    verifyCsrf(); $d = input();
    $photoId = (int)($d['photo_id'] ?? 0);
    $studentId = (int)($d['student_id'] ?? 0);
    if ($photoId <= 0 || $studentId <= 0) response(['success'=>false,'message'=>'Invalid photo or student'],422);
    $chk = $pdo->prepare('SELECT id FROM id_cards WHERE id=?'); $chk->execute([$studentId]);
    if (!$chk->fetch()) response(['success'=>false,'message'=>'Student record not found'],404);
    $stmt = $pdo->prepare('SELECT * FROM student_photos WHERE id=? AND is_active=1 LIMIT 1');
    $stmt->execute([$photoId]); $src = $stmt->fetch();
    if (!$src) response(['success'=>false,'message'=>'Photo not found or archived'],404);
    $srcAbs = photoSafePath($src['file_path']);
    if (!$srcAbs || !file_exists($srcAbs)) response(['success'=>false,'message'=>'Photo file is missing on the server'],404);
    $ext = strtolower(pathinfo($srcAbs, PATHINFO_EXTENSION));
    if (!in_array($ext, ['png','jpg','jpeg','webp'], true)) $ext = 'png';
    $pathInfo = photoStoragePath($studentId, $src['type'] === 'PROCESSED' ? 'PROCESSED' : 'ORIGINAL', $ext === 'jpeg' ? 'jpg' : $ext);
    $destAbs = $pathInfo['dir'] . '/' . basename($pathInfo['rel']);
    if (!copy($srcAbs, $destAbs)) response(['success'=>false,'message'=>'Failed to attach photo'],500);
    $relPath = 'uploads/' . $pathInfo['rel'];
    $img = @getimagesize($destAbs);
    $me = currentUser();
    $newId = photoRecord($pdo, $studentId, $src['source'], $src['type'],
      (int)$src['id'], $relPath, $src['mime_type'], filesize($destAbs),
      $img ? (int)$img[0] : (int)$src['width'], $img ? (int)$img[1] : (int)$src['height'],
      $src['background_info'] ? json_decode($src['background_info'], true) : null,
      $src['crop_data'] ? json_decode($src['crop_data'], true) : null,
      $me ? $me['id'] : null);
    $pdo->prepare('UPDATE id_cards SET preferred_photo_id=?, photo_path=? WHERE id=?')->execute([$newId, $relPath, $studentId]);
    auditLog('PHOTO_CLONED','student_photo',$newId,['student_id'=>$studentId,'source_photo_id'=>$photoId],['path'=>$relPath]);
    response(['success'=>true,'photo_id'=>$newId,'path'=>$relPath]);
  }

  /* --- Photo Library: set preferred photo --- */
  if ($action==='photoLibrarySetPreferred' && $_SERVER['REQUEST_METHOD']==='POST') {
    verifyCsrf(); $d = input();
    $photoId = (int)($d['photo_id'] ?? 0);
    $studentId = (int)($d['student_id'] ?? 0);
    if ($photoId <= 0 || $studentId <= 0) response(['success'=>false,'message'=>'Invalid photo or student'],422);
    $photo = photoVerifyOwnership($pdo, $photoId, $studentId);
    if (!$photo) response(['success'=>false,'message'=>'Photo not found or access denied'],404);
    if (!$photo['is_active']) response(['success'=>false,'message'=>'Cannot select an archived photo'],422);
    $pdo->prepare('UPDATE id_cards SET preferred_photo_id=?, photo_path=? WHERE id=?')->execute([$photoId, $photo['file_path'], $studentId]);
    auditLog('PHOTO_SELECTED','student_photo',$photoId,['student_id'=>$studentId],['photo_id'=>$photoId]);
    response(['success'=>true,'message'=>'Photo selected for ID']);
  }

  /* --- Photo Library: archive --- */
  if ($action==='photoLibraryArchive' && $_SERVER['REQUEST_METHOD']==='POST') {
    verifyCsrf(); $d = input();
    $photoId = (int)($d['photo_id'] ?? 0);
    $studentId = (int)($d['student_id'] ?? 0);
    if ($photoId <= 0 || $studentId <= 0) response(['success'=>false,'message'=>'Invalid photo or student'],422);
    if (!photoVerifyOwnership($pdo, $photoId, $studentId)) response(['success'=>false,'message'=>'Photo not found or access denied'],404);
    $ok = photoArchive($pdo, $photoId, $studentId);
    auditLog('PHOTO_ARCHIVED','student_photo',$photoId,['student_id'=>$studentId],['archived'=>true]);
    response(['success'=>$ok,'archived'=>$ok]);
  }

  /* --- Photo Library: restore --- */
  if ($action==='photoLibraryRestore' && $_SERVER['REQUEST_METHOD']==='POST') {
    verifyCsrf(); $d = input();
    $photoId = (int)($d['photo_id'] ?? 0);
    $studentId = (int)($d['student_id'] ?? 0);
    if ($photoId <= 0 || $studentId <= 0) response(['success'=>false,'message'=>'Invalid photo or student'],422);
    if (!photoVerifyOwnership($pdo, $photoId, $studentId)) response(['success'=>false,'message'=>'Photo not found or access denied'],404);
    $pdo->prepare('UPDATE student_photos SET is_active=1, type="ORIGINAL" WHERE id=? AND student_id=?')->execute([$photoId, $studentId]);
    auditLog('PHOTO_RESTORED','student_photo',$photoId,['student_id'=>$studentId],['is_active'=>1]);
    response(['success'=>true,'message'=>'Photo restored']);
  }

  /* --- Photo Library: permanent delete (removes record + file) --- */
  if ($action==='photoLibraryDelete' && $_SERVER['REQUEST_METHOD']==='POST') {
    verifyCsrf(); $d = input();
    $photoId = (int)($d['photo_id'] ?? 0);
    if ($photoId <= 0) response(['success'=>false,'message'=>'Invalid photo'],422);
    $stmt = $pdo->prepare('SELECT * FROM student_photos WHERE id=? LIMIT 1');
    $stmt->execute([$photoId]);
    $photo = $stmt->fetch();
    if (!$photo) response(['success'=>false,'message'=>'Photo not found'],404);
    /* Detach derived copies first so no row references a missing parent */
    $pdo->prepare('UPDATE student_photos SET parent_photo_id=NULL WHERE parent_photo_id=?')->execute([$photoId]);
    $pdo->prepare('DELETE FROM student_photos WHERE id=?')->execute([$photoId]);
    $abs = photoSafePath($photo['file_path']);
    if ($abs && file_exists($abs)) @unlink($abs);
    /* If a card still points at the deleted photo, clear its selection */
    $pdo->prepare('UPDATE id_cards SET preferred_photo_id=NULL, photo_path=IF(photo_path=?,"",photo_path) WHERE preferred_photo_id=?')
        ->execute([$photo['file_path'], $photoId]);
    auditLog('PHOTO_DELETED','student_photo',$photoId,['student_id'=>(int)$photo['student_id'],'file_path'=>$photo['file_path']],null);
    response(['success'=>true,'message'=>'Photo deleted']);
  }

  /* --- Photo Library: replace the file behind an existing photo record --- */
  if ($action==='photoLibraryReplace' && $_SERVER['REQUEST_METHOD']==='POST') {
    verifyCsrf();
    $photoId = (int)($_POST['photo_id'] ?? 0);
    if ($photoId <= 0) response(['success'=>false,'message'=>'Invalid photo'],422);
    $stmt = $pdo->prepare('SELECT * FROM student_photos WHERE id=? LIMIT 1');
    $stmt->execute([$photoId]);
    $photo = $stmt->fetch();
    if (!$photo) response(['success'=>false,'message'=>'Photo not found'],404);
    if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK)
      response(['success'=>false,'message'=>'No photo selected or upload failed'],422);
    $val = photoLibraryValidate($_FILES['photo']['tmp_name'], $_FILES['photo']['name']);
    if ($val['ok'] !== true) response(['success'=>false,'message'=>$val['message']],422);
    $studentId = (int)$photo['student_id'];
    $pathInfo = photoStoragePath($studentId, $photo['type'] === 'PROCESSED' ? 'PROCESSED' : 'ORIGINAL', $val['ext']);
    $destAbs = $pathInfo['dir'] . '/' . basename($pathInfo['rel']);
    if (!move_uploaded_file($_FILES['photo']['tmp_name'], $destAbs))
      response(['success'=>false,'message'=>'Failed to save photo'],500);
    $relPath = 'uploads/' . $pathInfo['rel'];
    /* Same row keeps its id/source/created_at so preferred-photo links stay
     * valid; processing metadata is reset because the new file is a fresh
     * upload (old background/crop no longer apply). */
    $pdo->prepare('UPDATE student_photos SET file_path=?, mime_type=?, file_size=?, width=?, height=?, background_info=NULL, crop_data=NULL, processing_status="completed" WHERE id=?')
        ->execute([$relPath, $val['mime'], filesize($destAbs), $val['w'], $val['h'], $photoId]);
    $pdo->prepare('UPDATE id_cards SET photo_path=? WHERE preferred_photo_id=?')->execute([$relPath, $photoId]);
    $old = photoSafePath($photo['file_path']);
    if ($old && $old !== $destAbs && file_exists($old)) @unlink($old);
    auditLog('PHOTO_REPLACED','student_photo',$photoId,['student_id'=>$studentId,'old_path'=>$photo['file_path']],['path'=>$relPath,'width'=>$val['w'],'height'=>$val['h']]);
    response(['success'=>true,'message'=>'Photo replaced','photo_id'=>$photoId,'path'=>$relPath,'width'=>$val['w'],'height'=>$val['h']]);
  }

  /* --- Photo Library: search across all students --- */
  if ($action==='photoLibrarySearch' && $_SERVER['REQUEST_METHOD']==='GET') {
    $filters = ['q'=>trim($_GET['q']??''),'id_type'=>trim($_GET['id_type']??''),'source'=>trim($_GET['source']??''),'type'=>trim($_GET['type']??''),'is_active'=>$_GET['is_active']??'','processing_status'=>trim($_GET['processing_status']??'')];
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(100, max(1, (int)($_GET['per_page'] ?? 20)));
    $result = photoSearch($pdo, $filters, $page, $perPage);
    response(['success'=>true,'data'=>$result['data'],'total'=>$result['total'],'page'=>$result['page'],'per_page'=>$result['per_page'],'total_pages'=>$result['total_pages']]);
  }

  /* --- Photo Library: serve file --- */
  if ($action==='photoLibraryServe' && $_SERVER['REQUEST_METHOD']==='GET') {
    photoServeFile($pdo, (int)($_GET['id'] ?? 0));
  }

  if ($action==='signatories' && $_SERVER['REQUEST_METHOD']==='GET') response(['success'=>true,'data'=>$pdo->query('SELECT * FROM signatories WHERE is_active=1 ORDER BY full_name')->fetchAll()]);
    if ($action==='saveSignatory' && $_SERVER['REQUEST_METHOD']==='POST') {
    requireAdmin(); verifyCsrf(); $id=(int)($_POST['id']??0); $name=trim($_POST['full_name']??''); $position=trim($_POST['position_title']??''); if($name==='') response(['success'=>false,'message'=>'Signatory name is required'],422);
    $path=uploadImage('signature','signatures') ?: trim($_POST['signature_path']??''); if($path==='') response(['success'=>false,'message'=>'Signature image is required'],422);
    if($id){
      $oldSig=auditFetchRow('signatories',$id);
      $pdo->prepare('UPDATE signatories SET full_name=?,position_title=?,signature_path=? WHERE id=?')->execute([$name,$position,$path,$id]);
      auditLog('signatory_updated','signatory',$id,$oldSig?['full_name'=>$oldSig['full_name'],'position_title'=>$oldSig['position_title'],'signature_path'=>$oldSig['signature_path']]:null,['full_name'=>$name,'position_title'=>$position,'signature_path'=>$path]);
    }
    else{
      $pdo->prepare('INSERT INTO signatories(full_name,position_title,signature_path) VALUES(?,?,?)')->execute([$name,$position,$path]);$id=(int)$pdo->lastInsertId();
      auditLog('signatory_created','signatory',$id,null,['full_name'=>$name,'position_title'=>$position,'signature_path'=>$path]);
    }
    response(['success'=>true,'id'=>$id]);
  }
  if ($action==='cards' && $_SERVER['REQUEST_METHOD']==='GET') {
    $search=trim($_GET['search']??''); $type=trim($_GET['type']??''); $sql='SELECT c.*, u.username AS released_by_name, (SELECT COUNT(*) FROM student_photos sp WHERE sp.student_id=c.id AND sp.is_active=1) AS photo_count FROM id_cards c LEFT JOIN users u ON u.id=c.released_by'; $where=[];$params=[];
    if($search!==''){$where[]='(student_name LIKE ? OR student_number LIKE ? OR student_id_number LIKE ? OR lrn LIKE ? OR course LIKE ? OR grade_level LIKE ? OR section_name LIKE ?)';$like="%$search%";$params=array_merge($params,[$like,$like,$like,$like,$like,$like,$like]);}
    if($type!==''){$where[]='id_type=?';$params[]=$type;}
    if($where)$sql.=' WHERE '.implode(' AND ',$where); $sql.=' ORDER BY updated_at DESC'; $stmt=$pdo->prepare($sql);$stmt->execute($params);response(['success'=>true,'data'=>$stmt->fetchAll()]);
  }
  if ($action==='card' && $_SERVER['REQUEST_METHOD']==='GET') { $stmt=$pdo->prepare('SELECT c.*, u.username AS released_by_name, (SELECT COUNT(*) FROM student_photos sp WHERE sp.student_id=c.id AND sp.is_active=1) AS photo_count FROM id_cards c LEFT JOIN users u ON u.id=c.released_by WHERE c.id=?');$stmt->execute([(int)($_GET['id']??0)]);$row=$stmt->fetch();if(!$row)response(['success'=>false,'message'=>'Record not found'],404);response(['success'=>true,'data'=>$row]); }
  /*
   * Dashboard Analytics (GET, any authenticated staff/admin session).
   * Returns aggregated counts computed entirely in SQL (GROUP BY / conditional
   * SUMs) so the frontend never loads all records just to calculate totals.
   * Filters (all optional): date_from / date_to (id_cards.created_at window),
   * id_type (COLLEGE|JUNIOR_HIGH|SENIOR_HIGH) and status (card lifecycle).
   * Metric sources:
   *   - Total/Printed/Released/Per-department/Per-month: id_cards.
   *     "Printed" = the card has been through the printer at least once
   *     (print_count > 0, which stays true after release).
   *   - Lost IDs: lost_id_requests (the lost-ID reprint request feature).
   *   - Reprints: print_history rows with print_type='reprint' (print date
   *     window), joined to id_cards so department/status filters still apply.
   */
  if ($action==='dashboardStats' && $_SERVER['REQUEST_METHOD']==='GET') {
    requireLogin();
    $from=trim($_GET['date_from']??''); $to=trim($_GET['date_to']??'');
    $type=trim($_GET['id_type']??'');   $status=trim($_GET['status']??'');
    if($type!=='' && !in_array($type,['COLLEGE','JUNIOR_HIGH','SENIOR_HIGH'],true)) $type='';
    if($status!=='' && !in_array($status,['created','done','edited','printed','released'],true)) $status='';
    $cardWhere=[];$cardParams=[];
    if($from!==''&&strtotime($from)){$cardWhere[]='c.created_at>=?';$cardParams[]=date('Y-m-d 00:00:00',strtotime($from));}
    if($to!==''&&strtotime($to)){$cardWhere[]='c.created_at<=?';$cardParams[]=date('Y-m-d 23:59:59',strtotime($to));}
    if($type!==''){$cardWhere[]='c.id_type=?';$cardParams[]=$type;}
    if($status!==''){$cardWhere[]='c.status=?';$cardParams[]=$status;}
    $cardWhereSql=$cardWhere?'WHERE '.implode(' AND ',$cardWhere):'';
    /* 1) Summary counters — one pass over the filtered card set. */
    $s=$pdo->prepare("SELECT COUNT(*) AS total,
        SUM(CASE WHEN c.print_count>0 THEN 1 ELSE 0 END) AS printed,
        SUM(CASE WHEN c.status='released' THEN 1 ELSE 0 END) AS released,
        SUM(CASE WHEN c.status IN ('created','done','edited') THEN 1 ELSE 0 END) AS in_progress
      FROM id_cards c $cardWhereSql");
    $s->execute($cardParams);
    $summary=$s->fetch()?:[];
    foreach(['total','printed','released','in_progress'] as $k) $summary[$k]=(int)($summary[$k]??0);

    /* 2) Students/IDs by department (College / JHS / SHS). */
    $s=$pdo->prepare("SELECT c.id_type, COUNT(*) AS total,
        SUM(CASE WHEN c.print_count>0 THEN 1 ELSE 0 END) AS printed,
        SUM(CASE WHEN c.status='released' THEN 1 ELSE 0 END) AS released
      FROM id_cards c $cardWhereSql GROUP BY c.id_type");
    $s->execute($cardParams);
    $byTypeMap=[];
    foreach($s->fetchAll() as $r) $byTypeMap[$r['id_type']]=['total'=>(int)$r['total'],'printed'=>(int)$r['printed'],'released'=>(int)$r['released']];
    $byType=[];
    foreach(['COLLEGE','JUNIOR_HIGH','SENIOR_HIGH'] as $t) $byType[]=['id_type'=>$t]+($byTypeMap[$t]??['total'=>0,'printed'=>0,'released'=>0]);
    /* 3) Monthly ID generation: IDs created per month + IDs printed per month
     * (print_history) for the bar/line chart. Both aggregated in SQL. */
    $s=$pdo->prepare("SELECT DATE_FORMAT(c.created_at,'%Y-%m') AS ym, COUNT(*) AS n
      FROM id_cards c $cardWhereSql GROUP BY ym ORDER BY ym");
    $s->execute($cardParams);
    $generated=[];
    foreach($s->fetchAll() as $r) $generated[$r['ym']]=(int)$r['n'];
    $printWhere=['1'];$printParams=[];
    if($from!==''&&strtotime($from)){$printWhere[]='p.printed_at>=?';$printParams[]=date('Y-m-01 00:00:00',strtotime($from));}
    if($to!==''&&strtotime($to)){$printWhere[]='p.printed_at<=?';$printParams[]=date('Y-m-t 23:59:59',strtotime($to));}
    if($type!==''){$printWhere[]='c.id_type=?';$printParams[]=$type;}
    if($status!==''){$printWhere[]='c.status=?';$printParams[]=$status;}
    $s=$pdo->prepare("SELECT DATE_FORMAT(p.printed_at,'%Y-%m') AS ym, COUNT(*) AS n
      FROM print_history p JOIN id_cards c ON c.id=p.card_id
      WHERE ".implode(' AND ',$printWhere)."
      GROUP BY ym ORDER BY ym");
    $s->execute($printParams);
    $printedMonthly=[];
    foreach($s->fetchAll() as $r) $printedMonthly[$r['ym']]=(int)$r['n'];

    /* 4) Lost IDs reported (lost_id_requests): date window on created_at,
     * department filter applies; card-status filter does not (request rows
     * carry their own pending/approved/reprinted/rejected lifecycle). */
    $lostWhere=[];$lostParams=[];
    if($from!==''&&strtotime($from)){$lostWhere[]='l.created_at>=?';$lostParams[]=date('Y-m-d 00:00:00',strtotime($from));}
    if($to!==''&&strtotime($to)){$lostWhere[]='l.created_at<=?';$lostParams[]=date('Y-m-d 23:59:59',strtotime($to));}
    if($type!==''){$lostWhere[]='l.id_type=?';$lostParams[]=$type;}
    $lostWhereSql=$lostWhere?'WHERE '.implode(' AND ',$lostWhere):'';
    $s=$pdo->prepare("SELECT COUNT(*) FROM lost_id_requests l $lostWhereSql");
    $s->execute($lostParams);
    $lost=(int)$s->fetchColumn();
    /* 5) Reprint jobs: print_history reprints joined to id_cards so the
     * department/status filters still apply; window is the print date. */
    $rpWhere=['p.print_type=?'];$rpParams=['reprint'];
    if($from!==''&&strtotime($from)){$rpWhere[]='p.printed_at>=?';$rpParams[]=date('Y-m-d 00:00:00',strtotime($from));}
    if($to!==''&&strtotime($to)){$rpWhere[]='p.printed_at<=?';$rpParams[]=date('Y-m-d 23:59:59',strtotime($to));}
    if($type!==''){$rpWhere[]='c.id_type=?';$rpParams[]=$type;}
    if($status!==''){$rpWhere[]='c.status=?';$rpParams[]=$status;}
    $s=$pdo->prepare('SELECT COUNT(*) FROM print_history p JOIN id_cards c ON c.id=p.card_id WHERE '.implode(' AND ',$rpWhere));
    $s->execute($rpParams);
    $reprints=(int)$s->fetchColumn();
    response(['success'=>true,'data'=>[
      'summary'=>$summary,
      'by_type'=>$byType,
      'monthly'=>['generated'=>$generated,'printed'=>$printedMonthly],
      'lost_ids'=>$lost,
      'reprints'=>$reprints,
      'filters'=>['date_from'=>$from,'date_to'=>$to,'id_type'=>$type,'status'=>$status],
    ]]);
  }

  if ($action==='saveCard' && $_SERVER['REQUEST_METHOD']==='POST') {
    verifyCsrf(); $data=input(); $fields=['student_name','id_type','course','grade_level','section_name','student_number','student_id_number','lrn','academic_year','school_year','photo_path','preferred_photo_id','address_line1','address_line2','emergency_label','emergency_contact','emergency_phone','terms_title','term_1','term_2','term_3','institution_name','institution_address','mobile_no','telephone_no','email_address','signatory_id','signatory_name','signature_path','template_id','template_version','original_photo_path','photo_processing_status','photo_processed_at','photo_crop','background_mode','background_color'];
    $values=[];foreach($fields as $f)$values[$f]=trim((string)($data[$f]??''));$id=(int)($data['id']??0);
    /* Photo processing metadata (kept neutral when not provided) */
    if (in_array($values['photo_processing_status']??'original',['original','processed','uploaded','failed'],true)===false) $values['photo_processing_status']='original';
    /* Background mode validation */
    if (!in_array($values['background_mode']??'transparent',['transparent','white','custom'],true)) $values['background_mode']='transparent';
    /* Background color validation (must be a valid HEX color) */
    if (!preg_match('/^#[0-9A-Fa-f]{6}$/',$values['background_color']??'')) $values['background_color']='#FFFFFF';
    /* Normalise the UI's ISO-8601 photo_processed_at for the DATETIME column. */
    $ppa=trim((string)($values['photo_processed_at']??''));
    if ($ppa==='') { $values['photo_processed_at']=null; }
    else { $ts=strtotime($ppa); $values['photo_processed_at']=$ts?date('Y-m-d H:i:s',$ts):null; }
    /* Type-specific validation & mapping (shared helper; mirrors the UI rules) */
    if ($err=normalizeCardByType($values)) response(['success'=>false,'message'=>$err['message']],$err['code']);
    /* Serialize duplicate-check + write (same lock as the public path) */
    $gotLock=(int)$pdo->query("SELECT GET_LOCK('lsc_card_write',10)")->fetchColumn();
    if ($gotLock!==1) response(['success'=>false,'message'=>'Server is busy, please try again shortly'],503);
    /* Duplicate prevention — parity with the public studentSaveCard path.
     * Excludes the record being edited so updating a card never flags itself. */
    $sn=trim((string)$values['student_number']); $sidn=trim((string)$values['student_id_number']); $lrnD=trim((string)$values['lrn']);
    $dupCols=[];$dupParams=[];
    if($sn!=='')  { $dupCols[]='student_number=?';    $dupParams[]=$sn; }
    if($sidn!==''){ $dupCols[]='student_id_number=?'; $dupParams[]=$sidn; }
    if($lrnD!==''){ $dupCols[]='lrn=?';               $dupParams[]=$lrnD; }
    if($dupCols){
      $dupParams=array_merge([$values['student_name']],$dupParams);
      $dupSql='SELECT id FROM id_cards WHERE student_name=? AND ('.implode(' OR ',$dupCols).')';
      if($id>0){ $dupSql.=' AND id<>?'; $dupParams[]=(int)$id; }
      $dupSql.=' LIMIT 1';
      $dupCheck=$pdo->prepare($dupSql);$dupCheck->execute($dupParams);
      $dupRow=$dupCheck->fetch();
      if($dupRow){
        $pdo->query("SELECT RELEASE_LOCK('lsc_card_write')");
        response(['success'=>false,'message'=>'A student with this name and student number/ID/LRN already exists.'],409);
      }
      /* no duplicate: KEEP holding the lock until after the write */
    }
    $curRow=null;
    if($id){
      /*
       * ID status lifecycle: created -> done -> edited / printed.
       * Editing a card that was already completed ("done") or already
       * printed automatically flags it as "edited" so staff can see the
       * ID changed after it was finalised / printed.
       */
      $cur=$pdo->prepare('SELECT * FROM id_cards WHERE id=?');$cur->execute([$id]);$curRow=$cur->fetch();
      $curStatus=$curRow?trim((string)$curRow['status']):'created';
      $newStatus=in_array($curStatus,['done','printed'],true)?'edited':($curStatus!==''?$curStatus:'created');
      $values['status']=$newStatus;
      $sets=implode(',',array_map(fn($f)=>"$f=:$f",$fields));
      $values['id']=$id;$stmt=$pdo->prepare("UPDATE id_cards SET $sets, status=:status WHERE id=:id");$stmt->execute($values);
    }else{
      $values['status']='created';
      $allFields=array_merge($fields,['status']);
      $cols=implode(',',$allFields);$pars=implode(',',array_map(fn($f)=>":$f",$allFields));$stmt=$pdo->prepare("INSERT INTO id_cards($cols) VALUES($pars)");$stmt->execute($values);$id=(int)$pdo->lastInsertId();
      $newStatus='created';
    }
    $pdo->query("SELECT RELEASE_LOCK('lsc_card_write')");
    /* Audit: staff edit with structured field-level diff, or new card creation */
    if ($id && $curRow) {
      $diff=auditDiffFields($curRow,$values);
      if ($diff) auditLog('card_updated','id_card',$id,array_intersect_key($curRow,$values),$diff);
    } else {
      auditLog('card_created','id_card',$id,null,['student_name'=>$values['student_name'],'student_number'=>$values['student_number'],'id_type'=>$values['id_type'],'status'=>'created']);
    }
    response([
      'success'=>true,
      'id'=>$id,
      'status'=>$newStatus,
      'message'=>($newStatus==='edited')
        ? 'ID updated — status is now "Edited" (changed after completion/printing).'
        : 'ID saved successfully (Status: Created).',
    ]);
  }
  if ($action==='deleteCard' && $_SERVER['REQUEST_METHOD']==='POST') {
    verifyCsrf(); $d=input(); $cid=(int)($d['id']??0);
    $oldCard=auditFetchRow('id_cards',$cid);
    $pdo->prepare('DELETE FROM id_cards WHERE id=?')->execute([$cid]);
    auditLog('card_deleted','id_card',$cid,$oldCard?['student_name'=>$oldCard['student_name'],'student_number'=>$oldCard['student_number'],'id_type'=>$oldCard['id_type'],'status'=>$oldCard['status']]:null,null);
    response(['success'=>true]);
  }
  /*
   * Quick student creation — creates a minimal id_cards record so photos
   * can be uploaded to the Photo Library before the full ID is filled in.
   * Used by the Photo Processing module "upload first" workflow.
   */
  if ($action==='quickCreateStudent' && $_SERVER['REQUEST_METHOD']==='POST') {
    verifyCsrf(); $d=input();
    $name=trim((string)($d['student_name']??''));
    $idType=trim((string)($d['id_type']??'COLLEGE'));
    $idNumber=trim((string)($d['student_id_number']??''));
    $lrn=trim((string)($d['lrn']??''));
    $sectionName=trim((string)($d['section_name']??''));
    if ($name==='') response(['success'=>false,'message'=>'Student name is required'],422);
    if (!in_array($idType,['COLLEGE','JUNIOR_HIGH','SENIOR_HIGH'],true)) $idType='COLLEGE';
    if ($lrn!=='' && !preg_match('/^\d{12}$/',$lrn)) response(['success'=>false,'message'=>'LRN must be exactly 12 digits'],422);
    if ($idType==='COLLEGE') { $lrn=''; $sectionName=''; } // LRN + Section are Basic Education only
    $defaultYear='2026-2027';
    $signatories=[];
    foreach($pdo->query('SELECT id,full_name,signature_path FROM signatories WHERE is_active=1') as $sig){
      $signatories[$sig['full_name']]=['id'=>(int)$sig['id'],'path'=>$sig['signature_path']];
    }
    $sigName=$idType==='COLLEGE'?'Sherill S. Villaluz':'Annabelle V. Molina';
    $sigId=null;$sigPath='';
    if(isset($signatories[$sigName])){$sigId=$signatories[$sigName]['id'];$sigPath=$signatories[$sigName]['path'];}
    $cols=['student_name','id_type','section_name','student_id_number','lrn','academic_year','school_year','signatory_id','signatory_name','signature_path','status'];
    $vals=['student_name'=>$name,'id_type'=>$idType,'section_name'=>$sectionName,'student_id_number'=>$idNumber,'lrn'=>$lrn,'academic_year'=>$defaultYear,'school_year'=>$defaultYear,'signatory_id'=>$sigId,'signatory_name'=>$sigName,'signature_path'=>$sigPath,'status'=>'created'];
    $colList=implode(',',$cols);
    $parList=implode(',',array_map(fn($f)=>":$f",$cols));
    $gotLock=(int)$pdo->query("SELECT GET_LOCK('lsc_card_write',10)")->fetchColumn();
    if($gotLock!==1) response(['success'=>false,'message'=>'Server busy'],503);
    $stmt=$pdo->prepare("INSERT INTO id_cards($colList) VALUES($parList)");
    $stmt->execute($vals);
    $newId=(int)$pdo->lastInsertId();
    $pdo->query("SELECT RELEASE_LOCK('lsc_card_write')");
    auditLog('student_quick_created','id_card',$newId,null,['student_name'=>$name,'id_type'=>$idType,'section_name'=>$sectionName,'student_id_number'=>$idNumber,'lrn'=>$lrn]);
    response(['success'=>true,'id'=>$newId,'student_name'=>$name,'id_type'=>$idType,'message'=>'Student created. You can now upload photos.']);
    }
  /*
   * ID status control.
   *  - "done"    : admin marked the ID as completed / ready for printing
   *  - "printed" : records the print job on the Smart ID 51 (timestamp + counter)
   */
  if ($action==='setCardStatus' && $_SERVER['REQUEST_METHOD']==='POST') {
    verifyCsrf(); $d=input(); $id=(int)($d['id']??0); $status=(string)($d['status']??'');
    $allowed=['created','done','edited','printed'];
    if($id<=0||!in_array($status,$allowed,true)) response(['success'=>false,'message'=>'Invalid card id or status'],422);
    /* Audit: capture the PREVIOUS row BEFORE the update so old_value is correct */
    $oldCard=auditFetchRow('id_cards',$id);
    /* Released IDs are terminal — no further status transitions */
    if($oldCard && ($oldCard['status']??'')==='released') response(['success'=>false,'message'=>'This ID has already been released and can no longer change status.'],409);
    if($status==='printed'){
      $pdo->prepare("UPDATE id_cards SET status='printed', printed_at=NOW(), print_count=print_count+1 WHERE id=?")->execute([$id]);
    }else{
      $pdo->prepare('UPDATE id_cards SET status=? WHERE id=?')->execute([$status,$id]);
    }
    $stmt=$pdo->prepare('SELECT id,status,printed_at,print_count FROM id_cards WHERE id=?');$stmt->execute([$id]);
    $newRow=$stmt->fetch();
    auditLog($status==='printed'?'card_printed':'card_status_changed','id_card',$id,['status'=>$oldCard['status']??null],$newRow);
    response(['success'=>true,'data'=>$newRow,'message'=>'Status updated']);
  }
  /*
   * Print History — records EVERY print job (printCard, POST, CSRF-protected).
   *  - 1st print of an ID -> print_type='original' (reason defaults to "New Student")
   *  - any later print    -> print_type='reprint'  (reason REQUIRED: Damaged /
   *    Lost / Incorrect Information / Other — free text allowed, max 255 chars)
   * Keeps id_cards.status/printed_at/print_count in sync in the SAME
   * transaction (legacy consumers depend on them) and appends one immutable
   * print_history row. Released IDs are terminal and cannot be printed.
   */
  if ($action==='printCard' && $_SERVER['REQUEST_METHOD']==='POST') {
    verifyCsrf(); $d=input(); $id=(int)($d['id']??0);
    $reason=trim((string)($d['reason']??''));
    if($id<=0) response(['success'=>false,'message'=>'Invalid card id'],422);
    $oldCard=auditFetchRow('id_cards',$id);
    if(!$oldCard) response(['success'=>false,'message'=>'Record not found'],404);
    if(($oldCard['status']??'')==='released') response(['success'=>false,'message'=>'This ID has already been released and can no longer be printed.'],409);
    /* Original vs Reprint is derived server-side (never trusted from the client) */
    $printType=((int)($oldCard['print_count']??0)===0 && ($oldCard['printed_at']??null)===null)?'original':'reprint';
    if($printType==='reprint' && $reason==='')
      response(['success'=>false,'message'=>'A reason is required for a reprint (Damaged, Lost, Incorrect Information or Other).'],422);
    if(mb_strlen($reason)>255) response(['success'=>false,'message'=>'Reason is too long (max 255 characters)'],422);
    if($printType==='original' && $reason==='') $reason='New Student';
    $me=currentUser();
    try{
      $pdo->beginTransaction();
      $pdo->prepare("UPDATE id_cards SET status='printed', printed_at=NOW(), print_count=print_count+1 WHERE id=?")->execute([$id]);
      /* photo_id records the exact photo used (2026_09_14 migration) — the
       * column was added to this INSERT without its value, which made every
       * print fail with a bound-variable mismatch. */
      $photoId=((int)($oldCard['preferred_photo_id']??0))?:null;
      $pdo->prepare('INSERT INTO print_history(card_id,photo_id,user_id,print_type,reason,printed_at) VALUES(?,?,?,?,?,NOW())')
        ->execute([$id,$photoId,(int)($me['id']??0),$printType,$reason]);
      $phId=(int)$pdo->lastInsertId();
      $pdo->commit();
    }catch(Throwable $e){
      if($pdo->inTransaction())$pdo->rollBack();
      response(['success'=>false,'message'=>'Could not record the print job. '.$e->getMessage()],500);
    }
    $stmt=$pdo->prepare('SELECT id,status,printed_at,print_count FROM id_cards WHERE id=?');$stmt->execute([$id]);
    $newRow=$stmt->fetch();
    /* Audit: "Staff printed Student ID" — enriched with type + reason */
    auditLog('card_printed','id_card',$id,
      ['status'=>$oldCard['status']??null,'print_count'=>(int)($oldCard['print_count']??0)],
      ['status'=>'printed','student_name'=>$oldCard['student_name']??null,'printed_at'=>$newRow['printed_at']??null,'print_count'=>(int)($newRow['print_count']??0),'print_type'=>$printType,'reason'=>$reason]);
    response(['success'=>true,'data'=>$newRow,'print_type'=>$printType,'reason'=>$reason,'print_history_id'=>$phId,
      'message'=>$printType==='reprint'?'Reprint recorded — print history updated.':'Print recorded — print history updated.']);
  }
  /*
   * Batch Printing (bulkPrint, POST, CSRF-protected).
   * Prints several IDs in ONE Smart ID 51 job from the Records screen and
   * routes EVERY card through the same tracking as the single-card print:
   *   - one print_history row per ID (user, print_type, reason, timestamp)
   *   - id_cards.status/printed_at/print_count kept in sync in the SAME
   *     transaction (legacy consumers depend on them)
   *   - released IDs are terminal and are SKIPPED, never re-printed
   *   - Original vs Reprint is derived server-side per card (never trusted
   *     from the client); a batch containing any already-printed ID REQUIRES
   *     a reason (Damaged / Lost / Incorrect Information / Other), first
   *     prints default to "New Student"
   * Audit: one card_printed row per ID (flagged bulk) plus a single
   * bulk_ids_printed summary row with user, count and every ID/student.
   */
  if ($action==='bulkPrint' && $_SERVER['REQUEST_METHOD']==='POST') {
    requireLogin(); verifyCsrf(); $d=input();
    $ids=$d['ids']??'';
    if(!is_array($ids)) response(['success'=>false,'message'=>'No IDs selected for printing.'],422);
    $ids=array_values(array_unique(array_filter(array_map('intval',$ids),fn($v)=>$v>0)));
    if(!$ids) response(['success'=>false,'message'=>'No valid IDs were selected for printing.'],422);
    if(count($ids)>200) response(['success'=>false,'message'=>'Too many IDs in one batch (max 200). Please print in smaller batches.'],422);
    $reason=trim((string)($d['reason']??''));
    if(mb_strlen($reason)>255) response(['success'=>false,'message'=>'Reason is too long (max 255 characters)'],422);
    /* Load every requested card once, then classify:
     * printable / skipped (released) / missing (unknown id). */
    $in=implode(',',array_fill(0,count($ids),'?'));
    $st=$pdo->prepare("SELECT * FROM id_cards WHERE id IN ($in)");
    $st->execute($ids);
    $byId=[];
    foreach($st->fetchAll() as $r) $byId[(int)$r['id']]=$r;
    $todo=[];$skipped=[];$missing=[];
    foreach($ids as $id){
      if(!isset($byId[$id])){ $missing[]=['id'=>$id]; continue; }
      $c=$byId[$id];
      if(($c['status']??'')==='released'){
        $skipped[]=['id'=>$id,'student_name'=>$c['student_name']??null,'reason'=>'Released IDs can no longer be printed'];
        continue;
      }
      $todo[]=$c;
    }
    if(!$todo) response(['success'=>false,'message'=>'None of the selected IDs can be printed.','data'=>['printed'=>0,'skipped'=>$skipped,'missing'=>$missing]],422);
    /* Reprint reason: required when the batch contains ANY already-printed ID */
    $hasReprint=false;
    foreach($todo as $c){ if(!((int)($c['print_count']??0)===0 && ($c['printed_at']??null)===null)){ $hasReprint=true; break; } }
    if($hasReprint && $reason==='')
      response(['success'=>false,'message'=>'A reason is required: the batch contains IDs that were printed before (Damaged, Lost, Incorrect Information or Other).'],422);
    if(!$hasReprint && $reason==='') $reason='New Student';
    $me=currentUser();
    $printedRows=[];
    try{
      $pdo->beginTransaction();
      $upd=$pdo->prepare("UPDATE id_cards SET status='printed', printed_at=NOW(), print_count=print_count+1 WHERE id=? AND status<>'released'");
      $ins=$pdo->prepare('INSERT INTO print_history(card_id,photo_id,user_id,print_type,reason,printed_at) VALUES(?,?,?,?,?,NOW())');
      foreach($todo as $c){
        $cid=(int)$c['id'];
        $printType=((int)($c['print_count']??0)===0 && ($c['printed_at']??null)===null)?'original':'reprint';
        $cardReason=$printType==='reprint'?$reason:'New Student';
        $upd->execute([$cid]);
        if($upd->rowCount()===0){
          /* The card became released concurrently — abort without recording
           * anything so no ID is marked printed unless the row was updated. */
          $pdo->rollBack();
          response(['success'=>false,'message'=>'One of the selected IDs changed while printing. Please review the batch and try again.'],409);
        }
        $ins->execute([$cid,((int)($c['preferred_photo_id']??0))?:null,(int)($me['id']??0),$printType,$cardReason]);
        $printedRows[]=['id'=>$cid,'print_history_id'=>(int)$pdo->lastInsertId(),'student_name'=>$c['student_name']??null,'id_type'=>$c['id_type']??null,'print_type'=>$printType,'reason'=>$cardReason,'old_status'=>$c['status']??null,'old_print_count'=>(int)($c['print_count']??0)];
      }
      $pdo->commit();
    }catch(Throwable $e){
      if($pdo->inTransaction())$pdo->rollBack();
      response(['success'=>false,'message'=>'Could not record the print jobs. '.$e->getMessage()],500);
    }
    /* Audit: per-ID card_printed rows keep the audit trail identical to
     * single prints; the summary row records the bulk action as one event
     * (user + count + every ID/student included + timestamp via created_at). */
    foreach($printedRows as $p){
      auditLog('card_printed','id_card',$p['id'],
        ['status'=>$p['old_status'],'print_count'=>$p['old_print_count']],
        ['status'=>'printed','student_name'=>$p['student_name'],'print_type'=>$p['print_type'],'reason'=>$p['reason'],'bulk_print'=>true]);
    }
    auditLog('bulk_ids_printed','id_card',null,null,[
      'batch_print'=>true,
      'count'=>count($printedRows),
      'reason'=>$reason,
      'ids'=>$printedRows,
      'skipped'=>$skipped,
      'user'=>$me['username']??null,
    ]);
    response(['success'=>true,'data'=>[
      'printed'=>count($printedRows),
      'skipped'=>$skipped,
      'missing'=>$missing,
      'details'=>$printedRows,
    ],'message'=>count($printedRows).' ID(s) printed and recorded in the Print History.'
      .($skipped?' '.count($skipped).' skipped (released).':'')
      .($missing?' '.count($missing).' not found.':'')]);
  }
  /*
   * Released ID status: PRINTED -> RELEASED (staff/admin, CSRF-protected).
   * Only an ID that has actually been printed may be released; a released ID
   * cannot be released again. Records who released it, when, optional notes
   * and whether the student physically received the card. Audited.
   */
  if ($action==='releaseCard' && $_SERVER['REQUEST_METHOD']==='POST') {
    verifyCsrf(); $d=input(); $id=(int)($d['id']??0);
    if($id<=0) response(['success'=>false,'message'=>'Invalid card id'],422);
    $oldCard=auditFetchRow('id_cards',$id);
    if(!$oldCard) response(['success'=>false,'message'=>'Record not found'],404);
    if(($oldCard['status']??'')==='released') response(['success'=>false,'message'=>'This ID has already been released.'],409);
    if(($oldCard['status']??'')!=='printed' && ($oldCard['printed_at']??null)===null)
      response(['success'=>false,'message'=>'Only a printed ID can be released.'],422);
    $notes=trim((string)($d['release_notes']??''));
    if(strlen($notes)>1000) response(['success'=>false,'message'=>'Release notes are too long (max 1000 characters)'],422);
    $received=!empty($d['student_received'])?1:0;
    $me=currentUser();
    $pdo->prepare("UPDATE id_cards SET status='released', released_by=?, released_at=NOW(), release_notes=?, student_received=? WHERE id=?")
      ->execute([(int)($me['id']??0),$notes,$received,$id]);
    $stmt=$pdo->prepare('SELECT c.*, u.username AS released_by_name, (SELECT COUNT(*) FROM student_photos sp WHERE sp.student_id=c.id AND sp.is_active=1) AS photo_count FROM id_cards c LEFT JOIN users u ON u.id=c.released_by WHERE c.id=?');
    $stmt->execute([$id]);
    $newRow=$stmt->fetch();
    auditLog('card_released','id_card',$id,
      ['status'=>$oldCard['status']??null],
      ['status'=>'released','released_by_name'=>$newRow['released_by_name']??null,'released_at'=>$newRow['released_at']??null,'release_notes'=>$notes!==''?$notes:null,'student_received'=>$received]);
    response(['success'=>true,'data'=>$newRow,'message'=>'ID released']);
  }
  /* ===== ID Template module ===== */
  if ($action==='templates' && $_SERVER['REQUEST_METHOD']==='GET') {
    $rows=$pdo->query("SELECT * FROM id_templates ORDER BY FIELD(id_type,'COLLEGE','JUNIOR_HIGH','SENIOR_HIGH'), is_system DESC, updated_at DESC")->fetchAll();
    foreach($rows as &$r){ $r['fields_json']=($r['fields_json']??'')?json_decode($r['fields_json'],true):[]; }
    response(['success'=>true,'data'=>$rows]);
  }
  if ($action==='saveTemplate' && $_SERVER['REQUEST_METHOD']==='POST') {
    requireAdmin(); verifyCsrf();
    $id=(int)($_POST['id']??0);
    $name=trim((string)($_POST['name']??''));

    /* System templates (the built-in Original LSC Design) are protected. */
    if ($id>0) {
      $sv=$pdo->prepare('SELECT id,name,id_type,is_active,is_system FROM id_templates WHERE id=?');
      $sv->execute([$id]);
      $existing=$sv->fetch();
      if ($existing && (int)$existing['is_system']===1) {
        response(['success'=>false,'message'=>'The Original LSC Design is a protected system template and cannot be edited or deleted.'],403);
      }
    }

    $idType=trim((string)($_POST['id_type']??''));
    if($name==='') response(['success'=>false,'message'=>'Template name is required'],422);
    if(!in_array($idType,['COLLEGE','JUNIOR_HIGH','SENIOR_HIGH'],true)) $idType='COLLEGE';
    $ppm=trim((string)($_POST['photo_processing_mode']??'ORIGINAL'));
    if(!in_array($ppm,['ORIGINAL','TRANSPARENT'],true)) $ppm='ORIGINAL';
    $fieldsRaw=trim((string)($_POST['fields_json']??'[]'));
    $fieldsArr=json_decode($fieldsRaw,true);
    if(!is_array($fieldsArr)) $fieldsArr=[];
    $front=uploadImage('front_image','templates');
    $back=uploadImage('back_image','templates');
    $prevFront=trim((string)($_POST['front_image_prev']??''));
    $prevBack=trim((string)($_POST['back_image_prev']??''));
    if($front==='') $front=$prevFront;
    if($back==='') $back=$prevBack;
    if($id===0 && $front==='') response(['success'=>false,'message'=>'Please upload the FRONT design image (PNG or JPG) of the template'],422);
    $isActive=isset($_POST['is_active'])&&(string)($_POST['is_active'])==='1'?1:0;
    if($isActive){ $pdo->prepare('UPDATE id_templates SET is_active=0 WHERE id_type=?')->execute([$idType]); }
    $json=json_encode($fieldsArr,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if($id){
      $pdo->prepare('UPDATE id_templates SET name=?,id_type=?,front_image=?,back_image=?,fields_json=?,photo_processing_mode=?,is_active=? WHERE id=?')
        ->execute([$name,$idType,$front,$back,$json,$ppm,$isActive,$id]);
      auditLog('template_updated','id_template',$id,isset($existing)?['name'=>$existing['name'],'id_type'=>$existing['id_type'],'is_active'=>(int)$existing['is_active']]:null,['name'=>$name,'id_type'=>$idType,'photo_processing_mode'=>$ppm,'is_active'=>$isActive]);
    }else{
      $pdo->prepare('INSERT INTO id_templates(name,id_type,front_image,back_image,fields_json,photo_processing_mode,is_active) VALUES(?,?,?,?,?,?,?)')
        ->execute([$name,$idType,$front,$back,$json,$ppm,$isActive]);
      $id=(int)$pdo->lastInsertId();
      auditLog('template_created','id_template',$id,null,['name'=>$name,'id_type'=>$idType,'photo_processing_mode'=>$ppm,'is_active'=>$isActive]);
    }
    response(['success'=>true,'id'=>$id,'message'=>'Template saved successfully']);
  }
  if ($action==='setActiveTemplate' && $_SERVER['REQUEST_METHOD']==='POST') {
    requireAdmin(); verifyCsrf(); $d=input();
    $id=(int)($d['id']??0);
    $sv=$pdo->prepare('SELECT id_type FROM id_templates WHERE id=?');
    $sv->execute([$id]);
    $row=$sv->fetch();
    if(!$row) response(['success'=>false,'message'=>'Template not found'],404);
    $pv=$pdo->prepare('SELECT id FROM id_templates WHERE id_type=? AND is_active=1');$pv->execute([$row['id_type']]);$prevActive=$pv->fetch();
    $pdo->prepare('UPDATE id_templates SET is_active=0 WHERE id_type=?')->execute([$row['id_type']]);
    $pdo->prepare('UPDATE id_templates SET is_active=1 WHERE id=?')->execute([$id]);
    auditLog('template_activated','id_template',$id,['previous_active_id'=>$prevActive?(int)$prevActive['id']:null],['id_type'=>$row['id_type']]);
    response(['success'=>true,'message'=>'Template set as active']);
  }
  if ($action==='deleteTemplate' && $_SERVER['REQUEST_METHOD']==='POST') {
    requireAdmin(); verifyCsrf(); $d=input();
    $id=(int)($d['id']??0);
    $sv=$pdo->prepare('SELECT is_system,name FROM id_templates WHERE id=?');
    $sv->execute([$id]);
    $existing=$sv->fetch();
    if ($existing && (int)$existing['is_system']===1) {
      response(['success'=>false,'message'=>'The Original LSC Design is a protected system template and cannot be deleted.'],403);
    }
    $pdo->prepare('DELETE FROM id_templates WHERE id=?')->execute([$id]);
    auditLog('template_deleted','id_template',$id,['name'=>$existing['name']??null],null);
    response(['success'=>true,'message'=>'Template deleted']);
  }
  if ($action==='lostIdRequests' && $_SERVER['REQUEST_METHOD']==='GET') {
    $status=trim($_GET['status']??'');
    if ($status!=='') {
      $stmt=$pdo->prepare("SELECT * FROM lost_id_requests WHERE status=? ORDER BY FIELD(status,'pending','approved','reprinted','rejected'), updated_at DESC");
      $stmt->execute([$status]);
    } else {
      $stmt=$pdo->query("SELECT * FROM lost_id_requests ORDER BY FIELD(status,'pending','approved','reprinted','rejected'), updated_at DESC");
    }
    response(['success'=>true,'data'=>$stmt->fetchAll()]);
  }
  if ($action==='lostIdRequest' && $_SERVER['REQUEST_METHOD']==='GET') {
    $stmt=$pdo->prepare('SELECT * FROM lost_id_requests WHERE id=?');
    $stmt->execute([(int)($_GET['id']??0)]);
    $row=$stmt->fetch();
    if (!$row) response(['success'=>false,'message'=>'Request not found'],404);
    response(['success'=>true,'data'=>$row]);
  }
  if ($action==='updateLostIdRequest' && $_SERVER['REQUEST_METHOD']==='POST') {
    verifyCsrf(); $d=input();
    $id=(int)($d['id']??0);
    if ($id<=0) response(['success'=>false,'message'=>'Invalid request id'],422);
    $updates=[];$values=[];
    $allowedStatus=['pending','approved','reprinted','rejected'];
    if (isset($d['status']) && in_array($d['status'],$allowedStatus,true)) { $updates[]='status=?'; $values[]=$d['status']; }
    if (isset($d['reference_card_id'])) { $updates[]='reference_card_id=?'; $values[]=(int)$d['reference_card_id']; }
    if (array_key_exists('notes',$d)) { $updates[]='notes=?'; $values[]=trim((string)$d['notes']); }
    if (!$updates) response(['success'=>false,'message'=>'Nothing to update'],422);
    $oldReq=auditFetchRow('lost_id_requests',$id);
    $values[]=$id;
    $pdo->prepare('UPDATE lost_id_requests SET '.implode(',',$updates).' WHERE id=?')->execute($values);
    auditLog(isset($d['status'])&&$d['status']==='approved'?'lost_id_approved':'lost_id_updated','lost_id_request',$id,
      $oldReq?['status'=>$oldReq['status'],'notes'=>$oldReq['notes'],'reference_card_id'=>(int)$oldReq['reference_card_id']]:null,
      ['status'=>$d['status']??($oldReq['status']??null),'notes'=>array_key_exists('notes',$d)?trim((string)$d['notes']):($oldReq['notes']??null),'reference_card_id'=>isset($d['reference_card_id'])?(int)$d['reference_card_id']:($oldReq['reference_card_id']??null)]);
    response(['success'=>true,'message'=>'Request updated successfully']);
  }
  if ($action==='deleteLostIdRequest' && $_SERVER['REQUEST_METHOD']==='POST') {
    requireAdmin(); verifyCsrf(); $d=input();
    $oldReq=auditFetchRow('lost_id_requests',(int)($d['id']??0));
    $pdo->prepare('DELETE FROM lost_id_requests WHERE id=?')->execute([(int)($d['id']??0)]);
    auditLog('lost_id_deleted','lost_id_request',(int)($d['id']??0),$oldReq?['student_name'=>$oldReq['student_name'],'status'=>$oldReq['status']]:null,null);
    response(['success'=>true,'message'=>'Request removed']);
  }
  if ($action==='users' && $_SERVER['REQUEST_METHOD']==='GET') {
    requireAdmin();
    response(['success'=>true,'data'=>$pdo->query('SELECT id,username,full_name,role,is_active,created_at FROM users ORDER BY id')->fetchAll()]);
  }
  /*
  | Audit Logs (admin-only, read-only). Server-side pagination + filters.
  | Users can never create, edit or delete log rows through the API:
  | rows are only ever INSERTed by auditLog() inside audited actions.
  */
  if ($action==='auditLogs' && $_SERVER['REQUEST_METHOD']==='GET') {
    requireAdmin();
    $search=trim($_GET['search']??''); $actFilter=trim($_GET['action_type']??''); $userFilter=trim($_GET['user_id']??'');
    $from=trim($_GET['date_from']??''); $to=trim($_GET['date_to']??'');
    $page=max(1,(int)($_GET['page']??1)); $perPage=min(100,max(5,(int)($_GET['per_page']??15)));
    $where=[];$params=[];
    if($search!==''){
      $where[]='(a.action LIKE ? OR a.entity_type LIKE ? OR a.entity_id=? OR a.old_value LIKE ? OR a.new_value LIKE ? OR u.username LIKE ? OR u.full_name LIKE ?)';
      $like="%$search%";
      array_push($params,$like,$like,ctype_digit($search)?(int)$search:0,$like,$like,$like,$like);
    }
    if($actFilter!==''){$where[]='a.action=?';$params[]=$actFilter;}
    if($userFilter!==''){$where[]='a.user_id=?';$params[]=(int)$userFilter;}
    if($from!==''&&strtotime($from)){$where[]='a.created_at>=?';$params[]=date('Y-m-d 00:00:00',strtotime($from));}
    if($to!==''&&strtotime($to)){$where[]='a.created_at<=?';$params[]=date('Y-m-d 23:59:59',strtotime($to));}
    $whereSql=$where?'WHERE '.implode(' AND ',$where):'';
    $c=$pdo->prepare("SELECT COUNT(*) FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id $whereSql");$c->execute($params);$total=(int)$c->fetchColumn();
    $totalPages=max(1,(int)ceil($total/$perPage));$page=min($page,$totalPages);
    $off=($page-1)*$perPage;
    $s=$pdo->prepare("SELECT a.*,u.username,u.full_name,u.role AS user_role FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id $whereSql ORDER BY a.id DESC LIMIT $perPage OFFSET $off");
    $s->execute($params);
    $acts=$pdo->query('SELECT DISTINCT action FROM audit_logs ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
    response(['success'=>true,'data'=>$s->fetchAll(),'pagination'=>['page'=>$page,'per_page'=>$perPage,'total'=>$total,'total_pages'=>$totalPages],'actions'=>$acts]);
  }
  /*
   * Print History (admin-only, read-only). Every print job recorded by the
   * printCard action: when, which student, who printed it, Original/Reprint
   * and the reason. Server-side pagination + search / user / type / date
   * filters, plus report totals (prints this month, original vs reprint and
   * how many IDs each staff member printed). Rows are never editable or
   * deletable through the API.
   */
  if ($action==='printHistory' && $_SERVER['REQUEST_METHOD']==='GET') {
    requireAdmin();
    $search=trim($_GET['search']??''); $typeFilter=trim($_GET['type']??''); $userFilter=trim($_GET['user_id']??'');
    $from=trim($_GET['date_from']??''); $to=trim($_GET['date_to']??'');
    $page=max(1,(int)($_GET['page']??1)); $perPage=min(100,max(5,(int)($_GET['per_page']??15)));
    $where=[];$params=[];
    if($search!==''){
      $where[]='(c.student_name LIKE ? OR c.student_number LIKE ? OR c.student_id_number LIKE ? OR c.lrn LIKE ? OR p.reason LIKE ? OR u.username LIKE ? OR u.full_name LIKE ?)';
      $like="%$search%";
      array_push($params,$like,$like,$like,$like,$like,$like,$like);
    }
    if($typeFilter!=='' && in_array($typeFilter,['original','reprint'],true)){$where[]='p.print_type=?';$params[]=$typeFilter;}
    if($userFilter!==''){$where[]='p.user_id=?';$params[]=(int)$userFilter;}
    if($from!==''&&strtotime($from)){$where[]='p.printed_at>=?';$params[]=date('Y-m-d 00:00:00',strtotime($from));}
    if($to!==''&&strtotime($to)){$where[]='p.printed_at<=?';$params[]=date('Y-m-d 23:59:59',strtotime($to));}
    $whereSql=$where?'WHERE '.implode(' AND ',$where):'';
    $c=$pdo->prepare("SELECT COUNT(*) FROM print_history p LEFT JOIN id_cards c ON c.id=p.card_id LEFT JOIN users u ON u.id=p.user_id $whereSql");
    $c->execute($params);$total=(int)$c->fetchColumn();
    $totalPages=max(1,(int)ceil($total/$perPage));$page=min($page,$totalPages);
    $off=($page-1)*$perPage;
    $s=$pdo->prepare("SELECT p.*,c.student_name,c.student_number,c.student_id_number,c.id_type,u.username,u.full_name,u.role AS user_role FROM print_history p LEFT JOIN id_cards c ON c.id=p.card_id LEFT JOIN users u ON u.id=p.user_id $whereSql ORDER BY p.printed_at DESC, p.id DESC LIMIT $perPage OFFSET $off");
    $s->execute($params);
    /* Report totals: "How many IDs were printed this month?", "original vs
     * reprints?" and "how many IDs did each staff member print?" */
    $monthStart=date('Y-m-01 00:00:00');
    $m=$pdo->prepare('SELECT print_type,COUNT(*) AS n FROM print_history WHERE printed_at>=? GROUP BY print_type');
    $m->execute([$monthStart]);
    $month=['total'=>0,'original'=>0,'reprint'=>0];
    foreach($m->fetchAll() as $r){$month[$r['print_type']]=(int)$r['n'];$month['total']+=(int)$r['n'];}
    $byUser=$pdo->prepare('SELECT u.id,u.username,u.full_name,u.role,SUM(CASE WHEN p.printed_at>=? THEN 1 ELSE 0 END) AS month_count,COUNT(*) AS total_count FROM print_history p JOIN users u ON u.id=p.user_id GROUP BY u.id,u.username,u.full_name,u.role ORDER BY total_count DESC, u.username');
    $byUser->execute([$monthStart]);
    response(['success'=>true,'data'=>$s->fetchAll(),'pagination'=>['page'=>$page,'per_page'=>$perPage,'total'=>$total,'total_pages'=>$totalPages],'summary'=>['month'=>$month,'by_user'=>$byUser->fetchAll()]]);
  }
  if ($action==='saveUser' && $_SERVER['REQUEST_METHOD']==='POST') {
    requireAdmin(); verifyCsrf(); $d=input();
    $id=(int)($d['id']??0); $username=trim((string)($d['username']??'')); $fullName=trim((string)($d['full_name']??''));
    $role=($d['role']??'staff')==='admin'?'admin':'staff'; $password=(string)($d['password']??'');
    $isActive=isset($d['is_active'])?((int)(bool)$d['is_active']):1;
    if($username===''||$fullName==='') response(['success'=>false,'message'=>'Username and full name are required'],422);
    if($password!==''&&strlen($password)<6) response(['success'=>false,'message'=>'Password must be at least 6 characters'],422);
    if(!$id&&$password==='') response(['success'=>false,'message'=>'Password is required for new users'],422);
    $dup=$pdo->prepare('SELECT id FROM users WHERE username=? AND id<>? LIMIT 1');$dup->execute([$username,$id]);
    if($dup->fetch()) response(['success'=>false,'message'=>'That username is already taken'],422);
    if($id===(int)$_SESSION['user_id']){$isActive=1;$role='admin';}
    if($id){
      $oldUser=auditFetchRow('users',$id);
      if($password!==''){$pdo->prepare('UPDATE users SET username=?,full_name=?,role=?,is_active=?,password=? WHERE id=?')->execute([$username,$fullName,$role,$isActive,password_hash($password,PASSWORD_DEFAULT),$id]);}
      else{$pdo->prepare('UPDATE users SET username=?,full_name=?,role=?,is_active=? WHERE id=?')->execute([$username,$fullName,$role,$isActive,$id]);}
      /* Audit (passwords are never logged) */
      $disabling=((int)($oldUser['is_active']??1)===1 && $isActive===0);
      auditLog($disabling?'user_disabled':'user_updated','user',$id,$oldUser?['username'=>$oldUser['username'],'full_name'=>$oldUser['full_name'],'role'=>$oldUser['role'],'is_active'=>(int)$oldUser['is_active']]:null,['username'=>$username,'full_name'=>$fullName,'role'=>$role,'is_active'=>$isActive]);
    }else{
      $pdo->prepare('INSERT INTO users(username,password,full_name,role,is_active) VALUES(?,?,?,?,1)')->execute([$username,password_hash($password,PASSWORD_DEFAULT),$fullName,$role]);
      $id=(int)$pdo->lastInsertId();
      auditLog('user_created','user',$id,null,['username'=>$username,'full_name'=>$fullName,'role'=>$role,'is_active'=>1]);
    }
    response(['success'=>true,'id'=>$id,'message'=>'User saved successfully']);
  }
  if ($action==='deleteUser' && $_SERVER['REQUEST_METHOD']==='POST') {
    requireAdmin(); verifyCsrf(); $d=input(); $id=(int)($d['id']??0);
    if($id===(int)$_SESSION['user_id']) response(['success'=>false,'message'=>'You cannot deactivate your own account'],422);
    $oldUser=auditFetchRow('users',$id);
    $pdo->prepare('UPDATE users SET is_active=0 WHERE id=?')->execute([$id]);
    auditLog('user_disabled','user',$id,$oldUser?['username'=>$oldUser['username'],'is_active'=>(int)$oldUser['is_active']]:['is_active'=>1],['is_active'=>0]);
    response(['success'=>true,'message'=>'User deactivated']);
  }
  /* CSV / Excel Student Import */
  if ($action==='importTemplate' && $_SERVER['REQUEST_METHOD']==='GET') { requireLogin(); while (ob_get_level() > 0) ob_end_clean(); importSendTemplate(); return; }
  if ($action==='importUpload' && $_SERVER['REQUEST_METHOD']==='POST') {
    requireLogin(); verifyCsrf();
    if(!isset($_FILES['file'])||$_FILES['file']['error']!==UPLOAD_ERR_OK) response(['success'=>false,'message'=>'No file uploaded or upload error'],422);
    $file=$_FILES['file']; $ext=strtolower(pathinfo($file['name'],PATHINFO_EXTENSION));
    if(!in_array($ext,['csv','xlsx','xls'],true)) response(['success'=>false,'message'=>'Only .csv, .xlsx and .xls files are allowed'],422);
    if($file['size']>IMPORT_MAX_FILE_BYTES) response(['success'=>false,'message'=>'File is too large (max 10 MB)'],422);
    try{
      $parsed=match($ext){'csv'=>importParseCsv($file['tmp_name']),'xlsx'=>importParseXlsx($file['tmp_name']),'xls'=>importParseXls($file['tmp_name'])};
      [$collected,$sheetErrors]=importCollectRows($parsed);
      $result=importValidateRows($pdo,$collected);
      response(['success'=>true,'data'=>$result]);
    }catch(Throwable $e){response(['success'=>false,'message'=>$e->getMessage()],422);}
  }
  if ($action==='importCommit' && $_SERVER['REQUEST_METHOD']==='POST') {
    requireLogin(); verifyCsrf(); $d=input();
    $rows=$d['rows']??[];
    if(!is_array($rows)||empty($rows)) response(['success'=>false,'message'=>'No rows to import'],422);
    try{
      $ids=importCommitRows($pdo,$rows);
      auditLog('students_imported','id_card',null,null,['count'=>count($ids),'ids'=>$ids]);
      response(['success'=>true,'imported'=>count($ids),'ids'=>$ids,'message'=>count($ids).' students imported successfully']);
        }catch(Throwable $e){response(['success'=>false,'message'=>$e->getMessage()],500);}
  }
  if ($action==='bulkGenerateAudit' && $_SERVER['REQUEST_METHOD']==='POST') {
    requireAdmin(); verifyCsrf(); $d=input();
    $total=(int)($d['total']??0);
    $succeeded=(int)($d['succeeded']??0);
    $failed=(int)($d['failed']??0);
    $templateId=$d['template_id']??null;
    auditLog('bulk_ids_generated','id_card',null,null,[
      'total'=>$total,
      'succeeded'=>$succeeded,
      'failed'=>$failed,
      'template_id'=>$templateId,
      'generated_ids'=>$d['generated_ids']??[],
    ]);
    response(['success'=>true,'message'=>'Bulk generation recorded in audit log']);
  }
  response(['success'=>false,'message'=>'Unknown action'],404);
} catch(Throwable $e){response(['success'=>false,'message'=>$e->getMessage()],500);}
