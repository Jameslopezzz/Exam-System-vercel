/* Admin reports: filter by department, year level, professor and examination; export CSV. Read-only. */
document.addEventListener("DOMContentLoaded", function () {
  Session.ready.then(function () {
    var f = Admin.filters({ onChange: load });

    function groupRow(g) {
      return "<tr><td><b>" + esc(g.label) + "</b></td><td>" + esc(g.students) + "</td><td>" + esc(g.completed) + '</td><td class="' + (g.auto_submitted ? "danger" : "muted") + '">' + esc(g.auto_submitted) +
        "</td><td>" + esc(g.violations) + "</td><td>" + Admin.pct(g.avg_score) + "</td></tr>";
    }

    function render(r) {
      var s = r.summary;
      el("repStats").innerHTML = Admin.stat("Examinations", s.exams) + Admin.stat("Participation", Admin.pct(s.participation), "", s.started + " of " + s.assigned + " assigned") +
        Admin.stat("Average score", Admin.pct(s.avg_score)) + Admin.stat("Pass rate (75%+)", Admin.pct(s.pass_rate), "is-success") + Admin.stat("Violations", s.violations) +
        Admin.stat("Auto-submitted", s.auto_submitted, "is-danger", s.started ? Math.round(s.auto_submitted / s.started * 100) + "% of those who started" : "");

      el("repPie").innerHTML = r.violations_by_type.length ? pieChartHtml(r.violations_by_type) : '<p class="muted text-sm">No violations in this selection.</p>';
      var bins = Object.keys(r.score_bins).map(function (k) { return { label: k + "%", value: r.score_bins[k] }; });
      el("repBars").innerHTML = bins.some(function (b) { return b.value; }) ? barChartHtml(bins) : '<p class="muted text-sm">No submitted scores in this selection.</p>';

      el("repDept").innerHTML = r.by_department.length ? r.by_department.map(groupRow).join("") : '<tr><td colspan="6" class="muted">No data.</td></tr>';
      el("repYear").innerHTML = r.by_year.length ? r.by_year.map(groupRow).join("") : '<tr><td colspan="6" class="muted">No data.</td></tr>';

      el("repExams").innerHTML = r.by_exam.length ? r.by_exam.map(function (e) {
        return "<tr><td><b>" + esc(e.code) + "</b> · " + esc(e.title) + "<p class=\"text-xs muted\">" + Admin.statusBadge(e.status).replace(/<[^>]+>/g, "") + "</p></td><td>" + esc(e.dept_code) + "</td><td>" + esc(e.professor) + "</td><td>" + Admin.yearTags(e.year_levels) +
          "</td><td>" + esc(e.assigned) + "</td><td>" + esc(e.started) + "</td><td>" + esc(e.completed) + '</td><td class="' + (e.auto_submitted ? "danger" : "muted") + '">' + esc(e.auto_submitted) +
          "</td><td>" + esc(e.violations) + "</td><td>" + Admin.pct(e.avg_score) + "</td></tr>";
      }).join("") : '<tr><td colspan="10" class="muted">No examinations match these filters.</td></tr>';

      el("repCount").textContent = "(" + r.students.length + (r.truncated ? "+" : "") + ")";
      el("repTrunc").hidden = !r.truncated;
      el("repStudents").innerHTML = r.students.length ? r.students.map(function (x) {
        return "<tr><td>" + esc(x.name) + '<p class="text-xs muted">' + esc(x.account_no) + "</p></td><td>" + esc(x.year_level || "—") + (x.section ? ' <span class="muted">(' + esc(x.section) + ")</span>" : "") +
          "</td><td>" + esc(x.exam_code) + '<p class="text-xs muted">' + esc(x.dept) + "</p></td><td>" + esc(x.professor) + "</td><td>" + Admin.stateBadge(x.state) + '</td><td class="' + (x.violations ? "danger" : "muted") + '">' + esc(x.violations) +
          "</td><td>" + esc(x.attempts) + "</td><td>" + Admin.pct(x.score) + "</td></tr>";
      }).join("") : '<tr><td colspan="8" class="muted">No student records match these filters.</td></tr>';

      var q = f.string();
      el("expStudents").setAttribute("href", "api/admin_report.php" + (q ? q + "&" : "?") + "export=students");
      el("expExams").setAttribute("href", "api/admin_report.php" + (q ? q + "&" : "?") + "export=exams");
    }

    function load() {
      return API.get("admin_report.php" + f.string()).then(render).catch(function (e) { Admin.fail(el("repExams"), e); });
    }
    f.ready.then(load);
  });
});
