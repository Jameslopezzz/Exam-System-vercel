<?php
// Violation Logger + Submission Engine: logs each flagged event and force-submits at the threshold.
require_once __DIR__ . '/bootstrap.php';
require_post();
$u = require_role('student');
$d = body();
$pdo = db();

$st = $pdo->prepare("SELECT s.id, s.exam_id, s.reopen_count, e.violation_threshold FROM submissions s JOIN exams e ON e.id = s.exam_id
                     WHERE s.exam_id = ? AND s.student_id = ? AND s.mode = 'in-progress'");
$st->execute([(int)($d['exam_id'] ?? 0), $u['id']]);
$s = $st->fetch();
if (!$s) json_out(['error' => 'No active exam session.', 'closed' => true], 409);
$attempt = (int)$s['reopen_count'] + 1;   // violations are counted per attempt; earlier attempts stay on record

$allowed = ['face-angle', 'eye-gaze', 'multiple-faces', 'no-face', 'copy-paste', 'tab-switch'];
$type = in_array($d['type_id'] ?? '', $allowed, true) ? $d['type_id'] : 'copy-paste';
$label = cut(trim((string)($d['label'] ?? $type)), 150);

$pdo->prepare('INSERT INTO violations (submission_id, type_id, label, severity, attempt) VALUES (?,?,?,?,?)')
    ->execute([$s['id'], $type, $label, violation_severity($type), $attempt]);
$violationId = (int)$pdo->lastInsertId();
$cnt = $pdo->prepare('SELECT COUNT(*) FROM violations WHERE submission_id = ? AND attempt = ?');
$cnt->execute([$s['id'], $attempt]);
$count = (int)$cnt->fetchColumn();

// Tell the exam's professor(s) about the possible cheating (one notification per recorded violation).
try {
    $ex = $pdo->prepare('SELECT * FROM exams WHERE id = ?');
    $ex->execute([(int)$s['exam_id']]);
    $exam = $ex->fetch();
    if ($exam) {
        notify_users(exam_staff_ids($pdo, $exam), 'violation', 'Possible cheating detected',
            $u['name'] . ' (' . $u['account_no'] . ') in ' . $exam['code'] . ' · ' . $exam['title'] . ': ' . $label . ' (' . $count . '/' . (int)$s['violation_threshold'] . ').',
            'professor-monitoring.html?exam_id=' . (int)$s['exam_id'], 'viol:' . $violationId);
    }
} catch (Throwable $t) { error_log('[exam-system] violation notification failed: ' . $t->getMessage()); }

$auto = false; $result = null;
if ($count >= (int)$s['violation_threshold']) {
    $answers = is_array($d['answers'] ?? null) ? $d['answers'] : [];
    $reason = 'Reached the maximum of ' . (int)$s['violation_threshold'] . ' violations (last: ' . $label . ')';
    $result = finalize_submission((int)$s['id'], (int)$s['exam_id'], $answers, 'auto', 'violations', $reason);
    $auto = true;
}
json_out(['count' => $count, 'threshold' => (int)$s['violation_threshold'], 'auto_submitted' => $auto, 'result' => $result]);
