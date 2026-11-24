-- =====================================================================
-- v5: department + year-level eligibility, separate exam setup / questions, shared exam list, admin-granted editors.
-- Optional: the app runs these steps automatically the first time anyone signs in (ensure_schema() in api/bootstrap.php).
-- Import once into an existing exam_system database; every older exam keeps working (see notes at the bottom).
-- =====================================================================
USE exam_system;

ALTER TABLE exams ADD COLUMN department_id INT NULL AFTER professor_id;
ALTER TABLE exams ADD COLUMN available_until DATETIME NULL AFTER scheduled_at;
ALTER TABLE exams ADD CONSTRAINT fk_exams_department FOREIGN KEY (department_id) REFERENCES departments(id);

CREATE TABLE IF NOT EXISTS exam_year_levels (
  exam_id    INT NOT NULL,
  year_level VARCHAR(20) NOT NULL,
  PRIMARY KEY (exam_id, year_level),
  FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS exam_editors (
  exam_id      INT NOT NULL,
  professor_id INT NOT NULL,
  can_delete   TINYINT(1) NOT NULL DEFAULT 0,
  granted_by   INT NULL,
  granted_at   DATETIME NOT NULL,
  PRIMARY KEY (exam_id, professor_id),
  FOREIGN KEY (exam_id)      REFERENCES exams(id) ON DELETE CASCADE,
  FOREIGN KEY (professor_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Existing exams belong to the department of the professor who created them.
UPDATE exams e JOIN users p ON p.id = e.professor_id SET e.department_id = p.department_id WHERE e.department_id IS NULL;

-- Notes: exams without any exam_year_levels row stay open to every year level, exactly as before.
-- Their department is now enforced (students of other departments no longer see them).
