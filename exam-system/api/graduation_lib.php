<?php
// Graduation rules (loaded by bootstrap.php).
//
// Two separate fields on a student (users):
//   student_type       regular | irregular | NULL (not classified yet)     -> WHO the student is
//   graduation_status  not_eligible | eligible | graduated                  -> WHERE the student is on the way to graduating
//
// What the AUTOMATION may do (run_graduation_evaluation / evaluate_graduation):
//   * a REGULAR student whose completed academic years reach the duration of their program becomes 'eligible'
//     (completed_years is counted when a school year is closed, see promote_students_for() in bootstrap.php);
//   * it also moves an automatic 'eligible' back to 'not_eligible' when the facts change (program, type, years);
//   * it NEVER sets 'graduated', never touches a 'graduated' student, never touches a status an administrator set by hand
//     (graduation_locked = 1), and never makes an irregular or unclassified student eligible.
// 'graduated' is only ever written by graduation_apply() - an administrator's verification - after the checks below.

const GRAD_STATUS_LABEL = ['not_eligible' => 'Not Yet Eligible', 'eligible' => 'Eligible for Graduation', 'graduated' => 'Graduated'];
const STUDENT_TYPE_LABEL = ['regular' => 'Regular', 'irregular' => 'Irregular'];

/** 'regular' / 'irregular' (any capitalisation) or null. */
function clean_student_type($v): ?string {
    $v = strtolower(trim((string)$v));
    return in_array($v, ['regular', 'irregular'], true) ? $v : null;
}

/** One account with its program duration - everything the graduation rules look at. */
function graduation_row(PDO $pdo, int $studentId): ?array {
    $st = $pdo->prepare("SELECT u.id, u.account_no, u.full_name, u.role, u.approval_status, u.is_active, u.program_id, u.year_level, u.student_type,
            u.graduation_status, u.completed_years, u.graduation_locked, u.graduation_verified_by, u.graduation_verified_at, u.graduation_note,
            p.code AS program_code, p.years AS required_years
        FROM users u LEFT JOIN programs p ON p.id = u.program_id WHERE u.id = ?");
    $st->execute([$studentId]);
    $r = $st->fetch();
    return $r ?: null;
}

/**
 * The facts for one student (validation steps 1-4 of the specification: program + duration, enrolment history, classification, current status).
 *  blockers      = reasons a student cannot be eligible / graduated at all (an admin cannot override these)
 *  auto_eligible = the automation may make this student 'eligible' (no blockers AND Regular)
 *  notes         = things an administrator must look at (irregular / unclassified students always need a human decision)
 */
function graduation_check(array $r): array {
    $required = $r['required_years'] !== null ? (int)$r['required_years'] : null;
    $completed = (int)$r['completed_years'];
    $blockers = []; $notes = [];
    if ($r['role'] !== 'student') $blockers[] = 'This is not a student account.';
    if ($r['approval_status'] !== 'approved') $blockers[] = 'The account is not approved.';
    if ($required === null) $blockers[] = 'The student has no program, so the required duration is unknown.';
    elseif ($completed < $required) $blockers[] = 'Completed ' . $completed . ' of the ' . $required . ' required academic year' . ($required === 1 ? '' : 's') . '.';
    if ($r['student_type'] === null) {
        $blockers[] = 'The student type (Regular / Irregular) has not been set.';
    } elseif ($r['student_type'] === 'irregular') {
        $notes[] = 'Irregular student: academic records must be verified by an administrator before graduation.';
    }
    $durationMet = $required !== null && $completed >= $required;
    return ['required_years' => $required, 'completed_years' => $completed, 'duration_met' => $durationMet, 'blockers' => $blockers, 'notes' => $notes,
            'auto_eligible' => $blockers === [] && $r['student_type'] === 'regular',
            'needs_verification' => $durationMet && $r['student_type'] !== 'regular' && $r['graduation_status'] !== 'graduated'];
}

/**
 * Re-evaluate ONE student with the automatic rules. Returns ['from' => ..., 'to' => ...] when the status changed, otherwise null.
 * Skips graduated students and statuses an administrator set by hand.
 */
function evaluate_graduation(PDO $pdo, int $studentId): ?array {
    $r = graduation_row($pdo, $studentId);
    if (!$r || $r['role'] !== 'student') return null;
    if ($r['graduation_status'] === 'graduated' || (int)$r['graduation_locked'] === 1) return null;
    $c = graduation_check($r);
    $want = $c['auto_eligible'] ? 'eligible' : 'not_eligible';
    if ($want === $r['graduation_status']) return null;
    // The WHERE repeats the safety rules, so a change made by an admin a moment ago can never be overwritten.
    $st = $pdo->prepare("UPDATE users SET graduation_status = ? WHERE id = ? AND graduation_status <> 'graduated' AND graduation_locked = 0");
    $st->execute([$want, $studentId]);
    if ($st->rowCount() === 0) return null;
    if ($want === 'eligible') {
        notify_user($studentId, 'graduation', 'You are eligible for graduation evaluation',
            'You completed the required ' . $c['required_years'] . ' year' . ((int)$c['required_years'] === 1 ? '' : 's') . ' of ' . $r['program_code'] . '. Your status becomes Graduated after the administrator verifies your records.',
            'profile.html', 'grad:' . $studentId . ':eligible:' . $c['completed_years']);
    }
    return ['from' => $r['graduation_status'], 'to' => $want];
}

/** Re-evaluate every student whose status the automation is allowed to change. Returns counts of what changed. */
function run_graduation_evaluation(PDO $pdo): array {
    $out = ['eligible' => 0, 'reverted' => 0];
    try {
        $ids = $pdo->query("SELECT id FROM users WHERE role = 'student' AND graduation_status <> 'graduated' AND graduation_locked = 0")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            $ch = evaluate_graduation($pdo, (int)$id);
            if ($ch && $ch['to'] === 'eligible') $out['eligible']++;
            elseif ($ch) $out['reverted']++;
        }
    } catch (Throwable $e) {
        error_log('[exam-system] graduation evaluation failed: ' . $e->getMessage());
    }
    return $out;
}

/**
 * An administrator's decision about one student (the verification step). Returns ['ok' => true, ...] or ['error' => ..., 'code' => http status].
 *   $target  eligible | not_eligible | graduated | revoke | auto
 *   $opts    note, confirm (required), confirm_records (required for Irregular students: "I verified their academic records"), expected_status
 */
function graduation_apply(PDO $pdo, array $admin, int $studentId, string $target, array $opts): array {
    $err = fn(string $m, int $code = 422) => ['error' => $m, 'code' => $code];
    if (!in_array($target, ['eligible', 'not_eligible', 'graduated', 'revoke', 'auto'], true)) return $err('Unknown graduation action.');
    if (empty($opts['confirm'])) return $err('Please confirm the action.');
    $note = cut(trim((string)($opts['note'] ?? '')), 255);

    $pdo->beginTransaction();
    $st = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'student' FOR UPDATE");
    $st->execute([$studentId]);
    if (!$st->fetch()) { $pdo->rollBack(); return $err('Student not found.', 404); }
    $r = graduation_row($pdo, $studentId);
    $c = graduation_check($r);
    $cur = $r['graduation_status'];

    // Validation step 4: the status on screen must still be the status in the database.
    if (isset($opts['expected_status']) && $opts['expected_status'] !== '' && $opts['expected_status'] !== $cur) {
        $pdo->rollBack();
        return $err('This student\'s graduation status changed in the meantime (now: ' . GRAD_STATUS_LABEL[$cur] . '). Reload the list and try again.', 409);
    }
    $fail = function (string $m, int $code = 422) use ($pdo) { $pdo->rollBack(); return ['error' => $m, 'code' => $code]; };

    if ($target === 'revoke') {
        if ($cur !== 'graduated') return $fail('Only a graduated student can have the graduation revoked.');
        $pdo->prepare("UPDATE users SET graduation_status = 'eligible', graduated = 0, graduation_locked = 1, graduation_verified_by = ?, graduation_verified_at = NOW(), graduation_note = ? WHERE id = ?")
            ->execute([$admin['id'], $note !== '' ? $note : 'Graduation revoked', $studentId]);
        $to = 'eligible';
    } elseif ($cur === 'graduated') {
        return $fail('This student is already marked as Graduated. Revoke the graduation first if it must change.', 409);
    } elseif ($target === 'auto') {
        $pdo->prepare("UPDATE users SET graduation_locked = 0, graduation_verified_by = NULL, graduation_verified_at = NULL, graduation_note = NULL WHERE id = ?")->execute([$studentId]);
        $pdo->commit();
        $ch = evaluate_graduation($pdo, $studentId);
        $now = graduation_row($pdo, $studentId);
        audit_log('graduation_auto', 'student', $r['account_no'] . ' ' . $r['full_name'], ['status' => $now['graduation_status']]);
        return ['ok' => true, 'status' => $now['graduation_status'], 'changed' => $ch !== null];
    } elseif ($target === 'not_eligible') {
        $pdo->prepare("UPDATE users SET graduation_status = 'not_eligible', graduation_locked = 1, graduation_verified_by = ?, graduation_verified_at = NOW(), graduation_note = ? WHERE id = ?")
            ->execute([$admin['id'], $note !== '' ? $note : null, $studentId]);
        $to = 'not_eligible';
    } else {   // eligible | graduated: every hard requirement must hold
        if ($c['blockers']) return $fail('This student cannot be marked ' . GRAD_STATUS_LABEL[$target] . ': ' . implode(' ', $c['blockers']));
        if ($r['student_type'] === 'irregular' && empty($opts['confirm_records'])) {
            return $fail('This is an irregular student. Confirm that you verified their academic records before changing the status.');
        }
        if ($target === 'graduated') {
            $pdo->prepare("UPDATE users SET graduation_status = 'graduated', graduated = 1, graduation_locked = 1, graduation_verified_by = ?, graduation_verified_at = NOW(), graduation_note = ? WHERE id = ?")
                ->execute([$admin['id'], $note !== '' ? $note : null, $studentId]);
        } else {
            $pdo->prepare("UPDATE users SET graduation_status = 'eligible', graduated = 0, graduation_locked = 1, graduation_verified_by = ?, graduation_verified_at = NOW(), graduation_note = ? WHERE id = ?")
                ->execute([$admin['id'], $note !== '' ? $note : null, $studentId]);
        }
        $to = $target;
    }
    $pdo->commit();

    audit_log('graduation_' . $target, 'student', $r['account_no'] . ' ' . $r['full_name'], ['from' => $cur, 'to' => $to, 'type' => $r['student_type'], 'note' => $note]);
    if ($to === 'graduated') {
        notify_user($studentId, 'graduation', 'Congratulations - you are marked as Graduated',
            'The administrator verified your records and updated your graduation status to Graduated.', 'profile.html', 'grad:' . $studentId . ':graduated');
    } elseif ($to === 'eligible' && $target === 'eligible') {
        notify_user($studentId, 'graduation', 'Your graduation eligibility was verified',
            'The administrator verified your records. Your status is now Eligible for Graduation.', 'profile.html', 'grad:' . $studentId . ':verified');
    }
    notify_admins('graduation', 'Graduation status updated', $r['full_name'] . ' (' . $r['account_no'] . '): ' . GRAD_STATUS_LABEL[$cur] . ' → ' . GRAD_STATUS_LABEL[$to] . '.',
        'admin-accounts.html#graduation', 'grad-admin:' . $studentId . ':' . $to . ':' . time(), (int)$admin['id']);
    return ['ok' => true, 'status' => $to];
}

/** Administrator verifies how many academic years a student really completed (needed for irregular students, whose years are not counted automatically). */
function graduation_set_years(PDO $pdo, array $admin, int $studentId, int $years, string $note): array {
    if ($years < 0 || $years > 10) return ['error' => 'Completed years must be between 0 and 10.', 'code' => 422];
    $r = graduation_row($pdo, $studentId);
    if (!$r || $r['role'] !== 'student') return ['error' => 'Student not found.', 'code' => 404];
    if ($r['graduation_status'] === 'graduated') return ['error' => 'This student is already graduated. Revoke the graduation first.', 'code' => 409];
    $pdo->prepare('UPDATE users SET completed_years = ? WHERE id = ?')->execute([$years, $studentId]);
    audit_log('graduation_years', 'student', $r['account_no'] . ' ' . $r['full_name'], ['from' => (int)$r['completed_years'], 'to' => $years, 'note' => cut($note, 255)]);
    evaluate_graduation($pdo, $studentId);   // no effect on locked / irregular students
    return ['ok' => true];
}
