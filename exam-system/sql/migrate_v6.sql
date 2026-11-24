-- =====================================================================
-- Migration v6 - alpha-test fixes
-- The application applies exactly this automatically on the first request (api/bootstrap.php, ensure_schema "v6"), and only
-- when a column / table is missing, so you normally do NOT need to run this file. It is provided for manual / DBA use.
-- Run it ONCE on a database created from an older schema. (ALTER TABLE ... ADD COLUMN is not repeatable.)
-- =====================================================================

-- ---- students: type + graduation tracking ----------------------------
ALTER TABLE users ADD COLUMN student_type ENUM('regular','irregular') NULL DEFAULT NULL;
-- Only what the data states for certain. Everybody else stays NULL = "not classified": nobody is assumed to be Regular.
UPDATE users SET student_type = 'irregular' WHERE role = 'student' AND year_level = 'Irregular';

ALTER TABLE users ADD COLUMN completed_years TINYINT NOT NULL DEFAULT 0;
UPDATE users SET completed_years = GREATEST(FIELD(year_level,'First Year','Second Year','Third Year','Fourth Year','Fifth Year') - 1, 0)
                                   + IF(graduated = 1 AND FIELD(year_level,'First Year','Second Year','Third Year','Fourth Year','Fifth Year') > 0, 1, 0)
 WHERE role = 'student' AND FIELD(year_level,'First Year','Second Year','Third Year','Fourth Year','Fifth Year') > 0;

ALTER TABLE users ADD COLUMN graduation_status ENUM('not_eligible','eligible','graduated') NOT NULL DEFAULT 'not_eligible';
-- The old automation graduated students without any verification: they become "eligible" (to be verified), never "graduated".
UPDATE users SET graduation_status = 'eligible' WHERE role = 'student' AND graduated = 1;
UPDATE users SET graduated = 0 WHERE role = 'student';      -- users.graduated is now kept in step with graduation_status = 'graduated'

ALTER TABLE users ADD COLUMN graduation_locked TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN graduation_verified_by INT NULL;
ALTER TABLE users ADD COLUMN graduation_verified_at DATETIME NULL;
ALTER TABLE users ADD COLUMN graduation_note VARCHAR(255) NULL;

-- Program durations (editable later in Admin > Departments)
UPDATE programs p JOIN departments d ON d.id = p.department_id SET p.years = 2 WHERE d.code = 'CITCS' AND p.code = 'ACT';
UPDATE programs p JOIN departments d ON d.id = p.department_id SET p.years = 4 WHERE d.code = 'CITCS' AND p.code IN ('BSIT','BSCS');

CREATE TABLE IF NOT EXISTS student_year_records (
  id INT AUTO_INCREMENT PRIMARY KEY, student_id INT NOT NULL, school_year_id INT NULL, program_id INT NULL, year_level VARCHAR(20) NULL,
  result ENUM('completed','enrolled') NOT NULL DEFAULT 'completed', recorded_at DATETIME NOT NULL,
  UNIQUE KEY uq_student_sy (student_id, school_year_id),
  FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---- exam result release + feedback ------------------------------------
ALTER TABLE exams ADD COLUMN release_score TINYINT(1) NOT NULL DEFAULT 0;
UPDATE exams SET release_score = 1;                          -- existing exams keep showing scores, exactly as before
ALTER TABLE exams ADD COLUMN release_answers TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE exams ADD COLUMN results_released_at DATETIME NULL;
ALTER TABLE submissions ADD COLUMN feedback TEXT NULL;
ALTER TABLE submissions ADD COLUMN graded_by INT NULL;
ALTER TABLE submissions ADD COLUMN graded_at DATETIME NULL;

-- ---- notifications, activity log -----------------------------------------
CREATE TABLE IF NOT EXISTS notifications (
  id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, type VARCHAR(30) NOT NULL, title VARCHAR(150) NOT NULL, message VARCHAR(500) NOT NULL,
  link VARCHAR(200) NULL, ref_key VARCHAR(120) NOT NULL, is_read TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, read_at DATETIME NULL,
  UNIQUE KEY uq_user_ref (user_id, ref_key), KEY idx_user_unread (user_id, is_read, id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_log (
  id BIGINT AUTO_INCREMENT PRIMARY KEY, actor_id INT NULL, actor_name VARCHAR(120) NULL, action VARCHAR(40) NOT NULL, target_type VARCHAR(30) NOT NULL,
  target_label VARCHAR(200) NULL, details TEXT NULL, ip_address VARCHAR(45) NULL, created_at DATETIME NOT NULL, KEY idx_audit_time (created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS system_state (k VARCHAR(40) PRIMARY KEY, v VARCHAR(100) NOT NULL) ENGINE=InnoDB;
