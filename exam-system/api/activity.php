<?php
// Admin: activity log (who did what) and announcements to students / professors.
require_once __DIR__ . '/bootstrap.php';
$me = require_role('admin');
$pdo = db();
$action = $_GET['action'] ?? 'list';

if ($action === 'list') {
    $limit = max(10, min(200, (int)($_GET['limit'] ?? 100)));
    $rows = $pdo->query("SELECT id, actor_name, action, target_type, target_label, details, ip_address, DATE_FORMAT(created_at, '%b %e, %Y %l:%i:%s %p') AS at
                         FROM audit_log ORDER BY id DESC LIMIT " . $limit)->fetchAll();
    json_out(['log' => $rows]);
}

require_post();
$d = body();

/* ---- Announcement: stored as a notification for every person in the chosen audience ---- */
if ($action === 'announce') {
    $title = cut(trim((string)($d['title'] ?? '')), 150);
    $msg = cut(trim((string)($d['message'] ?? '')), 500);
    $aud = (string)($d['audience'] ?? '');
    if ($title === '' || $msg === '') json_out(['error' => 'Enter a title and a message.'], 422);
    if (!in_array($aud, ['students', 'professors', 'everyone'], true)) json_out(['error' => 'Choose who should receive the announcement.'], 422);
    $dept = (int)($d['department_id'] ?? 0);
    $where = ["approval_status = 'approved'", 'is_active = 1']; $args = [];
    if ($aud === 'students') $where[] = "role = 'student'";
    elseif ($aud === 'professors') $where[] = "role = 'professor'";
    else $where[] = "role IN ('student','professor')";
    if ($dept > 0) { $where[] = 'department_id = ?'; $args[] = $dept; }
    $st = $pdo->prepare('SELECT id, role FROM users WHERE ' . implode(' AND ', $where));
    $st->execute($args);
    $key = 'announce:' . bin2hex(random_bytes(8));   // one announcement = one event; sending a second, identical one is a new event
    $n = 0;
    foreach ($st->fetchAll() as $u) {
        $link = $u['role'] === 'student' ? 'student-dashboard.html' : 'professor-dashboard.html';
        if (notify_user((int)$u['id'], 'announcement', $title, $msg, $link, $key)) $n++;
    }
    audit_log('announcement', 'announcement', $title, ['audience' => $aud, 'department_id' => $dept ?: null, 'recipients' => $n]);
    json_out(['ok' => true, 'recipients' => $n]);
}
json_out(['error' => 'Unknown action.'], 400);
