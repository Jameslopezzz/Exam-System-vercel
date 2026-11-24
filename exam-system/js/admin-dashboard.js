/* Admin dashboard: all exams across departments, grouped by department, with year levels, professors and student counts. */
document.addEventListener("DOMContentLoaded", function () {
  Session.ready.then(function () {
    var f = Admin.filters({ onChange: load });
    var firstDone = false;

    function render(r) {
      var t = r.totals, es = r.exam_status;
      el("examStats").innerHTML =
        Admin.stat("Total exams", t.exams, "", "all departments") + Admin.stat("Ongoing", es.available, "is-success", "open to students now") +
        Admin.stat("Pending", es.draft + es.scheduled, "", es.draft + " draft · " + es.scheduled + " scheduled") + Admin.stat("Closed", es.closed, "", "finished");
      el("attemptStats").innerHTML =
        Admin.stat("Students assigned", t.assigned, "", "across " + t.exams + " exam" + (t.exams === 1 ? "" : "s")) +
        Admin.stat("Completed", t.completed, "is-success", "handed in by the student") + Admin.stat("Ongoing", t.ongoing, "", "answering now") +
        Admin.stat("Pending", t.pending, "", "not started yet") + Admin.stat("Auto-submitted", t.auto_submitted, "is-danger", "violation limit, time or professor");

      var max = Math.max.apply(null, r.years.map(function (y) { return y.students; }).concat([1]));
      el("yearBars").innerHTML = r.years.length ? r.years.map(function (y) {
        return '<div class="bar-row"><span>' + esc(y.year) + '</span><div class="bar-row__track"><div class="bar-row__fill" style="width:' + Math.round(y.students / max * 100) + '%"></div></div><b>' + esc(y.students) + "</b></div>";
      }).join("") + '<p class="readonly-note">A student is counted once for every exam assigned to them.</p>' : '<p class="muted text-sm">No exams match.</p>';

      el("deptSummary").innerHTML = r.departments.length ? r.departments.map(function (d) {
        return "<tr><td><b>" + esc(d.code) + '</b><p class="text-xs muted">' + esc(d.name) + "</p></td><td>" + esc(d.professors) + "</td><td>" + esc(d.totals.exams) + "</td><td>" + esc(d.totals.assigned) +
          "</td><td>" + esc(d.totals.ongoing) + '</td><td class="' + (d.totals.auto_submitted ? "danger" : "muted") + '">' + esc(d.totals.auto_submitted) + "</td></tr>";
      }).join("") : '<tr><td colspan="6" class="muted">No exams match.</td></tr>';

      el("deptExams").innerHTML = r.departments.length ? r.departments.map(function (d) {
        return '<section class="card dept-exams"><header class="card__header"><h2>' + esc(d.code) + ' <span class="muted" style="font-weight:400;font-size:.9rem">' + esc(d.name) + "</span></h2>" +
          '<span class="text-xs muted">' + esc(d.totals.exams) + " exam" + (d.totals.exams === 1 ? "" : "s") + " · " + esc(d.totals.assigned) + " students assigned</span></header>" +
          '<div class="card__body"><div class="scroll-x"><table class="table"><thead><tr><th>Exam</th><th>Professor</th><th>Year level(s)</th><th>Students</th><th>Completed</th><th>Ongoing</th><th>Pending</th><th>Auto-submitted</th><th>Status</th><th></th></tr></thead><tbody>' +
          d.exams.map(function (e) {
            return "<tr><td><b>" + esc(e.code) + '</b> · ' + esc(e.title) + "</td><td>" + esc(e.professor) + "</td><td>" + Admin.yearTags(e.year_levels) + "</td><td>" + esc(e.assigned) +
              "</td><td>" + esc(e.completed) + "</td><td>" + esc(e.ongoing) + "</td><td>" + esc(e.pending) + '</td><td class="' + (e.auto_submitted ? "danger" : "muted") + '">' + esc(e.auto_submitted) +
              "</td><td>" + Admin.statusBadge(e.status) + '</td><td><a class="btn btn--outline btn--sm" href="admin-exams.html?exam=' + esc(e.id) + '">Details</a></td></tr>';
          }).join("") + "</tbody></table></div></div></section>";
      }).join("") : '<p class="muted">No examinations match these filters.</p>';
    }

    function load() {
      API.get("admin_exams.php?action=overview" + (f.string() ? "&" + f.string().slice(1) : "")).then(render).catch(function (e) { el("deptExams").innerHTML = '<p class="danger">' + esc(e.message) + "</p>"; });
    }
    f.ready.then(function () { Admin.poll(load, 15000); });
  });
});
