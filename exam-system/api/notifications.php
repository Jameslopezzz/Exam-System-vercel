<?php
// The signed-in person's own notifications (bell icon). Every query is restricted to user_id = the session user, so nobody can read,
// count or change another account's notifications - there is no user id in the request to tamper with.
//   GET  ?action=list&since=ID     latest notifications + unread count (the bell polls this)
//   POST ?action=read   {id}       mark one as read
//   POST ?action=read_all          mark all as read
require_once __DIR__ . '/bootstrap.php';
$u = require_role('student', 'professor', 'admin');
$pdo = db();
$uid = (int)$u['id'];
$action = $_GET['action'] ?? 'list';

if ($action === 'list') {
    notifications_tick($pdo);   // creates the "exam coming up" reminders when due (at most once a minute)
    $st = $pdo->prepare("SELECT id, type, title, message, link, is_read, DATE_FORMAT(created_at, '%b %e, %Y') AS date, DATE_FORMAT(created_at, '%l:%i %p') AS time, created_at
                         FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 30");
    $st->execute([$uid]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['is_read'] = (bool)(int)$r['is_read']; }
    unset($r);
    $c = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $c->execute([$uid]);
    $latest = $pdo->prepare('SELECT COALESCE(MAX(id), 0) FROM notifications WHERE user_id = ?');
    $latest->execute([$uid]);
    json_out(['notifications' => $rows, 'unread' => (int)$c->fetchColumn(), 'latest_id' => (int)$latest->fetchColumn()]);
}

require_post();
$d = body();

if ($action === 'read') {
    $st = $pdo->prepare('UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND user_id = ? AND is_read = 0');
    $st->execute([(int)($d['id'] ?? 0), $uid]);
    json_out(['ok' => true, 'changed' => $st->rowCount()]);
}
if ($action === 'read_all') {
    $st = $pdo->prepare('UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0');
    $st->execute([$uid]);
    json_out(['ok' => true, 'changed' => $st->rowCount()]);
}
json_out(['error' => 'Unknown action.'], 400);
