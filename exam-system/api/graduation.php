<?php
// Admin: graduation overview and verification.
//   list      every student with program duration, completed years, student type and graduation status
//   apply     an administrator's decision (eligible / not_eligible / graduated / revoke / auto) - see graduation_apply()
//   set_years the administrator verifies how many academic years the student completed
//   evaluate  run the automatic rules now (the same ones that run when a school year ends)
require_once __DIR__ . '/bootstrap.php';
$me = require_role('admin');
$pdo = db();
$action = $_GET['action'] ?? 'list';

if ($action === 'list') {
    run_school_year_rollover();
    $rows = $pdo->query("SELECT u.id, u.account_no, u.full_name, u.section, u.year_level, u.student_type, u.graduation_status, u.completed_years, u.graduation_locked,
            u.graduation_verified_at, u.graduation_note, u.is_active, u.approval_status, u.program_id, u.department_id,
            p.code AS program_code, p.years AS required_years, d.code AS dept_code, v.full_name AS verified_by
        FROM users u LEFT JOIN programs p ON p.id = u.program_id LEFT JOIN departments d ON d.id = u.department_id LEFT JOIN users v ON v.id = u.graduation_verified_by
        WHERE u.role = 'student' AND u.approval_status = 'approved'
        ORDER BY FIELD(u.graduation_status, 'eligible', 'not_eligible', 'graduated'), d.code, p.code, u.full_name")->fetchAll();
    foreach ($rows as &$r) {
        $c = graduation_check($r + ['role' => 'student']);
        $r['duration_met'] = $c['duration_met'];
        $r['blockers'] = $c['blockers'];
        $r['notes'] = $c['notes'];
        $r['needs_verification'] = $c['needs_verification'];
        $r['completed_years'] = (int)$r['completed_years'];
        $r['required_years'] = $r['required_years'] !== null ? (int)$r['required_years'] : null;
        $r['graduation_locked'] = (int)$r['graduation_locked'] === 1;
    }
    unset($r);
    $programs = $pdo->query('SELECT p.id, p.code, p.name, p.years, d.code AS dept_code FROM programs p JOIN departments d ON d.id = p.department_id ORDER BY d.code, p.code')->fetchAll();
    json_out(['students' => $rows, 'programs' => $programs, 'status_labels' => GRAD_STATUS_LABEL, 'type_labels' => STUDENT_TYPE_LABEL]);
}

require_post();
$d = body();

if ($action === 'apply') {
    $res = graduation_apply($pdo, $me, (int)($d['id'] ?? 0), (string)($d['target'] ?? ''), [
        'confirm' => !empty($d['confirm']), 'confirm_records' => !empty($d['confirm_records']),
        'note' => (string)($d['note'] ?? ''), 'expected_status' => (string)($d['expected_status'] ?? '')]);
    if (isset($res['error'])) json_out(['error' => $res['error']], (int)($res['code'] ?? 422));
    json_out($res);
}

if ($action === 'set_years') {
    $res = graduation_set_years($pdo, $me, (int)($d['id'] ?? 0), (int)($d['years'] ?? -1), (string)($d['note'] ?? ''));
    if (isset($res['error'])) json_out(['error' => $res['error']], (int)($res['code'] ?? 422));
    json_out($res);
}

if ($action === 'evaluate') {
    $res = run_graduation_evaluation($pdo);
    audit_log('graduation_evaluate', 'system', 'Automatic graduation evaluation', $res);
    json_out(['ok' => true] + $res);
}
json_out(['error' => 'Unknown action.'], 400);
