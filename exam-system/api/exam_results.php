<?php
// Examination form, student results and review - with the access rules enforced HERE, on the server.
//
//   Staff (professor / admin)
//     form    questions, choices, media and - when the caller may see it - the correct answers
//     list    every student's result for one exam
//     review  one student's submitted answers next to the correct answers, with points
//     grade   (professor) points for essay answers + feedback for the student
//     release (professor) which parts students may see: score + feedback, and the answers with the correct answers
//   Student
//     my_review  only the student's OWN submission, and only what the release settings allow
//
// Who may see what:
//   admin       every exam, read-only, answer key included
//   professor   exams they created or that an administrator allowed them to edit (answer key included); never other professors' answers
//   student     their own result once the professor released the score; their answers + the correct answers only once those were released,
//               and the correct answers are never part of any response before that
require_once __DIR__ . '/bootstrap.php';
$u = require_role('student', 'professor', 'admin');
$pdo = db();
$action = $_GET['action'] ?? '';

function load_exam(PDO $pdo, int $id): array {
    $st = $pdo->prepare('SELECT e.*, p.full_name AS professor, d.code AS dept_code FROM exams e JOIN users p ON p.id = e.professor_id LEFT JOIN departments d ON d.id = e.department_id WHERE e.id = ?');
    $st->execute([$id]);
    $e = $st->fetch();
    if (!$e) json_out(['error' => 'Examination not found.'], 404);
    return $e;
}

/**
 * Staff access to one exam: ['exam' => row, 'can_grade' => bool].
 * Ends the request with 403 when this staff member may not see the exam's answers.
 */
function staff_exam_access(PDO $pdo, array $u, int $examId): array {
    if ($u['role'] === 'student') json_out(['error' => 'Access denied.'], 403);
    $e = load_exam($pdo, $examId);
    if ($u['role'] === 'admin') return ['exam' => $e, 'can_grade' => false];
    $perm = exam_permissions($pdo, $u, $e);
    if (!$perm['can_edit']) json_out(['error' => 'You can only see the results of examinations you created or that an administrator allowed you to manage.'], 403);
    return ['exam' => $e, 'can_grade' => true];
}

function pct($points, $max): ?int { return (int)$max > 0 ? (int)round($points / $max * 100) : null; }

/** Questions of an exam, with or without the answer key. */
function exam_questions(PDO $pdo, int $examId, bool $withKey): array {
    $st = $pdo->prepare('SELECT id, position, type, prompt, points, choices_json, correct_answer, media_json FROM questions WHERE exam_id = ? ORDER BY position, id');
    $st->execute([$examId]);
    return array_map(function ($q) use ($withKey) {
        $row = ['id' => (int)$q['id'], 'position' => (int)$q['position'], 'type' => $q['type'], 'prompt' => $q['prompt'], 'points' => (int)$q['points'],
                'choices' => $q['choices_json'] ? json_decode($q['choices_json'], true) : [],
                'media' => $q['media_json'] ? json_decode($q['media_json'], true) : null];
        if ($withKey) $row['correct'] = $q['correct_answer'];
        return $row;
    }, $st->fetchAll());
}

/** The student's answers of one submission, keyed by question id. */
function submission_answers(PDO $pdo, int $subId): array {
    $st = $pdo->prepare('SELECT question_id, answer_text, is_correct, points_earned FROM answers WHERE submission_id = ?');
    $st->execute([$subId]);
    $map = [];
    foreach ($st->fetchAll() as $a) $map[(int)$a['question_id']] = $a;
    return $map;
}

/** Question list merged with one submission's answers. $withKey adds the correct answer and whether the student got it right. */
function review_rows(PDO $pdo, array $exam, int $subId, bool $withKey): array {
    $answers = submission_answers($pdo, $subId);
    $rows = [];
    foreach (exam_questions($pdo, (int)$exam['id'], $withKey) as $q) {
        $a = $answers[$q['id']] ?? null;
        $row = $q + ['answer' => $a ? $a['answer_text'] : null, 'points_earned' => $a ? (int)$a['points_earned'] : 0];
        if ($withKey) $row['is_correct'] = $a && $a['is_correct'] !== null ? (bool)(int)$a['is_correct'] : null;
        $rows[] = $row;
    }
    return $rows;
}

function exam_meta(array $e): array {
    return ['id' => (int)$e['id'], 'code' => $e['code'], 'title' => $e['title'], 'subject' => $e['subject'], 'status' => $e['status'],
            'professor' => $e['professor'], 'dept_code' => $e['dept_code'], 'duration' => (int)$e['duration_minutes'],
            'release_score' => (bool)(int)$e['release_score'], 'release_answers' => (bool)(int)$e['release_answers'], 'results_released_at' => $e['results_released_at']];
}

/* ========================================= student ========================================= */
if ($u['role'] === 'student') {
    if ($action !== 'my_review') json_out(['error' => 'Access denied.'], 403);
    $examId = (int)($_GET['exam_id'] ?? 0);
    // Always the signed-in student's OWN submission: there is no way to ask for somebody else's.
    $st = $pdo->prepare("SELECT * FROM submissions WHERE exam_id = ? AND student_id = ? AND mode <> 'in-progress'");
    $st->execute([$examId, $u['id']]);
    $sub = $st->fetch();
    if (!$sub) json_out(['error' => 'You have no submitted attempt for this examination.'], 404);
    $e = load_exam($pdo, $examId);
    if (!(int)$e['release_score']) json_out(['error' => 'Your professor has not released the results of this examination yet.', 'not_released' => true], 403);

    $ec = $pdo->prepare("SELECT COUNT(*) FROM questions WHERE exam_id = ? AND type = 'essay'");
    $ec->execute([$examId]);
    $hasEssay = (int)$ec->fetchColumn() > 0;
    $pending = $hasEssay && $sub['essay_score'] === null;
    $points = (int)$sub['auto_score'] + (int)$sub['essay_score'];
    $out = ['exam' => ['id' => (int)$e['id'], 'code' => $e['code'], 'title' => $e['title'], 'subject' => $e['subject'], 'professor' => $e['professor']],
            'score' => ['points' => $points, 'max' => (int)$sub['max_score'], 'percent' => pct($points, $sub['max_score']), 'essays_pending' => $pending],
            'feedback' => $sub['feedback'], 'answers_released' => false, 'submitted_at' => $sub['submitted_at']];
    if ((int)$e['release_answers']) {
        $out['answers_released'] = true;
        $out['questions'] = review_rows($pdo, $e, (int)$sub['id'], true);
    }
    json_out($out);
}

/* ========================================== staff ========================================== */
if ($action === 'form') {
    $acc = staff_exam_access($pdo, $u, (int)($_GET['exam_id'] ?? 0));
    $e = $acc['exam'];
    $qs = exam_questions($pdo, (int)$e['id'], true);
    json_out(['exam' => exam_meta($e) + ['description' => (string)$e['description'], 'total_points' => array_sum(array_column($qs, 'points'))],
              'questions' => $qs, 'can_grade' => $acc['can_grade'], 'read_only' => !$acc['can_grade']]);
}

if ($action === 'list') {
    $acc = staff_exam_access($pdo, $u, (int)($_GET['exam_id'] ?? 0));
    $e = $acc['exam'];
    $st = $pdo->prepare("SELECT s.id, s.mode, s.auto_score, s.essay_score, s.max_score, s.submitted_at, s.feedback, s.graded_at, s.reopen_count,
            u.account_no, u.full_name, u.section, u.year_level, " . all_violations_sql('s') . " AS violations
        FROM submissions s JOIN users u ON u.id = s.student_id WHERE s.exam_id = ? ORDER BY u.full_name");
    $st->execute([(int)$e['id']]);
    $ec = $pdo->prepare("SELECT COUNT(*) FROM questions WHERE exam_id = ? AND type = 'essay'");
    $ec->execute([(int)$e['id']]);
    $hasEssay = (int)$ec->fetchColumn() > 0;

    $rows = []; $percents = [];
    foreach ($st->fetchAll() as $r) {
        $done = $r['mode'] !== 'in-progress';
        $points = (int)$r['auto_score'] + (int)$r['essay_score'];
        $pending = $done && $hasEssay && $r['essay_score'] === null;
        $p = $done ? pct($points, $r['max_score']) : null;
        if ($p !== null) $percents[] = $p;
        $rows[] = ['submission_id' => (int)$r['id'], 'account_no' => $r['account_no'], 'name' => $r['full_name'], 'section' => $r['section'], 'year_level' => $r['year_level'],
                   'status' => $r['mode'] === 'auto' ? 'auto-submitted' : ($r['mode'] === 'manual' ? 'submitted' : 'in-progress'),
                   'points' => $done ? $points : null, 'max' => (int)$r['max_score'], 'percent' => $p, 'essays_pending' => $pending,
                   'violations' => (int)$r['violations'], 'attempts' => (int)$r['reopen_count'] + 1, 'submitted_at' => $r['submitted_at'],
                   'has_feedback' => $r['feedback'] !== null && $r['feedback'] !== '', 'graded_at' => $r['graded_at']];
    }
    json_out(['exam' => exam_meta($e) + ['has_essay' => $hasEssay], 'submissions' => $rows, 'can_grade' => $acc['can_grade'],
              'stats' => ['count' => count($percents), 'average' => $percents ? (int)round(array_sum($percents) / count($percents)) : null,
                          'highest' => $percents ? max($percents) : null, 'lowest' => $percents ? min($percents) : null]]);
}

if ($action === 'review') {
    $subId = (int)($_GET['submission_id'] ?? 0);
    $st = $pdo->prepare('SELECT s.*, u.account_no, u.full_name, u.section, u.year_level FROM submissions s JOIN users u ON u.id = s.student_id WHERE s.id = ?');
    $st->execute([$subId]);
    $sub = $st->fetch();
    if (!$sub) json_out(['error' => 'Submission not found.'], 404);
    $acc = staff_exam_access($pdo, $u, (int)$sub['exam_id']);   // same exam rule as the list
    if ($sub['mode'] === 'in-progress') json_out(['error' => 'This student is still taking the examination.'], 409);
    $e = $acc['exam'];
    $rows = review_rows($pdo, $e, $subId, true);
    $hasEssay = (bool)array_filter($rows, fn($q) => $q['type'] === 'essay');
    $points = (int)$sub['auto_score'] + (int)$sub['essay_score'];
    $vc = $pdo->prepare('SELECT label, severity, attempt, DATE_FORMAT(occurred_at, \'%b %e %l:%i:%s %p\') AS at FROM violations WHERE submission_id = ? ORDER BY id');
    $vc->execute([$subId]);
    json_out(['exam' => exam_meta($e),
              'student' => ['account_no' => $sub['account_no'], 'name' => $sub['full_name'], 'section' => $sub['section'], 'year_level' => $sub['year_level']],
              'submission' => ['id' => $subId, 'status' => $sub['mode'] === 'auto' ? 'auto-submitted' : 'submitted', 'submitted_at' => $sub['submitted_at'], 'attempt' => (int)$sub['reopen_count'] + 1,
                               'auto_reason' => $sub['auto_reason_text'], 'feedback' => $sub['feedback'], 'graded_at' => $sub['graded_at']],
              'score' => ['points' => $points, 'auto' => (int)$sub['auto_score'], 'essay' => $sub['essay_score'] === null ? null : (int)$sub['essay_score'],
                          'max' => (int)$sub['max_score'], 'percent' => pct($points, $sub['max_score']), 'essays_pending' => $hasEssay && $sub['essay_score'] === null],
              'questions' => $rows, 'violations' => $vc->fetchAll(), 'can_grade' => $acc['can_grade']]);
}

require_post();
$d = body();

/* ---- Professor: points for essay answers and a comment for the student ---- */
if ($action === 'grade') {
    $subId = (int)($d['submission_id'] ?? 0);
    $st = $pdo->prepare('SELECT * FROM submissions WHERE id = ?');
    $st->execute([$subId]);
    $sub = $st->fetch();
    if (!$sub) json_out(['error' => 'Submission not found.'], 404);
    $acc = staff_exam_access($pdo, $u, (int)$sub['exam_id']);
    if (!$acc['can_grade']) json_out(['error' => 'Administrators have read-only access to results.'], 403);
    if ($sub['mode'] === 'in-progress') json_out(['error' => 'This student is still taking the examination.'], 409);
    $e = $acc['exam'];

    $qs = $pdo->prepare("SELECT id, points FROM questions WHERE exam_id = ? AND type = 'essay'");
    $qs->execute([(int)$e['id']]);
    $essay = [];
    foreach ($qs->fetchAll() as $q) $essay[(int)$q['id']] = (int)$q['points'];

    $given = is_array($d['points'] ?? null) ? $d['points'] : [];
    foreach ($given as $qid => $pts) {
        if (!isset($essay[(int)$qid])) json_out(['error' => 'One of the graded questions is not an essay of this examination.'], 422);
        if (!is_numeric($pts) || (int)$pts < 0 || (int)$pts > $essay[(int)$qid]) {
            json_out(['error' => 'Essay points must be between 0 and ' . $essay[(int)$qid] . ' for that question.'], 422);
        }
    }
    $feedback = cut(trim((string)($d['feedback'] ?? '')), 2000);

    $pdo->beginTransaction();
    $upd = $pdo->prepare('UPDATE answers SET points_earned = ? WHERE submission_id = ? AND question_id = ?');
    foreach ($given as $qid => $pts) $upd->execute([(int)$pts, $subId, (int)$qid]);
    $sum = $pdo->prepare("SELECT COALESCE(SUM(a.points_earned), 0) FROM answers a JOIN questions q ON q.id = a.question_id WHERE a.submission_id = ? AND q.type = 'essay'");
    $sum->execute([$subId]);
    $essayScore = (int)$sum->fetchColumn();
    if ($essay) {   // an exam with essays: grading (even a 0) ends the "pending" state
        $pdo->prepare('UPDATE submissions SET essay_score = ?, feedback = ?, graded_by = ?, graded_at = NOW() WHERE id = ?')
            ->execute([$essayScore, $feedback !== '' ? $feedback : null, $u['id'], $subId]);
    } else {
        $pdo->prepare('UPDATE submissions SET feedback = ?, graded_by = ?, graded_at = NOW() WHERE id = ?')->execute([$feedback !== '' ? $feedback : null, $u['id'], $subId]);
    }
    $pdo->commit();

    if ((int)$e['release_score']) {   // results are already visible: tell the student that something changed
        $total = (int)$sub['auto_score'] + $essayScore;
        notify_user((int)$sub['student_id'], 'result', 'Your result was updated',
            $e['code'] . ' · ' . $e['title'] . ': your professor graded your answers' . ($feedback !== '' ? ' and left feedback' : '') . '.',
            'student-results.html', 'exam' . (int)$e['id'] . ':graded:' . $subId . ':' . $total . ':' . md5($feedback));
    }
    json_out(['ok' => true, 'essay_score' => $essay ? $essayScore : null]);
}

/* ---- Professor: choose what students may see ---- */
if ($action === 'release') {
    $acc = staff_exam_access($pdo, $u, (int)($d['exam_id'] ?? 0));
    if (!$acc['can_grade']) json_out(['error' => 'Administrators have read-only access to results.'], 403);
    $e = $acc['exam'];
    $score = !empty($d['release_score']) ? 1 : 0;
    $answers = !empty($d['release_answers']) ? 1 : 0;
    if ($answers) $score = 1;   // the answers cannot be shown without the score
    if ($answers && $e['status'] !== 'closed') {
        // Releasing the answer key while the exam can still be started would hand the answers to students who have not taken it yet.
        json_out(['error' => 'The correct answers can only be released after the examination is Closed.'], 422);
    }
    $now = $pdo->prepare('UPDATE exams SET release_score = ?, release_answers = ?, results_released_at = IF(? = 1 AND results_released_at IS NULL, NOW(), results_released_at) WHERE id = ?');
    $now->execute([$score, $answers, $score, (int)$e['id']]);

    $takers = $pdo->prepare("SELECT student_id FROM submissions WHERE exam_id = ? AND mode <> 'in-progress'");
    $takers->execute([(int)$e['id']]);
    $ids = array_map('intval', $takers->fetchAll(PDO::FETCH_COLUMN));
    $n = 0;
    if ($score && !(int)$e['release_score']) {
        $n = notify_users($ids, 'result', 'Exam results released', $e['code'] . ' · ' . $e['title'] . ': your score is now available.', 'student-results.html', 'exam' . (int)$e['id'] . ':release_score');
    }
    if ($answers && !(int)$e['release_answers']) {
        notify_users($ids, 'result', 'Exam answers released', $e['code'] . ' · ' . $e['title'] . ': you can now review your answers with the correct answers.', 'student-results.html', 'exam' . (int)$e['id'] . ':release_answers');
    }
    json_out(['ok' => true, 'release_score' => (bool)$score, 'release_answers' => (bool)$answers, 'notified' => $n]);
}
json_out(['error' => 'Unknown action.'], 400);
