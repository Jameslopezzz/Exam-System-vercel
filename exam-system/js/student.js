var EXAMS = [];   // filled from api/exam.php?action=list

function normaliseExam(r) {
  var done = r.sub_mode && r.sub_mode !== "in-progress";
  var status = done ? "completed" : r.status === "available" ? "available" : "scheduled";
  return {
    id: r.id, code: r.code, title: r.title, subject: r.subject, professor: r.professor,
    durationMinutes: r.duration_minutes, items: r.items, status: status,
    resume: r.sub_mode === "in-progress",
    violations: Number(r.violations) || 0,
    score: r.score_released && r.max_score > 0 ? Math.round((r.auto_score / r.max_score) * 100) : null,   // null until the professor releases it
    schedule: r.scheduled_at ? r.scheduled_at : (status === "available" ? "Open now" : "To be announced"),
    questions: { length: r.items }
  };
}

function loadExams() {
  return Session.ready.then(function () {
    return API.get("exam.php?action=list");
  }).then(function (r) {
    EXAMS = r.exams.map(normaliseExam);
  });
}

function examActionHtml(exam) {
  if (exam.status === "available") {
    return (
      '<a class="btn btn--primary" href="student-exam.html?exam=' + esc(exam.id) + '">' + (exam.resume ? "Resume exam" : "Start exam") + '</a>'
    );
  }
  if (exam.status === "completed") {
    return exam.score === null ? '<span class="badge">Submitted</span>' : '<span class="badge">Score ' + esc(exam.score) + "%</span>";
  }
  return '<span class="badge">Scheduled</span>';
}

/* Greeting and sub-title come from the signed-in account (course, year level, section, account number). */
function renderStudentGreeting() {
  var title = el("greeting");
  if (!title) return;
  var u = Session.user, h = new Date().getHours();
  var first = String(u.name || "").trim().split(/\s+/)[0] || "";
  title.textContent = (h < 12 ? "Good Morning" : h < 18 ? "Good Afternoon" : "Good Evening") + ", " + first;
  el("greetingMeta").textContent = "Student No. " + u.account_no;
  API.get("profile.php").then(function (r) {
    var a = r.account, bits = [];
    if (a.program) bits.push(a.program.code);
    if (a.year_level) bits.push(a.year_level);
    if (a.section) bits.push(a.section);
    bits.push("Student No. " + a.account_no);
    el("greetingMeta").textContent = bits.join(" · ");
  }).catch(function () {});
}

function renderStudentDashboard() {
  var list = el("dashboardExams");
  if (!list) return;

  var available = EXAMS.filter(function (e) {
    return e.status === "available";
  });
  if (el("statOpen")) el("statOpen").textContent = String(available.length);
  var violations = EXAMS.reduce(function (n, e) { return n + e.violations; }, 0);
  if (el("statDone")) el("statDone").textContent = String(EXAMS.filter(function (e) { return e.status === "completed"; }).length);
  if (el("statViol")) el("statViol").textContent = String(violations);
  if (el("statStanding")) {
    el("statStanding").textContent = violations === 0 ? "Good" : violations < 3 ? "Fair" : "At risk";
    el("statStanding").className = "stat__value " + (violations === 0 ? "is-success" : "is-danger");
  }

  if (!EXAMS.length) {
    list.innerHTML = '<li class="muted text-sm">No examinations are available for your department, year level and section yet.</li>';
    return;
  }
  list.innerHTML = EXAMS.map(function (exam) {
    return (
      '<li class="row-between">' +
      "<div>" +
      '<p class="font-display" style="font-size:1rem;font-weight:600">' +
      esc(exam.code) + " · " + esc(exam.title) +
      "</p>" +
      '<p class="text-sm muted">' + esc(exam.subject) + " · " + esc(exam.professor) + "</p>" +
      '<p class="text-xs muted">' + esc(exam.schedule) + " · " + esc(exam.durationMinutes) + " minutes</p>" +
      "</div>" +
      examActionHtml(exam) +
      "</li>"
    );
  }).join("");
}

function renderStudentExams(filter) {
  var grid = el("examGrid");
  if (!grid) return;

  var rows = EXAMS.filter(function (e) {
    return filter === "all" || e.status === filter;
  });

  if (!rows.length) {
    grid.innerHTML = '<p class="muted text-sm">No examinations match this filter.</p>';
    return;
  }

  grid.innerHTML = rows
    .map(function (exam) {
      var action;
      if (exam.status === "available") {
        action =
          '<a class="btn btn--primary btn--block exam-card__action" href="student-exam.html?exam=' +
          esc(exam.id) + '">' + (exam.resume ? "Resume exam" : "Take exam") + '</a>';
      } else if (exam.status === "completed") {
        action =
          '<a class="btn btn--outline btn--block exam-card__action" href="student-results.html">' +
          (exam.score === null ? "Submitted · results pending" : "View result · " + esc(exam.score) + "%") + "</a>";
      } else {
        action = '<p class="exam-card__status">Not yet open</p>';
      }

      return (
        '<section class="card exam-card"><div class="card__body">' +
        '<span class="exam-card__code">' + esc(exam.code) + "</span>" +
        "<h3>" + esc(exam.title) + "</h3>" +
        '<p class="text-sm muted">' + esc(exam.subject) + "</p>" +
        '<dl class="exam-card__meta">' +
        "<div><dt>Professor</dt><dd>" + esc(exam.professor) + "</dd></div>" +
        "<div><dt>Schedule</dt><dd>" + esc(exam.schedule) + "</dd></div>" +
        "<div><dt>Duration</dt><dd>" + esc(exam.durationMinutes) + " min</dd></div>" +
        "<div><dt>Items</dt><dd>" + (exam.items || "—") + "</dd></div>" +
        "</dl>" +
        action +
        "</div></section>"
      );
    })
    .join("");
}

function initExamFilters() {
  var chips = document.querySelectorAll("[data-filter]");
  if (!chips.length) return;

  renderStudentExams("all");

  for (var i = 0; i < chips.length; i++) {
    (function (chip) {
      chip.addEventListener("click", function () {
        for (var j = 0; j < chips.length; j++) chips[j].classList.remove("is-active");
        chip.classList.add("is-active");
        renderStudentExams(chip.getAttribute("data-filter"));
      });
    })(chips[i]);
  }
}

function renderStudentResults() {
  var body = el("resultsBody");
  if (!body) return;

  API.get("results.php").then(function (r) {
    var scored = r.results.filter(function (x) { return x.percent !== null && x.percent !== undefined; });
    var viol = r.results.reduce(function (n, x) { return n + (Number(x.violations) || 0); }, 0);
    el("resTaken").textContent = String(r.results.length);
    el("resAvg").textContent = scored.length
      ? Math.round(scored.reduce(function (n, x) { return n + Number(x.percent); }, 0) / scored.length) + "%" : "—";
    el("resViol").textContent = String(viol);
    el("resViol").className = "stat__value" + (viol ? " is-danger" : " is-success");
    if (!r.results.length) {
      body.innerHTML = '<tr><td colspan="7" class="muted">No submitted examinations yet.</td></tr>';
      return;
    }
    body.innerHTML = r.results.map(function (x) {
      return (
        "<tr>" +
        '<td style="font-weight:600">' + esc(x.code) + "</td>" +
        "<td>" + esc(x.title) + "</td>" +
        '<td class="muted">' + esc(x.date) + "</td>" +
        '<td style="font-weight:600">' + esc(x.score) + "</td>" +
        '<td class="' + (x.violations ? "danger" : "muted") + '">' + esc(x.violations) + "</td>" +
        '<td class="muted">' + esc(x.remark) + "</td>" +
        "<td>" + (x.released ? '<button type="button" class="btn btn--outline btn--sm" data-myreview="' + esc(x.exam_id) + '">' + (x.answers_released ? "Review answers" : "Details") + "</button>" : '<span class="text-xs muted">Not released</span>') + "</td>" +
        "</tr>"
      );
    }).join("");
  }).catch(function (e) {
    body.innerHTML = '<tr><td colspan="7" class="danger">' + esc(e.message) + "</td></tr>";
  });
  if (!body.getAttribute("data-wired")) {
    body.setAttribute("data-wired", "1");
    body.addEventListener("click", function (ev) {
      var b = ev.target.closest("[data-myreview]");
      if (b) openMyReview(b.getAttribute("data-myreview"));
    });
  }
}

/* The student's own submitted exam. The server decides what is included: the score and feedback once released, the answers
   together with the correct answers only when the professor released those too. */
function openMyReview(examId) {
  el("reviewTitle").textContent = "Your result";
  el("reviewBody").innerHTML = '<p class="muted">Loading…</p>';
  openModal("reviewModal");
  API.get("exam_results.php?action=my_review&exam_id=" + encodeURIComponent(examId)).then(function (r) {
    var sc = r.score;
    el("reviewTitle").textContent = r.exam.code + " · " + r.exam.title;
    var html = '<dl class="detail-list"><div><dt>Score</dt><dd><b>' + esc(sc.points) + " / " + esc(sc.max) + "</b> (" + esc(sc.percent === null ? "—" : sc.percent + "%") + ")" +
      (sc.essays_pending ? ' <span class="danger">· essay answers are not graded yet</span>' : "") + "</dd></div>" +
      "<div><dt>Professor</dt><dd>" + esc(r.exam.professor) + "</dd></div></dl>";
    if (r.feedback) html += '<p class="text-sm" style="margin:.6rem 0"><b>Feedback from your professor:</b><br>' + esc(r.feedback).replace(/\n/g, "<br>") + "</p>";
    if (!r.answers_released) {
      html += '<p class="muted text-sm">Your professor has not released the correct answers for this examination.</p>';
    } else {
      html += '<h3 class="section-title">Your answers</h3>' + r.questions.map(function (q, i) {
        var mcq = q.type === "multiple-choice";
        var h = '<div class="q-block" style="margin-bottom:1.1rem"><p><b>' + (i + 1) + ".</b> " + esc(q.prompt) + ' <span class="muted">(' + esc(q.points_earned) + " / " + esc(q.points) + " pts)</span></p>" + renderMedia(q.media);
        if (mcq) {
          h += '<ul style="margin:.35rem 0 0 1.1rem">' + q.choices.map(function (c) {
            return "<li" + (c === q.correct ? ' class="is-correct"' : "") + (c === q.answer ? ' style="font-weight:700"' : "") + ">" + esc(c) + (c === q.answer ? " ← your answer" : "") + (c === q.correct ? " ✓" : "") + "</li>";
          }).join("") + "</ul>";
        } else {
          h += '<div style="white-space:pre-wrap;border:1px solid var(--border);border-radius:var(--radius-sm);padding:.6rem;margin-top:.3rem">' + (q.answer ? esc(q.answer) : '<span class="muted">No answer</span>') + "</div>";
        }
        return h + "</div>";
      }).join("");
    }
    el("reviewBody").innerHTML = html;
  }).catch(function (e) { el("reviewBody").innerHTML = '<p class="danger">' + esc(e.message) + "</p>"; });
}

function renderGuidelines() {
  var list = el("monitoredList");
  if (!list) return;

  list.innerHTML = VIOLATION_TYPES.map(function (v) {
    return (
      '<li class="row-between"><span>' + esc(v.label) + "</span>" +
      '<span class="badge">' + esc(v.severity) + "</span></li>"
    );
  }).join("");

  var threshold = document.querySelectorAll("[data-threshold]");
  for (var i = 0; i < threshold.length; i++) {
    threshold[i].textContent = String(VIOLATION_THRESHOLD);
  }
}

document.addEventListener("DOMContentLoaded", function () {
  if (el("dashboardExams") || el("examGrid")) {
    loadExams().then(function () {
      renderStudentGreeting();
      renderStudentDashboard();
      initExamFilters();
    }).catch(function (e) {
      var msg = '<p class="danger text-sm">Could not load your examinations: ' + esc(e.message) + "</p>";
      if (el("dashboardExams")) el("dashboardExams").innerHTML = "<li>" + msg + "</li>";
      if (el("examGrid")) el("examGrid").innerHTML = msg;
    });
  }
  renderStudentResults();
  renderGuidelines();
});
