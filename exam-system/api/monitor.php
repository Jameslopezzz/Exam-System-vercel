<?php
// Live Monitor data for professors + instructor actions (send warning / force submit / re-enable exam access).
// Professors: only the examinations they created, with every instructor action.
// Administrators: every examination, VIEW ONLY (students, status, violations, live camera). Warn / force-submit / re-enable stay with the professor.
require_once __DIR__ . '/bootstrap.php';
$u = require_role('professor', 'admin');
$isAdmin = $u['role'] === 'admin';
$pdo = db();

/** The submission row (with its exam) if it belongs to an exam of this professor, otherwise null. */
function owned_submission(PDO $pdo, array $u, int $subId): ?array {
    $st = $pdo->prepare('SELECT s.* FROM submissions s JOIN exams e ON e.id = s.exam_id WHERE s.id = ? AND e.professor_id = ?');
    $st->execute([$subId, $u['id']]);
    $s = $st->fetch();
    return $s ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($isAdmin) json_out(['error' => 'Administrators can watch examinations but cannot warn, force-submit or re-enable students.'], 403);
    require_post();
    $d = body();
    $act = (string)($d['action'] ?? '');
    $s = owned_submission($pdo, $u, (int)($d['submission_id'] ?? 0));
    if (!$s) json_out(['error' => 'Not found'], 404);

    if ($act === 'warn') {
        $pdo->prepare("UPDATE submissions SET pending_warning = ? WHERE id = ? AND mode = 'in-progress'")
            ->execute([cut(trim((string)($d['message'] ?? 'Your instructor has issued a warning. Stay on the exam screen.')), 250), $s['id']]);
        json_out(['ok' => true]);
    }
    if ($act === 'force_submit') {
        $answers = $s['draft_json'] ? (json_decode($s['draft_json'], true) ?: []) : [];
        json_out(finalize_submission((int)$s['id'], (int)$s['exam_id'], $answers, 'auto', 'instructor', 'Submitted by the professor (' . $u['name'] . ')'));
    }

    /* ---- Re-enable exam access for a student who was auto-submitted at the violation limit ----
       Nothing is erased: the ended attempt is archived (answers, score, violations, reason), the violation rows stay in the
       violations table tagged with their attempt, and the decision is written to access_restorations with the date/time. */
    if ($act === 'restore') {
        if (empty($d['confirm'])) json_out(['error' => 'Please confirm before access is re-enabled.'], 422);
        $mode = (string)($d['mode'] ?? '');
        if (!in_array($mode, ['resume', 'new_attempt'], true)) json_out(['error' => 'Choose whether the student resumes the exam or starts a new attempt.'], 422);
        $note = cut(trim((string)($d['note'] ?? '')), 255);

        $pdo->beginTransaction();
        $st = $pdo->prepare('SELECT s.*, TIMESTAMPDIFF(SECOND, s.started_at, s.submitted_at) AS elapsed, e.duration_minutes, e.violation_threshold,
                                    e.status AS exam_status, e.code AS exam_code, e.professor_id
                             FROM submissions s JOIN exams e ON e.id = s.exam_id WHERE s.id = ? FOR UPDATE');
        $st->execute([$s['id']]);
        $r = $st->fetch();
        if (!$r || (int)$r['professor_id'] !== (int)$u['id']) { $pdo->rollBack(); json_out(['error' => 'Not found'], 404); }
        if ($r['mode'] !== 'auto') { $pdo->rollBack(); json_out(['error' => 'This student is not auto-submitted (it may already have been re-enabled).'], 409); }

        $attempt = (int)$r['reopen_count'] + 1;
        $cv = $pdo->prepare('SELECT COUNT(*) FROM violations WHERE submission_id = ? AND attempt = ?');
        $cv->execute([$r['id'], $attempt]);
        $vcount = (int)$cv->fetchColumn();
        $lastLbl = $pdo->prepare('SELECT label FROM violations WHERE submission_id = ? ORDER BY id DESC LIMIT 1');
        $lastLbl->execute([$r['id']]);
        $why = auto_reason_info($r, $vcount, (int)$r['violation_threshold'], $lastLbl->fetchColumn() ?: null);
        if ($why['code'] !== 'violations') { $pdo->rollBack(); json_out(['error' => 'Only exams that were auto-submitted for reaching the violation limit can be re-enabled.'], 409); }
        if ($r['exam_status'] !== 'available') { $pdo->rollBack(); json_out(['error' => 'Set the examination to Available first, then re-enable access for the student.'], 409); }

        // Answers of the ended attempt, as the student submitted them.
        $an = $pdo->prepare('SELECT question_id, answer_text, is_correct, points_earned FROM answers WHERE submission_id = ? ORDER BY question_id');
        $an->execute([$r['id']]);
        $answerRows = $an->fetchAll();
        $vl = $pdo->prepare('SELECT type_id, label, severity, occurred_at FROM violations WHERE submission_id = ? AND attempt = ? ORDER BY id');
        $vl->execute([$r['id'], $attempt]);

        $remaining = null; $draft = null; $progress = 0;
        if ($mode === 'resume') {
            $remaining = (int)$r['duration_minutes'] * 60 - max(0, (int)$r['elapsed']);
            if ($remaining < 60) {
                $pdo->rollBack();
                json_out(['error' => 'Less than a minute of the exam time was left, so the student cannot resume. Choose "Start a new attempt" instead.'], 409);
            }
            $map = [];
            foreach ($answerRows as $a) if ($a['answer_text'] !== null && trim((string)$a['answer_text']) !== '') $map[(string)$a['question_id']] = $a['answer_text'];
            $draft = $map ? json_encode($map, JSON_UNESCAPED_UNICODE) : null;
            $tq = $pdo->prepare('SELECT COUNT(*) FROM questions WHERE exam_id = ?');
            $tq->execute([$r['exam_id']]);
            $total = (int)$tq->fetchColumn();
            $progress = $total > 0 ? max(0, min(99, (int)round(count($map) / $total * 100))) : 0;
        }

        $pdo->prepare('INSERT INTO attempt_history (submission_id, attempt, started_at, ended_at, mode, end_reason, end_reason_text, auto_score, max_score,
                       progress, violation_count, answers_json, violations_json, archived_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())')
            ->execute([$r['id'], $attempt, $r['started_at'], $r['submitted_at'], $r['mode'], $why['code'], cut($why['text'], 255), (int)$r['auto_score'],
                       (int)$r['max_score'], (int)$r['progress'], $vcount, json_encode($answerRows, JSON_UNESCAPED_UNICODE),
                       json_encode($vl->fetchAll(), JSON_UNESCAPED_UNICODE)]);

        $pdo->prepare('DELETE FROM answers WHERE submission_id = ?')->execute([$r['id']]);   // archived above; the student's current answers live in draft_json
        $common = "mode = 'in-progress', submitted_at = NULL, progress = ?, auto_score = 0, essay_score = NULL, max_score = 0, last_ping = NULL, last_ping_ms = NULL,
                   pending_warning = NULL, draft_json = ?, auto_reason = NULL, auto_reason_text = NULL, cam_state = NULL, cam_updated = NULL,
                   reopen_count = reopen_count + 1, reopened_at = NOW()";
        if ($mode === 'resume') {
            // Same attempt: answers come back and the clock continues with the time that was left when it was cut off.
            $pdo->prepare("UPDATE submissions SET $common, started_at = DATE_SUB(NOW(), INTERVAL ? SECOND) WHERE id = ?")
                ->execute([$progress, $draft, max(0, (int)$r['elapsed']), $r['id']]);
        } else {
            // New attempt: blank answers and a full clock. The old attempt stays in attempt_history.
            $pdo->prepare("UPDATE submissions SET $common, started_at = NOW() WHERE id = ?")->execute([0, null, $r['id']]);
        }

        $stu = $pdo->prepare('SELECT account_no, full_name FROM users WHERE id = ?');
        $stu->execute([$r['student_id']]);
        $sx = $stu->fetch() ?: ['account_no' => '', 'full_name' => ''];
        $pdo->prepare('INSERT INTO access_restorations (submission_id, exam_id, exam_code, student_id, student_no, student_name, professor_id, professor_name,
                       mode, from_attempt, to_attempt, violations_at_restore, threshold, reason, note, ip_address, created_at)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())')
            ->execute([$r['id'], $r['exam_id'], $r['exam_code'], $r['student_id'], $sx['account_no'], $sx['full_name'], $u['id'], $u['name'],
                       $mode, $attempt, $attempt + 1, $vcount, (int)$r['violation_threshold'], cut($why['text'], 255), $note !== '' ? $note : null,
                       $_SERVER['REMOTE_ADDR'] ?? null]);
        $pdo->commit();
        json_out(['ok' => true, 'attempt' => $attempt + 1, 'mode' => $mode, 'seconds_left' => $mode === 'resume' ? $remaining : (int)$r['duration_minutes'] * 60]);
    }
    json_out(['error' => 'Unknown action'], 400);
}

$examId = (int)($_GET['exam_id'] ?? 0);
if (!$examId) {   // default to the newest exam that is open or has submissions (the professor's own; for an administrator: any exam)
    if ($isAdmin) {
        $st = $pdo->query("SELECT id FROM exams ORDER BY (SELECT COUNT(*) FROM submissions s WHERE s.exam_id = exams.id AND s.mode = 'in-progress') DESC, (status = 'available') DESC, id DESC LIMIT 1");
    } else {
        $st = $pdo->prepare("SELECT id FROM exams WHERE professor_id = ? ORDER BY (status = 'available') DESC, (EXISTS (SELECT 1 FROM submissions s WHERE s.exam_id = exams.id)) DESC, id DESC LIMIT 1");
        $st->execute([$u['id']]);
    }
    $examId = (int)$st->fetchColumn();
}
if ($isAdmin) {
    $ex = $pdo->prepare('SELECT e.*, p.full_name AS professor_name, d.code AS dept_code FROM exams e JOIN users p ON p.id = e.professor_id LEFT JOIN departments d ON d.id = COALESCE(e.department_id, p.department_id) WHERE e.id = ?');
    $ex->execute([$examId]);
    $exam = $examId ? ($ex->fetch() ?: null) : null;
} else {
    $exam = $examId ? owned_exam($pdo, $u, $examId) : null;
}
if (!$exam) json_out(['students' => [], 'alerts' => [], 'exam' => null, 'restorable' => [], 'restorations' => []]);
$threshold = (int)$exam['violation_threshold'];

$st = $pdo->prepare("SELECT s.id, u.full_name AS name, u.account_no AS studentNo, u.section, s.mode, s.progress, s.last_ping, s.last_ping_ms,
    s.reopen_count, s.auto_reason, s.auto_reason_text, s.cam_state, s.cam_updated, s.submitted_at,
    TIMESTAMPDIFF(SECOND, s.started_at, s.submitted_at) AS elapsed,
    " . cur_violations_sql('s') . " AS violations,
    " . all_violations_sql('s') . " AS total_violations,
    (SELECT v.label FROM violations v WHERE v.submission_id = s.id ORDER BY v.id DESC LIMIT 1) AS lastLabel,
    (SELECT CONCAT(v.label, ' · ', DATE_FORMAT(v.occurred_at, '%l:%i %p')) FROM violations v WHERE v.submission_id = s.id ORDER BY v.id DESC LIMIT 1) AS lastFlag
    FROM submissions s JOIN users u ON u.id = s.student_id WHERE s.exam_id = ? ORDER BY u.full_name");
$st->execute([$examId]);

$students = []; $restorable = [];
foreach ($st->fetchAll() as $r) {
    $viol = (int)$r['violations'];
    $status = $r['mode'] === 'auto' ? 'auto-submitted' : ($r['mode'] === 'manual' ? 'submitted' : ($viol > 0 ? 'flagged' : 'in-progress'));
    $active = $r['mode'] === 'in-progress';
    $online = $active && $r['last_ping'] && (time() - strtotime($r['last_ping'])) < 30;
    $row = ['id' => (int)$r['id'], 'name' => $r['name'], 'studentNo' => $r['studentNo'], 'section' => $r['section'],
            'progress' => (int)$r['progress'], 'violations' => $viol, 'totalViolations' => (int)$r['total_violations'], 'lastFlag' => $r['lastFlag'] ?: '—',
            'status' => $status, 'online' => $online, 'pingMs' => $r['last_ping_ms'] !== null ? (int)$r['last_ping_ms'] : null,
            'cam' => $active ? ($r['cam_state'] ?: 'unknown') : 'n/a', 'attempt' => (int)$r['reopen_count'] + 1,
            'reopened' => (int)$r['reopen_count'], 'exam' => $exam['code'] . ' ' . $exam['title']];
    if ($r['mode'] === 'auto') {
        $why = auto_reason_info($r, $viol, $threshold, $r['lastLabel']);
        $row['autoReason'] = $why['text'];
        if ($why['code'] === 'violations' && !$isAdmin) {
            $left = (int)$exam['duration_minutes'] * 60 - max(0, (int)$r['elapsed']);
            $restorable[] = ['id' => $row['id'], 'name' => $row['name'], 'studentNo' => $row['studentNo'], 'section' => $row['section'],
                             'violations' => $viol, 'threshold' => $threshold, 'reason' => $why['text'], 'status' => 'auto-submitted',
                             'submittedAt' => $r['submitted_at'], 'attempt' => $row['attempt'], 'restoredBefore' => (int)$r['reopen_count'],
                             'examStatus' => $exam['status'], 'canRestore' => $exam['status'] === 'available',
                             'canResume' => $left >= 60, 'resumeSecondsLeft' => max(0, $left)];
        }
    }
    $students[] = $row;
}

$al = $pdo->prepare("SELECT v.id, u.full_name AS student, v.label AS type, v.severity, v.attempt, DATE_FORMAT(v.occurred_at, '%l:%i:%s %p') AS time
    FROM violations v JOIN submissions s ON s.id = v.submission_id JOIN users u ON u.id = s.student_id
    WHERE s.exam_id = ? ORDER BY v.id DESC LIMIT 25");
$al->execute([$examId]);

$hist = $pdo->prepare("SELECT id, student_no AS studentNo, student_name AS student, professor_name AS professor, mode, from_attempt AS fromAttempt, to_attempt AS toAttempt,
    violations_at_restore AS violations, threshold, reason, note, DATE_FORMAT(created_at, '%b %e, %Y %l:%i:%s %p') AS at
    FROM access_restorations WHERE exam_id = ? ORDER BY id DESC LIMIT 50");
$hist->execute([$examId]);

json_out(['exam' => ['id' => (int)$exam['id'], 'code' => $exam['code'], 'title' => $exam['title'], 'status' => $exam['status'],
                     'violation_threshold' => $threshold, 'duration_minutes' => (int)$exam['duration_minutes'],
                     'professor' => $exam['professor_name'] ?? null, 'dept_code' => $exam['dept_code'] ?? null],
          'read_only' => $isAdmin,
          'students' => $students, 'alerts' => $al->fetchAll(), 'restorable' => $restorable, 'restorations' => $hist->fetchAll(),
          'server_time' => date('c')]);
