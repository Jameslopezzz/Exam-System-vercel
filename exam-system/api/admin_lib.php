<?php
// Shared READ-ONLY queries for the administrator's exam pages (dashboard, exam management, monitoring, reports).
// Nothing in here changes data. Administrators never touch a professor's exam builder, monitor or reports.
require_once __DIR__ . '/bootstrap.php';

const ADMIN_EXAM_STATUSES = ['draft', 'scheduled', 'available', 'closed'];
// Student states inside one exam: completed = handed in by the student, auto-submitted, ongoing = answering now,
// pending = has not started yet (exam scheduled / open), absent = never started and the exam is closed, not-open = exam still a draft.
const ADMIN_STUDENT_STATES = ['completed', 'auto-submitted', 'ongoing', 'pending', 'absent', 'not-open'];

/** Filters shared by every admin exam page, read from the query string and validated. */
function admin_filters(): array {
    $year = (string)($_GET['year_level'] ?? '');
    $status = (string)($_GET['status'] ?? '');
    $sstate = (string)($_GET['student_status'] ?? '');
    return [
        'department_id' => (int)($_GET['department_id'] ?? 0),
        'year_level'    => in_array($year, YEAR_LEVELS, true) ? $year : '',
        'professor_id'  => (int)($_GET['professor_id'] ?? 0),
        'exam_id'       => (int)($_GET['exam_id'] ?? 0),
        'status'        => in_array($status, ADMIN_EXAM_STATUSES, true) ? $status : '',
        'student_status'=> in_array($sstate, ADMIN_STUDENT_STATES, true) ? $sstate : '',
        'q'             => cut(trim((string)($_GET['q'] ?? '')), 60),
    ];
}

/** Drop-down contents for the filter bars. Exams are all listed so the page can cascade them. */
function admin_filter_options(): array {
    $pdo = db();
    return [
        'departments' => $pdo->query('SELECT id, code, name FROM departments ORDER BY code')->fetchAll(),
        'professors'  => $pdo->query("SELECT id, full_name AS name, account_no, department_id FROM users WHERE role = 'professor' AND approval_status = 'approved' ORDER BY full_name")->fetchAll(),
        'exams'       => $pdo->query('SELECT e.id, e.code, e.title, e.status, e.professor_id, COALESCE(e.department_id, p.department_id) AS department_id FROM exams e JOIN users p ON p.id = e.professor_id ORDER BY e.code, e.id')->fetchAll(),
        'year_levels' => YEAR_LEVELS,
        'statuses'    => ADMIN_EXAM_STATUSES,
        'student_states' => ADMIN_STUDENT_STATES,
    ];
}

function admin_lower(string $s): string { return function_exists('mb_strtolower') ? mb_strtolower(trim($s)) : strtolower(trim($s)); }

function admin_student_state(?array $sub, string $examStatus): string {
    if ($sub) return $sub['mode'] === 'auto' ? 'auto-submitted' : ($sub['mode'] === 'manual' ? 'completed' : 'ongoing');
    return $examStatus === 'closed' ? 'absent' : ($examStatus === 'draft' ? 'not-open' : 'pending');
}

function admin_score(array $sub): ?int {
    if ($sub['mode'] === 'in-progress' || (int)$sub['max_score'] <= 0) return null;
    return (int)round((((int)$sub['auto_score'] + (int)$sub['essay_score']) / (int)$sub['max_score']) * 100);
}

/**
 * Exams matching the filters, each with the students assigned to it and how far they are.
 * "Assigned" follows the real access rule: the exam's department, its eligible year levels (none = all) and its sections (none = all); students who already have an attempt always count. Department = the exam's own department (the creating professor's), year levels = the exam's eligible year levels.
 * With $withStudents the per-student rows are included as well.
 */
function admin_load(array $f, bool $withStudents = false): array {
    $pdo = db();
    $where = ['1 = 1']; $args = [];
    if ($f['department_id']) { $where[] = 'COALESCE(e.department_id, p.department_id) = ?'; $args[] = $f['department_id']; }
    if ($f['professor_id'])  { $where[] = 'e.professor_id = ?';  $args[] = $f['professor_id']; }
    if ($f['exam_id'])       { $where[] = 'e.id = ?';            $args[] = $f['exam_id']; }
    if ($f['status'] !== '') { $where[] = 'e.status = ?';        $args[] = $f['status']; }
    if ($f['q'] !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $f['q']) . '%';
        $where[] = '(e.code LIKE ? OR e.title LIKE ? OR e.subject LIKE ?)';
        array_push($args, $like, $like, $like);
    }
    $st = $pdo->prepare("SELECT e.id, e.code, e.title, e.subject, e.description, e.status, e.scheduled_at, e.duration_minutes, e.violation_threshold,
            e.created_at, e.updated_at, e.available_until, e.professor_id, p.full_name AS professor, p.account_no AS professor_no, COALESCE(e.department_id, p.department_id) AS department_id,
            d.code AS dept_code, d.name AS dept_name,
            (SELECT COUNT(*) FROM questions q WHERE q.exam_id = e.id) AS items,
            (SELECT GROUP_CONCAT(es.section ORDER BY es.section SEPARATOR '|') FROM exam_sections es WHERE es.exam_id = e.id) AS sections,
            (SELECT GROUP_CONCAT(y.year_level SEPARATOR '|') FROM exam_year_levels y WHERE y.exam_id = e.id) AS eligible_years
        FROM exams e JOIN users p ON p.id = e.professor_id LEFT JOIN departments d ON d.id = COALESCE(e.department_id, p.department_id)
        WHERE " . implode(' AND ', $where) . " ORDER BY (d.code IS NULL), d.code, e.id DESC");
    $st->execute($args);
    $exams = $st->fetchAll();
    if (!$exams) return ['exams' => []];

    $ids = array_map(fn($e) => (int)$e['id'], $exams);
    $in = implode(',', $ids);   // integers only

    $students = $pdo->query("SELECT u.id, u.account_no, u.full_name, u.section, u.year_level, u.department_id, d.code AS dept_code
        FROM users u LEFT JOIN departments d ON d.id = u.department_id
        WHERE u.role = 'student' AND u.approval_status = 'approved' AND u.is_active = 1 AND u.graduated = 0")->fetchAll();

    $subsByExam = [];
    $sq = $pdo->query("SELECT s.id, s.exam_id, s.student_id, s.mode, s.progress, s.auto_score, s.essay_score, s.max_score, s.last_ping, s.reopen_count,
            s.cam_state, s.submitted_at, s.started_at, u.account_no, u.full_name, u.section, u.year_level, u.department_id, d.code AS dept_code,
            " . all_violations_sql('s') . " AS violations, " . cur_violations_sql('s') . " AS cur_violations
        FROM submissions s JOIN users u ON u.id = s.student_id LEFT JOIN departments d ON d.id = u.department_id
        WHERE s.exam_id IN ($in)");
    foreach ($sq->fetchAll() as $r) $subsByExam[(int)$r['exam_id']][(int)$r['student_id']] = $r;

    $out = [];
    foreach ($exams as $e) {
        $eid = (int)$e['id'];
        $secList = $e['sections'] !== null && $e['sections'] !== '' ? explode('|', $e['sections']) : [];
        $secSet = array_flip(array_map('admin_lower', $secList));
        $subs = $subsByExam[$eid] ?? [];

        $roster = [];
        $eligYears = $e['eligible_years'] !== null && $e['eligible_years'] !== '' ? explode('|', $e['eligible_years']) : [];
        foreach ($students as $s) {   // same three rules as student_exam_eligibility(): department, year level, section
            if ($e['department_id'] !== null && (int)$s['department_id'] !== (int)$e['department_id']) continue;
            if ($eligYears && !in_array((string)$s['year_level'], $eligYears, true)) continue;
            if (!$secSet || isset($secSet[admin_lower((string)$s['section'])])) $roster[(int)$s['id']] = $s;
        }
        foreach ($subs as $sid => $r) {   // students with an attempt always belong to the roster, even if they were disabled / graduated since
            $roster[$sid] = ['id' => $sid, 'account_no' => $r['account_no'], 'full_name' => $r['full_name'], 'section' => $r['section'],
                             'year_level' => $r['year_level'], 'department_id' => $r['department_id'], 'dept_code' => $r['dept_code']];
        }
        if ($f['year_level'] !== '') $roster = array_filter($roster, fn($s) => $s['year_level'] === $f['year_level']);
        if ($f['year_level'] !== '' && !$roster) continue;   // nobody of that year level is assigned to this exam

        $c = ['completed' => 0, 'auto-submitted' => 0, 'ongoing' => 0, 'pending' => 0, 'absent' => 0, 'not-open' => 0];
        $years = []; $scores = []; $viol = 0; $rows = [];
        foreach ($roster as $sid => $s) {
            $sub = $subs[$sid] ?? null;
            $state = admin_student_state($sub, $e['status']);
            $c[$state]++;
            $y = $s['year_level'] ?: 'Not set';
            $years[$y] = ($years[$y] ?? 0) + 1;
            $score = null;
            if ($sub) { $viol += (int)$sub['violations']; $score = admin_score($sub); if ($score !== null) $scores[] = $score; }
            if ($withStudents) {
                $online = $sub && $sub['mode'] === 'in-progress' && $sub['last_ping'] && (time() - strtotime($sub['last_ping'])) < 30;
                $rows[] = ['student_id' => (int)$sid, 'submission_id' => $sub ? (int)$sub['id'] : null, 'account_no' => $s['account_no'], 'name' => $s['full_name'],
                           'section' => $s['section'], 'year_level' => $s['year_level'], 'dept_code' => $s['dept_code'], 'state' => $state,
                           'progress' => $sub ? (int)$sub['progress'] : 0, 'violations' => $sub ? (int)$sub['violations'] : 0,
                           'current_violations' => $sub ? (int)$sub['cur_violations'] : 0, 'attempts' => $sub ? (int)$sub['reopen_count'] + 1 : 0,
                           'score' => $score, 'online' => (bool)$online, 'camera' => $sub && $sub['mode'] === 'in-progress' ? ($sub['cam_state'] ?: 'unknown') : null,
                           'submitted_at' => $sub ? $sub['submitted_at'] : null,
                           'exam_id' => $eid, 'exam_code' => $e['code'], 'exam_title' => $e['title'], 'professor' => $e['professor'], 'dept' => $e['dept_code']];
            }
        }
        $yl = [];
        foreach (array_merge(YEAR_LEVELS, ['Not set']) as $y) if (isset($years[$y])) $yl[] = ['year' => $y, 'students' => $years[$y]];
        $assigned = count($roster);
        $row = ['id' => $eid, 'code' => $e['code'], 'title' => $e['title'], 'subject' => $e['subject'], 'description' => $e['description'], 'status' => $e['status'],
                'scheduled_at' => $e['scheduled_at'], 'duration_minutes' => (int)$e['duration_minutes'], 'violation_threshold' => (int)$e['violation_threshold'],
                'items' => (int)$e['items'], 'sections' => $secList, 'eligible_years' => sort_year_levels($eligYears), 'available_until' => $e['available_until'], 'created_at' => $e['created_at'], 'updated_at' => $e['updated_at'],
                'professor' => $e['professor'], 'professor_id' => (int)$e['professor_id'], 'professor_no' => $e['professor_no'],
                'department_id' => $e['department_id'] !== null ? (int)$e['department_id'] : null, 'dept_code' => $e['dept_code'] ?: 'Unassigned', 'dept_name' => $e['dept_name'] ?: 'No department set',
                'year_levels' => $yl, 'assigned' => $assigned, 'completed' => $c['completed'], 'auto_submitted' => $c['auto-submitted'], 'ongoing' => $c['ongoing'],
                'pending' => $c['pending'], 'absent' => $c['absent'],
                'started' => $c['completed'] + $c['auto-submitted'] + $c['ongoing'],
                'violations' => $viol, 'avg_score' => $scores ? (int)round(array_sum($scores) / count($scores)) : null, 'scores' => $scores];
        if ($withStudents) $row['roster'] = $rows;
        $out[] = $row;
    }
    return ['exams' => $out];
}

/** Add up the numbers of a list of exam rows (as returned by admin_load). */
function admin_sum(array $exams): array {
    $t = ['exams' => count($exams), 'assigned' => 0, 'started' => 0, 'completed' => 0, 'auto_submitted' => 0, 'ongoing' => 0, 'pending' => 0, 'absent' => 0, 'violations' => 0];
    $scores = [];
    foreach ($exams as $e) {
        foreach (['assigned', 'started', 'completed', 'auto_submitted', 'ongoing', 'pending', 'absent', 'violations'] as $k) $t[$k] += $e[$k];
        foreach ($e['scores'] as $s) $scores[] = $s;
    }
    $t['avg_score'] = $scores ? (int)round(array_sum($scores) / count($scores)) : null;
    $t['pass_rate'] = $scores ? (int)round(count(array_filter($scores, fn($s) => $s >= 75)) / count($scores) * 100) : null;
    $t['participation'] = $t['assigned'] > 0 ? (int)round($t['started'] / $t['assigned'] * 100) : null;
    $t['lowest'] = $scores ? min($scores) : null;
    return $t;
}

/** The same exam rows without the bulky per-student score lists. */
function admin_strip(array $exams): array {
    foreach ($exams as &$e) { unset($e['scores']); }
    unset($e);
    return $exams;
}
