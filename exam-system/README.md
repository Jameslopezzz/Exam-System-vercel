# EXAM System - PHP / MySQL / face-api.js build

## 1. Setup (XAMPP)
1. Copy this folder to `C:\xampp\htdocs\exam-system` and start **Apache** + **MySQL** in the XAMPP control panel.
2. Open http://localhost/phpmyadmin -> **Import** -> choose `sql/schema.sql` -> Go.
3. Import `sql/seed.sql` the same way (demo accounts + the IT 401 exam with its answer key).
   *Upgrading an older install?* Import `sql/migrate_v2.sql` instead of `schema.sql` - it keeps your data.
4. If your MySQL root has a password, edit `api/config.php`.
5. Open **http://localhost/exam-system/** (use `localhost`, not a file:// path - the camera and sessions need http).

Demo accounts (PIN for all: `password` - change them from the Admin page):

| Role | Account No |
|---|---|
| Admin | ADMIN01 |
| Professor | PROF001 |
| Student | 23151159, 23151250, 23151223, 23151216, 23151179 |

## 2. What was added
| Area | Files |
|---|---|
| Database | `sql/schema.sql`, `sql/seed.sql` (users, exams, questions, submissions, answers, violations) |
| Login + roles | `api/auth.php`, `js/api.js` (page guard), `js/login.js`, bcrypt hashes, session, 5-try lockout |
| Face + gaze detection | `js/detection.js`, `js/vendor/face-api.js`, `models/` (bundled, works offline) |
| Violation logger / auto-submit | `api/violation.php` (server counts strikes and force-submits at the threshold) |
| Auto-grading | `api/submit.php` + `finalize_submission()` in `api/bootstrap.php` |
| Live monitor | `api/monitor.php` (poll every 3 s, Send warning, Force submit) |
| Network status bar | `api/ping.php` + header indicator in `student-exam.html` |
| Reports + CSV export | `api/report.php`, `api/export.php` (scores and violation log), `professor-reports.html` |
| Exam Builder | saves exams with rules, threshold and answer keys (`api/exam.php?action=save`) |
| Student/User manager | `admin-accounts.html`, `api/users.php` |

## 2b. Admin console, departments, sign-up approval, school years
| Feature | Where |
|---|---|
| Admin manages accounts, departments and school years (the professor pages are professor-only; the admin has its own exam pages, see section 2d) | `admin-accounts.html` |
| Departments (CITCS, CAS, CBA, CCJ, COA preloaded) with their programs; admin sees every professor per department | Admin Console > Departments, `api/departments.php` |
| Sign-up for students / professors; stays *pending* until an admin approves it | `index.html` > "Create an account", Admin Console > Approvals |
| Existing account number -> sign-up is rejected automatically | `api/auth.php?action=signup` (also guarded by the UNIQUE key) |
| Account forms: department for professors; department + course + year level (First, Second, Third, Fourth, Fifth, Irregular) for students | sign-up page and Admin > New account |
| Admin can edit any account (name, number, role, department, course, year), plus disable / reset PIN | Admin Console > Accounts > Edit |
| School years: when the end date passes, every approved student moves up one year level once. Irregular students are skipped; students already in their program's last year are marked *Graduated* | Admin Console > School Year, `api/school_year.php` |

Notes:
- The promotion runs automatically the next time anyone signs in or the admin opens the console after the end date (no cron job needed). *End now & promote* does it on demand.
- A school year added with an end date already in the past is stored as history and does **not** promote students.
- Only *pending / rejected* sign-up requests can be deleted; approved accounts are disabled instead.
- Admins cannot take part in exams, so the student pages remain student-only.

## 2c. Profile, exam sections, delete / re-open exams, dark mode (v3)
*Upgrading?* Nothing to import: the app adds the new tables/columns to an older database automatically the first time anyone signs in (`ensure_schema()` in `api/bootstrap.php`). `sql/migrate_v3.sql` is kept only for manual upgrades. Fresh installs: `schema.sql` already has everything.

| Feature | Where |
|---|---|
| **My Profile** for every role - click your name in the header > *My profile* (also has Sign out, Change PIN and the dark-mode switch) | `profile.html`, `js/profile.js`, `api/profile.php` |
| Professor profile: account info + every exam they created, grouped **Draft / Scheduled / Available / Closed** | profile page |
| Student profile: account info + their examinations (available, upcoming, completed, auto-submitted, closed) with scores and violations | profile page |
| Admin profile: account info + system overview (accounts, approvals, exams, submissions, violations, departments, school year, recent exams/accounts) | profile page |
| Change PIN (asks for the current PIN) | `api/auth.php?action=change_pin` |
| **Sections per exam**: while creating an exam choose *All sections* or only selected ones (pick existing sections or type a new one). Students outside the chosen sections cannot see or start the exam. A **Sections** button on each exam edits it later | Exam Builder, `exam_sections` table, `api/exam.php` |
| **Delete exam** (red button on each exam; asks for confirmation; removes questions, submissions, answers and violations) | Exam Builder, `api/exam.php?action=delete` |
| **Re-enable access after an auto-submit**: replaced by the audited flow in section 2d (nothing is deleted any more) | `api/monitor.php` (action `restore`) |
| Live Monitoring now has an exam selector (also reachable via the *Monitor* button in the Exam Builder) | `professor-monitoring.html` |
| **Dark mode**: switch in the account menu / profile page (toggle button on the sign-in and exam pages). Remembered per device; follows the OS setting until you choose | `js/theme.js`, `css/style.css` |

Notes:
- An exam with no section rows is open to every section (same as before).
- Sections come from the free-text *Section* field of student accounts (e.g. `BSIT-4F`).
- Also fixed: `.btn--primary` was never defined in the CSS, so primary buttons looked unstyled.

## 3. Detection notes (tune during alpha testing)
- All analysis runs in the student's browser; no video is uploaded.
- The first ~5 seconds are **calibration** (student looks at the screen). Later head-turn / gaze are compared with that baseline.
- A condition must last 2.5 s (4 s for "no face") and each type has a 10 s cooldown. Constants are at the top of `js/detection.js`.
- Eye gaze is estimated from the iris position inside each eye contour, so it needs a reasonably lit, front-facing webcam. Expect false positives with glasses glare or very low light.
- Demo without a camera: open `student-exam.html?exam=1&demo=1` to show manual trigger buttons.

## 4. Known limits
- Essay questions are stored but there is no grading screen yet; the Percent column treats pending essays as 0.
- The monitor shows connection status and flags, not a live video feed (that needs WebRTC/streaming).
- Question media in the builder accepts picture/video links only (no file upload). Tables/charts exist only in the seeded exam.
- Change all demo PINs and the DB password before real use, and serve over HTTPS in production.


## 2d. Admin exam oversight, exam editing, live webcam, re-enable access (v4)

**Upgrade an existing database:** nothing to do - the app adds the new columns/tables the first time anyone signs in (`ensure_schema()`), or import `sql/migrate_v4.sql` once. Fresh installs use `sql/schema.sql`.

**Admin (own pages, read-only for exams)** - `admin-dashboard.html`, `admin-exams.html`, `admin-monitoring.html`, `admin-reports.html`, `admin-accounts.html`
backed by `api/admin_exams.php`, `api/admin_monitor.php`, `api/admin_report.php` (+ `api/admin_lib.php`). Admins can no longer open or call the professor pages / endpoints (403).
- Dashboard: total exams, exams grouped by department, professor, year level(s), students assigned, and completed / ongoing / pending / auto-submitted counts.
- Exams: filter by department, year level, professor, status; read-only details (rules, questions with answer key, roster, restoration history).
- Monitoring: participation across departments, per-student status/progress/violations, latest violations. No video, warnings or force-submit.
- Reports: filter by department, year level, professor, exam; charts, tables, CSV export.
- "Students assigned" follows the real access rule: an exam with no sections is open to every active student, otherwise only the chosen sections; students who already have an attempt always count. Department = the creating professor's department.

**Professor - edit exams** (`Exam Builder` > *Edit exam*, or `professor-exam-creation.html?edit=ID`; `exam.php` actions `get` / `update`): only the creator can edit (403 otherwise). Title, subject, description, schedule and sections can always be changed (sections that already have attempts cannot be removed). Questions, choices, correct answers, points, time limit, violation limit and proctoring rules are **locked** while the exam is Available or any student has an attempt.

**Professor - live webcam** (`js/livecam.js`, `api/signal.php`): real WebRTC video, browser-to-browser and encrypted (DTLS-SRTP). The server only relays small signalling messages, and only between a professor and students of *that professor's own* exams. Each student sends ~320x240, 12 fps, 150 kbps; the monitor opens up to 24 videos automatically (others via *Load camera*), closes them when the tab stays in the background, reconnects automatically, and shows *camera disconnected / blocked / no video / student offline* on the tile, in a banner and in the event list.
- Needs HTTPS (or `localhost`) for the student's camera, like before. Across different networks/NATs set a TURN relay: environment variables `EXAM_TURN_URL`, `EXAM_TURN_USER`, `EXAM_TURN_PASS` (STUN: `EXAM_STUN_URL`). On one LAN it works without them.

**Professor - re-enable access** (Live Monitoring > *Auto-submitted at the violation limit*): lists students auto-submitted for reaching the violation limit (name, ID, violations, reason, status). *Re-enable Exam Access* asks for confirmation and a choice: **Resume** (answers restored, clock continues with the remaining time) or **New attempt** (blank answers, full time). Nothing is deleted: violations stay in `violations` tagged with their `attempt`; the ended attempt (answers, score, reason) is archived in `attempt_history`; every decision is logged in `access_restorations` (professor, date/time, mode, note, IP). The new session's violation count starts at 0.

## 2e. Department + year-level eligibility, two-step exam builder, shared exam list (v5)

**Upgrade an existing database:** nothing to do - the app adds the new columns/tables the first time anyone signs in (`ensure_schema()`), or import `sql/migrate_v5.sql` once. Fresh installs use `sql/schema.sql` + `sql/seed.sql`.
Existing exams keep working: each gets the department of the professor who created it; an exam with no year-level rows stays open to every year level.

| Feature | Where |
|---|---|
| **Department eligibility** - an exam's department is the creating professor's department, read from the database on the server (never from the browser). Only students of that department can see or start it. The section picker only lists sections of the professor's own department, and the server rejects sections that only contain students of other departments | `exams.department_id`, `api/exam.php` (`save`, `update`, `sections`), `student_exam_eligibility()` in `api/bootstrap.php` |
| **Year-level eligibility** - one or more of First ... Fifth Year / Irregular, required in the setup. Only students of the exam's department AND a selected year level (AND a selected section, if any) can open it; a live count of eligible students is shown while setting up | table `exam_year_levels`, `api/exam.php?action=eligible` |
| **Step 1 - Exam setup** (title, subject, description/instructions, department, year levels, sections, exam date, *available until*, duration, violation limit, proctoring rules). Saving creates the exam as a **draft**, then opens step 2 | `professor-exam-setup.html`, `js/exam-setup.js` |
| **Step 2 - Add questions** (add, edit, delete, reorder, media link). Every question belongs to one exam. No questions inside the setup form | `professor-exam-questions.html`, `js/exam-questions.js`, `exam.php` actions `question_save`, `question_delete`, `question_reorder` |
| **Shared exam list** - *Exam Builder > All examinations* (and a card on the professor dashboard): every professor sees every exam with title, created by, department, year level(s), schedule and status, with filters. *View* opens the details and questions read-only | `exam.php?action=shared`, `action=view` |
| **Who can change an exam** - only the creator. An **administrator** can allow other professors to edit (optionally delete) a specific exam: Admin > Exams > *View details* > *Extra editors* | table `exam_editors`, `admin_exams.php` (`editors`, `grant_editor`, `revoke_editor`) |

Rules and notes:
- **Enforced on the server** (the pages only mirror them): `exam.php?action=list` only returns exams the student is eligible for, and `action=start` - the only place an attempt can begin - refuses other departments / year levels / sections with a 403. Students who already have an attempt keep access to it (same as sections before).
- An exam cannot be set to *Scheduled* or *Available* until it has at least one question. Questions stay locked while the exam is Available or has attempts (unchanged). Year levels / sections that already have attempts cannot be removed.
- *Available until*: after that time students can no longer **start** the exam (the exam then shows as closed to them; those already taking it finish normally). *Exam date and time* is informational, as before - opening is still done with the Status menu.
- Other professors can read questions but **do not receive the answer key**; the creator and professors granted editing do. Monitoring, reports, CSV export and live camera stay creator-only.
- The old combined "New examination / Edit examination" pop-ups were replaced by the two pages above (the *Sections* button is now part of the setup page). The old `save` / `update` actions no longer accept questions.
- Admin counts ("students assigned") follow the same department + year level + section rule.
