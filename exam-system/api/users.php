<?php
// Admin: account manager (list + search, create, edit, approve / reject sign-ups, enable-disable, reset PIN, delete).
// Graduation decisions live in graduation.php; the activity log and announcements in activity.php.
require_once __DIR__ . '/bootstrap.php';
$me = require_role('admin');
$pdo = db();
$action = $_GET['action'] ?? 'list';

/** Lower-case text (works without the mbstring extension). */
function low(string $s): string { return function_exists('mb_strtolower') ? mb_strtolower($s) : strtolower($s); }

const USER_SELECT = "u.id, u.account_no, u.full_name, u.section, u.role, u.department_id, u.program_id, u.year_level,
        u.graduated, u.student_type, u.graduation_status, u.completed_years, u.graduation_locked,
        u.approval_status, u.is_active, u.created_at, d.code AS dept_code, p.code AS program_code, p.years AS program_years";

if ($action === 'list') {
    run_school_year_rollover();
    // Optional filters. Everything is compared case-insensitively (LOWER on both sides), so "alice", "ALICE" and "Alice" find the same account.
    $where = ['1 = 1']; $args = [];
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], low($q)) . '%';
        $where[] = "LOWER(CONCAT_WS(' ', u.account_no, u.full_name, u.role, u.section, u.year_level, d.code, p.code, u.student_type, u.graduation_status)) LIKE ?";
        $args[] = $like;
    }
    $role = low(trim((string)($_GET['role'] ?? '')));
    if (in_array($role, ['student', 'professor', 'admin'], true)) { $where[] = 'u.role = ?'; $args[] = $role; }
    if ((int)($_GET['department_id'] ?? 0) > 0) { $where[] = 'u.department_id = ?'; $args[] = (int)$_GET['department_id']; }
    $st = $pdo->prepare('SELECT ' . USER_SELECT . "
                          FROM users u
                          LEFT JOIN departments d ON d.id = u.department_id
                          LEFT JOIN programs p ON p.id = u.program_id
                          WHERE " . implode(' AND ', $where) . "
                          ORDER BY FIELD(u.approval_status,'pending','approved','rejected'), u.role, u.full_name");
    $st->execute($args);
    json_out(['users' => $st->fetchAll()]);
}

require_post();
$d = body();

/** Validate the fields shared by create + update. Returns [account_no, name, role, section, dept, prog, year, student_type] or exits. */
function account_fields(array $d): array {
    $acct = trim((string)($d['account_no'] ?? '')); $name = trim((string)($d['full_name'] ?? ''));
    $role = (string)($d['role'] ?? '');
    if ($acct === '' || $name === '' || !in_array($role, ['student', 'professor', 'admin'], true)) json_out(['error' => 'Account number, name and role are required.'], 422);
    if (!preg_match('/^[A-Za-z0-9_-]{3,30}$/', $acct)) json_out(['error' => 'Account number must be 3-30 letters, numbers, dash or underscore.'], 422);
    $aff = validate_affiliation($role, $d);
    if (is_string($aff)) json_out(['error' => $aff], 422);
    $section = $role === 'student' ? (cut(trim((string)($d['section'] ?? '')), 30) ?: null) : null;
    $type = null;
    if ($role === 'student') {
        $type = clean_student_type($d['student_type'] ?? '');
        if ($type === null) json_out(['error' => 'Select whether the student is Regular or Irregular.'], 422);
    }
    return [$acct, cut($name, 120), $role, $section, $aff[0], $aff[1], $aff[2], $type];
}

/** Tell the other administrators about an important account change (the person who did it already knows). */
function account_event(array $me, string $title, string $message): void {
    notify_admins('account', $title, $message, 'admin-accounts.html', 'acct:' . md5($title . $message) . ':' . time(), (int)$me['id']);
}

function account_label(array $r): string { return $r['account_no'] . ' · ' . $r['full_name']; }

if ($action === 'create') {
    [$acct, $name, $role, $section, $dept, $prog, $year, $type] = account_fields($d);
    $pass = (string)($d['password'] ?? '');
    if (strlen($pass) < 6) json_out(['error' => 'PIN must be at least 6 characters.'], 422);
    try {
        $pdo->prepare("INSERT INTO users (account_no, full_name, section, role, department_id, program_id, year_level, student_type, approval_status, password_hash)
                       VALUES (?,?,?,?,?,?,?,?,'approved',?)")
            ->execute([$acct, $name, $section, $role, $dept, $prog, $year, $type, password_hash($pass, PASSWORD_DEFAULT)]);
    } catch (PDOException $e) { json_out(['error' => 'That account number already exists.'], 409); }
    $newId = (int)$pdo->lastInsertId();
    if ($role === 'student') evaluate_graduation($pdo, $newId);
    audit_log('account_create', 'account', $acct . ' · ' . $name, ['role' => $role]);
    account_event($me, 'Account created', ucfirst($role) . ' account ' . $acct . ' (' . $name . ') was created by ' . $me['name'] . '.');
    json_out(['ok' => true]);
}

if ($action === 'update') {
    $id = (int)($d['id'] ?? 0);
    $st = $pdo->prepare('SELECT id, role, account_no, full_name, program_id, student_type, graduation_status, graduation_locked FROM users WHERE id = ?'); $st->execute([$id]);
    $cur = $st->fetch();
    if (!$cur) json_out(['error' => 'Account not found.'], 404);
    [$acct, $name, $role, $section, $dept, $prog, $year, $type] = account_fields($d);
    if ($id === $me['id'] && $role !== 'admin') json_out(['error' => 'You cannot remove your own admin role.'], 422);
    if ($cur['role'] === 'admin' && $role !== 'admin') {
        $others = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'admin' AND approval_status = 'approved' AND id <> ?");
        $others->execute([$id]);
        if ((int)$others->fetchColumn() === 0) json_out(['error' => 'This is the only administrator account. Create another administrator first.'], 422);
    }
    if ($cur['role'] === 'student' && $role === 'student' && $cur['graduation_status'] === 'graduated' && $type !== $cur['student_type']) {
        json_out(['error' => 'This student is already marked as Graduated. Revoke the graduation (Graduation tab) before changing the student type.'], 409);
    }
    try {
        $pdo->prepare('UPDATE users SET account_no=?, full_name=?, section=?, role=?, department_id=?, program_id=?, year_level=?, student_type=? WHERE id=?')
            ->execute([$acct, $name, $section, $role, $dept, $prog, $year, $type, $id]);
    } catch (PDOException $e) { json_out(['error' => 'Another account already uses that account number.'], 409); }
    if ($role !== 'student' && $cur['role'] === 'student') {
        // No longer a student: the graduation fields would only be stale.
        $pdo->prepare("UPDATE users SET graduation_status = 'not_eligible', graduated = 0, graduation_locked = 0, completed_years = 0, graduation_verified_by = NULL, graduation_verified_at = NULL, graduation_note = NULL WHERE id = ?")->execute([$id]);
    }
    $grad = null;
    if ($role === 'student') {
        // Program or classification changed -> the automation looks at the student again. It leaves graduated students and
        // statuses an administrator set by hand alone, and a change of classification can never graduate anybody by itself.
        $ch = evaluate_graduation($pdo, $id);
        $row = graduation_row($pdo, $id);
        $grad = ['status' => $row['graduation_status'], 'changed' => $ch !== null, 'locked' => (int)$row['graduation_locked'] === 1];
    }
    if ($id === $me['id']) { $_SESSION['user']['account_no'] = $acct; $_SESSION['user']['name'] = $name; }
    audit_log('account_update', 'account', $acct . ' · ' . $name, ['role' => $role, 'student_type' => $type]);
    if ($cur['role'] !== $role) account_event($me, 'Account role changed', $acct . ' (' . $name . ') changed from ' . $cur['role'] . ' to ' . $role . '.');
    json_out(['ok' => true, 'graduation' => $grad]);
}

if ($action === 'approve' || $action === 'reject') {
    $id = (int)($d['id'] ?? 0);
    $new = $action === 'approve' ? 'approved' : 'rejected';
    $st = $pdo->prepare("UPDATE users SET approval_status = ? WHERE id = ? AND approval_status = 'pending'");
    $st->execute([$new, $id]);
    if ($st->rowCount() === 0) json_out(['error' => 'That request is no longer pending.'], 409);
    $who = $pdo->prepare('SELECT account_no, full_name, role FROM users WHERE id = ?'); $who->execute([$id]);
    $w = $who->fetch() ?: ['account_no' => '', 'full_name' => '', 'role' => ''];
    audit_log('account_' . $new, 'account', account_label($w), ['role' => $w['role']]);
    account_event($me, 'Sign-up request ' . $new, $w['full_name'] . ' (' . $w['account_no'] . ') was ' . $new . ' by ' . $me['name'] . '.');
    json_out(['ok' => true]);
}
// approving an approved account again is a no-op above; "Approve anyway" for rejected requests:
if ($action === 'approve_rejected') {
    $id = (int)($d['id'] ?? 0);
    $st = $pdo->prepare("UPDATE users SET approval_status = 'approved' WHERE id = ? AND approval_status = 'rejected'");
    $st->execute([$id]);
    if ($st->rowCount() === 0) json_out(['error' => 'That request is not rejected any more.'], 409);
    audit_log('account_approved', 'account', '#' . $id, ['was' => 'rejected']);
    json_out(['ok' => true]);
}

if ($action === 'toggle') {
    $id = (int)($d['id'] ?? 0);
    if ($id === $me['id']) json_out(['error' => 'You cannot disable your own account.'], 422);
    $pdo->prepare('UPDATE users SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
    $who = $pdo->prepare('SELECT account_no, full_name, is_active FROM users WHERE id = ?'); $who->execute([$id]);
    if ($w = $who->fetch()) {
        $state = (int)$w['is_active'] ? 'enabled' : 'disabled';
        audit_log('account_' . $state, 'account', account_label($w));
        account_event($me, 'Account ' . $state, $w['full_name'] . ' (' . $w['account_no'] . ') was ' . $state . ' by ' . $me['name'] . '.');
    }
    json_out(['ok' => true]);
}

if ($action === 'reset_pin') {
    $pass = (string)($d['password'] ?? '');
    if (strlen($pass) < 6) json_out(['error' => 'PIN must be at least 6 characters.'], 422);
    $id = (int)($d['id'] ?? 0);
    $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
    $who = $pdo->prepare('SELECT account_no, full_name FROM users WHERE id = ?'); $who->execute([$id]);
    if ($w = $who->fetch()) audit_log('account_reset_pin', 'account', account_label($w));
    json_out(['ok' => true]);
}

/* ---------------------------- delete an account ---------------------------- */

/**
 * What deleting this account would affect, and whether it is allowed at all. Used by the confirmation dialog (delete_check)
 * and checked again by the delete itself, so the browser can never skip a rule.
 */
function delete_impact(PDO $pdo, array $me, array $t): array {
    $imp = ['id' => (int)$t['id'], 'account_no' => $t['account_no'], 'name' => $t['full_name'], 'role' => $t['role'],
            'blocked' => null, 'attempts' => 0, 'violations' => 0, 'exams' => 0, 'exam_attempts' => 0,
            'requires_force' => false, 'requires_reassign' => false, 'candidates' => []];
    if ((int)$t['id'] === (int)$me['id']) { $imp['blocked'] = 'You cannot delete your own account.'; return $imp; }
    if ($t['role'] === 'admin' && $t['approval_status'] === 'approved') {
        $o = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'admin' AND approval_status = 'approved' AND id <> ?");
        $o->execute([$t['id']]);
        if ((int)$o->fetchColumn() === 0) { $imp['blocked'] = 'This is the only administrator account and cannot be deleted.'; return $imp; }
    }
    if ($t['role'] === 'student') {
        $a = $pdo->prepare('SELECT COUNT(*) FROM submissions WHERE student_id = ?'); $a->execute([$t['id']]);
        $imp['attempts'] = (int)$a->fetchColumn();
        $v = $pdo->prepare('SELECT COUNT(*) FROM violations v JOIN submissions s ON s.id = v.submission_id WHERE s.student_id = ?'); $v->execute([$t['id']]);
        $imp['violations'] = (int)$v->fetchColumn();
        $imp['requires_force'] = $imp['attempts'] > 0;
    }
    if ($t['role'] === 'professor') {
        $e = $pdo->prepare('SELECT COUNT(*) FROM exams WHERE professor_id = ?'); $e->execute([$t['id']]);
        $imp['exams'] = (int)$e->fetchColumn();
        $ea = $pdo->prepare('SELECT COUNT(*) FROM submissions s JOIN exams e ON e.id = s.exam_id WHERE e.professor_id = ?'); $ea->execute([$t['id']]);
        $imp['exam_attempts'] = (int)$ea->fetchColumn();
        if ($imp['exams'] > 0) {
            $imp['requires_reassign'] = true;
            $c = $pdo->prepare("SELECT u.id, u.full_name AS name, u.account_no, d.code AS dept_code FROM users u LEFT JOIN departments d ON d.id = u.department_id
                                WHERE u.role = 'professor' AND u.approval_status = 'approved' AND u.is_active = 1 AND u.id <> ? ORDER BY u.full_name");
            $c->execute([$t['id']]);
            $imp['candidates'] = $c->fetchAll();
        }
    }
    return $imp;
}

if ($action === 'delete_check' || $action === 'delete') {
    $id = (int)($d['id'] ?? 0);
    $st = $pdo->prepare('SELECT id, account_no, full_name, role, approval_status FROM users WHERE id = ?'); $st->execute([$id]);
    $t = $st->fetch();
    if (!$t) json_out(['error' => 'Account not found.'], 404);
    $imp = delete_impact($pdo, $me, $t);
    if ($action === 'delete_check') json_out(['impact' => $imp]);

    if (empty($d['confirm'])) json_out(['error' => 'Please confirm that this account must be deleted permanently.'], 422);
    if ($imp['blocked']) json_out(['error' => $imp['blocked']], 422);
    if ($imp['requires_force'] && empty($d['force_records'])) {
        json_out(['error' => 'This student has ' . $imp['attempts'] . ' examination attempt(s) on record. Deleting the account also deletes them.', 'needs_force' => true, 'impact' => $imp], 409);
    }
    $reassign = (int)($d['reassign_to'] ?? 0);
    if ($imp['requires_reassign']) {
        $ok = false;
        foreach ($imp['candidates'] as $c) if ((int)$c['id'] === $reassign) $ok = true;
        if (!$ok) json_out(['error' => 'This professor created ' . $imp['exams'] . ' examination(s). Choose another professor to take them over, or disable the account instead.', 'needs_reassign' => true, 'impact' => $imp], 409);
    }

    $pdo->beginTransaction();
    try {
        if ($t['role'] === 'student') {
            // Attempts go first (their answers, violations, attempt history and camera signals are removed by the foreign keys).
            $pdo->prepare('DELETE FROM submissions WHERE student_id = ?')->execute([$id]);
        }
        if ($t['role'] === 'professor') {
            if ($imp['requires_reassign']) $pdo->prepare('UPDATE exams SET professor_id = ? WHERE professor_id = ?')->execute([$reassign, $id]);
            $pdo->prepare('DELETE FROM rtc_signals WHERE viewer_id = ?')->execute([$id]);
        }
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);   // notifications, year records and editor grants follow by cascade
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_out(['error' => 'This account is still linked to other records and could not be deleted. Disable it instead.'], 409);
    }
    audit_log('account_delete', 'account', account_label($t), ['role' => $t['role'], 'attempts_deleted' => $imp['attempts'], 'exams_reassigned_to' => $imp['requires_reassign'] ? $reassign : null]);
    account_event($me, 'Account deleted', ucfirst($t['role']) . ' account ' . $t['account_no'] . ' (' . $t['full_name'] . ') was permanently deleted by ' . $me['name'] . '.');
    json_out(['ok' => true]);
}
json_out(['error' => 'Unknown action.'], 400);
