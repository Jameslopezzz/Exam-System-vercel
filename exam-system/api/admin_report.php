<?php
// Administrator: reports across departments, year levels, professors and examinations (+ CSV export). READ-ONLY.
require_once __DIR__ . '/admin_lib.php';
$me = require_role('admin');
$pdo = db();

$f = admin_filters();
$exams = admin_load($f, true)['exams'];

/** Flat student rows of every exam in scope, optionally limited to one student state. */
function report_rows(array $exams, array $f): array {
    $rows = [];
    foreach ($exams as $e) foreach ($e['roster'] as $r) {
        if ($f['student_status'] !== '' && $r['state'] !== $f['student_status']) continue;
        if ($f['student_status'] === '' && in_array($r['state'], ['pending', 'absent', 'not-open'], true)) continue;   // default: students who started
        $rows[] = $r;
    }
    return $rows;
}

$stateLabel = ['completed' => 'Completed', 'auto-submitted' => 'Auto-submitted', 'ongoing' => 'Ongoing', 'pending' => 'Pending', 'absent' => 'Did not take it', 'not-open' => 'Not open yet'];

/* ---------------------------------- CSV export ---------------------------------- */
if (($_GET['export'] ?? '') === 'students' || ($_GET['export'] ?? '') === 'exams') {
    $kind = $_GET['export'];
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="admin_' . $kind . '_report_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    if ($kind === 'exams') {
        fputcsv($out, ['Exam code', 'Title', 'Department', 'Professor', 'Status', 'Year levels', 'Assigned', 'Started', 'Completed', 'Auto-submitted', 'Ongoing', 'Pending', 'Did not take it', 'Violations', 'Average %']);
        foreach ($exams as $e) {
            fputcsv($out, [csv_cell($e['code']), csv_cell($e['title']), csv_cell($e['dept_code']), csv_cell($e['professor']), $e['status'],
                           csv_cell(implode('; ', array_map(fn($y) => $y['year'] . ' (' . $y['students'] . ')', $e['year_levels']))),
                           $e['assigned'], $e['started'], $e['completed'], $e['auto_submitted'], $e['ongoing'], $e['pending'], $e['absent'], $e['violations'],
                           $e['avg_score'] === null ? '' : $e['avg_score']]);
        }
    } else {
        fputcsv($out, ['Exam code', 'Exam', 'Department', 'Professor', 'Account No', 'Student', 'Year level', 'Section', 'Status', 'Progress %', 'Violations (all attempts)', 'Attempts', 'Score %', 'Submitted at']);
        foreach (report_rows($exams, $f) as $r) {
            fputcsv($out, [csv_cell($r['exam_code']), csv_cell($r['exam_title']), csv_cell($r['dept']), csv_cell($r['professor']), csv_cell($r['account_no']), csv_cell($r['name']),
                           csv_cell($r['year_level']), csv_cell($r['section']), $stateLabel[$r['state']] ?? $r['state'], $r['progress'], $r['violations'], $r['attempts'],
                           $r['score'] === null ? '' : $r['score'], $r['submitted_at']]);
        }
    }
    fclose($out);
    exit;
}

/* ----------------------------------- JSON report ----------------------------------- */
$summary = admin_sum($exams);
$rows = report_rows($exams, $f);

// Breakdown per department and per year level (the students' own year level, even when the exam mixes several).
$byDept = []; $byYear = [];
$add = function (array &$bucket, string $key, array $r) {
    if (!isset($bucket[$key])) $bucket[$key] = ['label' => $key, 'students' => 0, 'completed' => 0, 'auto_submitted' => 0, 'ongoing' => 0, 'violations' => 0, 'scores' => []];
    $b = &$bucket[$key];
    $b['students']++;
    if ($r['state'] === 'completed') $b['completed']++;
    if ($r['state'] === 'auto-submitted') $b['auto_submitted']++;
    if ($r['state'] === 'ongoing') $b['ongoing']++;
    $b['violations'] += $r['violations'];
    if ($r['score'] !== null) $b['scores'][] = $r['score'];
};
foreach ($rows as $r) {
    $add($byDept, $r['dept'] ?: 'Unassigned', $r);
    $add($byYear, $r['year_level'] ?: 'Not set', $r);
}
$finish = function (array $bucket, array $order = []): array {
    $out = [];
    foreach ($bucket as $b) { $b['avg_score'] = $b['scores'] ? (int)round(array_sum($b['scores']) / count($b['scores'])) : null; unset($b['scores']); $out[] = $b; }
    if ($order) usort($out, fn($a, $b) => (array_search($a['label'], $order) === false ? 99 : array_search($a['label'], $order)) <=> (array_search($b['label'], $order) === false ? 99 : array_search($b['label'], $order)));
    else usort($out, fn($a, $b) => strcmp($a['label'], $b['label']));
    return $out;
};

$bins = ['0-59' => 0, '60-69' => 0, '70-79' => 0, '80-89' => 0, '90-100' => 0];
foreach ($rows as $r) {
    if ($r['score'] === null) continue;
    $p = $r['score'];
    $bins[$p < 60 ? '0-59' : ($p < 70 ? '60-69' : ($p < 80 ? '70-79' : ($p < 90 ? '80-89' : '90-100')))]++;
}

$labels = ['face-angle' => 'Face angle', 'eye-gaze' => 'Eye gaze', 'multiple-faces' => 'Multiple faces', 'no-face' => 'No face', 'copy-paste' => 'Copy / paste', 'tab-switch' => 'Tab switch'];
$byType = [];
if ($exams) {
    $in = implode(',', array_map(fn($e) => (int)$e['id'], $exams));
    $st = $pdo->prepare("SELECT v.type_id, COUNT(*) c FROM violations v JOIN submissions s ON s.id = v.submission_id JOIN users u ON u.id = s.student_id
        WHERE s.exam_id IN ($in)" . ($f['year_level'] !== '' ? ' AND u.year_level = ?' : '') . ' GROUP BY v.type_id ORDER BY c DESC');
    $st->execute($f['year_level'] !== '' ? [$f['year_level']] : []);
    foreach ($st->fetchAll() as $r) $byType[] = ['label' => $labels[$r['type_id']] ?? $r['type_id'], 'value' => (int)$r['c']];
}

$truncated = count($rows) > 1000;
json_out(['summary' => $summary, 'by_department' => $finish($byDept), 'by_year' => $finish($byYear, YEAR_LEVELS), 'by_exam' => admin_strip($exams),
          'score_bins' => $bins, 'violations_by_type' => $byType, 'students' => array_slice($rows, 0, 1000), 'truncated' => $truncated, 'filters' => $f]);
