<?php
// Administrator: system-wide monitoring across departments and year levels. READ-ONLY.
// No video, no warnings, no force-submit: those belong to the professor of each exam, so admin monitoring never interferes with them.
require_once __DIR__ . '/admin_lib.php';
$me = require_role('admin');
$pdo = db();

$f = admin_filters();
$exams = admin_load($f, true)['exams'];

$summary = admin_sum($exams);
unset($summary['pass_rate'], $summary['lowest']);
$summary['online'] = 0;

// Student rows: everyone who has started (or, when asked for explicitly, those who are pending / absent).
$students = [];
foreach ($exams as $e) {
    foreach ($e['roster'] as $r) {
        if ($r['online']) $summary['online']++;
        $want = $f['student_status'] !== '' ? $r['state'] === $f['student_status'] : in_array($r['state'], ['ongoing', 'completed', 'auto-submitted'], true);
        if ($want) $students[] = $r;
    }
}
// Ongoing first, then auto-submitted, then completed; newest activity at the top inside each group.
$rank = ['ongoing' => 0, 'auto-submitted' => 1, 'completed' => 2, 'pending' => 3, 'absent' => 4, 'not-open' => 5];
$ts = fn($v) => $v ? (int)strtotime((string)$v) : 0;
usort($students, fn($a, $b) => [$rank[$a['state']], -$ts($a['submitted_at'])] <=> [$rank[$b['state']], -$ts($b['submitted_at'])]);
$truncated = count($students) > 400;
$students = array_slice($students, 0, 400);

// Exam cards: exams that are open right now first.
$exRows = admin_strip($exams);
usort($exRows, fn($a, $b) => [($a['status'] === 'available' ? 0 : 1), -$a['ongoing']] <=> [($b['status'] === 'available' ? 0 : 1), -$b['ongoing']]);

// Latest violations inside the same scope.
$alerts = [];
if ($exams) {
    $in = implode(',', array_map(fn($e) => (int)$e['id'], $exams));
    $sql = "SELECT v.id, u.full_name AS student, u.account_no AS studentNo, u.year_level, e.code AS exam, d.code AS dept, v.label AS type, v.severity, v.attempt,
                   DATE_FORMAT(v.occurred_at, '%b %e %l:%i:%s %p') AS time
            FROM violations v JOIN submissions s ON s.id = v.submission_id JOIN users u ON u.id = s.student_id
            JOIN exams e ON e.id = s.exam_id JOIN users pr ON pr.id = e.professor_id LEFT JOIN departments d ON d.id = COALESCE(e.department_id, pr.department_id)
            WHERE s.exam_id IN ($in)" . ($f['year_level'] !== '' ? ' AND u.year_level = ?' : '') . " ORDER BY v.id DESC LIMIT 40";
    $st = $pdo->prepare($sql);
    $st->execute($f['year_level'] !== '' ? [$f['year_level']] : []);
    $alerts = $st->fetchAll();
}
json_out(['summary' => $summary, 'exams' => $exRows, 'students' => $students, 'truncated' => $truncated, 'alerts' => $alerts, 'filters' => $f, 'server_time' => date('c')]);
