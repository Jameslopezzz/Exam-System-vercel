/* Admin monitoring: read-only, system-wide. No video, no warnings, no force-submit - those belong to each exam's professor. */
document.addEventListener("DOMContentLoaded", function () {
  Session.ready.then(function () {
    var f = Admin.filters({ onChange: load });
    var CAM = { on: "Camera on", lost: "Camera lost", denied: "Camera blocked", unavailable: "No camera", unknown: "Camera: waiting" };

    function render(r) {
      var s = r.summary;
      el("monStats").innerHTML = Admin.stat("Examinations", s.exams) + Admin.stat("Students assigned", s.assigned) +
        Admin.stat("Participation", Admin.pct(s.participation), "", s.started + " started") + Admin.stat("Ongoing", s.ongoing, "", s.online + " online now") +
        Admin.stat("Completed", s.completed, "is-success") + Admin.stat("Pending", s.pending) + Admin.stat("Auto-submitted", s.auto_submitted, "is-danger");

      el("monExams").innerHTML = r.exams.length ? r.exams.map(function (e) {
        return "<tr><td><b>" + esc(e.code) + "</b> · " + esc(e.title) + "</td><td>" + esc(e.dept_code) + "</td><td>" + esc(e.professor) + "</td><td>" + Admin.yearTags(e.year_levels) +
          "</td><td>" + Admin.statusBadge(e.status) + "</td><td>" + Admin.participation(e) + "</td><td>" + esc(e.ongoing) + "</td><td>" + esc(e.completed) +
          '</td><td class="' + (e.auto_submitted ? "danger" : "muted") + '">' + esc(e.auto_submitted) + "</td><td>" + esc(e.pending) + "</td></tr>";
      }).join("") : '<tr><td colspan="10" class="muted">No examinations match these filters.</td></tr>';

      el("monCount").textContent = "(" + r.students.length + (r.truncated ? "+" : "") + ")";
      el("monTrunc").hidden = !r.truncated;
      el("monStudents").innerHTML = r.students.length ? r.students.map(function (x) {
        var conn = x.state === "ongoing" ? (x.online ? "Online · " + (CAM[x.camera] || "") : "Offline") : "—";
        return "<tr><td>" + esc(x.name) + '<p class="text-xs muted">' + esc(x.account_no) + "</p></td><td>" + esc(x.year_level || "—") + (x.section ? ' <span class="muted">(' + esc(x.section) + ")</span>" : "") +
          "</td><td>" + esc(x.exam_code) + '<p class="text-xs muted">' + esc(x.dept) + " · " + esc(x.professor) + "</p></td><td>" + Admin.stateBadge(x.state) + "</td><td>" + esc(x.progress) + "%</td>" +
          '<td class="' + (x.violations ? "danger" : "muted") + '">' + esc(x.violations) + (x.attempts > 1 ? ' <span class="muted text-xs">(attempt ' + esc(x.attempts) + ")</span>" : "") + '</td><td class="text-sm">' + esc(conn) + "</td></tr>";
      }).join("") : '<tr><td colspan="7" class="muted">No student activity matches these filters.</td></tr>';

      el("monAlerts").innerHTML = r.alerts.length ? r.alerts.map(function (a) {
        return '<li class="alert-item alert-item--' + esc(a.severity) + '"><p class="alert-item__name">' + esc(a.student) + '</p><p class="alert-item__meta">' + esc(a.type) + " · " + esc(a.exam) + " · " + esc(a.dept) +
          '</p><p class="alert-item__time">' + esc(a.time) + "</p></li>";
      }).join("") : '<li class="muted text-sm">No violations recorded.</li>';
    }

    function load() {
      return API.get("admin_monitor.php" + f.string()).then(render).catch(function (e) { Admin.fail(el("monExams"), e); });
    }
    f.ready.then(function () { Admin.poll(load, 5000); });
  });
});
