-- =====================================================================
-- EXAM System - MySQL schema (run in phpMyAdmin or: mysql -u root < schema.sql)
-- v7: SAFE TO RE-RUN. It no longer drops tables, so importing it again never deletes data (it only creates what is
--     missing). To start completely over, drop the exam_system database first, then import this file.
-- =====================================================================
CREATE DATABASE IF NOT EXISTS exam_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE exam_system;

-- Departments (CITCS, CAS, CBA, CCJ, COA ...) - admin can add more
CREATE TABLE IF NOT EXISTS departments (
  id    INT AUTO_INCREMENT PRIMARY KEY,
  code  VARCHAR(20)  NOT NULL UNIQUE,
  name  VARCHAR(150) NOT NULL
) ENGINE=InnoDB;

-- Programs / courses offered by a department; years = length of the program
CREATE TABLE IF NOT EXISTS programs (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  department_id INT NOT NULL,
  code          VARCHAR(20)  NOT NULL UNIQUE,
  name          VARCHAR(150) NOT NULL,
  years         TINYINT NOT NULL DEFAULT 4,
  FOREIGN KEY (department_id) REFERENCES departments(id)
) ENGINE=InnoDB;

-- School years: when end_date passes, students are promoted one year level (once)
CREATE TABLE IF NOT EXISTS school_years (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  label      VARCHAR(20) NOT NULL UNIQUE,      -- e.g. 2026-2027
  start_date DATE NOT NULL,
  end_date   DATE NOT NULL,
  is_current TINYINT(1) NOT NULL DEFAULT 0,
  promoted   TINYINT(1) NOT NULL DEFAULT 0,    -- 1 once students were advanced for this year
  promoted_at DATETIME NULL
) ENGINE=InnoDB;

-- Users: students, professors, admins (role-based access)
CREATE TABLE IF NOT EXISTS users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  account_no    VARCHAR(30)  NOT NULL UNIQUE,
  full_name     VARCHAR(120) NOT NULL,
  section       VARCHAR(30)  NULL,
  role          ENUM('student','professor','admin') NOT NULL,
  department_id INT NULL,                        -- students + professors (admin: none)
  program_id    INT NULL,                        -- students only
  year_level    VARCHAR(20) NULL,                -- students only
  graduated     TINYINT(1)   NOT NULL DEFAULT 0,     -- kept in step with graduation_status = 'graduated' (older queries use it)
  student_type  ENUM('regular','irregular') NULL DEFAULT NULL,                 -- students only. NULL = not classified yet (an admin must classify)
  graduation_status ENUM('not_eligible','eligible','graduated') NOT NULL DEFAULT 'not_eligible',
  completed_years TINYINT NOT NULL DEFAULT 0,                                  -- academic years completed (counted at each school-year rollover, or verified by an admin)
  graduation_locked TINYINT(1) NOT NULL DEFAULT 0,                             -- 1 = status set by an admin: the automation never overwrites it
  graduation_verified_by INT NULL,
  graduation_verified_at DATETIME NULL,
  graduation_note VARCHAR(255) NULL,
  approval_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved',
  password_hash VARCHAR(255) NOT NULL,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (department_id) REFERENCES departments(id),
  FOREIGN KEY (program_id)    REFERENCES programs(id),
  CONSTRAINT fk_users_grad_verified_by FOREIGN KEY (graduation_verified_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Exams: configuration set by the instructor
CREATE TABLE IF NOT EXISTS exams (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  code               VARCHAR(20)  NOT NULL,
  title              VARCHAR(150) NOT NULL,
  subject            VARCHAR(150) NOT NULL,
  description        TEXT NULL,
  professor_id       INT NOT NULL,
  department_id      INT NULL,                     -- department of the creating professor (set automatically); only its students may take the exam
  duration_minutes   INT NOT NULL DEFAULT 45,
  violation_threshold INT NOT NULL DEFAULT 3,
  status             ENUM('draft','scheduled','available','closed') NOT NULL DEFAULT 'draft',
  scheduled_at       DATETIME NULL,                -- exam date/time (opens)
  available_until    DATETIME NULL,                -- last moment a student may START the exam (NULL = no end of the window)
  detect_face_angle  TINYINT(1) NOT NULL DEFAULT 1,
  detect_eye_gaze    TINYINT(1) NOT NULL DEFAULT 1,
  detect_multi_face  TINYINT(1) NOT NULL DEFAULT 1,
  block_copy_paste   TINYINT(1) NOT NULL DEFAULT 1,
  detect_tab_switch  TINYINT(1) NOT NULL DEFAULT 1,
  require_camera     TINYINT(1) NOT NULL DEFAULT 1,
  release_score      TINYINT(1) NOT NULL DEFAULT 0,   -- 1 = students may see their score (and the professor's feedback)
  release_answers    TINYINT(1) NOT NULL DEFAULT 0,   -- 1 = students may review their answers together with the correct answers
  results_released_at DATETIME NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NULL,                -- last time the owning professor edited the exam
  KEY idx_exams_status_sched (status, scheduled_at),
  KEY idx_exams_code (code),                     -- a SUBJECT code several exams may share: plain index, not UNIQUE
  FOREIGN KEY (professor_id) REFERENCES users(id),
  FOREIGN KEY (department_id) REFERENCES departments(id)
) ENGINE=InnoDB;

-- Questions: media_json holds {kind, src, caption, data, headers, rows}
CREATE TABLE IF NOT EXISTS questions (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  exam_id        INT NOT NULL,
  position       INT NOT NULL DEFAULT 1,
  type           ENUM('multiple-choice','essay') NOT NULL,
  prompt         TEXT NOT NULL,
  points         INT NOT NULL DEFAULT 1,
  choices_json   TEXT NULL,
  correct_answer VARCHAR(255) NULL,
  media_json     MEDIUMTEXT NULL,
  KEY idx_q_exam_pos (exam_id, position),        -- positions are renumbered row by row: plain index, not UNIQUE
  FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Exam sections: which student sections may take an exam. No rows = every section can take it.
CREATE TABLE IF NOT EXISTS exam_sections (
  exam_id INT NOT NULL,
  section VARCHAR(30) NOT NULL,
  PRIMARY KEY (exam_id, section),
  FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Exam year levels: which student year levels may take an exam (First Year ... Irregular). No rows = every year level (older exams).
CREATE TABLE IF NOT EXISTS exam_year_levels (
  exam_id    INT NOT NULL,
  year_level VARCHAR(20) NOT NULL,
  PRIMARY KEY (exam_id, year_level),
  FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Exam editors: extra professors an ADMINISTRATOR allowed to edit an exam they did not create (the creator can always edit).
CREATE TABLE IF NOT EXISTS exam_editors (
  exam_id      INT NOT NULL,
  professor_id INT NOT NULL,
  can_delete   TINYINT(1) NOT NULL DEFAULT 0,
  granted_by   INT NULL,
  granted_at   DATETIME NOT NULL,
  PRIMARY KEY (exam_id, professor_id),
  FOREIGN KEY (exam_id)      REFERENCES exams(id) ON DELETE CASCADE,
  FOREIGN KEY (professor_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_editor_granted_by FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Submissions: one per student per exam
CREATE TABLE IF NOT EXISTS submissions (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  exam_id       INT NOT NULL,
  student_id    INT NOT NULL,
  started_at    DATETIME NOT NULL,
  submitted_at  DATETIME NULL,
  mode          ENUM('in-progress','manual','auto') NOT NULL DEFAULT 'in-progress',
  progress      INT NOT NULL DEFAULT 0,
  auto_score    INT NOT NULL DEFAULT 0,   -- graded multiple-choice points
  essay_score   INT NULL,                 -- filled in by professor
  max_score     INT NOT NULL DEFAULT 0,
  last_ping     DATETIME NULL,
  last_ping_ms  INT NULL,
  pending_warning VARCHAR(255) NULL,      -- instructor warning delivered on next heartbeat
  draft_json    MEDIUMTEXT NULL,          -- autosaved answers (used for forced submits)
  reopen_count  INT NOT NULL DEFAULT 0,   -- times a professor restored access after an auto-submit (current attempt = reopen_count + 1)
  reopened_at   DATETIME NULL,
  auto_reason   VARCHAR(20)  NULL,        -- why an auto-submit happened: violations | time | instructor | other
  auto_reason_text VARCHAR(255) NULL,
  feedback      TEXT NULL,                -- professor's comment, shown to the student when the score is released
  graded_by     INT NULL,
  graded_at     DATETIME NULL,
  cam_state     VARCHAR(20)  NULL,        -- last camera state reported by the student's browser: on | lost | denied | unavailable
  cam_updated   DATETIME NULL,
  UNIQUE KEY uq_exam_student (exam_id, student_id),
  FOREIGN KEY (exam_id)    REFERENCES exams(id) ON DELETE CASCADE,
  FOREIGN KEY (student_id) REFERENCES users(id),
  CONSTRAINT fk_sub_graded_by FOREIGN KEY (graded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Answers: every student response
CREATE TABLE IF NOT EXISTS answers (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  submission_id INT NOT NULL,
  question_id   INT NOT NULL,
  answer_text   MEDIUMTEXT NULL,
  is_correct    TINYINT(1) NULL,
  points_earned INT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_sub_q (submission_id, question_id),
  FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE,
  FOREIGN KEY (question_id)   REFERENCES questions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Violations: audit log with type + timestamp
CREATE TABLE IF NOT EXISTS violations (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  submission_id INT NOT NULL,
  type_id       VARCHAR(30)  NOT NULL,
  label         VARCHAR(150) NOT NULL,
  severity      ENUM('medium','high','critical') NOT NULL DEFAULT 'medium',
  occurred_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  attempt       INT NOT NULL DEFAULT 1,     -- which attempt / violation window it belongs to (never deleted when access is restored)
  KEY idx_vio_sub_attempt (submission_id, attempt),
  FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Violation history: violations of an earlier attempt, kept for the audit trail when a professor re-opens an exam
CREATE TABLE IF NOT EXISTS violation_history (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  submission_id INT NOT NULL,
  attempt       INT NOT NULL,
  type_id       VARCHAR(30)  NOT NULL,
  label         VARCHAR(150) NOT NULL,
  severity      ENUM('medium','high','critical') NOT NULL DEFAULT 'medium',
  occurred_at   TIMESTAMP NULL,
  FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Attempt history: a snapshot of every attempt that ended and was then restored (answers, score, violations, why it ended)
CREATE TABLE IF NOT EXISTS attempt_history (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  submission_id   INT NOT NULL,
  attempt         INT NOT NULL,
  started_at      DATETIME NULL,
  ended_at        DATETIME NULL,
  mode            VARCHAR(12) NOT NULL,
  end_reason      VARCHAR(20) NULL,
  end_reason_text VARCHAR(255) NULL,
  auto_score      INT NOT NULL DEFAULT 0,
  max_score       INT NOT NULL DEFAULT 0,
  progress        INT NOT NULL DEFAULT 0,
  violation_count INT NOT NULL DEFAULT 0,
  answers_json    MEDIUMTEXT NULL,
  violations_json MEDIUMTEXT NULL,
  archived_at     DATETIME NOT NULL,
  UNIQUE KEY uq_attempt (submission_id, attempt),
  FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Audit log of every "Re-enable exam access" decision. Deliberately has no foreign keys, so the record survives later deletions.
CREATE TABLE IF NOT EXISTS access_restorations (
  id                   INT AUTO_INCREMENT PRIMARY KEY,
  submission_id        INT NOT NULL,
  exam_id              INT NOT NULL,
  exam_code            VARCHAR(20)  NOT NULL,
  student_id           INT NOT NULL,
  student_no           VARCHAR(30)  NOT NULL,
  student_name         VARCHAR(120) NOT NULL,
  professor_id         INT NOT NULL,
  professor_name       VARCHAR(120) NOT NULL,
  mode                 ENUM('resume','new_attempt') NOT NULL,
  from_attempt         INT NOT NULL,
  to_attempt           INT NOT NULL,
  violations_at_restore INT NOT NULL DEFAULT 0,
  threshold            INT NOT NULL DEFAULT 0,
  reason               VARCHAR(255) NULL,
  note                 VARCHAR(255) NULL,
  ip_address           VARCHAR(45) NULL,
  created_at           DATETIME NOT NULL,
  KEY idx_restore_exam (exam_id, created_at),
  KEY idx_restore_prof (professor_id, created_at)
) ENGINE=InnoDB;

-- WebRTC signalling for the live webcam view (short-lived messages between a professor and a student's browser).
-- The video itself never touches the server: it travels browser-to-browser, encrypted (DTLS-SRTP).
CREATE TABLE IF NOT EXISTS rtc_signals (
  id            BIGINT AUTO_INCREMENT PRIMARY KEY,
  submission_id INT NOT NULL,
  viewer_id     INT NOT NULL,                      -- the professor watching
  conn_id       VARCHAR(40) NOT NULL,
  direction     ENUM('to_student','to_viewer') NOT NULL,
  kind          VARCHAR(10) NOT NULL,              -- offer | answer | ice | bye
  payload       MEDIUMTEXT NULL,
  created_at    DATETIME NOT NULL,
  KEY k_student (submission_id, direction, id),
  KEY k_viewer  (viewer_id, direction, id),
  FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE,
  CONSTRAINT fk_rtc_viewer FOREIGN KEY (viewer_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Academic-year history of a student: one row per school year that was closed while the student was enrolled
CREATE TABLE IF NOT EXISTS student_year_records (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  student_id     INT NOT NULL,
  school_year_id INT NULL,
  program_id     INT NULL,
  year_level     VARCHAR(20) NULL,
  result         ENUM('completed','enrolled') NOT NULL DEFAULT 'completed',   -- 'enrolled' = counted as attended but not as a completed year (irregular students: an admin verifies those)
  recorded_at    DATETIME NOT NULL,
  UNIQUE KEY uq_student_sy (student_id, school_year_id),
  FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_syr_school_year FOREIGN KEY (school_year_id) REFERENCES school_years(id) ON DELETE SET NULL,
  CONSTRAINT fk_syr_program FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- In-app notifications (bell icon). One row per recipient; (user_id, ref_key) is unique so one event never notifies a person twice.
CREATE TABLE IF NOT EXISTS notifications (
  id         BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT NOT NULL,
  type       VARCHAR(30)  NOT NULL,
  title      VARCHAR(150) NOT NULL,
  message    VARCHAR(500) NOT NULL,
  link       VARCHAR(200) NULL,
  ref_key    VARCHAR(120) NOT NULL,
  is_read    TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  read_at    DATETIME NULL,
  UNIQUE KEY uq_user_ref (user_id, ref_key),
  KEY idx_user_unread (user_id, is_read, id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Activity log of administrative actions. No foreign keys on purpose: the record must survive deletions.
CREATE TABLE IF NOT EXISTS audit_log (
  id          BIGINT AUTO_INCREMENT PRIMARY KEY,
  actor_id    INT NULL,
  actor_name  VARCHAR(120) NULL,
  action      VARCHAR(40)  NOT NULL,
  target_type VARCHAR(30)  NOT NULL,
  target_label VARCHAR(200) NULL,
  details     TEXT NULL,
  ip_address  VARCHAR(45) NULL,
  created_at  DATETIME NOT NULL,
  KEY idx_audit_time (created_at)
) ENGINE=InnoDB;

-- Tiny key/value table (used to throttle the "exam is coming up" reminder check)
CREATE TABLE IF NOT EXISTS system_state (
  k VARCHAR(40) PRIMARY KEY,
  v VARCHAR(100) NOT NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Reference data: departments, programs, current school year
-- ---------------------------------------------------------------------
INSERT IGNORE INTO departments (code, name) VALUES
('CITCS','College of Information Technology and Computer Studies'),
('CAS','College of Arts and Sciences'),
('CBA','College of Business Administration'),
('CCJ','College of Criminal Justice'),
('COA','College of Accountancy');

INSERT IGNORE INTO programs (department_id, code, name, years) VALUES
((SELECT id FROM departments WHERE code='CITCS'),'BSIT','Bachelor of Science in Information Technology',4),
((SELECT id FROM departments WHERE code='CITCS'),'BSCS','Bachelor of Science in Computer Science',4),
((SELECT id FROM departments WHERE code='CITCS'),'ACT','Associate in Computer Technology',2),
((SELECT id FROM departments WHERE code='CAS'),'BACOM','Bachelor of Arts in Communication',4),
((SELECT id FROM departments WHERE code='CAS'),'BPA','Bachelor of Public Administration',4),
((SELECT id FROM departments WHERE code='CAS'),'BSPSY','Bachelor of Science in Psychology',4),
((SELECT id FROM departments WHERE code='CBA'),'BSBA','Bachelor of Science in Business Administration',4),
((SELECT id FROM departments WHERE code='CCJ'),'BSCRIM','Bachelor of Science in Criminology',4),
((SELECT id FROM departments WHERE code='CCJ'),'BSISM','Bachelor of Science in Industrial Security Management',4),
((SELECT id FROM departments WHERE code='COA'),'BSA','Bachelor of Science in Accountancy',4),
((SELECT id FROM departments WHERE code='COA'),'BSMA','Bachelor of Science in Management Accounting',4);

INSERT IGNORE INTO school_years (label, start_date, end_date, is_current) VALUES ('2026-2027','2026-08-01','2027-05-31',1);

-- ---------------------------------------------------------------------
-- Housekeeping events (OPTIONAL - keep last). They only run while the event scheduler is ON
-- (XAMPP: add  event_scheduler=ON  under [mysqld] in my.ini and restart MySQL).
-- ---------------------------------------------------------------------
CREATE EVENT IF NOT EXISTS ev_purge_rtc_signals
  ON SCHEDULE EVERY 1 HOUR
  DO DELETE FROM rtc_signals WHERE created_at < NOW() - INTERVAL 1 DAY;

CREATE EVENT IF NOT EXISTS ev_purge_old_notifications
  ON SCHEDULE EVERY 1 DAY
  DO DELETE FROM notifications WHERE is_read = 1 AND created_at < NOW() - INTERVAL 180 DAY;
