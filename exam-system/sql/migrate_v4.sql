-- =====================================================================
-- Upgrade an EXISTING exam_system database to v4 (keeps all data):
--   * exam description + last-edited time (professors can now edit their exams)
--   * auto-submit reason + camera state on submissions
--   * violations are tagged with the attempt they belong to and are never deleted when access is restored
--   * attempt_history + access_restorations (audit trail for "Re-enable exam access")
--   * rtc_signals (signalling for the live webcam view)
-- Fresh installs do not need this - schema.sql already includes everything. The app also applies these
-- changes automatically the first time anyone signs in (ensure_schema() in api/bootstrap.php), so importing
-- this file is optional. Run it ONCE: it fails with "Duplicate column" if the changes are already there.
-- =====================================================================
USE exam_system;

ALTER TABLE exams
  ADD COLUMN description TEXT NULL AFTER subject,
  ADD COLUMN updated_at DATETIME NULL;

ALTER TABLE submissions
  ADD COLUMN auto_reason VARCHAR(20) NULL,
  ADD COLUMN auto_reason_text VARCHAR(255) NULL,
  ADD COLUMN cam_state VARCHAR(20) NULL,
  ADD COLUMN cam_updated DATETIME NULL;

ALTER TABLE violations
  ADD COLUMN attempt INT NOT NULL DEFAULT 1,
  ADD KEY idx_vio_sub_attempt (submission_id, attempt);

-- Current violations belong to the latest attempt; violations archived by the old "re-open" come back as earlier attempts.
UPDATE violations v JOIN submissions s ON s.id = v.submission_id SET v.attempt = s.reopen_count + 1;
INSERT INTO violations (submission_id, type_id, label, severity, occurred_at, attempt)
  SELECT submission_id, type_id, label, severity, COALESCE(occurred_at, NOW()), attempt FROM violation_history;

CREATE TABLE IF NOT EXISTS attempt_history (
  id INT AUTO_INCREMENT PRIMARY KEY, submission_id INT NOT NULL, attempt INT NOT NULL,
  started_at DATETIME NULL, ended_at DATETIME NULL, mode VARCHAR(12) NOT NULL,
  end_reason VARCHAR(20) NULL, end_reason_text VARCHAR(255) NULL,
  auto_score INT NOT NULL DEFAULT 0, max_score INT NOT NULL DEFAULT 0, progress INT NOT NULL DEFAULT 0,
  violation_count INT NOT NULL DEFAULT 0, answers_json MEDIUMTEXT NULL, violations_json MEDIUMTEXT NULL,
  archived_at DATETIME NOT NULL,
  UNIQUE KEY uq_attempt (submission_id, attempt),
  FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS access_restorations (
  id INT AUTO_INCREMENT PRIMARY KEY, submission_id INT NOT NULL, exam_id INT NOT NULL, exam_code VARCHAR(20) NOT NULL,
  student_id INT NOT NULL, student_no VARCHAR(30) NOT NULL, student_name VARCHAR(120) NOT NULL,
  professor_id INT NOT NULL, professor_name VARCHAR(120) NOT NULL,
  mode ENUM('resume','new_attempt') NOT NULL, from_attempt INT NOT NULL, to_attempt INT NOT NULL,
  violations_at_restore INT NOT NULL DEFAULT 0, threshold INT NOT NULL DEFAULT 0,
  reason VARCHAR(255) NULL, note VARCHAR(255) NULL, ip_address VARCHAR(45) NULL, created_at DATETIME NOT NULL,
  KEY idx_restore_exam (exam_id, created_at), KEY idx_restore_prof (professor_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rtc_signals (
  id BIGINT AUTO_INCREMENT PRIMARY KEY, submission_id INT NOT NULL, viewer_id INT NOT NULL, conn_id VARCHAR(40) NOT NULL,
  direction ENUM('to_student','to_viewer') NOT NULL, kind VARCHAR(10) NOT NULL, payload MEDIUMTEXT NULL, created_at DATETIME NOT NULL,
  KEY k_student (submission_id, direction, id), KEY k_viewer (viewer_id, direction, id),
  FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE
) ENGINE=InnoDB;
