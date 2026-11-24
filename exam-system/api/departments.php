<?php
// Admin: departments, their programs, and the professors / students in each department.
// Deleting is guarded on the server: the CITCS department can never be deleted, and nothing that still has students, accounts or
// examinations attached is removed unless those records are handled first (students of a program can be transferred to another program).
require_once __DIR__ . '/bootstrap.php';
$me = require_role('admin');
$pdo = db();
$action = $_GET['action'] ?? 'list';

const PROTECTED_DEPARTMENT = 'CITCS';

function is_protected_department(array $dept): bool {
    return strtoupper(trim((string)$dept['code'])) === PROTECTED_DEPARTMENT;
}

function dept_row(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT id, code, name FROM departments WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    return $r ?: null;
}

function program_row(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT p.id, p.department_id, p.code, p.name, p.years, d.code AS dept_code FROM programs p JOIN departments d ON d.id = p.department_id WHERE p.id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    return $r ?: null;
}

/** What a department delete would affect (and whether it is allowed). */
function department_impact(PDO $pdo, array $dept): array {
    $one = function (string $sql, array $args) use ($pdo): int { $st = $pdo->prepare($sql); $st->execute($args); return (int)$st->fetchColumn(); };
    $id = (int)$dept['id'];
    $imp = ['id' => $id, 'code' => $dept['code'], 'name' => $dept['name'], 'protected' => is_protected_department($dept),
            'programs' => $one('SELECT COUNT(*) FROM programs WHERE department_id = ?', [$id]),
            'students' => $one("SELECT COUNT(*) FROM users WHERE department_id = ? AND role = 'student'", [$id]),
            'professors' => $one("SELECT COUNT(*) FROM users WHERE department_id = ? AND role = 'professor'", [$id]),
            'exams' => $one('SELECT COUNT(*) FROM exams WHERE department_id = ?', [$id]),
            'blocked' => null];
    if ($imp['protected']) $imp['blocked'] = 'The ' . PROTECTED_DEPARTMENT . ' department is protected and cannot be deleted.';
    elseif ($imp['students'] || $imp['professors'] || $imp['exams']) {
        $parts = [];
        if ($imp['students']) $parts[] = $imp['students'] . ' student account' . ($imp['students'] === 1 ? '' : 's');
        if ($imp['professors']) $parts[] = $imp['professors'] . ' professor account' . ($imp['professors'] === 1 ? '' : 's');
        if ($imp['exams']) $parts[] = $imp['exams'] . ' examination' . ($imp['exams'] === 1 ? '' : 's');
        $imp['blocked'] = $dept['code'] . ' still has ' . implode(', ', $parts) . '. Move or remove them first, so no student, professor or examination record is lost.';
    }
    return $imp;
}

/** What a program delete would affect. Students of the program must be transferred to another program of the same department. */
function program_impact(PDO $pdo, array $prog): array {
    $id = (int)$prog['id'];
    $st = $pdo->prepare("SELECT COUNT(*) AS total, SUM(graduation_status = 'graduated') AS graduated, SUM(is_active = 1 AND approval_status = 'approved') AS active
                         FROM users WHERE program_id = ?");
    $st->execute([$id]);
    $c = $st->fetch();
    $cand = $pdo->prepare('SELECT id, code, name, years FROM programs WHERE department_id = ? AND id <> ? ORDER BY code');
    $cand->execute([(int)$prog['department_id'], $id]);
    return ['id' => $id, 'code' => $prog['code'], 'name' => $prog['name'], 'dept_code' => $prog['dept_code'],
            'students' => (int)$c['total'], 'graduated' => (int)$c['graduated'], 'active' => (int)$c['active'],
            'requires_transfer' => (int)$c['total'] > 0, 'candidates' => $cand->fetchAll()];
}

/** Notify the other administrators about a department / program change. */
function structure_event(array $me, string $title, string $message): void {
    notify_admins('department', $title, $message, 'admin-accounts.html#departments', 'dept:' . md5($title . $message) . ':' . time(), (int)$me['id']);
}

if ($action === 'list') {
    $depts = $pdo->query("SELECT d.id, d.code, d.name,
        (SELECT COUNT(*) FROM users u WHERE u.department_id = d.id AND u.role = 'professor' AND u.approval_status = 'approved') AS professors,
        (SELECT COUNT(*) FROM users u WHERE u.department_id = d.id AND u.role = 'student'   AND u.approval_status = 'approved') AS students
        FROM departments d ORDER BY d.code")->fetchAll();
    foreach ($depts as &$dp) $dp['protected'] = is_protected_department($dp);
    unset($dp);
    $progs = $pdo->query("SELECT p.id, p.department_id, p.code, p.name, p.years,
        (SELECT COUNT(*) FROM users u WHERE u.program_id = p.id) AS students FROM programs p ORDER BY p.code")->fetchAll();
    json_out(['departments' => $depts, 'programs' => $progs]);
}

if ($action === 'professors') {   // every professor in one department
    $st = $pdo->prepare("SELECT id, account_no, full_name, is_active, approval_status,
        (SELECT COUNT(*) FROM exams e WHERE e.professor_id = users.id) AS exams
        FROM users WHERE role = 'professor' AND department_id = ? ORDER BY full_name");
    $st->execute([(int)($_GET['department_id'] ?? 0)]);
    json_out(['professors' => $st->fetchAll()]);
}

if ($action === 'department_check') {
    $dept = dept_row($pdo, (int)($_GET['id'] ?? 0));
    if (!$dept) json_out(['error' => 'Department not found.'], 404);
    json_out(['impact' => department_impact($pdo, $dept)]);
}
if ($action === 'program_check') {
    $prog = program_row($pdo, (int)($_GET['id'] ?? 0));
    if (!$prog) json_out(['error' => 'Program not found.'], 404);
    json_out(['impact' => program_impact($pdo, $prog)]);
}

require_post();
$d = body();
$code = strtoupper(trim((string)($d['code'] ?? '')));
$name = trim((string)($d['name'] ?? ''));

function check_code_name(string $code, string $name): void {
    if (!preg_match('/^[A-Z0-9_-]{2,20}$/', $code)) json_out(['error' => 'Code must be 2-20 letters or numbers (e.g. CITCS).'], 422);
    if ($name === '' || strlen($name) > 150) json_out(['error' => 'Enter a name.'], 422);
}

if ($action === 'create_department') {
    check_code_name($code, $name);
    try { $pdo->prepare('INSERT INTO departments (code, name) VALUES (?,?)')->execute([$code, $name]); }
    catch (PDOException $e) { json_out(['error' => 'A department with that code already exists.'], 409); }
    audit_log('department_create', 'department', $code . ' · ' . $name);
    structure_event($me, 'Department added', 'Department ' . $code . ' (' . $name . ') was added by ' . $me['name'] . '.');
    json_out(['ok' => true]);
}
if ($action === 'update_department') {
    check_code_name($code, $name);
    $dept = dept_row($pdo, (int)($d['id'] ?? 0));
    if (!$dept) json_out(['error' => 'Department not found.'], 404);
    // Renaming the protected department's code would hide it from the protection, so only its name may change.
    if (is_protected_department($dept) && $code !== strtoupper($dept['code'])) json_out(['error' => 'The code of the ' . PROTECTED_DEPARTMENT . ' department cannot be changed.'], 422);
    try { $pdo->prepare('UPDATE departments SET code = ?, name = ? WHERE id = ?')->execute([$code, $name, (int)$dept['id']]); }
    catch (PDOException $e) { json_out(['error' => 'A department with that code already exists.'], 409); }
    audit_log('department_update', 'department', $code . ' · ' . $name, ['was' => $dept['code'] . ' · ' . $dept['name']]);
    structure_event($me, 'Department updated', 'Department ' . $dept['code'] . ' is now ' . $code . ' (' . $name . ').');
    json_out(['ok' => true]);
}
if ($action === 'delete_department') {
    $dept = dept_row($pdo, (int)($d['id'] ?? 0));
    if (!$dept) json_out(['error' => 'Department not found.'], 404);
    $imp = department_impact($pdo, $dept);
    if ($imp['blocked']) json_out(['error' => $imp['blocked'], 'impact' => $imp], $imp['protected'] ? 403 : 409);
    if (empty($d['confirm'])) json_out(['error' => 'Please confirm that this department must be deleted.'], 422);
    $pdo->beginTransaction();
    try {
        // Only empty programs can be left at this point (no accounts and no exams exist in the department).
        $pdo->prepare('DELETE FROM programs WHERE department_id = ?')->execute([(int)$dept['id']]);
        $pdo->prepare('DELETE FROM departments WHERE id = ?')->execute([(int)$dept['id']]);
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_out(['error' => 'This department is still linked to other records and could not be deleted.'], 409);
    }
    audit_log('department_delete', 'department', $dept['code'] . ' · ' . $dept['name'], ['programs_deleted' => $imp['programs']]);
    structure_event($me, 'Department deleted', 'Department ' . $dept['code'] . ' (' . $dept['name'] . ') was deleted by ' . $me['name'] . '.');
    json_out(['ok' => true]);
}
if ($action === 'create_program') {
    check_code_name($code, $name);
    $years = max(1, min(5, (int)($d['years'] ?? 4)));
    try { $pdo->prepare('INSERT INTO programs (department_id, code, name, years) VALUES (?,?,?,?)')->execute([(int)($d['department_id'] ?? 0), $code, $name, $years]); }
    catch (PDOException $e) { json_out(['error' => 'That program code already exists (or the department is invalid).'], 409); }
    audit_log('program_create', 'program', $code . ' · ' . $name, ['years' => $years]);
    structure_event($me, 'Program added', 'Program ' . $code . ' (' . $name . ', ' . $years . ' years) was added by ' . $me['name'] . '.');
    json_out(['ok' => true]);
}
if ($action === 'update_program') {   // name and required duration (the graduation rules read the duration from here)
    $prog = program_row($pdo, (int)($d['id'] ?? 0));
    if (!$prog) json_out(['error' => 'Program not found.'], 404);
    $years = (int)($d['years'] ?? 0);
    if ($years < 1 || $years > 5) json_out(['error' => 'The duration must be between 1 and 5 years.'], 422);
    if ($name === '' || strlen($name) > 150) json_out(['error' => 'Enter a name.'], 422);
    $pdo->prepare('UPDATE programs SET name = ?, years = ? WHERE id = ?')->execute([$name, $years, (int)$prog['id']]);
    $reeval = 0;
    if ($years !== (int)$prog['years']) {   // a different duration changes who is eligible: re-check the students of this program (graduated / admin-set statuses are left alone)
        $ids = $pdo->prepare("SELECT id FROM users WHERE program_id = ? AND role = 'student' AND graduation_status <> 'graduated' AND graduation_locked = 0");
        $ids->execute([(int)$prog['id']]);
        foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $sid) if (evaluate_graduation($pdo, (int)$sid)) $reeval++;
    }
    audit_log('program_update', 'program', $prog['code'] . ' · ' . $name, ['years_from' => (int)$prog['years'], 'years_to' => $years, 'students_reevaluated' => $reeval]);
    structure_event($me, 'Program updated', 'Program ' . $prog['code'] . ' now lasts ' . $years . ' year' . ($years === 1 ? '' : 's') . '.');
    json_out(['ok' => true, 're_evaluated' => $reeval]);
}
if ($action === 'delete_program') {
    $prog = program_row($pdo, (int)($d['id'] ?? 0));
    if (!$prog) json_out(['error' => 'Program not found.'], 404);
    if (empty($d['confirm'])) json_out(['error' => 'Please confirm that this program must be deleted.'], 422);
    $imp = program_impact($pdo, $prog);
    $target = (int)($d['transfer_to'] ?? 0);
    if ($imp['requires_transfer']) {
        $ok = false;
        foreach ($imp['candidates'] as $c) if ((int)$c['id'] === $target) $ok = true;
        if (!$ok) {
            json_out(['error' => $prog['code'] . ' still has ' . $imp['students'] . ' student account' . ($imp['students'] === 1 ? '' : 's') . ' (' . $imp['active'] . ' active). Transfer them to another program of ' . $prog['dept_code'] . ' first.',
                      'needs_transfer' => true, 'impact' => $imp], 409);
        }
    }
    $moved = [];
    $pdo->beginTransaction();
    try {
        if ($imp['requires_transfer']) {
            $ids = $pdo->prepare('SELECT id FROM users WHERE program_id = ?');
            $ids->execute([(int)$prog['id']]);
            $moved = $ids->fetchAll(PDO::FETCH_COLUMN);
            $pdo->prepare('UPDATE users SET program_id = ? WHERE program_id = ?')->execute([$target, (int)$prog['id']]);
        }
        $pdo->prepare('DELETE FROM programs WHERE id = ?')->execute([(int)$prog['id']]);
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_out(['error' => 'This program is still linked to other records and could not be deleted.'], 409);
    }
    foreach ($moved as $sid) evaluate_graduation($pdo, (int)$sid);   // the new program may have another duration
    audit_log('program_delete', 'program', $prog['code'] . ' · ' . $prog['name'], ['students_transferred' => count($moved), 'transferred_to' => $imp['requires_transfer'] ? $target : null]);
    structure_event($me, 'Program deleted', 'Program ' . $prog['code'] . ' (' . $prog['name'] . ') was deleted by ' . $me['name'] . '.' . ($moved ? ' ' . count($moved) . ' student(s) were transferred.' : ''));
    json_out(['ok' => true, 'transferred' => count($moved)]);
}
json_out(['error' => 'Unknown action.'], 400);
