/* Admin exam management: every professor's exams with filters, and a READ-ONLY detail window. */
document.addEventListener("DOMContentLoaded", function () {
  Session.ready.then(function () {
    var f = Admin.filters({ onChange: load });
    var exams = [];

    function row(e) {
      return "<tr><td><b>" + esc(e.code) + "</b> · " + esc(e.title) + '<p class="text-xs muted">' + esc(e.subject) + " · " + esc(e.duration_minutes) + " min · " + esc(e.items) + " items</p></td>" +
        "<td>" + esc(e.dept_code) + "</td><td>" + esc(e.professor) + '<p class="text-xs muted">' + esc(e.professor_no) + "</p></td><td>" + Admin.yearTags(e.year_levels) + "</td>" +
        "<td>" + esc(e.assigned) + "</td><td>" + Admin.statusBadge(e.status) + "</td><td>" + Admin.participation(e) +
        (e.auto_submitted ? '<p class="text-xs danger">' + esc(e.auto_submitted) + " auto-submitted</p>" : "") + '</td><td><button type="button" class="btn btn--outline btn--sm" data-detail="' + esc(e.id) + '">View details</button></td></tr>';
    }

    function load() {
      return API.get("admin_exams.php?action=list" + (f.string() ? "&" + f.string().slice(1) : "")).then(function (r) {
        exams = r.exams;
        el("examCount").textContent = "(" + exams.length + (r.totals.assigned ? " · " + r.totals.assigned + " student assignments" : "") + ")";
        el("examBody").innerHTML = exams.length ? exams.map(row).join("") : '<tr><td colspan="8" class="muted">No examinations match these filters.</td></tr>';
      }).catch(function (e) { Admin.fail(el("examBody"), e); });
    }

    el("examBody").addEventListener("click", function (e) {
      var b = e.target.closest("[data-detail]");
      if (b) openDetail(b.getAttribute("data-detail"));
    });

    function openDetail(id) {
      el("examModalTitle").textContent = "Examination details";
      el("examModalBody").innerHTML = '<p class="muted">Loading…</p>';
      openModal("examModal");
      API.get("admin_exams.php?action=detail&id=" + encodeURIComponent(id)).then(function (r) {
        var e = r.exam, ru = r.rules;
        el("examModalTitle").textContent = e.code + " · " + e.title;
        var rules = [["Camera required", ru.require_camera], ["Face angle", ru.detect_face_angle], ["Eye gaze", ru.detect_eye_gaze], ["Additional person", ru.detect_multi_face],
                     ["Block copy / paste", ru.block_copy_paste], ["Leaving the window", ru.detect_tab_switch]];
        el("examModalBody").innerHTML =
          '<p style="margin-bottom:.5rem"><a class="btn btn--primary btn--sm" href="admin-exam-results.html?exam=' + esc(e.id) + '">View exam form &amp; student results</a> <a class="btn btn--outline btn--sm" href="admin-live.html?exam_id=' + esc(e.id) + '">Live monitoring</a></p>' + '<p class="readonly-note">Read-only view. Only the professor who created this exam can edit it, unless you allow another professor below.</p>' +
          '<dl class="detail-list" style="margin-top:.75rem">' +
          "<div><dt>Subject</dt><dd>" + esc(e.subject) + "</dd></div><div><dt>Department</dt><dd>" + esc(e.dept_code) + " · " + esc(e.dept_name) + "</dd></div>" +
          "<div><dt>Professor</dt><dd>" + esc(e.professor) + " (" + esc(e.professor_no) + ")</dd></div><div><dt>Status</dt><dd>" + Admin.statusBadge(e.status) + "</dd></div>" +
          "<div><dt>Schedule</dt><dd>" + esc(Admin.when(e.scheduled_at)) + "</dd></div><div><dt>Duration</dt><dd>" + esc(e.duration_minutes) + " min</dd></div>" +
          "<div><dt>Violation limit</dt><dd>" + esc(e.violation_threshold) + "</dd></div><div><dt>Questions</dt><dd>" + esc(e.items) + "</dd></div>" +
          "<div><dt>Created</dt><dd>" + esc(Admin.when(e.created_at)) + "</dd></div><div><dt>Last edited by professor</dt><dd>" + esc(Admin.when(e.updated_at)) + "</dd></div>" +
          "<div><dt>Sections</dt><dd>" + (e.sections.length ? esc(e.sections.join(", ")) : "All sections") + "</dd></div><div><dt>Eligible year level(s)</dt><dd>" + (e.eligible_years && e.eligible_years.length ? esc(e.eligible_years.join(", ")) : "All year levels") + "</dd></div>" +
          "<div><dt>Open until</dt><dd>" + esc(e.available_until ? Admin.when(e.available_until) : "No end set") + "</dd></div>" +
          "<div><dt>Assigned students by year</dt><dd>" + Admin.yearTags(e.year_levels) + "</dd></div></dl>" +
          '<h3 class="section-title">Extra editors</h3><div id="editorsBox" class="text-sm muted">Loading…</div>' +
          (e.description ? '<p class="text-sm" style="margin-top:.75rem">' + esc(e.description) + "</p>" : "") +
          '<div class="grid grid--stats mt-6">' + Admin.stat("Assigned", e.assigned) + Admin.stat("Completed", e.completed, "is-success") + Admin.stat("Ongoing", e.ongoing) +
          Admin.stat("Pending", e.pending) + Admin.stat("Auto-submitted", e.auto_submitted, "is-danger") + "</div>" +
          '<h3 class="section-title">Proctoring rules</h3><p class="text-sm">' + rules.map(function (x) { return (Number(x[1]) ? "✓ " : "✗ ") + esc(x[0]); }).join(" &nbsp;·&nbsp; ") + "</p>" +
          '<h3 class="section-title">Assigned students (' + e.roster.length + ')</h3><div class="scroll-x" style="max-height:18rem;overflow:auto"><table class="table"><thead><tr><th>Student</th><th>Year / section</th><th>Status</th><th>Progress</th><th>Violations</th><th>Score</th></tr></thead><tbody>' +
          (e.roster.length ? e.roster.map(function (s) {
            return "<tr><td>" + esc(s.name) + '<p class="text-xs muted">' + esc(s.account_no) + "</p></td><td>" + esc(s.year_level || "—") + (s.section ? ' <span class="muted">(' + esc(s.section) + ")</span>" : "") +
              "</td><td>" + Admin.stateBadge(s.state) + "</td><td>" + esc(s.progress) + "%</td><td>" + esc(s.violations) + "</td><td>" + Admin.pct(s.score) + "</td></tr>";
          }).join("") : '<tr><td colspan="6" class="muted">No students are assigned.</td></tr>') + "</tbody></table></div>" +
          '<h3 class="section-title">Questions (' + r.questions.length + ")</h3>" +
          r.questions.map(function (q) {
            return '<div class="q-block"><b>' + esc(q.position) + ".</b> " + esc(q.prompt) + ' <span class="muted">(' + (q.type === "essay" ? "essay" : "multiple choice") + ", " + esc(q.points) + " pts" + (q.media_kind ? ", " + esc(q.media_kind) : "") + ")</span>" +
              (q.type === "multiple-choice" ? "<ul style=\"margin:.35rem 0 0 1rem\">" + q.choices.map(function (c) { return "<li" + (c === q.correct ? ' class="is-correct"' : "") + ">" + esc(c) + (c === q.correct ? " ✓" : "") + "</li>"; }).join("") + "</ul>" : "") + "</div>";
          }).join("") +
          (r.restorations.length ? '<h3 class="section-title">Access restorations by the professor</h3><div class="scroll-x"><table class="table"><thead><tr><th>When</th><th>Student</th><th>Action</th><th>By</th><th>Reason / note</th></tr></thead><tbody>' +
            r.restorations.map(function (h) { return "<tr><td>" + esc(h.at) + "</td><td>" + esc(h.student) + '<p class="text-xs muted">' + esc(h.studentNo) + "</p></td><td>" + (h.mode === "resume" ? "Resumed attempt " + esc(h.fromAttempt) : "New attempt " + esc(h.toAttempt)) + "</td><td>" + esc(h.professor) + '</td><td class="text-sm">' + esc(h.reason || "") + (h.note ? " — " + esc(h.note) : "") + "</td></tr>"; }).join("") + "</tbody></table></div>" : "");
        loadEditors(id);
      }).catch(function (err) { el("examModalBody").innerHTML = '<p class="danger">' + esc(err.message) + "</p>"; });
    }

    /* Admin may allow other professors to edit (and optionally delete) an exam they did not create. */
    function loadEditors(id) {
      var box = el("editorsBox");
      if (!box) return;
      API.get("admin_exams.php?action=editors&id=" + encodeURIComponent(id)).then(function (r) {
        box.className = "text-sm";
        box.innerHTML =
          (r.editors.length ? '<ul class="divided">' + r.editors.map(function (g) {
            return '<li class="row-between"><span>' + esc(g.name) + ' <span class="muted">(' + esc(g.account_no) + " · " + esc(g.dept_code || "—") + ") · " +
              (Number(g.can_delete) ? "can edit and delete" : "can edit") + ' · since ' + esc(g.granted_at) + '</span></span>' +
              '<button type="button" class="btn btn--outline btn--sm" data-revoke="' + esc(g.professor_id) + '">Remove</button></li>';
          }).join("") + "</ul>" : '<p class="muted">No extra editors. Only the creator can edit this exam.</p>') +
          (r.candidates.length ? '<div class="btn-row" style="margin-top:.75rem;align-items:center"><select class="select" id="editorPick" style="width:auto;min-width:14rem" aria-label="Professor"><option value="">Allow a professor to edit…</option>' +
            r.candidates.map(function (c) { return '<option value="' + esc(c.id) + '">' + esc(c.name) + " (" + esc(c.dept_code || "—") + ")</option>"; }).join("") +
            '</select><label class="check"><input type="checkbox" id="editorDelete" /> also allow delete</label><button type="button" class="btn btn--primary btn--sm" id="editorGrant">Allow</button></div>' : "") +
          '<p class="text-xs danger" id="editorNote" hidden></p>';
        function fail(err) { var n = el("editorNote"); n.hidden = false; n.textContent = err.message; }
        var g = el("editorGrant");
        if (g) g.addEventListener("click", function () {
          var pid = Number(el("editorPick").value);
          if (!pid) return fail(new Error("Choose a professor first."));
          API.post("admin_exams.php?action=grant_editor", { exam_id: Number(id), professor_id: pid, can_delete: el("editorDelete").checked }).then(function () { loadEditors(id); }).catch(fail);
        });
        var rv = box.querySelectorAll("[data-revoke]");
        for (var i = 0; i < rv.length; i++) (function (b) {
          b.addEventListener("click", function () {
            API.post("admin_exams.php?action=revoke_editor", { exam_id: Number(id), professor_id: Number(b.getAttribute("data-revoke")) }).then(function () { loadEditors(id); }).catch(fail);
          });
        })(rv[i]);
      }).catch(function (err) { box.textContent = err.message; });
    }

    f.ready.then(function () {
      load().then(function () {
        var want = new URLSearchParams(window.location.search).get("exam");
        if (want) openDetail(want);
      });
      window.setInterval(load, 20000);
    });
  });
});
