<?php
// Student's own results history. A score (and the professor's feedback) is only included once the professor released it;
// until then the row says "pending release" and carries no numbers at all.
require_once __DIR__ . '/bootstrap.php';
$u = require_role('student');
$st = db()->prepare("SELECT e.id AS exam_id, e.code, e.title, e.release_score, e.release_answers, DATE_FORMAT(s.submitted_at, '%b %e, %Y') AS date,
    s.auto_score, s.essay_score, s.max_score, s.mode,
    (SELECT COUNT(*) FROM questions q WHERE q.exam_id = e.id AND q.type = 'essay') AS essays,
    " . all_violations_sql('s') . " AS violations
    FROM submissions s JOIN exams e ON e.id = s.exam_id WHERE s.student_id = ? AND s.mode <> 'in-progress' ORDER BY s.submitted_at DESC");
$st->execute([$u['id']]);
$rows = array_map(function ($r) {
    $remark = $r['mode'] === 'auto' ? 'Auto-submitted' : ((int)$r['violations'] ? 'Warned' : 'Clean');
    $base = ['exam_id' => (int)$r['exam_id'], 'code' => $r['code'], 'title' => $r['title'], 'date' => $r['date'], 'violations' => (int)$r['violations'], 'remark' => $remark,
             'released' => (int)$r['release_score'] === 1, 'answers_released' => (int)$r['release_answers'] === 1];
    if (!$base['released']) {
        return $base + ['points' => null, 'max' => null, 'percent' => null, 'score' => 'Pending release'];
    }
    $pending = (int)$r['essays'] > 0 && $r['essay_score'] === null;
    $points = (int)$r['auto_score'] + (int)$r['essay_score'];
    $max = (int)$r['max_score'];
    return $base + ['points' => $points, 'max' => $max, 'percent' => $max > 0 ? round($points / $max * 100) : null,
                    'score' => $points . ' / ' . $max . ($pending ? ' (essays pending)' : '')];
}, $st->fetchAll());
json_out(['results' => $rows]);
