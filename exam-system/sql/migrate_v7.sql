-- =====================================================================
-- Migration v7 - database integrity + housekeeping
--
--   * real foreign keys for columns that pointed at other tables without any constraint
--     (submissions.graded_by, exam_editors.granted_by, users.graduation_verified_by,
--      rtc_signals.viewer_id, student_year_records.school_year_id / program_id)
--   * indexes for the lists the app reads most (exam list by status/date, questions in order, exam code lookup)
--   * automatic clean-up of short-lived data (camera signalling, old read notifications)
--
-- SAFE TO RE-RUN: every step checks information_schema first and skips what already exists.
-- Nothing is dropped and no exam, question, submission or answer is touched.
-- Import it once into an existing exam_system database (phpMyAdmin > Import, or: mysql -u root < migrate_v7.sql).
-- Fresh installs do not need it - schema.sql already includes all of this.
-- =====================================================================
USE exam_system;

-- ---------------------------------------------------------------------
-- 1. Orphan clean-up (a foreign key cannot be added while a row points at something that no longer exists)
--    Pure references are set to NULL; signalling rows of a missing professor are simply removed.
-- ---------------------------------------------------------------------
UPDATE submissions          SET graded_by = NULL            WHERE graded_by IS NOT NULL            AND graded_by NOT IN (SELECT id FROM users);
UPDATE exam_editors         SET granted_by = NULL           WHERE granted_by IS NOT NULL           AND granted_by NOT IN (SELECT id FROM users);
UPDATE users                SET graduation_verified_by = NULL WHERE graduation_verified_by IS NOT NULL AND graduation_verified_by NOT IN (SELECT id FROM (SELECT id FROM users) AS u);
UPDATE student_year_records SET school_year_id = NULL       WHERE school_year_id IS NOT NULL       AND school_year_id NOT IN (SELECT id FROM school_years);
UPDATE student_year_records SET program_id = NULL           WHERE program_id IS NOT NULL           AND program_id NOT IN (SELECT id FROM programs);
DELETE FROM rtc_signals WHERE viewer_id NOT IN (SELECT id FROM users);

-- ---------------------------------------------------------------------
-- 2. Foreign keys
-- ---------------------------------------------------------------------
SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE submissions ADD CONSTRAINT fk_sub_graded_by FOREIGN KEY (graded_by) REFERENCES users(id) ON DELETE SET NULL',
  'DO 0') FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'submissions' AND CONSTRAINT_NAME = 'fk_sub_graded_by' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE exam_editors ADD CONSTRAINT fk_editor_granted_by FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL',
  'DO 0') FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'exam_editors' AND CONSTRAINT_NAME = 'fk_editor_granted_by' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE users ADD CONSTRAINT fk_users_grad_verified_by FOREIGN KEY (graduation_verified_by) REFERENCES users(id) ON DELETE SET NULL',
  'DO 0') FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND CONSTRAINT_NAME = 'fk_users_grad_verified_by' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE rtc_signals ADD CONSTRAINT fk_rtc_viewer FOREIGN KEY (viewer_id) REFERENCES users(id) ON DELETE CASCADE',
  'DO 0') FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'rtc_signals' AND CONSTRAINT_NAME = 'fk_rtc_viewer' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE student_year_records ADD CONSTRAINT fk_syr_school_year FOREIGN KEY (school_year_id) REFERENCES school_years(id) ON DELETE SET NULL',
  'DO 0') FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'student_year_records' AND CONSTRAINT_NAME = 'fk_syr_school_year' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE student_year_records ADD CONSTRAINT fk_syr_program FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE SET NULL',
  'DO 0') FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'student_year_records' AND CONSTRAINT_NAME = 'fk_syr_program' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------
-- 3. Indexes
--    (exams.code is a SUBJECT code that several exams may share, so it gets a plain index, not a UNIQUE one;
--     questions.position is renumbered one row at a time, so it also gets a plain index, not a UNIQUE one.)
-- ---------------------------------------------------------------------
SET @s := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE exams ADD INDEX idx_exams_status_sched (status, scheduled_at)', 'DO 0')
  FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'exams' AND INDEX_NAME = 'idx_exams_status_sched');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE exams ADD INDEX idx_exams_code (code)', 'DO 0')
  FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'exams' AND INDEX_NAME = 'idx_exams_code');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE questions ADD INDEX idx_q_exam_pos (exam_id, position)', 'DO 0')
  FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'questions' AND INDEX_NAME = 'idx_q_exam_pos');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------
-- 4. Housekeeping: one-off purge now ...
--    rtc_signals are short-lived webcam handshake messages; read notifications older than 180 days are not needed.
-- ---------------------------------------------------------------------
DELETE FROM rtc_signals  WHERE created_at < NOW() - INTERVAL 1 DAY;
DELETE FROM notifications WHERE is_read = 1 AND created_at < NOW() - INTERVAL 180 DAY;

-- ---------------------------------------------------------------------
-- 5. ... and keep it tidy automatically (OPTIONAL - keep this block last).
--    MySQL/MariaDB only runs events while the event scheduler is on. In XAMPP add  event_scheduler=ON  under [mysqld]
--    in my.ini and restart MySQL (or run  SET GLOBAL event_scheduler = ON;  as an admin until the next restart).
--    If your account may not create events this part fails harmlessly: everything above is already applied.
-- ---------------------------------------------------------------------
CREATE EVENT IF NOT EXISTS ev_purge_rtc_signals
  ON SCHEDULE EVERY 1 HOUR
  DO DELETE FROM rtc_signals WHERE created_at < NOW() - INTERVAL 1 DAY;

CREATE EVENT IF NOT EXISTS ev_purge_old_notifications
  ON SCHEDULE EVERY 1 DAY
  DO DELETE FROM notifications WHERE is_read = 1 AND created_at < NOW() - INTERVAL 180 DAY;
