<?php
require_once __DIR__ . '/bootstrap.php';
require_post();
$u = require_role('student');
$d = body();
$examId = (int)($d['exam_id'] ?? 0);

$pdo = db();
$st = $pdo->prepare('SELECT s.id, s.reopen_count, e.violation_threshold FROM submissions s JOIN exams e ON e.id = s.exam_id WHERE s.exam_id = ? AND s.student_id = ?');
$st->execute([$examId, $u['id']]);
$s = $st->fetch();
if (!$s) json_out(['error' => 'No exam session found.'], 404);

$mode = ($d['mode'] ?? 'manual') === 'auto' ? 'auto' : 'manual';
$answers = is_array($d['answers'] ?? null) ? $d['answers'] : [];

// The reason of an automatic submission is decided here from the stored violations, never taken from the browser.
$reason = null; $reasonText = null;
if ($mode === 'auto') {
    $c = $pdo->prepare('SELECT COUNT(*) FROM violations WHERE submission_id = ? AND attempt = ?');
    $c->execute([$s['id'], (int)$s['reopen_count'] + 1]);
    $count = (int)$c->fetchColumn();
    if ($count >= (int)$s['violation_threshold']) {
        $reason = 'violations';
        $reasonText = 'Reached the maximum of ' . (int)$s['violation_threshold'] . ' violations';
    } elseif (($d['reason'] ?? '') === 'time') {
        $reason = 'time'; $reasonText = 'The exam time ran out';
    } else {
        $reason = 'other'; $reasonText = 'Submitted automatically';
    }
}
json_out(finalize_submission((int)$s['id'], $examId, $answers, $mode, $reason, $reasonText));
