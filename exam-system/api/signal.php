<?php
// WebRTC signalling for the live webcam view.
//
//   professor (viewer)  --offer-->   student     student  --answer-->  professor      (+ ICE candidates both ways)
//
// The professor's browser asks for a stream, the student's browser answers, and the video then flows directly between the two
// browsers. This file only relays those small messages, and it enforces who may talk to whom:
//   * a professor can only open a stream for a student who is taking one of THEIR OWN examinations, and only while it is in progress;
//   * a student can only answer a professor who sent an offer for that student's own attempt;
//   * an administrator may watch any student who is taking an examination (view only: no warnings, no force-submit);
//   * other professors can never request or receive a stream.
require_once __DIR__ . '/bootstrap.php';
$u = require_role('professor', 'admin', 'student');
$pdo = db();
session_write_close();   // read-only from here on: do not hold the session lock, the pages poll this endpoint every second or two
$action = $_GET['action'] ?? '';

const SIGNAL_MAX_PAYLOAD = 20000;     // bytes; an SDP offer is ~2-5 KB
const SIGNAL_MAX_AGE = 60;            // seconds; older messages belong to a connection that is long gone
const SIGNAL_KINDS_VIEWER = ['offer', 'ice', 'bye'];
const SIGNAL_KINDS_STUDENT = ['answer', 'ice', 'bye'];

function ice_servers(): array {
    $list = [];
    if (RTC_STUN_URL !== '') $list[] = ['urls' => RTC_STUN_URL];
    if (RTC_TURN_URL !== '') $list[] = ['urls' => RTC_TURN_URL, 'username' => RTC_TURN_USER, 'credential' => RTC_TURN_PASS];
    return $list;
}

function valid_conn(string $c): bool { return (bool)preg_match('/^[A-Za-z0-9_-]{8,40}$/', $c); }

function clean_payload($p): ?string {
    if ($p === null) return null;
    $json = json_encode($p, JSON_UNESCAPED_UNICODE);
    if ($json === false || strlen($json) > SIGNAL_MAX_PAYLOAD) json_out(['error' => 'Signalling message too large.'], 413);
    return $json;
}

function prune_signals(PDO $pdo): void {
    if (random_int(1, 25) === 1) $pdo->exec('DELETE FROM rtc_signals WHERE created_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)');
}

if ($action === 'ice') {   // which STUN/TURN servers to use (no secrets beyond the optional TURN login, only for signed-in users)
    json_out(['iceServers' => ice_servers()]);
}

/* =============================== professor side =============================== */
if ($u['role'] === 'professor' || $u['role'] === 'admin') {
    $isAdmin = $u['role'] === 'admin';
    if ($action === 'send') {
        require_post();
        $d = body();
        $items = is_array($d['messages'] ?? null) ? array_slice($d['messages'], 0, 40) : [];
        $ownCache = [];
        $ins = $pdo->prepare('INSERT INTO rtc_signals (submission_id, viewer_id, conn_id, direction, kind, payload, created_at) VALUES (?,?,?,?,?,?,NOW())');
        $sent = 0;
        foreach ($items as $m) {
            if (!is_array($m)) continue;
            $sid = (int)($m['submission_id'] ?? 0);
            $conn = (string)($m['conn_id'] ?? '');
            $kind = (string)($m['kind'] ?? '');
            if (!$sid || !valid_conn($conn) || !in_array($kind, SIGNAL_KINDS_VIEWER, true)) continue;
            if (!array_key_exists($sid, $ownCache)) {
                // Is this attempt part of one of MY examinations? (professors: strictly their own; administrators: any examination)
                if ($isAdmin) { $st = $pdo->prepare('SELECT s.mode FROM submissions s WHERE s.id = ?'); $st->execute([$sid]); }
                else { $st = $pdo->prepare('SELECT s.mode FROM submissions s JOIN exams e ON e.id = s.exam_id WHERE s.id = ? AND e.professor_id = ?'); $st->execute([$sid, $u['id']]); }
                $mode = $st->fetchColumn();
                $ownCache[$sid] = $mode === false ? null : $mode;
            }
            if ($ownCache[$sid] === null) json_out(['error' => 'This student is not taking one of your examinations.'], 403);
            if ($kind !== 'bye' && $ownCache[$sid] !== 'in-progress') continue;   // nothing to watch once the attempt ended
            $ins->execute([$sid, $u['id'], $conn, 'to_student', $kind, clean_payload($m['payload'] ?? null)]);
            $sent++;
        }
        prune_signals($pdo);
        json_out(['ok' => true, 'sent' => $sent]);
    }
    if ($action === 'pull') {
        $after = max(0, (int)($_GET['after'] ?? 0));
        // Messages are always addressed to the viewer's own id, so an administrator and a professor never receive each other's streams.
        $st = $pdo->prepare('SELECT r.id, r.submission_id, r.conn_id, r.kind, r.payload FROM rtc_signals r
                             JOIN submissions s ON s.id = r.submission_id JOIN exams e ON e.id = s.exam_id
                             WHERE r.direction = \'to_viewer\' AND r.viewer_id = ? AND (e.professor_id = ? OR ?) AND r.id > ?
                               AND r.created_at >= DATE_SUB(NOW(), INTERVAL ' . SIGNAL_MAX_AGE . ' SECOND)
                             ORDER BY r.id LIMIT 300');
        $st->execute([$u['id'], $u['id'], $isAdmin ? 1 : 0, $after]);
        $rows = $st->fetchAll();
        $last = $after;
        foreach ($rows as &$r) { $last = max($last, (int)$r['id']); $r['id'] = (int)$r['id']; $r['submission_id'] = (int)$r['submission_id']; $r['payload'] = $r['payload'] !== null ? json_decode($r['payload'], true) : null; }
        unset($r);
        json_out(['messages' => $rows, 'last' => $last]);
    }
    json_out(['error' => 'Unknown action.'], 400);
}

/* ================================ student side ================================ */
// The student's own attempt for the exam they are taking (must still be in progress).
$examId = (int)($_GET['exam_id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') { $b = body(); $examId = (int)($b['exam_id'] ?? $examId); }
$st = $pdo->prepare("SELECT id FROM submissions WHERE exam_id = ? AND student_id = ? AND mode = 'in-progress'");
$st->execute([$examId, $u['id']]);
$subId = (int)$st->fetchColumn();
if (!$subId) json_out(['error' => 'No active exam session.', 'closed' => true], 409);

if ($action === 'pull') {
    $after = max(0, (int)($_GET['after'] ?? 0));
    $st = $pdo->prepare('SELECT id, viewer_id, conn_id, kind, payload FROM rtc_signals
                         WHERE submission_id = ? AND direction = \'to_student\' AND id > ?
                           AND created_at >= DATE_SUB(NOW(), INTERVAL ' . SIGNAL_MAX_AGE . ' SECOND)
                         ORDER BY id LIMIT 300');
    $st->execute([$subId, $after]);
    $rows = $st->fetchAll();
    $last = $after;
    foreach ($rows as &$r) { $last = max($last, (int)$r['id']); $r['id'] = (int)$r['id']; $r['viewer_id'] = (int)$r['viewer_id']; $r['payload'] = $r['payload'] !== null ? json_decode($r['payload'], true) : null; }
    unset($r);
    json_out(['messages' => $rows, 'last' => $last]);
}
if ($action === 'send') {
    require_post();
    $items = is_array($b['messages'] ?? null) ? array_slice($b['messages'], 0, 40) : [];
    $known = $pdo->prepare("SELECT 1 FROM rtc_signals WHERE submission_id = ? AND conn_id = ? AND viewer_id = ? AND direction = 'to_student' AND kind = 'offer' LIMIT 1");
    $ins = $pdo->prepare('INSERT INTO rtc_signals (submission_id, viewer_id, conn_id, direction, kind, payload, created_at) VALUES (?,?,?,?,?,?,NOW())');
    $sent = 0;
    foreach ($items as $m) {
        if (!is_array($m)) continue;
        $conn = (string)($m['conn_id'] ?? ''); $kind = (string)($m['kind'] ?? ''); $viewer = (int)($m['viewer_id'] ?? 0);
        if (!$viewer || !valid_conn($conn) || !in_array($kind, SIGNAL_KINDS_STUDENT, true)) continue;
        $known->execute([$subId, $conn, $viewer]);          // only reply to a professor who actually asked for this stream
        if (!$known->fetchColumn()) continue;
        $ins->execute([$subId, $viewer, $conn, 'to_viewer', $kind, clean_payload($m['payload'] ?? null)]);
        $sent++;
    }
    prune_signals($pdo);
    json_out(['ok' => true, 'sent' => $sent]);
}
json_out(['error' => 'Unknown action.'], 400);
