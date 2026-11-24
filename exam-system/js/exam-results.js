/* =========================================================
   Examination results + exam form (professor and administrator pages).
   Professor: own / authorised exams - results, essay grading + feedback, release settings.
   Administrator: every exam, read-only.
   All data comes from api/exam_results.php, which decides on the server what each role may see.
   ========================================================= */
document.addEventListener("DOMContentLoaded", function () {
  Session.ready.then(function (user) {
    var isAdmin = user.role === "admin";
    var examId = new URLSearchParams(window.location.search).get("exam") || "";
    var data = null, formLoaded = null;

    var STATUS = { submitted: "Submitted", "auto-submitted": "Auto-submitted", "in-progress": "In progress" };
    function when(s) { return s ? String(s).replace("T", " ").slice(0, 16) : "—"; }
    function note(id, text, bad) { var n = el(id); n.textContent = text; n.style.color = bad ? "var(--destructive)" : ""; }

    function loadExamList() {
      var req = isAdmin ? API.get("admin_exams.php?action=options").then(function (o) { return o.exams; })
                        : API.get("exam.php?action=list").then(function (r) { return r.exams; });
      return req.then(function (list) {
        var sel = el("resExam");
        sel.innerHTML = list.length ? '<option value="">Select an examination</option>' + list.slice().reverse().map(function (e) {
          return '<option value="' + esc(e.id) + '">' + esc(e.code) + " · " + esc(e.title) + " (" + esc(e.status) + ")</option>";
        }).join("") : '<option value="">No examinations</option>';
        if (examId) sel.value = examId;
      });
    }

    function renderList(r) {
      data = r;
      var e = r.exam;
      el("resSubtitle").textContent = e.code + " · " + e.title + " · " + e.subject + " · " + e.status + (e.professor ? " · " + e.professor : "");
      el("releaseCard").hidden = !r.can_grade;
      el("relScore").checked = e.release_score; el("relAnswers").checked = e.release_answers;
      var s = r.stats;
      el("resStats").innerHTML =
        '<div class="stat"><p class="stat__label">Graded attempts</p><p class="stat__value">' + esc(s.count) + "</p></div>" +
        '<div class="stat"><p class="stat__label">Average</p><p class="stat__value">' + (s.average === null ? "—" : esc(s.average) + "%") + "</p></div>" +
        '<div class="stat"><p class="stat__label">Highest</p><p class="stat__value is-success">' + (s.highest === null ? "—" : esc(s.highest) + "%") + "</p></div>" +
        '<div class="stat"><p class="stat__label">Lowest</p><p class="stat__value is-danger">' + (s.lowest === null ? "—" : esc(s.lowest) + "%") + "</p></div>";
      el("resBody").innerHTML = r.submissions.length ? r.submissions.map(function (x) {
        var done = x.status !== "in-progress";
        return "<tr><td>" + esc(x.name) + '<p class="text-xs muted">' + esc(x.account_no) + "</p></td><td>" + esc(x.section || "—") + "</td>" +
          '<td><span class="badge badge--' + esc(x.status) + '">' + esc(STATUS[x.status] || x.status) + "</span></td>" +
          "<td>" + (done ? "<b>" + esc(x.points) + " / " + esc(x.max) + "</b> (" + esc(x.percent === null ? "—" : x.percent + "%") + ")" + (x.essays_pending ? '<p class="text-xs danger">Essays need grading</p>' : "") : "—") + "</td>" +
          '<td class="' + (x.violations ? "danger" : "muted") + '">' + esc(x.violations) + "</td><td class=\"text-sm\">" + esc(when(x.submitted_at)) + "</td>" +
          "<td>" + (done ? '<button type="button" class="btn btn--outline btn--sm" data-review="' + esc(x.submission_id) + '">' + (r.can_grade ? "Review / grade" : "View answers") + "</button>" : "") + "</td></tr>";
      }).join("") : '<tr><td colspan="7" class="muted">No student has started this examination yet.</td></tr>';
    }

    function loadResults() {
      if (!examId) { el("resSubtitle").textContent = "Select an examination."; el("resBody").innerHTML = '<tr><td colspan="7" class="muted">No examination selected.</td></tr>'; el("resStats").innerHTML = ""; el("releaseCard").hidden = true; return Promise.resolve(); }
      return API.get("exam_results.php?action=list&exam_id=" + encodeURIComponent(examId)).then(renderList).catch(function (e) {
        el("resSubtitle").textContent = e.message;
        el("resBody").innerHTML = '<tr><td colspan="7" class="danger">' + esc(e.message) + "</td></tr>";
        el("releaseCard").hidden = true; el("resStats").innerHTML = "";
      });
    }

    /* ---------- exam form ---------- */
    function questionHtml(q, i) {
      var key = q.correct;
      return '<div class="q-block" style="margin-bottom:1.1rem"><p><b>' + (i + 1) + ".</b> " + esc(q.prompt) + ' <span class="muted">(' + (q.type === "essay" ? "essay" : "multiple choice") + ", " + esc(q.points) + " pt" + (q.points === 1 ? "" : "s") + ")</span></p>" +
        renderMedia(q.media) +
        (q.type === "multiple-choice" ? '<ul style="margin:.35rem 0 0 1.1rem">' + q.choices.map(function (c) {
          return "<li" + (c === key ? ' class="is-correct" style="font-weight:600"' : "") + ">" + esc(c) + (c === key ? " ✓ correct answer" : "") + "</li>";
        }).join("") + "</ul>" : '<p class="text-sm muted" style="margin-top:.3rem">Written answer, graded by the professor.</p>') + "</div>";
    }
    function loadForm() {
      if (!examId) { el("formBody").innerHTML = '<p class="muted">Select an examination.</p>'; return; }
      el("formBody").innerHTML = '<p class="muted">Loading…</p>';
      API.get("exam_results.php?action=form&exam_id=" + encodeURIComponent(examId)).then(function (r) {
        el("formNote").textContent = r.exam.code + " · " + r.exam.title + " · " + r.questions.length + " questions · " + r.exam.total_points + " points" + (r.read_only ? " · read-only" : "");
        el("formBody").innerHTML = (r.exam.description ? '<p class="text-sm" style="margin-bottom:.8rem">' + esc(r.exam.description) + "</p>" : "") +
          (r.questions.map(questionHtml).join("") || '<p class="muted">This examination has no questions yet.</p>');
      }).catch(function (e) { el("formBody").innerHTML = '<p class="danger">' + esc(e.message) + "</p>"; });
    }

    /* ---------- review one student ---------- */
    function reviewQuestion(q, i, canGrade) {
      var mcq = q.type === "multiple-choice";
      var verdict = mcq ? (q.answer === null || q.answer === "" ? '<span class="muted">Not answered</span>' : q.is_correct ? '<span class="is-success">Correct</span>' : '<span class="danger">Incorrect</span>') : "";
      var h = '<div class="q-block" style="margin-bottom:1.1rem;padding-bottom:.9rem;border-bottom:1px solid var(--border)"><p><b>' + (i + 1) + ".</b> " + esc(q.prompt) + ' <span class="muted">(' + esc(q.points) + " pt" + (q.points === 1 ? "" : "s") + ")</span></p>" + renderMedia(q.media);
      if (mcq) {
        h += '<ul style="margin:.35rem 0 0 1.1rem">' + q.choices.map(function (c) {
          var mine = c === q.answer, right = c === q.correct;
          return "<li" + (right ? ' class="is-correct"' : "") + ' style="' + (mine ? "font-weight:700;" : "") + '">' + esc(c) + (mine ? " ← student's answer" : "") + (right ? " ✓" : "") + "</li>";
        }).join("") + "</ul>" +
          '<p class="text-sm" style="margin-top:.3rem">' + verdict + " · " + esc(q.points_earned) + " / " + esc(q.points) + " pts</p>";
      } else {
        h += '<p class="text-sm muted" style="margin-top:.3rem">Student\'s answer:</p><div style="white-space:pre-wrap;border:1px solid var(--border);border-radius:var(--radius-sm);padding:.6rem;margin:.2rem 0">' +
          (q.answer ? esc(q.answer) : '<span class="muted">No answer</span>') + "</div>" +
          (canGrade ? '<label class="field" style="max-width:14rem"><span class="field__label">Points (0 - ' + esc(q.points) + ')</span><input class="input" type="number" min="0" max="' + esc(q.points) + '" data-essay="' + esc(q.id) + '" value="' + esc(q.points_earned) + '" /></label>'
                      : '<p class="text-sm">' + esc(q.points_earned) + " / " + esc(q.points) + " pts</p>");
      }
      return h + "</div>";
    }

    function openReview(subId) {
      el("reviewTitle").textContent = "Student answers";
      el("reviewBody").innerHTML = '<p class="muted">Loading…</p>';
      openModal("reviewModal");
      API.get("exam_results.php?action=review&submission_id=" + encodeURIComponent(subId)).then(function (r) {
        var sc = r.score, st = r.student;
        el("reviewTitle").textContent = st.name + " · " + r.exam.code;
        el("reviewBody").innerHTML =
          '<dl class="detail-list"><div><dt>Student</dt><dd>' + esc(st.name) + " (" + esc(st.account_no) + ")</dd></div><div><dt>Status</dt><dd>" + esc(STATUS[r.submission.status] || r.submission.status) + "</dd></div>" +
          "<div><dt>Score</dt><dd><b>" + esc(sc.points) + " / " + esc(sc.max) + "</b> (" + esc(sc.percent === null ? "—" : sc.percent + "%") + ")" + (sc.essays_pending ? ' <span class="danger">· essays not graded yet</span>' : "") + "</dd></div>" +
          "<div><dt>Submitted</dt><dd>" + esc(when(r.submission.submitted_at)) + "</dd></div>" +
          "<div><dt>Violations</dt><dd>" + esc(r.violations.length) + "</dd></div>" + (r.submission.auto_reason ? "<div><dt>Auto-submitted because</dt><dd>" + esc(r.submission.auto_reason) + "</dd></div>" : "") + "</dl>" +
          (r.violations.length ? '<p class="text-xs muted" style="margin:.4rem 0">' + r.violations.map(function (v) { return esc(v.label) + " (" + esc(v.at) + ")"; }).join(" · ") + "</p>" : "") +
          '<h3 class="section-title">Answers</h3>' + r.questions.map(function (q, i) { return reviewQuestion(q, i, r.can_grade); }).join("") +
          (r.can_grade
            ? '<label class="field"><span class="field__label">Feedback for the student (shown when you release scores)</span><textarea class="input" id="gFeedback" rows="3" maxlength="2000">' + esc(r.submission.feedback || "") + "</textarea></label>" +
              '<div class="btn-row"><button type="button" class="btn btn--primary" id="gSave">Save grading</button><span class="text-sm" id="gNote"></span></div>'
            : (r.submission.feedback ? '<p class="text-sm"><b>Professor\'s feedback:</b> ' + esc(r.submission.feedback) + "</p>" : ""));
        var save = el("gSave");
        if (save) save.addEventListener("click", function () {
          var pts = {};
          [].forEach.call(el("reviewBody").querySelectorAll("[data-essay]"), function (i) { pts[i.getAttribute("data-essay")] = Number(i.value) || 0; });
          save.disabled = true;
          API.post("exam_results.php?action=grade", { submission_id: subId, points: pts, feedback: el("gFeedback").value }).then(function () {
            note("gNote", "Saved."); loadResults(); openReview(subId);
          }).catch(function (e) { save.disabled = false; note("gNote", e.message, true); });
        });
      }).catch(function (e) { el("reviewBody").innerHTML = '<p class="danger">' + esc(e.message) + "</p>"; });
    }

    /* ---------- wiring ---------- */
    el("resBody").addEventListener("click", function (e) { var b = e.target.closest("[data-review]"); if (b) openReview(b.getAttribute("data-review")); });
    el("resExam").addEventListener("change", function () {
      examId = this.value; formLoaded = null;
      try { history.replaceState(null, "", examId ? "?exam=" + encodeURIComponent(examId) : window.location.pathname); } catch (e) {}
      loadResults();
      if (!el("rtab-form").hidden) loadForm();
    });
    [].forEach.call(document.querySelectorAll("[data-rtab]"), function (b) {
      b.addEventListener("click", function () {
        var t = b.getAttribute("data-rtab");
        el("rtab-results").hidden = t !== "results"; el("rtab-form").hidden = t !== "form";
        [].forEach.call(document.querySelectorAll("[data-rtab]"), function (x) { x.classList.toggle("is-active", x === b); });
        if (t === "form" && formLoaded !== examId) { formLoaded = examId; loadForm(); }
      });
    });
    el("relAnswers").addEventListener("change", function () { if (this.checked) el("relScore").checked = true; });
    el("relSave").addEventListener("click", function () {
      API.post("exam_results.php?action=release", { exam_id: Number(examId), release_score: el("relScore").checked, release_answers: el("relAnswers").checked }).then(function (r) {
        note("relNote", "Saved" + (r.notified ? " · " + r.notified + " student(s) notified." : "."));
        loadResults();
      }).catch(function (e) { note("relNote", e.message, true); loadResults(); });
    });

    loadExamList().then(loadResults);
    window.setInterval(function () { if (examId && el("reviewModal").hidden) loadResults(); }, 15000);
  });
});
