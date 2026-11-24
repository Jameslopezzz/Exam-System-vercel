<?php
// Exam delivery for students + exam setup, question management and the shared exam list for professors.
//
// Professor workflow (two separate steps, two separate screens):
//   Step 1  Exam setup      save / update / get            (title, department, year levels, sections, schedule, duration, rules)
//   Step 2  Exam questions  question_save / question_delete / question_reorder (+ view to read them)
// Shared list: shared (every professor sees every exam), view (details + questions, read-only unless allowed to edit).
require_once __DIR__ . '/bootstrap.php';
$action = $_GET['action'] ?? '';
$pdo = db();

const MAX_QUESTIONS_PER_EXAM = 200;

/** Clean a list of section names: trimmed, unique, max 30 chars, max 50 sections. */
function clean_sections($raw): array {
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $v) {
        $v = cut(trim((string)$v), 30);
        if ($v !== '' && !in_array(strtolower($v), array_map('strtolower', $out), true)) $out[] = $v;
        if (count($out) >= 50) break;
    }
    return $out;
}

function student_section(PDO $pdo, int $userId): ?string {
    $st = $pdo->prepare('SELECT section FROM users WHERE id = ?');
    $st->execute([$userId]);
    $v = $st->fetchColumn();
    return ($v === false || $v === '') ? null : $v;
}

function lower_str(string $s): string { return function_exists('mb_strtolower') ? mb_strtolower($s) : strtolower($s); }

/** Validate + normalise a list of questions. Returns the clean list, or an error string. $first = number shown in messages for the first one. */
function normalize_questions($qs, int $first = 1) {
    if (!is_array($qs)) return 'The question is invalid.';
    $out = [];
    foreach (array_values($qs) as $i => $q) {
        $n = $i + $first;
        if (!is_array($q)) return 'Question ' . $n . ' is invalid.';
        $prompt = trim((string)($q['prompt'] ?? ''));
        if ($prompt === '') return 'Question ' . $n . ' has no prompt.';
        $type = ($q['type'] ?? '') === 'essay' ? 'essay' : 'multiple-choice';
        $choices = null; $correct = null;
        if ($type === 'multiple-choice') {
            $raw = is_array($q['choices'] ?? null) ? $q['choices'] : [];
            $choices = array_values(array_filter(array_map(fn($c) => trim((string)$c), $raw), 'strlen'));
            if (count($choices) < 2) return 'Question ' . $n . ' needs at least two choices.';
            if (count(array_unique(array_map('lower_str', $choices))) < count($choices)) return 'Question ' . $n . ' has two choices with the same text. Every choice must be different.';
            $correct = trim((string)($q['correct'] ?? ''));
            if (!in_array($correct, $choices, true)) return 'Question ' . $n . ' needs a correct answer picked from its choices.';
        }
        $media = null;
        if (!empty($q['media']) && is_array($q['media'])) {
            $media = json_encode($q['media'], JSON_UNESCAPED_UNICODE);
            if ($media === false || strlen($media) > 300000) return 'The media of question ' . $n . ' is too large.';
        }
        $out[] = ['type' => $type, 'prompt' => $prompt, 'points' => max(1, min(1000, (int)($q['points'] ?? 1))),
                  'choices' => $choices !== null ? json_encode($choices, JSON_UNESCAPED_UNICODE) : null, 'correct' => $correct, 'media' => $media];
    }
    return $out;
}

/** null / datetime string for a date field; returns false when it is not a valid date. */
function parse_schedule($v) {
    $v = trim((string)$v);
    if ($v === '') return null;
    $t = strtotime($v);
    return $t === false ? false : date('Y-m-d H:i:s', $t);
}

/** Number of student attempts that exist for an exam, and whether its content is locked for editing. */
function exam_lock_state(PDO $pdo, array $exam): array {
    $c = $pdo->prepare('SELECT COUNT(*) FROM submissions WHERE exam_id = ?');
    $c->execute([(int)$exam['id']]);
    $subs = (int)$c->fetchColumn();
    $ongoing = $exam['status'] === 'available';
    $reason = null;
    if ($subs > 0) $reason = $subs . ' student attempt' . ($subs === 1 ? '' : 's') . ' already exist' . ($subs === 1 ? 's' : '') . ', so the questions, answers, time limit and proctoring rules are locked to protect the recorded results.';
    elseif ($ongoing) $reason = 'This examination is open to students right now, so its questions, time limit and proctoring rules are locked. Set it to Scheduled or Draft first if you need to change them.';
    return ['locked' => $reason !== null, 'reason' => $reason, 'submissions' => $subs, 'ongoing' => $ongoing];
}

/** When sections are narrowed, students who already have an attempt must still be allowed. Returns the names that would be cut off. */
function sections_cut_off(PDO $pdo, int $examId, array $newSections): array {
    if (!$newSections) return [];   // "all sections" never cuts anyone off
    $st = $pdo->prepare("SELECT DISTINCT COALESCE(u.section, '') AS section FROM submissions s JOIN users u ON u.id = s.student_id WHERE s.exam_id = ?");
    $st->execute([$examId]);
    $allowed = array_map('lower_str', $newSections);
    $cut = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $sec) {
        if (!in_array(lower_str((string)$sec), $allowed, true)) $cut[] = $sec === '' ? '(no section)' : $sec;
    }
    return $cut;
}

/** Same for year levels: students who already have an attempt keep their access, so their year level cannot be removed. */
function year_levels_cut_off(PDO $pdo, int $examId, array $newYears): array {
    $st = $pdo->prepare("SELECT DISTINCT COALESCE(u.year_level, '') FROM submissions s JOIN users u ON u.id = s.student_id WHERE s.exam_id = ?");
    $st->execute([$examId]);
    $cut = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $y) if (!in_array((string)$y, $newYears, true)) $cut[] = $y === '' ? '(no year level)' : $y;
    return $cut;
}

/**
 * Sections that belong ONLY to students of other departments (a professor may not assign an exam to them).
 * A section nobody has joined yet is fine, and so is a section that has at least one student of the department.
 */
function sections_outside_department(PDO $pdo, int $deptId, array $sections): array {
    $bad = [];
    $st = $pdo->prepare("SELECT COUNT(*) AS total, SUM(department_id = ?) AS mine FROM users WHERE role = 'student' AND section = ?");
    foreach ($sections as $sec) {
        $st->execute([$deptId, $sec]);
        $r = $st->fetch();
        if ((int)$r['total'] > 0 && (int)$r['mine'] === 0) $bad[] = $sec;
    }
    return $bad;
}

/** Students that can take an exam with this department / year levels / sections (active, approved, not graduated). */
function eligible_students(PDO $pdo, ?int $deptId, array $years, array $sections): array {
    $where = ["role = 'student'", "approval_status = 'approved'", 'is_active = 1', 'graduated = 0']; $args = [];
    if ($deptId !== null) { $where[] = 'department_id = ?'; $args[] = $deptId; }
    if ($years)    { $where[] = 'year_level IN (' . implode(',', array_fill(0, count($years), '?')) . ')'; $args = array_merge($args, $years); }
    if ($sections) { $where[] = 'section IN (' . implode(',', array_fill(0, count($sections), '?')) . ')'; $args = array_merge($args, $sections); }
    $st = $pdo->prepare('SELECT COALESCE(year_level, \'Not set\') AS y, COUNT(*) AS n FROM users WHERE ' . implode(' AND ', $where) . ' GROUP BY y');
    $st->execute($args);
    $by = []; $total = 0;
    foreach ($st->fetchAll() as $r) { $by[$r['y']] = (int)$r['n']; $total += (int)$r['n']; }
    return ['count' => $total, 'by_year' => $by];
}

function exam_rules_row(array $e): array {
    return ['faceAngle' => (bool)$e['detect_face_angle'], 'eyeGaze' => (bool)$e['detect_eye_gaze'], 'multiFace' => (bool)$e['detect_multi_face'],
            'copyPaste' => (bool)$e['block_copy_paste'], 'tabSwitch' => (bool)$e['detect_tab_switch'], 'requireCamera' => (bool)$e['require_camera']];
}

function split_list($v, bool $isYear = false): array {
    if ($v === null || $v === '') return [];
    $a = explode('|', (string)$v);
    return $isYear ? sort_year_levels($a) : $a;
}

function local_dt($v): string { return $v ? date('Y-m-d\TH:i', strtotime($v)) : ''; }

/**
 * Validate the Exam Setup form (step 1). Returns the clean values, or an error string.
 * $deptId = department of the exam: the sections must belong to it. Questions are NOT part of this form.
 */
function validate_setup(PDO $pdo, array $d, ?int $deptId, bool $creating) {
    if (!empty($d['questions'])) return 'Questions are added in the next step, after the exam setup is saved.';
    $title = trim((string)($d['title'] ?? ''));
    $code  = trim((string)($d['code'] ?? ''));
    if ($title === '' || $code === '') return 'Title and subject code are required.';

    $rawYears = $d['year_levels'] ?? [];
    if (!is_array($rawYears)) return 'The year levels are invalid.';
    $years = clean_year_levels($rawYears);
    if (count($years) !== count(array_unique(array_map('strval', $rawYears)))) return 'One of the selected year levels is not valid.';
    if (!$years) return 'Select at least one eligible year level.';

    $schedule = parse_schedule($d['schedule'] ?? '');
    if ($schedule === false) return 'The exam date and time is not valid.';
    $until = parse_schedule($d['available_until'] ?? '');
    if ($until === false) return 'The availability end is not a valid date and time.';
    if ($until !== null && $schedule !== null && strtotime($until) <= strtotime($schedule)) return 'The availability must end after the exam date and time.';
    if ($creating && $until !== null && strtotime($until) <= time()) return 'The availability end must be in the future.';

    $sections = clean_sections($d['sections'] ?? []);
    if ($deptId !== null && $sections) {
        $bad = sections_outside_department($pdo, $deptId, $sections);
        if ($bad) return 'These sections only have students from other departments, so you cannot assign the exam to them: ' . implode(', ', $bad) . '.';
    }
    $desc = cut(trim((string)($d['description'] ?? '')), 2000);
    return ['title' => cut($title, 150), 'code' => cut($code, 20), 'subject' => cut(trim((string)($d['subject'] ?? '')) ?: $title, 150),
            'description' => $desc !== '' ? $desc : null, 'schedule' => $schedule, 'until' => $until, 'years' => $years, 'sections' => $sections];
}

function replace_list(PDO $pdo, string $table, string $col, int $examId, array $values): void {
    $pdo->prepare("DELETE FROM $table WHERE exam_id = ?")->execute([$examId]);
    $ins = $pdo->prepare("INSERT INTO $table (exam_id, $col) VALUES (?, ?)");
    foreach ($values as $v) $ins->execute([$examId, $v]);
}

/**
 * Students (and nobody else) hear about an exam when it is published, closed or cancelled. One event = one notification:
 * the key contains the exam and the event, so switching the status back and forth never repeats the same message.
 */
function notify_exam_status_change(PDO $pdo, array $e, string $old, string $new): void {
    try {
        $id = (int)$e['id'];
        $label = $e['code'] . ' · ' . $e['title'];
        $ids = exam_student_ids($pdo, $e);
        $when = $e['scheduled_at'] ? date('M j, Y g:i A', strtotime($e['scheduled_at'])) : null;
        $published = ['scheduled', 'available'];
        if ($new === 'scheduled') {
            notify_users($ids, 'exam', 'New examination scheduled', $label . ' was scheduled' . ($when ? ' for ' . $when : '') . '. Duration: ' . (int)$e['duration_minutes'] . ' minutes.',
                'student-exam-dashboard.html', 'exam' . $id . ':scheduled');
        } elseif ($new === 'available') {
            notify_users($ids, 'exam', 'Examination is now open', $label . ' is open. You can start it from My Exams. Duration: ' . (int)$e['duration_minutes'] . ' minutes.',
                'student-exam-dashboard.html', 'exam' . $id . ':available');
        } elseif ($new === 'draft' && in_array($old, $published, true)) {
            notify_users($ids, 'exam', 'Examination cancelled', $label . ' was withdrawn by your professor. It is not available for now.',
                'student-exam-dashboard.html', 'exam' . $id . ':cancelled:' . time());
        } elseif ($new === 'closed' && $old === 'scheduled') {
            notify_users($ids, 'exam', 'Examination cancelled', $label . ' was cancelled before it opened.', 'student-exam-dashboard.html', 'exam' . $id . ':cancelled:' . time());
        } elseif ($new === 'closed') {
            notify_users($ids, 'exam', 'Examination closed', $label . ' is now closed.', 'student-exam-dashboard.html', 'exam' . $id . ':closed');
        }
        if (in_array($new, $published, true) || $new === 'closed') {
            // The administrators follow what professors publish.
            notify_admins('exam', 'Examination ' . ($new === 'closed' ? 'closed' : 'published'), $label . ' is now ' . $new . '.', 'admin-exams.html', 'exam' . $id . ':admin:' . $new);
        }
    } catch (Throwable $t) {
        error_log('[exam-system] exam notification failed: ' . $t->getMessage());
    }
}

/* ---- List exams: students see only the exams they are eligible for; professors see their own ---- */
if ($action === 'list') {
    $u = require_role('student', 'professor');   // admins use the read-only admin pages (api/admin_exams.php)
    if ($u['role'] === 'student') {
        $me = student_profile_row($pdo, (int)$u['id']);
        $st = $pdo->prepare("SELECT e.id, e.code, e.title, e.subject, e.duration_minutes, e.status, e.scheduled_at, e.available_until,
              e.description, p.full_name AS professor, (SELECT COUNT(*) FROM questions q WHERE q.exam_id = e.id) AS items,
              s.mode AS sub_mode, s.auto_score, s.max_score, e.release_score, e.release_answers,
              " . cur_violations_sql('s') . " AS violations
            FROM exams e JOIN users p ON p.id = e.professor_id
            LEFT JOIN submissions s ON s.exam_id = e.id AND s.student_id = ?
            WHERE e.status <> 'draft' AND " . student_visible_sql('e', 's') . "
            ORDER BY e.id");
        $st->execute([$u['id'], (int)$me['department_id'], (string)$me['year_level'], (string)$me['section']]);
        $rows = $st->fetchAll();
        foreach ($rows as &$r) {
            if ($r['sub_mode'] === null) $r['status'] = effective_exam_status($r);   // availability window over -> closed
            // The score only reaches the student's browser once the professor released it.
            $released = (int)$r['release_score'] === 1;
            if (!$released) { $r['auto_score'] = null; $r['max_score'] = null; }
            $r['score_released'] = $released;
            $r['answers_released'] = (int)$r['release_answers'] === 1;
            unset($r['release_score'], $r['release_answers']);
        }
        unset($r);
    } else {
        $st = $pdo->prepare("SELECT e.*, p.full_name AS professor, d.code AS dept_code,
              (SELECT COUNT(*) FROM questions q WHERE q.exam_id = e.id) AS items,
              (SELECT COUNT(*) FROM submissions s WHERE s.exam_id = e.id) AS submissions,
              (SELECT COUNT(*) FROM submissions s WHERE s.exam_id = e.id AND s.mode = 'auto') AS auto_submitted,
              (SELECT GROUP_CONCAT(es.section ORDER BY es.section SEPARATOR '|') FROM exam_sections es WHERE es.exam_id = e.id) AS sections,
              (SELECT GROUP_CONCAT(y.year_level SEPARATOR '|') FROM exam_year_levels y WHERE y.exam_id = e.id) AS year_levels
            FROM exams e JOIN users p ON p.id = e.professor_id LEFT JOIN departments d ON d.id = e.department_id
            WHERE e.professor_id = ? ORDER BY e.id");
        $st->execute([$u['id']]);
        $rows = $st->fetchAll();
        foreach ($rows as &$r) {
            $r['sections'] = split_list($r['sections']);
            $r['year_levels'] = split_list($r['year_levels'], true);
            $r['locked'] = (int)$r['submissions'] > 0 || $r['status'] === 'available';   // questions / time / rules cannot be edited
        }
        unset($r);
    }
    json_out(['exams' => $rows]);
}

/* ---- Professor: every exam of every professor (shared list). Read-only unless the professor made it or an admin allowed editing ---- */
if ($action === 'shared') {
    $u = require_role('professor');
    $rows = $pdo->query("SELECT e.id, e.code, e.title, e.subject, e.status, e.scheduled_at, e.available_until, e.duration_minutes, e.created_at,
          e.professor_id, e.department_id, p.full_name AS professor, d.code AS dept_code, d.name AS dept_name,
          (SELECT COUNT(*) FROM questions q WHERE q.exam_id = e.id) AS items,
          (SELECT COUNT(*) FROM submissions s WHERE s.exam_id = e.id) AS submissions,
          (SELECT GROUP_CONCAT(es.section ORDER BY es.section SEPARATOR '|') FROM exam_sections es WHERE es.exam_id = e.id) AS sections,
          (SELECT GROUP_CONCAT(y.year_level SEPARATOR '|') FROM exam_year_levels y WHERE y.exam_id = e.id) AS year_levels
        FROM exams e JOIN users p ON p.id = e.professor_id LEFT JOIN departments d ON d.id = e.department_id
        ORDER BY e.id DESC")->fetchAll();
    $g = $pdo->prepare('SELECT exam_id, can_delete FROM exam_editors WHERE professor_id = ?');
    $g->execute([$u['id']]);
    $grants = [];
    foreach ($g->fetchAll() as $x) $grants[(int)$x['exam_id']] = (bool)(int)$x['can_delete'];
    foreach ($rows as &$r) {
        $own = (int)$r['professor_id'] === (int)$u['id'];
        $r['is_owner'] = $own;
        $r['can_edit'] = $own || isset($grants[(int)$r['id']]);
        $r['can_delete'] = $own || !empty($grants[(int)$r['id']]);
        $r['sections'] = split_list($r['sections']);
        $r['year_levels'] = split_list($r['year_levels'], true);
        $r['window_closed'] = effective_exam_status($r) === 'closed' && $r['status'] === 'available';
    }
    unset($r);
    $mine = professor_department($pdo, $u);
    json_out(['exams' => $rows, 'department' => $mine, 'year_levels' => YEAR_LEVELS]);
}

/* ---- Professor: sections that can be picked while setting up an exam - ONLY sections of students in the professor's department ---- */
if ($action === 'sections') {
    $u = require_role('professor');
    $dept = professor_department($pdo, $u);
    if (!$dept) json_out(['error' => 'Your account has no department yet. Ask an administrator to set it.'], 403);
    $st = $pdo->prepare("SELECT u.section, COUNT(*) AS students
        FROM users u
        WHERE u.role = 'student' AND u.approval_status = 'approved' AND u.is_active = 1 AND u.graduated = 0
          AND u.department_id = ? AND u.section IS NOT NULL AND u.section <> ''
        GROUP BY u.section ORDER BY u.section");
    $st->execute([$dept['id']]);
    json_out(['sections' => $st->fetchAll(), 'department' => $dept]);
}

/* ---- Professor: how many students can take an exam with this setup (live preview in the setup form) ---- */
if ($action === 'eligible') {
    require_post();
    $u = require_role('professor');
    $d = body();
    $dept = professor_department($pdo, $u);
    if (!$dept) json_out(['error' => 'Your account has no department yet.'], 403);
    json_out(eligible_students($pdo, (int)$dept['id'], clean_year_levels($d['year_levels'] ?? []), clean_sections($d['sections'] ?? [])) + ['department' => $dept]);
}

/* ---- Student: start/resume exam. Correct answers are never sent. ---- */
if ($action === 'start') {
    $u = require_role('student');
    $id = (int)($_GET['id'] ?? 0);
    $ex = $pdo->prepare('SELECT * FROM exams WHERE id = ?');
    $ex->execute([$id]);
    $e = $ex->fetch();
    if (!$e || $e['status'] !== 'available') json_out(['error' => 'This examination is not open.'], 403);

    $sub = $pdo->prepare('SELECT * FROM submissions WHERE exam_id = ? AND student_id = ?');
    $sub->execute([$id, $u['id']]);
    $s = $sub->fetch();

    if (!$s) {
        // A NEW attempt: the student must belong to the exam's department, year level and section, and the exam window must be open.
        $why = student_exam_eligibility($pdo, $e, student_profile_row($pdo, (int)$u['id']));
        if ($why !== null) json_out(['error' => $why, 'not_eligible' => true], 403);
        if (effective_exam_status($e) === 'closed') json_out(['error' => 'The availability of this examination has ended.'], 403);
        $qc = $pdo->prepare('SELECT COUNT(*) FROM questions WHERE exam_id = ?');
        $qc->execute([$id]);
        if ((int)$qc->fetchColumn() === 0) json_out(['error' => 'This examination has no questions yet.'], 403);
    }

    if ($s && $s['mode'] !== 'in-progress') {
        $cnt = $pdo->prepare('SELECT COUNT(*) FROM violations WHERE submission_id = ? AND attempt = ?');
        $cnt->execute([$s['id'], (int)$s['reopen_count'] + 1]);
        $why = auto_reason_info($s, (int)$cnt->fetchColumn(), (int)$e['violation_threshold']);
        if ($s['mode'] === 'auto' && $why['code'] === 'violations') {
            json_out(['error' => 'Your examination was submitted automatically because you reached the maximum number of violations. Ask your professor if you need access to be re-enabled.', 'auto_submitted' => true], 409);
        }
        json_out(['error' => 'You have already submitted this examination.'], 409);
    }
    if (!$s) {
        $pdo->prepare('INSERT INTO submissions (exam_id, student_id, started_at, last_ping) VALUES (?,?,NOW(),NOW())')->execute([$id, $u['id']]);
        $subId = (int)$pdo->lastInsertId();
        $started = time();
        $attempt = 1; $restored = 0;
    } else {
        $subId = (int)$s['id'];
        $started = strtotime($s['started_at']);
        $attempt = (int)$s['reopen_count'] + 1; $restored = (int)$s['reopen_count'];
    }
    $remaining = max(0, $e['duration_minutes'] * 60 - (time() - $started));

    $qs = $pdo->prepare('SELECT id, type, prompt, points, choices_json, media_json FROM questions WHERE exam_id = ? ORDER BY position');
    $qs->execute([$id]);
    $questions = array_map(function ($q) {
        return ['id' => (string)$q['id'], 'type' => $q['type'], 'prompt' => $q['prompt'], 'points' => (int)$q['points'],
                'choices' => $q['choices_json'] ? json_decode($q['choices_json'], true) : null,
                'media' => $q['media_json'] ? json_decode($q['media_json'], true) : null];
    }, $qs->fetchAll());

    // Only the violations of the running attempt count towards the limit; earlier attempts stay in the database as history.
    $vc = $pdo->prepare('SELECT type_id AS typeId, label, DATE_FORMAT(occurred_at, "%h:%i:%s %p") AS time FROM violations WHERE submission_id = ? AND attempt = ? ORDER BY id');
    $vc->execute([$subId, $attempt]);

    json_out(['submission_id' => $subId, 'attempt' => $attempt, 'restored' => $restored,
        'draft' => (object)($s && $s['draft_json'] ? json_decode($s['draft_json'], true) : []), 'secondsLeft' => $remaining, 'violations' => $vc->fetchAll(),
        'exam' => ['id' => (int)$e['id'], 'code' => $e['code'], 'title' => $e['title'], 'subject' => $e['subject'], 'description' => $e['description'],
                   'durationMinutes' => (int)$e['duration_minutes'], 'threshold' => (int)$e['violation_threshold'],
                   'rules' => exam_rules_row($e),
                   'questions' => $questions]]);
}

/* =====================================================================
   STEP 1 - EXAM SETUP (no questions in here)
   ===================================================================== */

/* ---- Professor: create the exam setup. The department is the professor's own and is never taken from the browser. ---- */
if ($action === 'save') {
    require_post();
    $u = require_role('professor');
    $d = body();
    $dept = professor_department($pdo, $u);
    if (!$dept) json_out(['error' => 'Your account has no department yet, so you cannot create examinations. Ask an administrator to set it.'], 403);
    if (isset($d['department_id']) && $d['department_id'] !== '' && $d['department_id'] !== null && (int)$d['department_id'] !== (int)$dept['id']) {
        json_out(['error' => 'You can only create examinations for your own department (' . $dept['code'] . ').'], 403);
    }
    $v = validate_setup($pdo, $d, (int)$dept['id'], true);
    if (is_string($v)) json_out(['error' => $v], 422);
    $thr = max(1, min(20, (int)($d['threshold'] ?? 3)));
    $dur = max(1, min(600, (int)($d['duration'] ?? 45)));
    $r = $d['rules'] ?? [];
    $b = fn($k) => empty($r) || !empty($r[$k]) ? 1 : 0;

    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO exams (code,title,subject,description,professor_id,department_id,duration_minutes,violation_threshold,status,scheduled_at,available_until,
        detect_face_angle,detect_eye_gaze,detect_multi_face,block_copy_paste,detect_tab_switch,require_camera)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$v['code'], $v['title'], $v['subject'], $v['description'], $u['id'], $dept['id'], $dur, $thr, 'draft', $v['schedule'], $v['until'],
                   $b('faceAngle'), $b('eyeGaze'), $b('multiFace'), $b('copyPaste'), $b('tabSwitch'), $b('requireCamera')]);
    $eid = (int)$pdo->lastInsertId();
    replace_list($pdo, 'exam_year_levels', 'year_level', $eid, $v['years']);
    replace_list($pdo, 'exam_sections', 'section', $eid, $v['sections']);
    $pdo->commit();
    json_out(['id' => $eid, 'next' => 'professor-exam-questions.html?exam=' . $eid . '&new=1']);
}

/* ---- Professor: the setup of one exam they may edit (creator, or allowed by an administrator) ---- */
if ($action === 'get') {
    $u = require_role('professor');
    $e = require_editable_exam($pdo, $u, (int)($_GET['id'] ?? 0));
    $perm = exam_permissions($pdo, $u, $e);
    $years = exam_year_levels($pdo, (int)$e['id']);
    $sec = $pdo->prepare('SELECT section FROM exam_sections WHERE exam_id = ? ORDER BY section');
    $sec->execute([(int)$e['id']]);
    $sections = $sec->fetchAll(PDO::FETCH_COLUMN);
    $dep = null;
    if ($e['department_id'] !== null) {
        $ds = $pdo->prepare('SELECT id, code, name FROM departments WHERE id = ?');
        $ds->execute([$e['department_id']]);
        $dep = $ds->fetch() ?: null;
    }
    $qc = $pdo->prepare('SELECT COUNT(*) FROM questions WHERE exam_id = ?');
    $qc->execute([(int)$e['id']]);
    json_out(['exam' => ['id' => (int)$e['id'], 'code' => $e['code'], 'title' => $e['title'], 'subject' => $e['subject'], 'description' => (string)$e['description'],
        'status' => $e['status'], 'schedule' => local_dt($e['scheduled_at']), 'available_until' => local_dt($e['available_until']),
        'duration' => (int)$e['duration_minutes'], 'threshold' => (int)$e['violation_threshold'], 'rules' => exam_rules_row($e),
        'sections' => $sections, 'year_levels' => $years, 'department' => $dep, 'question_count' => (int)$qc->fetchColumn(), 'updated_at' => $e['updated_at']],
        'lock' => exam_lock_state($pdo, $e), 'permissions' => $perm,
        'eligible' => eligible_students($pdo, $e['department_id'] !== null ? (int)$e['department_id'] : null, $years, $sections)]);
}

/* ---- Professor: save the setup edits of an exam they may edit. Questions are NOT changed here. ---- */
if ($action === 'update') {
    require_post();
    $u = require_role('professor');
    $d = body();
    $id = (int)($d['id'] ?? 0);
    $first = require_editable_exam($pdo, $u, $id);
    if (isset($d['department_id']) && $d['department_id'] !== '' && $d['department_id'] !== null && (int)$d['department_id'] !== (int)$first['department_id']) {
        json_out(['error' => 'The department of an examination cannot be changed.'], 403);
    }

    $pdo->beginTransaction();
    // Lock the row so the "does anyone have an attempt yet?" check and the save cannot interleave with another edit.
    $st = $pdo->prepare('SELECT * FROM exams WHERE id = ? FOR UPDATE');
    $st->execute([$id]);
    $e = $st->fetch();
    if (!$e) { $pdo->rollBack(); json_out(['error' => 'Examination not found.'], 404); }
    $lock = exam_lock_state($pdo, $e);

    $v = validate_setup($pdo, $d, $e['department_id'] !== null ? (int)$e['department_id'] : null, false);
    if (is_string($v)) { $pdo->rollBack(); json_out(['error' => $v], 422); }

    $dur = (int)$e['duration_minutes']; $thr = (int)$e['violation_threshold'];
    $rulesCols = ['faceAngle' => 'detect_face_angle', 'eyeGaze' => 'detect_eye_gaze', 'multiFace' => 'detect_multi_face',
                  'copyPaste' => 'block_copy_paste', 'tabSwitch' => 'detect_tab_switch', 'requireCamera' => 'require_camera'];
    $ruleVals = [];
    foreach ($rulesCols as $k => $col) $ruleVals[$col] = (int)$e[$col];
    $wantsDur = array_key_exists('duration', $d) ? max(1, min(600, (int)$d['duration'])) : $dur;
    $wantsThr = array_key_exists('threshold', $d) ? max(1, min(20, (int)$d['threshold'])) : $thr;
    $wantsRules = $ruleVals;
    if (isset($d['rules']) && is_array($d['rules'])) foreach ($rulesCols as $k => $col) if (array_key_exists($k, $d['rules'])) $wantsRules[$col] = !empty($d['rules'][$k]) ? 1 : 0;

    if ($lock['locked']) {
        if ($wantsDur !== $dur || $wantsThr !== $thr || $wantsRules !== $ruleVals) {
            $pdo->rollBack();
            json_out(['error' => 'The time limit, violation limit and proctoring rules are locked. ' . $lock['reason'], 'locked' => true], 409);
        }
    } else {
        $dur = $wantsDur; $thr = $wantsThr; $ruleVals = $wantsRules;
    }

    $cut = sections_cut_off($pdo, $id, $v['sections']);
    if ($cut) { $pdo->rollBack(); json_out(['error' => 'These sections already have students with an attempt and cannot be removed: ' . implode(', ', $cut) . '.'], 409); }
    $cutY = year_levels_cut_off($pdo, $id, $v['years']);
    if ($cutY) { $pdo->rollBack(); json_out(['error' => 'These year levels already have students with an attempt and cannot be removed: ' . implode(', ', $cutY) . '.'], 409); }

    $pdo->prepare('UPDATE exams SET code = ?, title = ?, subject = ?, description = ?, scheduled_at = ?, available_until = ?, duration_minutes = ?, violation_threshold = ?,
                   detect_face_angle = ?, detect_eye_gaze = ?, detect_multi_face = ?, block_copy_paste = ?, detect_tab_switch = ?, require_camera = ?,
                   updated_at = NOW() WHERE id = ?')
        ->execute([$v['code'], $v['title'], $v['subject'], $v['description'], $v['schedule'], $v['until'], $dur, $thr,
                   $ruleVals['detect_face_angle'], $ruleVals['detect_eye_gaze'], $ruleVals['detect_multi_face'], $ruleVals['block_copy_paste'],
                   $ruleVals['detect_tab_switch'], $ruleVals['require_camera'], $id]);
    replace_list($pdo, 'exam_year_levels', 'year_level', $id, $v['years']);
    replace_list($pdo, 'exam_sections', 'section', $id, $v['sections']);
    $pdo->commit();
    // A published exam whose date or availability window changed: tell the students (once per distinct change).
    if (in_array($e['status'], ['scheduled', 'available'], true)) {
        $oldSig = (string)$e['scheduled_at'] . '|' . (string)$e['available_until'];
        $newSig = (string)$v['schedule'] . '|' . (string)$v['until'];
        if ($oldSig !== $newSig) {
            try {
                $fresh = $pdo->prepare('SELECT * FROM exams WHERE id = ?'); $fresh->execute([$id]); $fe = $fresh->fetch();
                $when = $fe['scheduled_at'] ? date('M j, Y g:i A', strtotime($fe['scheduled_at'])) : 'a date that will be announced';
                notify_users(exam_student_ids($pdo, $fe), 'exam', 'Examination rescheduled / updated', $fe['code'] . ' · ' . $fe['title'] . ' is now set for ' . $when .
                    ($fe['available_until'] ? ' and stays open until ' . date('M j, Y g:i A', strtotime($fe['available_until'])) : '') . '.',
                    'student-exam-dashboard.html', 'exam' . $id . ':updated:' . md5($newSig));
            } catch (Throwable $t) { error_log('[exam-system] reschedule notification failed: ' . $t->getMessage()); }
        }
    }
    json_out(['ok' => true, 'id' => $id, 'locked' => $lock['locked'], 'next' => 'professor-exam-questions.html?exam=' . $id]);
}

if ($action === 'set_status') {
    require_post();
    $u = require_role('professor');
    $d = body();
    if (!in_array($d['status'] ?? '', ['draft', 'scheduled', 'available', 'closed'], true)) json_out(['error' => 'Bad status'], 422);
    $e = require_editable_exam($pdo, $u, (int)($d['id'] ?? 0));
    if (in_array($d['status'], ['scheduled', 'available'], true)) {
        $qc = $pdo->prepare('SELECT COUNT(*) FROM questions WHERE exam_id = ?');
        $qc->execute([(int)$e['id']]);
        if ((int)$qc->fetchColumn() === 0) json_out(['error' => 'Add at least one question before scheduling or opening this examination.', 'no_questions' => true], 422);
    }
    $old = (string)$e['status'];
    $new = (string)$d['status'];
    if ($old === $new) json_out(['ok' => true]);
    $pdo->prepare('UPDATE exams SET status = ? WHERE id = ?')->execute([$new, $e['id']]);
    if ($new !== 'closed' && (int)$e['release_answers']) {
        // The exam can be started again, so the answer key must not stay released.
        $pdo->prepare('UPDATE exams SET release_answers = 0 WHERE id = ?')->execute([$e['id']]);
    }
    notify_exam_status_change($pdo, $e, $old, $new);
    json_out(['ok' => true]);
}

/* ---- Professor: change which sections may take an exam (empty list = all sections) ---- */
if ($action === 'set_sections') {
    require_post();
    $u = require_role('professor');
    $d = body();
    $id = (int)($d['id'] ?? 0);
    $e = require_editable_exam($pdo, $u, $id);
    $sections = clean_sections($d['sections'] ?? []);
    if ($e['department_id'] !== null && $sections) {
        $bad = sections_outside_department($pdo, (int)$e['department_id'], $sections);
        if ($bad) json_out(['error' => 'These sections only have students from other departments: ' . implode(', ', $bad) . '.'], 422);
    }
    $cut = sections_cut_off($pdo, $id, $sections);
    if ($cut) json_out(['error' => 'These sections already have students with an attempt and cannot be removed: ' . implode(', ', $cut) . '.'], 409);
    $pdo->beginTransaction();
    replace_list($pdo, 'exam_sections', 'section', $id, $sections);
    $pdo->commit();
    json_out(['ok' => true, 'sections' => $sections]);
}

/* ---- Professor: delete an exam (questions, submissions, answers and violations go with it). Creator only, unless an admin allowed it. ---- */
if ($action === 'delete') {
    require_post();
    $u = require_role('professor');
    $d = body();
    $id = (int)($d['id'] ?? 0);
    require_editable_exam($pdo, $u, $id, true);
    $cnt = $pdo->prepare('SELECT COUNT(*) FROM submissions WHERE exam_id = ?');
    $cnt->execute([$id]);
    $subs = (int)$cnt->fetchColumn();
    foreach (['exam_sections', 'exam_year_levels', 'exam_editors'] as $t) $pdo->prepare("DELETE FROM $t WHERE exam_id = ?")->execute([$id]);
    $pdo->prepare('DELETE FROM exams WHERE id = ?')->execute([$id]);   // ON DELETE CASCADE removes everything attached to it
    json_out(['ok' => true, 'deleted_submissions' => $subs]);
}

/* =====================================================================
   STEP 2 - EXAM QUESTIONS (linked to one exam)
   ===================================================================== */

/* ---- Professor: details + questions of ANY exam (read-only for exams they may not edit; the answer key is only sent to editors) ---- */
if ($action === 'view') {
    $u = require_role('professor');
    $id = (int)($_GET['id'] ?? 0);
    $st = $pdo->prepare('SELECT e.*, p.full_name AS professor, p.account_no AS professor_no, d.code AS dept_code, d.name AS dept_name
        FROM exams e JOIN users p ON p.id = e.professor_id LEFT JOIN departments d ON d.id = e.department_id WHERE e.id = ?');
    $st->execute([$id]);
    $e = $st->fetch();
    if (!$e) json_out(['error' => 'Examination not found.'], 404);
    $perm = exam_permissions($pdo, $u, $e);
    $withKey = $perm['can_edit'];

    $qs = $pdo->prepare('SELECT id, position, type, prompt, points, choices_json, correct_answer, media_json FROM questions WHERE exam_id = ? ORDER BY position, id');
    $qs->execute([$id]);
    $questions = array_map(function ($q) use ($withKey) {
        $row = ['id' => (int)$q['id'], 'position' => (int)$q['position'], 'type' => $q['type'], 'prompt' => $q['prompt'], 'points' => (int)$q['points'],
                'choices' => $q['choices_json'] ? json_decode($q['choices_json'], true) : [],
                'media' => $q['media_json'] ? json_decode($q['media_json'], true) : null];
        if ($withKey) $row['correct'] = $q['correct_answer'];
        return $row;
    }, $qs->fetchAll());

    $sec = $pdo->prepare('SELECT section FROM exam_sections WHERE exam_id = ? ORDER BY section');
    $sec->execute([$id]);
    $years = exam_year_levels($pdo, $id);
    $sections = $sec->fetchAll(PDO::FETCH_COLUMN);
    $out = ['exam' => ['id' => (int)$e['id'], 'code' => $e['code'], 'title' => $e['title'], 'subject' => $e['subject'], 'description' => (string)$e['description'],
        'status' => $e['status'], 'scheduled_at' => $e['scheduled_at'], 'available_until' => $e['available_until'], 'duration' => (int)$e['duration_minutes'],
        'threshold' => (int)$e['violation_threshold'], 'rules' => exam_rules_row($e), 'professor' => $e['professor'], 'professor_id' => (int)$e['professor_id'],
        'dept_code' => $e['dept_code'], 'dept_name' => $e['dept_name'], 'year_levels' => $years, 'sections' => $sections,
        'created_at' => $e['created_at'], 'updated_at' => $e['updated_at'], 'total_points' => array_sum(array_column($questions, 'points'))],
        'questions' => $questions, 'permissions' => $perm, 'answer_key_visible' => $withKey];
    if ($perm['can_edit']) $out['lock'] = exam_lock_state($pdo, $e);
    json_out($out);
}

/** Shared by the question actions: the exam must be editable by this professor AND its questions must not be locked. */
function questions_editable_exam(PDO $pdo, array $u, int $examId): array {
    $e = require_editable_exam($pdo, $u, $examId);
    $lock = exam_lock_state($pdo, $e);
    if ($lock['locked']) json_out(['error' => 'The questions are locked. ' . $lock['reason'], 'locked' => true], 409);
    return $e;
}

function renumber_questions(PDO $pdo, int $examId): void {
    $st = $pdo->prepare('SELECT id FROM questions WHERE exam_id = ? ORDER BY position, id');
    $st->execute([$examId]);
    $upd = $pdo->prepare('UPDATE questions SET position = ? WHERE id = ?');
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $i => $qid) $upd->execute([$i + 1, $qid]);
}

/* ---- Professor: add a question to the exam, or save the edits of one ---- */
if ($action === 'question_save') {
    require_post();
    $u = require_role('professor');
    $d = body();
    $examId = (int)($d['exam_id'] ?? 0);
    $qid = (int)($d['id'] ?? 0);
    $pdo->beginTransaction();
    $e = questions_editable_exam($pdo, $u, $examId);
    $pos = 0;
    if ($qid) {
        $ck = $pdo->prepare('SELECT position FROM questions WHERE id = ? AND exam_id = ?');
        $ck->execute([$qid, $examId]);
        $pos = $ck->fetchColumn();
        if ($pos === false) { $pdo->rollBack(); json_out(['error' => 'That question does not belong to this examination.'], 404); }
        $pos = (int)$pos;
    } else {
        $cnt = $pdo->prepare('SELECT COUNT(*), COALESCE(MAX(position), 0) FROM questions WHERE exam_id = ?');
        $cnt->execute([$examId]);
        [$n, $max] = array_map('intval', $cnt->fetch(PDO::FETCH_NUM));
        if ($n >= MAX_QUESTIONS_PER_EXAM) { $pdo->rollBack(); json_out(['error' => 'An examination can have at most ' . MAX_QUESTIONS_PER_EXAM . ' questions.'], 422); }
        $pos = $max + 1;
    }
    $norm = normalize_questions([$d['question'] ?? null], $pos ?: 1);
    if (is_string($norm)) { $pdo->rollBack(); json_out(['error' => $norm], 422); }
    $q = $norm[0];
    if ($qid) {
        $pdo->prepare('UPDATE questions SET type = ?, prompt = ?, points = ?, choices_json = ?, correct_answer = ?, media_json = ? WHERE id = ? AND exam_id = ?')
            ->execute([$q['type'], $q['prompt'], $q['points'], $q['choices'], $q['correct'], $q['media'], $qid, $examId]);
    } else {
        $pdo->prepare('INSERT INTO questions (exam_id,position,type,prompt,points,choices_json,correct_answer,media_json) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$examId, $pos, $q['type'], $q['prompt'], $q['points'], $q['choices'], $q['correct'], $q['media']]);
        $qid = (int)$pdo->lastInsertId();
    }
    $pdo->prepare('UPDATE exams SET updated_at = NOW() WHERE id = ?')->execute([$examId]);
    $pdo->commit();
    json_out(['ok' => true, 'id' => $qid]);
}

/* ---- Professor: delete one question of the exam ---- */
if ($action === 'question_delete') {
    require_post();
    $u = require_role('professor');
    $d = body();
    $examId = (int)($d['exam_id'] ?? 0);
    $pdo->beginTransaction();
    questions_editable_exam($pdo, $u, $examId);
    $del = $pdo->prepare('DELETE FROM questions WHERE id = ? AND exam_id = ?');
    $del->execute([(int)($d['id'] ?? 0), $examId]);
    if ($del->rowCount() === 0) { $pdo->rollBack(); json_out(['error' => 'That question does not belong to this examination.'], 404); }
    renumber_questions($pdo, $examId);
    $pdo->prepare('UPDATE exams SET updated_at = NOW() WHERE id = ?')->execute([$examId]);
    $pdo->commit();
    json_out(['ok' => true]);
}

/* ---- Professor: change the order of the questions (the full list of question ids in the new order) ---- */
if ($action === 'question_reorder') {
    require_post();
    $u = require_role('professor');
    $d = body();
    $examId = (int)($d['exam_id'] ?? 0);
    $order = is_array($d['order'] ?? null) ? array_values(array_map('intval', $d['order'])) : [];
    $pdo->beginTransaction();
    questions_editable_exam($pdo, $u, $examId);
    $st = $pdo->prepare('SELECT id FROM questions WHERE exam_id = ?');
    $st->execute([$examId]);
    $have = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $a = $have; $b = $order; sort($a); sort($b);
    if ($a !== $b) { $pdo->rollBack(); json_out(['error' => 'The question list is out of date. Reload the page and try again.'], 409); }
    $upd = $pdo->prepare('UPDATE questions SET position = ? WHERE id = ? AND exam_id = ?');
    foreach ($order as $i => $qid) $upd->execute([$i + 1, $qid, $examId]);
    $pdo->commit();
    json_out(['ok' => true]);
}

json_out(['error' => 'Unknown action.'], 400);
