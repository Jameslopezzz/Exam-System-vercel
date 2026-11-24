<?php
// Administrator: dashboard overview, exam management list and exam details. An admin never edits an exam's content - editing belongs
// to the professor who created it. The one thing an admin CAN do is allow another professor to edit (or delete) an exam: editors / grant_editor / revoke_editor.
require_once __DIR__ . '/admin_lib.php';
$me = require_role('admin');
$pdo = db();
$action = $_GET['action'] ?? 'list';

if ($action === 'options') json_out(admin_filter_options());

if ($action === 'overview') {
    $f = admin_filters();
    $exams = admin_load($f)['exams'];
    $total = admin_sum($exams);

    $examStatus = ['draft' => 0, 'scheduled' => 0, 'available' => 0, 'closed' => 0];
    $byDept = []; $byYear = [];
    foreach ($exams as $e) {
        $examStatus[$e['status']]++;
        $k = $e['dept_code'];
        if (!isset($byDept[$k])) $byDept[$k] = ['code' => $k, 'name' => $e['dept_name'], 'department_id' => $e['department_id'], 'exams' => [], 'professors' => []];
        $byDept[$k]['exams'][] = $e;
        $byDept[$k]['professors'][$e['professor_id']] = true;
        foreach ($e['year_levels'] as $y) $byYear[$y['year']] = ($byYear[$y['year']] ?? 0) + $y['students'];
    }
    $departments = [];
    foreach ($byDept as $d) {
        $sum = admin_sum($d['exams']);
        unset($sum['pass_rate'], $sum['lowest']);
        $departments[] = ['code' => $d['code'], 'name' => $d['name'], 'department_id' => $d['department_id'], 'professors' => count($d['professors']),
                          'totals' => $sum, 'exams' => admin_strip($d['exams'])];
    }
    $years = [];
    foreach (array_merge(YEAR_LEVELS, ['Not set']) as $y) if (isset($byYear[$y])) $years[] = ['year' => $y, 'students' => $byYear[$y]];
    unset($total['pass_rate'], $total['lowest']);
    json_out(['totals' => $total, 'exam_status' => $examStatus, 'departments' => $departments, 'years' => $years, 'filters' => $f]);
}

if ($action === 'list') {
    $f = admin_filters();
    $exams = admin_load($f)['exams'];
    json_out(['exams' => admin_strip($exams), 'totals' => admin_sum($exams), 'filters' => $f]);
}

if ($action === 'detail') {
    $id = (int)($_GET['id'] ?? 0);
    $f = ['department_id' => 0, 'year_level' => '', 'professor_id' => 0, 'exam_id' => $id, 'status' => '', 'student_status' => '', 'q' => ''];
    $found = admin_load($f, true)['exams'];
    if (!$found) json_out(['error' => 'Examination not found.'], 404);
    $e = $found[0];

    $qs = $pdo->prepare('SELECT id, position, type, prompt, points, choices_json, correct_answer, media_json FROM questions WHERE exam_id = ? ORDER BY position');
    $qs->execute([$id]);
    $questions = array_map(function ($q) {
        $media = $q['media_json'] ? json_decode($q['media_json'], true) : null;
        return ['id' => (int)$q['id'], 'position' => (int)$q['position'], 'type' => $q['type'], 'prompt' => $q['prompt'], 'points' => (int)$q['points'],
                'choices' => $q['choices_json'] ? json_decode($q['choices_json'], true) : [], 'correct' => $q['correct_answer'],
                'media_kind' => is_array($media) ? ($media['kind'] ?? null) : null];
    }, $qs->fetchAll());
    $r = $pdo->prepare('SELECT detect_face_angle, detect_eye_gaze, detect_multi_face, block_copy_paste, detect_tab_switch, require_camera FROM exams WHERE id = ?');
    $r->execute([$id]);
    $rules = $r->fetch();
    $hist = $pdo->prepare("SELECT student_name AS student, student_no AS studentNo, professor_name AS professor, mode, from_attempt AS fromAttempt, to_attempt AS toAttempt,
        violations_at_restore AS violations, reason, note, DATE_FORMAT(created_at, '%b %e, %Y %l:%i %p') AS at FROM access_restorations WHERE exam_id = ? ORDER BY id DESC LIMIT 100");
    $hist->execute([$id]);
    unset($e['scores']);
    json_out(['exam' => $e, 'questions' => $questions, 'rules' => $rules, 'restorations' => $hist->fetchAll()]);
}

/* ---- Who may edit this exam besides its creator, and which professors could be added ---- */
if ($action === 'editors') {
    $id = (int)($_GET['id'] ?? 0);
    $ex = $pdo->prepare('SELECT id, professor_id FROM exams WHERE id = ?');
    $ex->execute([$id]);
    $exam = $ex->fetch();
    if (!$exam) json_out(['error' => 'Examination not found.'], 404);
    $g = $pdo->prepare("SELECT g.professor_id, u.full_name AS name, u.account_no, d.code AS dept_code, g.can_delete, DATE_FORMAT(g.granted_at, '%b %e, %Y %l:%i %p') AS granted_at
        FROM exam_editors g JOIN users u ON u.id = g.professor_id LEFT JOIN departments d ON d.id = u.department_id WHERE g.exam_id = ? ORDER BY u.full_name");
    $g->execute([$id]);
    $c = $pdo->prepare("SELECT u.id, u.full_name AS name, u.account_no, d.code AS dept_code FROM users u LEFT JOIN departments d ON d.id = u.department_id
        WHERE u.role = 'professor' AND u.approval_status = 'approved' AND u.is_active = 1 AND u.id <> ?
          AND u.id NOT IN (SELECT professor_id FROM exam_editors WHERE exam_id = ?) ORDER BY u.full_name");
    $c->execute([$exam['professor_id'], $id]);
    json_out(['editors' => $g->fetchAll(), 'candidates' => $c->fetchAll()]);
}

if ($action === 'grant_editor' || $action === 'revoke_editor') {
    require_post();
    $d = body();
    $id = (int)($d['exam_id'] ?? 0);
    $pid = (int)($d['professor_id'] ?? 0);
    $ex = $pdo->prepare('SELECT professor_id FROM exams WHERE id = ?');
    $ex->execute([$id]);
    $owner = $ex->fetchColumn();
    if ($owner === false) json_out(['error' => 'Examination not found.'], 404);
    if ($action === 'revoke_editor') {
        $pdo->prepare('DELETE FROM exam_editors WHERE exam_id = ? AND professor_id = ?')->execute([$id, $pid]);
        json_out(['ok' => true]);
    }
    if ($pid === (int)$owner) json_out(['error' => 'The creator of the examination can already edit it.'], 422);
    $p = $pdo->prepare("SELECT 1 FROM users WHERE id = ? AND role = 'professor' AND approval_status = 'approved' AND is_active = 1");
    $p->execute([$pid]);
    if (!$p->fetch()) json_out(['error' => 'Choose an active professor.'], 422);
    $pdo->prepare('INSERT INTO exam_editors (exam_id, professor_id, can_delete, granted_by, granted_at) VALUES (?,?,?,?,NOW())
                   ON DUPLICATE KEY UPDATE can_delete = VALUES(can_delete), granted_by = VALUES(granted_by), granted_at = NOW()')
        ->execute([$id, $pid, !empty($d['can_delete']) ? 1 : 0, $me['id']]);
    $ei = $pdo->prepare('SELECT code, title FROM exams WHERE id = ?'); $ei->execute([$id]); $x = $ei->fetch();
    if ($x) notify_user($pid, 'exam', 'You can now manage an examination', 'An administrator allowed you to edit ' . $x['code'] . ' · ' . $x['title'] . ($d['can_delete'] ?? false ? ' (including deleting it)' : '') . '.',
                        'professor-exam-questions.html?exam=' . $id, 'exam' . $id . ':editor:' . $pid);
    audit_log('exam_editor_grant', 'exam', $x ? $x['code'] : '#' . $id, ['professor_id' => $pid]);
    json_out(['ok' => true]);
}

json_out(['error' => 'Unknown action.'], 400);
