<?php
// Shared helpers: PDO connection, session, JSON I/O, role guard.
require_once __DIR__ . '/config.php';

// Never print PHP warnings into the JSON the pages read (XAMPP has display_errors on by default).
ini_set('display_errors', '0');
error_reporting(E_ALL);

// Any uncaught error is answered as JSON, so the page can show what went wrong instead of silently failing.
set_exception_handler(function (Throwable $e) {
    error_log('[exam-system] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
});

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER, DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                 PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                 PDO::ATTR_EMULATE_PREPARES => false]
            );
        } catch (PDOException $e) {
            json_out(['error' => 'Database connection failed. Check api/config.php and that MySQL is running.'], 500);
        }
        ensure_schema($pdo);
    }
    return $pdo;
}

/**
 * Upgrades an older database in place (exam sections, restore-access audit trail, live-camera signalling ...), so nobody
 * has to remember to import the migrate_*.sql files. Every step checks first, so it is safe to run on any version and
 * as often as needed.
 */
function ensure_schema(PDO $pdo): void {
    if (!empty($_SESSION['schema_v6'])) return;
    try {
        $has = function (string $table, string $column) use ($pdo): bool {
            $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $st->execute([$table, $column]);
            return (bool)$st->fetchColumn();
        };
        $hasIndex = function (string $table, string $index) use ($pdo): bool {
            $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
            $st->execute([$table, $index]);
            return (bool)$st->fetchColumn();
        };
        // Create a table with its foreign key; if the key cannot be added on this server, create it without.
        $create = function (string $sql, string $fk = '') use ($pdo): void {
            if ($fk === '') { $pdo->exec($sql . ') ENGINE=InnoDB'); return; }
            try { $pdo->exec($sql . ', ' . $fk . ') ENGINE=InnoDB'); }
            catch (PDOException $e) { $pdo->exec($sql . ') ENGINE=InnoDB'); }
        };

        // ---- v3: exam sections, re-open support ----
        $create('CREATE TABLE IF NOT EXISTS exam_sections (exam_id INT NOT NULL, section VARCHAR(30) NOT NULL, PRIMARY KEY (exam_id, section)',
                'FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE');
        if (!$has('submissions', 'reopen_count')) $pdo->exec('ALTER TABLE submissions ADD COLUMN reopen_count INT NOT NULL DEFAULT 0');
        if (!$has('submissions', 'reopened_at'))  $pdo->exec('ALTER TABLE submissions ADD COLUMN reopened_at DATETIME NULL');
        $create("CREATE TABLE IF NOT EXISTS violation_history (id INT AUTO_INCREMENT PRIMARY KEY, submission_id INT NOT NULL, attempt INT NOT NULL,
                 type_id VARCHAR(30) NOT NULL, label VARCHAR(150) NOT NULL, severity ENUM('medium','high','critical') NOT NULL DEFAULT 'medium',
                 occurred_at TIMESTAMP NULL",
                'FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE');

        // ---- v4: exam editing, restore-access audit trail, camera state, live-camera signalling ----
        if (!$has('exams', 'description')) $pdo->exec('ALTER TABLE exams ADD COLUMN description TEXT NULL');
        if (!$has('exams', 'updated_at'))  $pdo->exec('ALTER TABLE exams ADD COLUMN updated_at DATETIME NULL');
        if (!$has('submissions', 'auto_reason'))      $pdo->exec('ALTER TABLE submissions ADD COLUMN auto_reason VARCHAR(20) NULL');
        if (!$has('submissions', 'auto_reason_text')) $pdo->exec('ALTER TABLE submissions ADD COLUMN auto_reason_text VARCHAR(255) NULL');
        if (!$has('submissions', 'cam_state'))        $pdo->exec('ALTER TABLE submissions ADD COLUMN cam_state VARCHAR(20) NULL');
        if (!$has('submissions', 'cam_updated'))      $pdo->exec('ALTER TABLE submissions ADD COLUMN cam_updated DATETIME NULL');

        if (!$has('violations', 'attempt')) {
            // Violations now stay where they are when a professor restores access; each one is tagged with its attempt instead.
            $pdo->exec('ALTER TABLE violations ADD COLUMN attempt INT NOT NULL DEFAULT 1');
            $pdo->exec('UPDATE violations v JOIN submissions s ON s.id = v.submission_id SET v.attempt = s.reopen_count + 1');
            // Violations that the old "re-open" moved into violation_history come back as earlier attempts.
            $pdo->exec('INSERT INTO violations (submission_id, type_id, label, severity, occurred_at, attempt)
                        SELECT submission_id, type_id, label, severity, COALESCE(occurred_at, NOW()), attempt FROM violation_history');
        }
        if (!$hasIndex('violations', 'idx_vio_sub_attempt')) $pdo->exec('ALTER TABLE violations ADD INDEX idx_vio_sub_attempt (submission_id, attempt)');

        $create('CREATE TABLE IF NOT EXISTS attempt_history (id INT AUTO_INCREMENT PRIMARY KEY, submission_id INT NOT NULL, attempt INT NOT NULL,
                 started_at DATETIME NULL, ended_at DATETIME NULL, mode VARCHAR(12) NOT NULL, end_reason VARCHAR(20) NULL, end_reason_text VARCHAR(255) NULL,
                 auto_score INT NOT NULL DEFAULT 0, max_score INT NOT NULL DEFAULT 0, progress INT NOT NULL DEFAULT 0, violation_count INT NOT NULL DEFAULT 0,
                 answers_json MEDIUMTEXT NULL, violations_json MEDIUMTEXT NULL, archived_at DATETIME NOT NULL, UNIQUE KEY uq_attempt (submission_id, attempt)',
                'FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE');
        $create("CREATE TABLE IF NOT EXISTS access_restorations (id INT AUTO_INCREMENT PRIMARY KEY, submission_id INT NOT NULL, exam_id INT NOT NULL,
                 exam_code VARCHAR(20) NOT NULL, student_id INT NOT NULL, student_no VARCHAR(30) NOT NULL, student_name VARCHAR(120) NOT NULL,
                 professor_id INT NOT NULL, professor_name VARCHAR(120) NOT NULL, mode ENUM('resume','new_attempt') NOT NULL,
                 from_attempt INT NOT NULL, to_attempt INT NOT NULL, violations_at_restore INT NOT NULL DEFAULT 0, threshold INT NOT NULL DEFAULT 0,
                 reason VARCHAR(255) NULL, note VARCHAR(255) NULL, ip_address VARCHAR(45) NULL, created_at DATETIME NOT NULL,
                 KEY idx_restore_exam (exam_id, created_at), KEY idx_restore_prof (professor_id, created_at)");
        $create("CREATE TABLE IF NOT EXISTS rtc_signals (id BIGINT AUTO_INCREMENT PRIMARY KEY, submission_id INT NOT NULL, viewer_id INT NOT NULL,
                 conn_id VARCHAR(40) NOT NULL, direction ENUM('to_student','to_viewer') NOT NULL, kind VARCHAR(10) NOT NULL, payload MEDIUMTEXT NULL,
                 created_at DATETIME NOT NULL, KEY k_student (submission_id, direction, id), KEY k_viewer (viewer_id, direction, id)",
                'FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE');

        // ---- v5: department + year-level eligibility, exam window, admin-granted editors ----
        if (!$has('exams', 'department_id')) {
            $pdo->exec('ALTER TABLE exams ADD COLUMN department_id INT NULL AFTER professor_id');
            try { $pdo->exec('ALTER TABLE exams ADD CONSTRAINT fk_exams_department FOREIGN KEY (department_id) REFERENCES departments(id)'); }
            catch (PDOException $e) { /* the column works without the key */ }
        }
        if (!$has('exams', 'available_until')) $pdo->exec('ALTER TABLE exams ADD COLUMN available_until DATETIME NULL AFTER scheduled_at');
        $create('CREATE TABLE IF NOT EXISTS exam_year_levels (exam_id INT NOT NULL, year_level VARCHAR(20) NOT NULL, PRIMARY KEY (exam_id, year_level)',
                'FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE');
        $create('CREATE TABLE IF NOT EXISTS exam_editors (exam_id INT NOT NULL, professor_id INT NOT NULL, can_delete TINYINT(1) NOT NULL DEFAULT 0,
                 granted_by INT NULL, granted_at DATETIME NOT NULL, PRIMARY KEY (exam_id, professor_id)',
                'FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE');
        // Every exam belongs to the department of the professor who made it (older exams get it here).
        $pdo->exec('UPDATE exams e JOIN users p ON p.id = e.professor_id SET e.department_id = p.department_id WHERE e.department_id IS NULL AND p.department_id IS NOT NULL');

        // ---- v6: graduation tracking, result release + feedback, notifications, activity log ----
        $v6first = !$has('users', 'student_type');
        if ($v6first) {
            $pdo->exec("ALTER TABLE users ADD COLUMN student_type ENUM('regular','irregular') NULL DEFAULT NULL");
            // Only what the existing data states for certain: the year level "Irregular" means an irregular student.
            // Everybody else stays unclassified until an administrator classifies them - nobody is assumed to be Regular.
            $pdo->exec("UPDATE users SET student_type = 'irregular' WHERE role = 'student' AND year_level = 'Irregular'");
        }
        if (!$has('users', 'completed_years')) {
            $pdo->exec('ALTER TABLE users ADD COLUMN completed_years TINYINT NOT NULL DEFAULT 0');
            // Best evidence that exists for older accounts: the year level they are in (Fourth Year = three years completed).
            // Students the old automation already marked graduated had completed their last year as well. Irregular / unknown: 0 until verified by an admin.
            $pdo->exec("UPDATE users SET completed_years = GREATEST(FIELD(year_level, 'First Year','Second Year','Third Year','Fourth Year','Fifth Year') - 1, 0) + IF(graduated = 1 AND FIELD(year_level, 'First Year','Second Year','Third Year','Fourth Year','Fifth Year') > 0, 1, 0)
                        WHERE role = 'student' AND FIELD(year_level, 'First Year','Second Year','Third Year','Fourth Year','Fifth Year') > 0");
        }
        if (!$has('users', 'graduation_status')) {
            $pdo->exec("ALTER TABLE users ADD COLUMN graduation_status ENUM('not_eligible','eligible','graduated') NOT NULL DEFAULT 'not_eligible'");
            // The old automation marked students graduated at the end of their last year with no verification at all.
            // Those students become 'eligible' (waiting for an admin to verify them); nobody is migrated straight to 'graduated'.
            $pdo->exec("UPDATE users SET graduation_status = 'eligible' WHERE role = 'student' AND graduated = 1");
            $pdo->exec("UPDATE users SET graduated = 0 WHERE role = 'student'");
        }
        if (!$has('users', 'graduation_locked'))      $pdo->exec('ALTER TABLE users ADD COLUMN graduation_locked TINYINT(1) NOT NULL DEFAULT 0');
        if (!$has('users', 'graduation_verified_by')) $pdo->exec('ALTER TABLE users ADD COLUMN graduation_verified_by INT NULL');
        if (!$has('users', 'graduation_verified_at')) $pdo->exec('ALTER TABLE users ADD COLUMN graduation_verified_at DATETIME NULL');
        if (!$has('users', 'graduation_note'))        $pdo->exec('ALTER TABLE users ADD COLUMN graduation_note VARCHAR(255) NULL');
        if ($v6first) {
            // Program durations used by the graduation rules (editable afterwards in Admin > Departments).
            $pdo->exec("UPDATE programs p JOIN departments d ON d.id = p.department_id SET p.years = 2 WHERE d.code = 'CITCS' AND p.code = 'ACT'");
            $pdo->exec("UPDATE programs p JOIN departments d ON d.id = p.department_id SET p.years = 4 WHERE d.code = 'CITCS' AND p.code IN ('BSIT','BSCS')");
        }

        if (!$has('exams', 'release_score')) {
            $pdo->exec('ALTER TABLE exams ADD COLUMN release_score TINYINT(1) NOT NULL DEFAULT 0');
            $pdo->exec('UPDATE exams SET release_score = 1');   // existing exams keep showing scores to students, exactly as before
        }
        if (!$has('exams', 'release_answers'))      $pdo->exec('ALTER TABLE exams ADD COLUMN release_answers TINYINT(1) NOT NULL DEFAULT 0');
        if (!$has('exams', 'results_released_at'))  $pdo->exec('ALTER TABLE exams ADD COLUMN results_released_at DATETIME NULL');
        if (!$has('submissions', 'feedback'))   $pdo->exec('ALTER TABLE submissions ADD COLUMN feedback TEXT NULL');
        if (!$has('submissions', 'graded_by'))  $pdo->exec('ALTER TABLE submissions ADD COLUMN graded_by INT NULL');
        if (!$has('submissions', 'graded_at'))  $pdo->exec('ALTER TABLE submissions ADD COLUMN graded_at DATETIME NULL');

        $create("CREATE TABLE IF NOT EXISTS student_year_records (id INT AUTO_INCREMENT PRIMARY KEY, student_id INT NOT NULL, school_year_id INT NULL,
                 program_id INT NULL, year_level VARCHAR(20) NULL, result ENUM('completed','enrolled') NOT NULL DEFAULT 'completed', recorded_at DATETIME NOT NULL,
                 UNIQUE KEY uq_student_sy (student_id, school_year_id)",
                'FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE');
        $create('CREATE TABLE IF NOT EXISTS notifications (id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, type VARCHAR(30) NOT NULL,
                 title VARCHAR(150) NOT NULL, message VARCHAR(500) NOT NULL, link VARCHAR(200) NULL, ref_key VARCHAR(120) NOT NULL,
                 is_read TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, read_at DATETIME NULL,
                 UNIQUE KEY uq_user_ref (user_id, ref_key), KEY idx_user_unread (user_id, is_read, id)',
                'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE');
        $create('CREATE TABLE IF NOT EXISTS audit_log (id BIGINT AUTO_INCREMENT PRIMARY KEY, actor_id INT NULL, actor_name VARCHAR(120) NULL,
                 action VARCHAR(40) NOT NULL, target_type VARCHAR(30) NOT NULL, target_label VARCHAR(200) NULL, details TEXT NULL,
                 ip_address VARCHAR(45) NULL, created_at DATETIME NOT NULL, KEY idx_audit_time (created_at)');
        $create('CREATE TABLE IF NOT EXISTS system_state (k VARCHAR(40) PRIMARY KEY, v VARCHAR(100) NOT NULL');
        $_SESSION['schema_v6'] = true;
    } catch (Throwable $e) {
        error_log('[exam-system] automatic schema upgrade failed: ' . $e->getMessage());
    }
}

function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function body(): array {
    $raw = file_get_contents('php://input');
    $d = json_decode($raw ?: '[]', true);
    return is_array($d) ? $d : [];
}

function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}

/** Require a logged-in user with one of the given roles. */
function require_role(string ...$roles): array {
    $u = current_user();
    if (!$u) json_out(['error' => 'Not signed in.'], 401);
    if ($roles && !in_array($u['role'], $roles, true)) json_out(['error' => 'Access denied.'], 403);
    return $u;
}

/** Same-origin check for state-changing requests (basic CSRF defence). */
function require_post(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'POST required.'], 405);
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== ($_SERVER['SERVER_NAME'] ?? '')) {
        json_out(['error' => 'Bad origin.'], 403);
    }
}

function violation_severity(string $typeId): string {
    return ['multiple-faces' => 'critical', 'face-angle' => 'high', 'eye-gaze' => 'high',
            'tab-switch' => 'high', 'no-face' => 'medium', 'copy-paste' => 'medium'][$typeId] ?? 'medium';
}

/** Finalise a submission: grade multiple-choice, store answers. Safe to call twice. */
function finalize_submission(int $subId, int $examId, array $answers, string $mode, ?string $reason = null, ?string $reasonText = null): array {
    $pdo = db();
    $sub = $pdo->prepare('SELECT * FROM submissions WHERE id = ?');
    $sub->execute([$subId]);
    $s = $sub->fetch();
    if (!$s) return ['error' => 'No submission'];
    if ($s['mode'] !== 'in-progress') return ['score' => (int)$s['auto_score'], 'max' => (int)$s['max_score'], 'mode' => $s['mode']];

    $qs = $pdo->prepare('SELECT * FROM questions WHERE exam_id = ?');
    $qs->execute([$examId]);
    $total = 0; $score = 0; $answered = 0;
    $pdo->beginTransaction();
    $ins = $pdo->prepare('INSERT INTO answers (submission_id, question_id, answer_text, is_correct, points_earned)
                          VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE answer_text=VALUES(answer_text),
                          is_correct=VALUES(is_correct), points_earned=VALUES(points_earned)');
    $hasEssay = false;
    foreach ($qs->fetchAll() as $q) {
        $total += (int)$q['points'];
        if ($q['type'] === 'essay') $hasEssay = true;
        $a = $answers[$q['id']] ?? null;
        $a = is_string($a) ? cut(trim($a), 20000) : null;
        // A multiple-choice answer must be one of the question's own choices; anything else the browser sends counts as "no answer".
        if ($a !== null && $a !== '' && $q['type'] === 'multiple-choice') {
            $choices = $q['choices_json'] ? json_decode($q['choices_json'], true) : [];
            if (!is_array($choices) || !in_array($a, $choices, true)) $a = null;
        }
        if ($a !== null && $a !== '') $answered++;
        $correct = null; $pts = 0;
        if ($q['type'] === 'multiple-choice' && $q['correct_answer'] !== null && $a !== null) {
            $correct = hash_equals((string)$q['correct_answer'], $a) ? 1 : 0;
            $pts = $correct ? (int)$q['points'] : 0;
            $score += $pts;
        }
        $ins->execute([$subId, $q['id'], $a, $correct, $pts]);
    }
    // Remember why an automatic submission happened (violation limit / time ran out / professor) - shown to the professor.
    // essay_score stays NULL ("waiting for the professor") only when the exam really has essay questions.
    $upd = $pdo->prepare('UPDATE submissions SET submitted_at = NOW(), mode = ?, auto_score = ?, essay_score = ?, max_score = ?, progress = 100,
                          auto_reason = ?, auto_reason_text = ? WHERE id = ?');
    $upd->execute([$mode, $score, $hasEssay ? null : 0, $total, $mode === 'auto' ? ($reason ?? 'other') : null,
                   $mode === 'auto' && $reasonText !== null ? cut($reasonText, 255) : null, $subId]);
    $pdo->commit();
    notify_submission_finished($pdo, $s, $examId, $mode, $reason, $hasEssay);
    return ['score' => $score, 'max' => $total, 'mode' => $mode, 'answered' => $answered];
}

/** Truncate text safely (works even if the mbstring extension is disabled). */
function cut(string $s, int $n): string {
    return function_exists('mb_substr') ? mb_substr($s, 0, $n) : substr($s, 0, $n);
}

// ---------------------------------------------------------------------
// Attempts / violations / exam ownership helpers
// ---------------------------------------------------------------------

/**
 * SQL for "violations of the attempt that is running now" (alias $s = submissions). Earlier attempts stay in the
 * violations table, tagged with their own attempt number, so the audit trail is never lost when access is restored.
 */
function cur_violations_sql(string $s = 's'): string {
    return "(SELECT COUNT(*) FROM violations v WHERE v.submission_id = $s.id AND v.attempt = $s.reopen_count + 1)";
}

/** SQL for every violation ever recorded on a submission, across all attempts. */
function all_violations_sql(string $s = 's'): string {
    return "(SELECT COUNT(*) FROM violations v WHERE v.submission_id = $s.id)";
}

/** Exam row if it belongs to this professor, otherwise null. Admins are NOT owners: they use the read-only admin pages. */
function owned_exam(PDO $pdo, array $u, int $examId): ?array {
    if ($u['role'] !== 'professor') return null;
    $st = $pdo->prepare('SELECT * FROM exams WHERE id = ? AND professor_id = ?');
    $st->execute([$examId, $u['id']]);
    $e = $st->fetch();
    return $e ?: null;
}

/** The professor's department, read fresh from the database (never from the browser): ['id', 'code', 'name'] or null. */
function professor_department(PDO $pdo, array $u): ?array {
    $st = $pdo->prepare('SELECT d.id, d.code, d.name FROM users p JOIN departments d ON d.id = p.department_id WHERE p.id = ? AND p.role = \'professor\'');
    $st->execute([$u['id']]);
    $d = $st->fetch();
    return $d ?: null;
}

/**
 * What a professor may do with one exam: the creator can do everything; another professor can only edit / delete when an
 * administrator granted it (exam_editors). Everybody else has read-only access.
 */
function exam_permissions(PDO $pdo, array $u, array $exam): array {
    $none = ['is_owner' => false, 'can_edit' => false, 'can_delete' => false, 'granted' => false];
    if (($u['role'] ?? '') !== 'professor') return $none;
    if ((int)$exam['professor_id'] === (int)$u['id']) return ['is_owner' => true, 'can_edit' => true, 'can_delete' => true, 'granted' => false];
    $st = $pdo->prepare('SELECT can_delete FROM exam_editors WHERE exam_id = ? AND professor_id = ?');
    $st->execute([(int)$exam['id'], (int)$u['id']]);
    $g = $st->fetch();
    if (!$g) return $none;
    return ['is_owner' => false, 'can_edit' => true, 'can_delete' => (bool)(int)$g['can_delete'], 'granted' => true];
}

/** Exam row the professor may EDIT (or delete), otherwise the request is answered with 404 / 403 and ends. */
function require_editable_exam(PDO $pdo, array $u, int $examId, bool $forDelete = false): array {
    $st = $pdo->prepare('SELECT * FROM exams WHERE id = ?');
    $st->execute([$examId]);
    $e = $st->fetch();
    if (!$e) json_out(['error' => 'Examination not found.'], 404);
    $p = exam_permissions($pdo, $u, $e);
    if (!($forDelete ? $p['can_delete'] : $p['can_edit'])) {
        json_out(['error' => $forDelete ? 'Only the professor who created this examination can delete it.' : 'Only the professor who created this examination can edit it. You can view it, but not change it.'], 403);
    }
    return $e;
}

/** Year levels (display order) an exam is open to. Empty = every year level (older exams). */
function exam_year_levels(PDO $pdo, int $examId): array {
    $st = $pdo->prepare('SELECT year_level FROM exam_year_levels WHERE exam_id = ?');
    $st->execute([$examId]);
    return sort_year_levels($st->fetchAll(PDO::FETCH_COLUMN));
}

function sort_year_levels(array $levels): array {
    return array_values(array_filter(YEAR_LEVELS, fn($y) => in_array($y, $levels, true)));
}

/** Keep only valid year levels from a request (unique, in display order). */
function clean_year_levels($raw): array {
    return is_array($raw) ? sort_year_levels(array_map('strval', $raw)) : [];
}

/**
 * THE rule for who may take an exam. Returns null when the student is allowed, otherwise the reason (shown to the student).
 * $student = row of users (department_id, year_level, section). The same three rules, in SQL form, are in student_visible_sql().
 *  - department: only students of the exam's department (exams without a department are older exams: open to all)
 *  - year level: only the selected year levels (no rows = every year level)
 *  - section: only the selected sections (no rows = every section)
 */
function student_exam_eligibility(PDO $pdo, array $exam, array $student): ?string {
    if ($exam['department_id'] !== null && (int)$exam['department_id'] !== (int)($student['department_id'] ?? 0)) {
        return 'This examination is only for students of another department.';
    }
    $years = exam_year_levels($pdo, (int)$exam['id']);
    if ($years && !in_array((string)($student['year_level'] ?? ''), $years, true)) {
        return 'This examination is not open to your year level.';
    }
    $st = $pdo->prepare('SELECT COUNT(*) AS total, SUM(section = ?) AS mine FROM exam_sections WHERE exam_id = ?');
    $st->execute([(string)($student['section'] ?? ''), (int)$exam['id']]);
    $r = $st->fetch();
    if ((int)$r['total'] > 0 && (int)$r['mine'] === 0) return 'This examination is not available for your section.';
    return null;
}

/**
 * SQL fragment: "this student may see the exam" - for lists. Three positional parameters, in this order:
 * the student's department_id, year_level and section. Students that already have an attempt always keep seeing their exam.
 */
function student_visible_sql(string $e = 'e', string $s = 's'): string {
    return "($s.id IS NOT NULL OR (
        ($e.department_id IS NULL OR $e.department_id = ?)
        AND (NOT EXISTS (SELECT 1 FROM exam_year_levels yl WHERE yl.exam_id = $e.id)
             OR EXISTS (SELECT 1 FROM exam_year_levels yl WHERE yl.exam_id = $e.id AND yl.year_level = ?))
        AND (NOT EXISTS (SELECT 1 FROM exam_sections es WHERE es.exam_id = $e.id)
             OR EXISTS (SELECT 1 FROM exam_sections es WHERE es.exam_id = $e.id AND es.section = ?))))";
}

/** The signed-in student's department / year level / section, read fresh from the database. */
function student_profile_row(PDO $pdo, int $userId): array {
    $st = $pdo->prepare('SELECT id, department_id, year_level, section FROM users WHERE id = ?');
    $st->execute([$userId]);
    return $st->fetch() ?: ['id' => $userId, 'department_id' => null, 'year_level' => null, 'section' => null];
}

/** An exam that is 'available' but whose availability window already ended shows as 'closed' to students who have not started. */
function effective_exam_status(array $row): string {
    $status = (string)$row['status'];
    if ($status === 'available' && !empty($row['available_until']) && strtotime($row['available_until']) < time()) return 'closed';
    return $status;
}

/** Human-readable reason of an automatic submission (also fills in older rows that never stored one). */
function auto_reason_info(array $row, int $violations, int $threshold, ?string $lastLabel = null): array {
    $code = $row['auto_reason'] ?? null;
    if ($code === null || $code === '') $code = $violations >= $threshold ? 'violations' : 'other';
    $text = $row['auto_reason_text'] ?? null;
    if ($text === null || $text === '') {
        $text = $code === 'violations'
            ? 'Reached the maximum of ' . $threshold . ' violations' . ($lastLabel ? ' (last: ' . $lastLabel . ')' : '')
            : ($code === 'time' ? 'The exam time ran out' : ($code === 'instructor' ? 'Submitted by the professor' : 'Submitted automatically'));
    }
    return ['code' => $code, 'text' => $text];
}

/** Spreadsheet-safe CSV cell (neutralises =, +, -, @ at the start). */
function csv_cell($v): string {
    $v = (string)($v ?? '');
    return ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) ? "'" . $v : $v;
}

/** Which year-level labels exist, in display order. */
function year_level_order(): array { return YEAR_LEVELS; }

// ---------------------------------------------------------------------
// Departments / programs / year levels / school-year rollover
// ---------------------------------------------------------------------
const YEAR_LEVELS = ['First Year', 'Second Year', 'Third Year', 'Fourth Year', 'Fifth Year', 'Irregular'];

/**
 * Validate the department / program / year level that goes with a role.
 * Returns [department_id, program_id, year_level] or a string error message.
 * admin: nothing. professor: department. student: department + program (inside that department) + year level.
 */
function validate_affiliation(string $role, array $d) {
    if ($role === 'admin') return [null, null, null];
    $pdo = db();
    $dept = (int)($d['department_id'] ?? 0);
    $st = $pdo->prepare('SELECT id FROM departments WHERE id = ?');
    $st->execute([$dept]);
    if (!$st->fetch()) return 'Select a department.';
    if ($role === 'professor') return [$dept, null, null];

    $prog = (int)($d['program_id'] ?? 0);
    $st = $pdo->prepare('SELECT id FROM programs WHERE id = ? AND department_id = ?');
    $st->execute([$prog, $dept]);
    if (!$st->fetch()) return 'Select a course that belongs to the chosen department.';
    $year = (string)($d['year_level'] ?? '');
    if (!in_array($year, YEAR_LEVELS, true)) return 'Select the student\'s year level.';
    return [$dept, $prog, $year];
}

/** Make the school year that covers today the current one (only one is current). */
function refresh_current_school_year(): void {
    $pdo = db();
    $today = date('Y-m-d');
    $st = $pdo->prepare('SELECT id FROM school_years WHERE start_date <= ? AND end_date >= ? ORDER BY start_date DESC LIMIT 1');
    $st->execute([$today, $today]);
    $id = $st->fetchColumn();
    $pdo->exec('UPDATE school_years SET is_current = 0');
    if ($id) $pdo->prepare('UPDATE school_years SET is_current = 1 WHERE id = ?')->execute([$id]);
}

/**
 * Close one school year (runs once per school year):
 *   1. every approved, not-yet-graduated student gets a history row; Regular (and not yet classified) students in a real year level
 *      also get one more COMPLETED academic year. Irregular students are recorded as "enrolled" only - an admin verifies their records.
 *   2. everybody moves up one year level, never past the last year of their own program; Irregular students are not moved.
 *   3. graduation eligibility is re-evaluated. Nobody is marked GRADUATED here: that only happens when an admin verifies the student.
 */
function promote_students_for(int $schoolYearId): int {
    $pdo = db();
    $pdo->beginTransaction();
    // Claim it first so two requests can never promote the same school year twice.
    $claim = $pdo->prepare('UPDATE school_years SET promoted = 1, promoted_at = NOW() WHERE id = ? AND promoted = 0');
    $claim->execute([$schoolYearId]);
    if ($claim->rowCount() === 0) { $pdo->rollBack(); return 0; }

    $order = "'First Year','Second Year','Third Year','Fourth Year','Fifth Year'";
    $base = "u.role = 'student' AND u.approval_status = 'approved' AND u.graduation_status <> 'graduated'";

    $pdo->prepare("INSERT IGNORE INTO student_year_records (student_id, school_year_id, program_id, year_level, result, recorded_at)
                   SELECT u.id, ?, u.program_id, u.year_level,
                          IF(u.student_type = 'irregular' OR COALESCE(u.year_level, '') NOT IN ($order), 'enrolled', 'completed'), NOW()
                   FROM users u WHERE $base")->execute([$schoolYearId]);
    $pdo->prepare("UPDATE users u JOIN student_year_records r ON r.student_id = u.id AND r.school_year_id = ? AND r.result = 'completed'
                   SET u.completed_years = LEAST(u.completed_years + 1, 10) WHERE $base")->execute([$schoolYearId]);

    // Move up one year level (not past the last year of the program; Irregular students are never changed automatically).
    $n = $pdo->exec("UPDATE users u LEFT JOIN programs p ON p.id = u.program_id
                     SET u.year_level = ELT(FIELD(u.year_level, $order) + 1, $order)
                     WHERE $base AND FIELD(u.year_level, $order) BETWEEN 1 AND 4
                       AND FIELD(u.year_level, $order) < COALESCE(p.years, 4)");
    $pdo->commit();

    $changed = run_graduation_evaluation($pdo);
    if ($changed['eligible']) {
        notify_admins('graduation', 'Students eligible for graduation',
            $changed['eligible'] . ' student' . ($changed['eligible'] === 1 ? ' is' : 's are') . ' now eligible for graduation evaluation after the school year ended. Verify their records to update their status.',
            'admin-accounts.html#graduation', 'grad-eligible:sy' . $schoolYearId);
    }
    return (int)$n;
}

/** Called on login / admin requests: if a school year has ended, promote students automatically. */
function run_school_year_rollover(): void {
    try {
        $pdo = db();
        $st = $pdo->prepare('SELECT id FROM school_years WHERE promoted = 0 AND end_date < ? ORDER BY end_date');
        $st->execute([date('Y-m-d')]);
        $ids = $st->fetchAll(PDO::FETCH_COLUMN);
        if (!$ids) return;
        foreach ($ids as $id) promote_students_for((int)$id);
        refresh_current_school_year();
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    }
}

// ---------------------------------------------------------------------
// Notifications + activity log
// ---------------------------------------------------------------------

/** A notification may only point to one of our own pages (relative .html path, optional query / #tab). */
function clean_notification_link(?string $link): ?string {
    if ($link === null || $link === '') return null;
    return preg_match('/^[A-Za-z0-9_-]+\.html(\?[A-Za-z0-9_=&-]*)?(#[A-Za-z0-9_-]*)?$/', $link) ? $link : null;
}

/**
 * Store one notification for one person. $refKey identifies the EVENT: the same event (same key) never notifies the same person twice.
 * Returns true when a new notification was created. A notification problem must never break the action that caused it.
 */
function notify_user(int $userId, string $type, string $title, string $message, ?string $link, string $refKey): bool {
    try {
        $st = db()->prepare('INSERT IGNORE INTO notifications (user_id, type, title, message, link, ref_key, created_at) VALUES (?,?,?,?,?,?,NOW())');
        $st->execute([$userId, cut($type, 30), cut($title, 150), cut($message, 500), clean_notification_link($link), cut($refKey, 120)]);
        return $st->rowCount() > 0;
    } catch (Throwable $e) {
        error_log('[exam-system] notification failed: ' . $e->getMessage());
        return false;
    }
}

/** Same notification for several people. Returns how many were created. */
function notify_users(array $userIds, string $type, string $title, string $message, ?string $link, string $refKey): int {
    $n = 0;
    foreach (array_unique(array_map('intval', $userIds)) as $id) {
        if ($id > 0 && notify_user($id, $type, $title, $message, $link, $refKey)) $n++;
    }
    return $n;
}

/** Notify every approved, active administrator (optionally except the one who did the action). */
function notify_admins(string $type, string $title, string $message, ?string $link, string $refKey, ?int $exceptUserId = null): int {
    try {
        $ids = db()->query("SELECT id FROM users WHERE role = 'admin' AND approval_status = 'approved' AND is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) { return 0; }
    $ids = array_filter($ids, fn($id) => $exceptUserId === null || (int)$id !== $exceptUserId);
    return notify_users($ids, $type, $title, $message, $link, $refKey);
}

/** Active, approved students who may take this exam (same department / year level / section rules as student_exam_eligibility()). */
function exam_student_ids(PDO $pdo, array $exam): array {
    $where = ["u.role = 'student'", "u.approval_status = 'approved'", 'u.is_active = 1', "u.graduation_status <> 'graduated'"];
    $args = [];
    if ($exam['department_id'] !== null) { $where[] = 'u.department_id = ?'; $args[] = (int)$exam['department_id']; }
    $years = exam_year_levels($pdo, (int)$exam['id']);
    if ($years) { $where[] = 'u.year_level IN (' . implode(',', array_fill(0, count($years), '?')) . ')'; $args = array_merge($args, $years); }
    $sec = $pdo->prepare('SELECT section FROM exam_sections WHERE exam_id = ?');
    $sec->execute([(int)$exam['id']]);
    $sections = $sec->fetchAll(PDO::FETCH_COLUMN);
    if ($sections) { $where[] = 'u.section IN (' . implode(',', array_fill(0, count($sections), '?')) . ')'; $args = array_merge($args, $sections); }
    $st = $pdo->prepare('SELECT u.id FROM users u WHERE ' . implode(' AND ', $where));
    $st->execute($args);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** Everyone who is allowed to follow an exam as staff: its creator and the professors an admin allowed to edit it. */
function exam_staff_ids(PDO $pdo, array $exam): array {
    $ids = [(int)$exam['professor_id']];
    $st = $pdo->prepare('SELECT professor_id FROM exam_editors WHERE exam_id = ?');
    $st->execute([(int)$exam['id']]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) $ids[] = (int)$id;
    return array_values(array_unique($ids));
}

/** Write one line to the activity log (who did what to what). Never throws. */
function audit_log(string $action, string $targetType, ?string $label = null, $details = null): void {
    try {
        $u = current_user();
        $d = $details === null ? null : (is_string($details) ? $details : json_encode($details, JSON_UNESCAPED_UNICODE));
        db()->prepare('INSERT INTO audit_log (actor_id, actor_name, action, target_type, target_label, details, ip_address, created_at) VALUES (?,?,?,?,?,?,?,NOW())')
            ->execute([$u['id'] ?? null, isset($u['name']) ? cut((string)$u['name'], 120) : null, cut($action, 40), cut($targetType, 30),
                       $label !== null ? cut($label, 200) : null, $d, $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Throwable $e) {
        error_log('[exam-system] audit log failed: ' . $e->getMessage());
    }
}

/**
 * Tell the exam's professor(s) that a student finished, and when everybody who may take the exam has finished.
 * Violation auto-submits are also reported to the administrators. One event = one notification (unique key per attempt).
 */
function notify_submission_finished(PDO $pdo, array $sub, int $examId, string $mode, ?string $reason, bool $hasEssay): void {
    try {
        $ex = $pdo->prepare('SELECT * FROM exams WHERE id = ?');
        $ex->execute([$examId]);
        $e = $ex->fetch();
        if (!$e) return;
        $stu = $pdo->prepare('SELECT full_name, account_no FROM users WHERE id = ?');
        $stu->execute([(int)$sub['student_id']]);
        $who = $stu->fetch() ?: ['full_name' => 'A student', 'account_no' => ''];
        $attempt = (int)$sub['reopen_count'] + 1;
        $label = $e['code'] . ' · ' . $e['title'];
        $staff = exam_staff_ids($pdo, $e);

        if ($mode === 'auto') {
            $why = $reason === 'violations' ? 'reached the violation limit' : ($reason === 'time' ? 'ran out of time' : ($reason === 'instructor' ? 'was submitted by the professor' : 'was submitted automatically'));
            notify_users($staff, 'exam', 'Exam auto-submitted', $who['full_name'] . ' (' . $who['account_no'] . ') ' . $why . ' in ' . $label . '.',
                'professor-monitoring.html?exam_id=' . $examId, 'sub:' . (int)$sub['id'] . ':' . $attempt . ':done');
            if ($reason === 'violations') {
                notify_admins('alert', 'Exam auto-submitted for violations', $who['full_name'] . ' (' . $who['account_no'] . ') reached the violation limit in ' . $label . '.',
                    'admin-live.html?exam_id=' . $examId, 'autosub:' . (int)$sub['id'] . ':' . $attempt);
            }
        } else {
            notify_users($staff, 'exam', 'Exam submitted', $who['full_name'] . ' (' . $who['account_no'] . ') submitted ' . $label . '.' . ($hasEssay ? ' Essay answers need grading.' : ''),
                'professor-exam-results.html?exam=' . $examId, 'sub:' . (int)$sub['id'] . ':' . $attempt . ':done');
        }

        // Everybody who may take the exam has handed in an attempt -> the exam is complete.
        $eligible = exam_student_ids($pdo, $e);
        if ($eligible) {
            $in = implode(',', $eligible);   // integers only
            $done = (int)$pdo->query("SELECT COUNT(DISTINCT student_id) FROM submissions WHERE exam_id = " . (int)$examId . " AND mode <> 'in-progress' AND student_id IN ($in)")->fetchColumn();
            if ($done >= count($eligible)) {
                notify_users($staff, 'exam', 'Examination completed', 'All ' . count($eligible) . ' eligible student' . (count($eligible) === 1 ? ' has' : 's have') . ' submitted ' . $label . '.',
                    'professor-exam-results.html?exam=' . $examId, 'exam' . $examId . ':completed');
            }
        }
    } catch (Throwable $t) {
        error_log('[exam-system] submission notification failed: ' . $t->getMessage());
    }
}

/**
 * "Your exam is coming up" reminders. There is no cron job, so whoever polls notifications triggers this - at most once a minute
 * (system_state remembers the last run). Exams that start in the next 24 hours notify their eligible students once.
 */
function notifications_tick(PDO $pdo): void {
    try {
        $now = time();
        $st = $pdo->prepare("SELECT v FROM system_state WHERE k = 'reminder_tick'");
        $st->execute();
        $last = (int)$st->fetchColumn();
        if ($now - $last < 60) return;
        $pdo->prepare("INSERT INTO system_state (k, v) VALUES ('reminder_tick', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([(string)$now]);

        $ex = $pdo->query("SELECT * FROM exams WHERE status IN ('scheduled','available') AND scheduled_at IS NOT NULL
                           AND scheduled_at > NOW() AND scheduled_at <= DATE_ADD(NOW(), INTERVAL 24 HOUR)");
        foreach ($ex->fetchAll() as $e) {
            $when = date('M j, g:i A', strtotime($e['scheduled_at']));
            notify_users(exam_student_ids($pdo, $e), 'exam', 'Exam coming up',
                $e['code'] . ' · ' . $e['title'] . ' starts ' . $when . '. Review the guidelines and check your camera.',
                'student-exam-dashboard.html', 'exam' . (int)$e['id'] . ':soon:' . date('YmdHi', strtotime($e['scheduled_at'])));
        }
    } catch (Throwable $e) {
        error_log('[exam-system] reminder tick failed: ' . $e->getMessage());
    }
}

require_once __DIR__ . '/graduation_lib.php';
