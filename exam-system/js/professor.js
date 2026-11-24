/* =========================================================
   Professor pages: dashboard, exam builder, reports (all API-driven)
   ========================================================= */

function alertItemHtml(a) {
  return (
    '<li class="alert-item alert-item--' + esc(a.severity) + '">' +
    '<p class="alert-item__name">' + esc(a.student) + "</p>" +
    '<p class="alert-item__meta">' + esc(a.type) + "</p>" +
    '<p class="alert-item__time">' + esc(a.time) + "</p>" +
    "</li>"
  );
}

/* ---------- dashboard ---------- */

function renderProfessorDashboard() {
  var roster = el("sessionRoster");
  if (!roster) return;

  function load() {
    API.get("monitor.php").then(function (r) {
      var list = r.students;
      if (el("statTaking")) el("statTaking").textContent = String(list.filter(function (s) { return s.status === "in-progress" || s.status === "flagged"; }).length);
      if (el("statFlagged")) el("statFlagged").textContent = String(list.filter(function (s) { return s.violations > 0; }).length);
      if (el("statAuto")) el("statAuto").textContent = String(list.filter(function (s) { return s.status === "auto-submitted"; }).length);
      if (el("statDone")) el("statDone").textContent = String(list.filter(function (s) { return s.status === "submitted" || s.status === "auto-submitted"; }).length);
      if (el("dashSubtitle")) el("dashSubtitle").textContent = r.exam
        ? r.exam.code + " · " + r.exam.title + " · " + r.exam.status
        : "No examination yet. Create one in the Exam Builder.";

      roster.innerHTML = list.length ? list.map(function (s) {
        return (
          '<li class="row-between">' +
          '<div><p style="font-weight:600">' + esc(s.name) + "</p>" +
          '<p class="text-xs muted">' + esc(s.studentNo) + " · " + esc(s.section || "") + "</p></div>" +
          '<div style="display:flex;align-items:center;gap:1rem;font-size:0.75rem">' +
          '<span class="muted">' + esc(s.progress) + "% done</span>" +
          '<span class="' + (s.violations ? "danger" : "muted") + '" style="font-weight:600">' +
          esc(s.violations) + " violation" + (s.violations === 1 ? "" : "s") + "</span>" +
          "</div></li>"
        );
      }).join("") : '<li class="muted text-sm">No active exam sessions.</li>';

      var feed = el("alertFeed");
      if (feed) feed.innerHTML = r.alerts.length ? r.alerts.map(alertItemHtml).join("") : '<li class="muted text-sm">No alerts yet.</li>';
    }).catch(function () {});
  }
  load();
  window.setInterval(load, 5000);
}

/* ---------- dashboard: newest examinations of every professor (read-only) ---------- */

function renderSharedDashboard() {
  var ul = el("sharedDash");
  if (!ul) return;
  API.get("exam.php?action=shared").then(function (r) {
    ul.innerHTML = r.exams.length ? r.exams.slice(0, 6).map(function (e) {
      return '<li class="row-between"><div><p style="font-weight:600">' + esc(e.code) + " · " + esc(e.title) + ' <span class="badge badge--' + esc(e.status) + '">' + esc(e.status) + "</span></p>" +
        '<p class="text-xs muted">' + esc(e.professor) + " · " + esc(e.dept_code || "—") + " · " + esc((e.year_levels.length ? e.year_levels.join(", ") : "All year levels")) + " · " + esc(scheduleText(e)) + "</p></div>" +
        '<a class="btn btn--outline btn--sm" href="professor-exam-questions.html?exam=' + esc(e.id) + '">View</a></li>';
    }).join("") : '<li class="muted text-sm">No examinations have been created yet.</li>';
  }).catch(function (e) { ul.innerHTML = '<li class="danger text-sm">' + esc(e.message) + "</li>"; });
}

/* ---------- section picker (used in the exam setup form) ---------- */

var sectionCatalog = null;   // [{section, students}] - ONLY sections of students in the professor's own department
var sectionDept = null;      // {id, code, name} - the professor's department
var sectionError = null;

function loadSectionCatalog() {
  if (sectionCatalog) return Promise.resolve(sectionCatalog);
  return API.get("exam.php?action=sections").then(function (r) { sectionCatalog = r.sections; sectionDept = r.department; return sectionCatalog; })
    .catch(function (e) { sectionCatalog = []; sectionError = e.message; return sectionCatalog; });
}

var pickerSeq = 0;

/* Builds "All sections / Only selected sections" controls inside `root`. onChange() fires whenever the choice changes. */
function SectionPicker(root, onChange) {
  var uid = "secmode" + (++pickerSeq);
  var selected = [];   // section names ticked (includes custom ones)
  var mode = "all";
  function changed() { if (onChange) onChange(); }

  root.innerHTML =
    '<div class="section-picker">' +
    '<label class="check"><input type="radio" name="' + uid + '" value="all" checked /> <span>All sections of my department <span class="muted">(every eligible student)</span></span></label>' +
    '<label class="check"><input type="radio" name="' + uid + '" value="selected" /> <span>Only the sections I select</span></label>' +
    '<div class="section-picker__body" hidden>' +
    '<div class="section-picker__list" role="group" aria-label="Sections"></div>' +
    '<div class="section-picker__add"><input class="input" maxlength="30" placeholder="Add a section not listed, e.g. BSIT-4A" aria-label="Add a section" />' +
    '<button type="button" class="btn btn--outline btn--sm">Add</button></div>' +
    "</div></div>";

  var radios = root.querySelectorAll('input[type="radio"]');
  var body = root.querySelector(".section-picker__body");
  var list = root.querySelector(".section-picker__list");
  var addInput = root.querySelector(".section-picker__add input");
  var addBtn = root.querySelector(".section-picker__add button");

  function known() {
    var names = (sectionCatalog || []).map(function (c) { return c.section; });
    selected.forEach(function (n) { if (names.map(function (x) { return x.toLowerCase(); }).indexOf(n.toLowerCase()) === -1) names.push(n); });
    return names;
  }
  function info(name) {
    var c = (sectionCatalog || []).filter(function (x) { return x.section.toLowerCase() === name.toLowerCase(); })[0];
    return c ? c.students + (Number(c.students) === 1 ? " student" : " students") : "no students yet";
  }
  function has(name) { return selected.some(function (x) { return x.toLowerCase() === name.toLowerCase(); }); }

  function draw() {
    body.hidden = mode !== "selected";
    var names = known();
    list.innerHTML = names.length ? names.map(function (n) {
      return '<label class="check check--box"><input type="checkbox" value="' + esc(n) + '"' + (has(n) ? " checked" : "") + " /> " +
        "<span>" + esc(n) + ' <span class="muted text-xs">' + esc(info(n)) + "</span></span></label>";
    }).join("") : '<p class="muted text-sm">No sections found in your department yet. Add one below.</p>';
    var boxes = list.querySelectorAll('input[type="checkbox"]');
    for (var i = 0; i < boxes.length; i++) {
      boxes[i].addEventListener("change", function () {
        var v = this.value;
        selected = selected.filter(function (x) { return x.toLowerCase() !== v.toLowerCase(); });
        if (this.checked) selected.push(v);
        changed();
      });
    }
  }

  for (var i = 0; i < radios.length; i++) {
    radios[i].addEventListener("change", function () { mode = this.value; draw(); changed(); });
  }
  function addCustom() {
    var v = addInput.value.trim();
    if (!v) return;
    if (!has(v)) selected.push(v);
    addInput.value = "";
    draw();
    changed();
  }
  addBtn.addEventListener("click", addCustom);
  addInput.addEventListener("keydown", function (e) { if (e.key === "Enter") { e.preventDefault(); addCustom(); } });

  loadSectionCatalog().then(draw);
  draw();

  return {
    /* names = [] means "all sections" */
    set: function (names) {
      selected = (names || []).slice();
      mode = selected.length ? "selected" : "all";
      for (var j = 0; j < radios.length; j++) radios[j].checked = radios[j].value === mode;
      draw();
    },
    /* [] = all sections, null = "only selected" chosen but nothing ticked */
    get: function () {
      if (mode === "all") return [];
      return selected.length ? selected.slice() : null;
    }
  };
}

/* ---------- exam builder hub: my examinations + all examinations ---------- */

function statusOptions(current) {
  return ["draft", "scheduled", "available", "closed"].map(function (s) {
    return '<option value="' + s + '"' + (s === current ? " selected" : "") + ">" + s + "</option>";
  }).join("");
}

function sectionTags(sections) {
  if (!sections || !sections.length) return '<span class="tag tag--all">All sections</span>';
  return sections.map(function (x) { return '<span class="tag">' + esc(x) + "</span>"; }).join("");
}

function yearTags(years) {
  if (!years || !years.length) return '<span class="tag tag--all">All year levels</span>';
  return years.map(function (x) { return '<span class="tag">' + esc(x) + "</span>"; }).join("");
}

function fmtWhen(v) {
  if (!v) return "";
  var d = new Date(String(v).replace(" ", "T"));
  if (isNaN(d.getTime())) return String(v);
  return d.toLocaleString([], { year: "numeric", month: "short", day: "numeric", hour: "numeric", minute: "2-digit" });
}

function scheduleText(e) {
  var start = e.scheduled_at ? fmtWhen(e.scheduled_at) : "No date set";
  return start + (e.available_until ? " → until " + fmtWhen(e.available_until) : "");
}

function renderProfessorExams() {
  var list = el("examManagerList");
  if (!list) return;

  var exams = [];

  function rowHtml(e) {
    var subs = Number(e.submissions) || 0, autos = Number(e.auto_submitted) || 0, id = esc(e.id);
    return (
      '<li class="exam-row">' +
      '<div class="exam-row__main">' +
      '<p class="exam-row__title">' + esc(e.code) + " · " + esc(e.title) + ' <span class="badge badge--' + esc(e.status) + '">' + esc(e.status) + "</span></p>" +
      '<p class="text-xs muted">' + esc(e.dept_code || "No department") + " · " + esc(e.duration_minutes) + " min · " + esc(e.items) + " question" + (Number(e.items) === 1 ? "" : "s") + " · threshold " + esc(e.violation_threshold) + " · " +
      subs + " submission" + (subs === 1 ? "" : "s") + (autos ? " (" + autos + " auto-submitted)" : "") + "</p>" +
      '<p class="text-xs muted">' + esc(scheduleText(e)) + "</p>" +
      (Number(e.items) === 0 ? '<p class="text-xs danger">No questions yet. Add questions before opening the exam.</p>' : "") +
      (e.locked ? '<p class="text-xs muted">Locked for editing: questions, time and rules cannot change while the exam is open or has attempts.</p>' : "") +
      '<p class="exam-row__sections text-xs"><span class="muted">Year level(s):</span> ' + yearTags(e.year_levels) + "</p>" +
      '<p class="exam-row__sections text-xs"><span class="muted">Sections:</span> ' + sectionTags(e.sections) + "</p>" +
      "</div>" +
      '<div class="exam-row__actions">' +
      '<select class="select" data-exam-status="' + id + '" aria-label="Status of ' + esc(e.code) + '">' + statusOptions(e.status) + "</select>" +
      '<a class="btn btn--outline btn--sm" href="professor-exam-setup.html?id=' + id + '">1 · Setup</a>' +
      '<a class="btn btn--primary btn--sm" href="professor-exam-questions.html?exam=' + id + '">2 · Questions</a>' +
      '<a class="btn btn--outline btn--sm" href="professor-monitoring.html?exam_id=' + id + '">Monitor</a>' +
      '<a class="btn btn--outline btn--sm" href="professor-exam-results.html?exam=' + id + '">Results</a>' +
      '<a class="btn btn--outline btn--sm" href="api/export.php?exam_id=' + id + '&type=scores">CSV</a>' +
      '<button type="button" class="btn btn--danger btn--sm" data-exam-delete="' + id + '">Delete</button>' +
      "</div></li>"
    );
  }

  function find(id) { return exams.filter(function (x) { return Number(x.id) === Number(id); })[0]; }

  function load() {
    return API.get("exam.php?action=list").then(function (r) {
      exams = r.exams;
      list.innerHTML = exams.length ? exams.map(rowHtml).join("") : '<li class="muted text-sm">No examinations yet. Click "New examination" to set up your first one.</li>';

      var sels = list.querySelectorAll("[data-exam-status]");
      for (var i = 0; i < sels.length; i++) {
        (function (sel) {
          sel.addEventListener("change", function () {
            API.post("exam.php?action=set_status", { id: Number(sel.getAttribute("data-exam-status")), status: sel.value })
              .then(load)
              .catch(function (e) { window.alert(e.message); load(); });
          });
        })(sels[i]);
      }
      var del = list.querySelectorAll("[data-exam-delete]");
      for (var d = 0; d < del.length; d++) {
        (function (btn) { btn.addEventListener("click", function () { deleteExam(find(btn.getAttribute("data-exam-delete"))); }); })(del[d]);
      }
    }).catch(function (e) {
      list.innerHTML = '<li class="danger text-sm">Could not load your examinations: ' + esc(e.message) + "</li>";
    });
  }
  load();

  function deleteExam(e) {
    if (!e) return;
    var subs = Number(e.submissions) || 0;
    var msg = 'Delete "' + e.code + " · " + e.title + '"?\n\n' +
      "This permanently removes the exam, its " + e.items + " question(s)" +
      (subs ? ", and " + subs + " student submission(s) together with their answers, scores and violation records" : "") + ". This cannot be undone." +
      (e.status === "available" ? "\n\nThe exam is available right now - students taking it will lose their session. To stop it but keep the results, set the status to Closed instead." : "");
    if (!window.confirm(msg)) return;
    API.post("exam.php?action=delete", { id: Number(e.id) })
      .then(load)
      .catch(function (err) { window.alert(err.message); });
  }

  /* ----- tabs ----- */
  var tabMine = el("tabMine"), tabAll = el("tabAll");
  function showTab(which) {
    var all = which === "all";
    el("paneMine").hidden = all; el("paneAll").hidden = !all;
    tabMine.classList.toggle("is-active", !all); tabAll.classList.toggle("is-active", all);
    tabMine.setAttribute("aria-selected", String(!all)); tabAll.setAttribute("aria-selected", String(all));
    if (all) loadShared();
    if (window.history && window.history.replaceState) window.history.replaceState(null, "", all ? "#all" : window.location.pathname + window.location.search);
  }
  tabMine.addEventListener("click", function () { showTab("mine"); });
  tabAll.addEventListener("click", function () { showTab("all"); });

  /* ----- all examinations (shared by every professor) ----- */
  var shared = [], sharedLoaded = false;

  function sharedRow(e) {
    var id = esc(e.id);
    var actions = '<a class="btn btn--outline btn--sm" href="professor-exam-questions.html?exam=' + id + '">View</a>' +
      (e.can_edit ? ' <a class="btn btn--primary btn--sm" href="professor-exam-setup.html?id=' + id + '">Edit</a>' : "");
    return "<tr><td><b>" + esc(e.code) + "</b> · " + esc(e.title) + '<p class="text-xs muted">' + esc(e.subject) + " · " + esc(e.items) + " question" + (Number(e.items) === 1 ? "" : "s") + " · " + esc(e.duration_minutes) + " min</p></td>" +
      "<td>" + esc(e.professor) + (e.is_owner ? ' <span class="tag">You</span>' : (e.can_edit ? ' <span class="tag">You can edit</span>' : "")) + "</td>" +
      "<td>" + esc(e.dept_code || "—") + "</td><td>" + yearTags(e.year_levels) + "</td>" +
      '<td class="text-sm">' + esc(scheduleText(e)) + "</td>" +
      '<td><span class="badge badge--' + esc(e.status) + '">' + esc(e.status) + "</span>" + (e.window_closed ? '<p class="text-xs muted">window ended</p>' : "") + "</td>" +
      '<td style="white-space:nowrap">' + actions + "</td></tr>";
  }

  function fillSelect(id, first, values) {
    var sel = el(id), cur = sel.value;
    sel.innerHTML = '<option value="">' + esc(first) + "</option>" + values.map(function (v) { return '<option value="' + esc(v[0]) + '">' + esc(v[1]) + "</option>"; }).join("");
    sel.value = cur;
  }

  function drawShared() {
    var q = el("sfSearch").value.trim().toLowerCase(), dept = el("sfDept").value, year = el("sfYear").value, prof = el("sfProf").value, status = el("sfStatus").value;
    var rows = shared.filter(function (e) {
      if (q && (e.code + " " + e.title + " " + e.subject).toLowerCase().indexOf(q) === -1) return false;
      if (dept && (e.dept_code || "") !== dept) return false;
      if (year && e.year_levels.length && e.year_levels.indexOf(year) === -1) return false;
      if (prof && String(e.professor_id) !== prof) return false;
      if (status && e.status !== status) return false;
      return true;
    });
    el("allCount").textContent = "(" + shared.length + ")";
    el("sharedBody").innerHTML = rows.length ? rows.map(sharedRow).join("") : '<tr><td colspan="7" class="muted">No examinations match these filters.</td></tr>';
  }

  function loadShared() {
    return API.get("exam.php?action=shared").then(function (r) {
      shared = r.exams;
      if (!sharedLoaded) {
        sharedLoaded = true;
        var depts = {}, profs = {};
        shared.forEach(function (e) { if (e.dept_code) depts[e.dept_code] = 1; profs[e.professor_id] = e.professor; });
        fillSelect("sfDept", "All departments", Object.keys(depts).sort().map(function (c) { return [c, c]; }));
        fillSelect("sfYear", "All year levels", (r.year_levels || []).map(function (y) { return [y, y]; }));
        fillSelect("sfProf", "All professors", Object.keys(profs).map(function (k) { return [k, profs[k]]; }).sort(function (a, b) { return a[1].localeCompare(b[1]); }));
        ["sfSearch", "sfDept", "sfYear", "sfProf", "sfStatus"].forEach(function (id) { el(id).addEventListener(id === "sfSearch" ? "input" : "change", drawShared); });
      }
      drawShared();
    }).catch(function (e) {
      el("sharedBody").innerHTML = '<tr><td colspan="7" class="danger">' + esc(e.message) + "</td></tr>";
    });
  }
  // the count in the tab label is useful before the tab is opened
  API.get("exam.php?action=shared").then(function (r) { el("allCount").textContent = "(" + r.exams.length + ")"; }).catch(function () {});
  if (window.location.hash === "#all") showTab("all");
}

/* ---------- reports ---------- */

function renderReports() {
  var body = el("reportBody");
  if (!body) return;

  function setExport(id) {
    el("exportScores").href = "api/export.php?exam_id=" + id + "&type=scores";
    el("exportViolations").href = "api/export.php?exam_id=" + id + "&type=violations";
  }

  function draw(r) {
    if (!r.exam) {
      body.innerHTML = '<tr><td colspan="7" class="muted">No data for this exam yet.</td></tr>';
      return;
    }
    setExport(r.exam.id);
    el("reportSubtitle").textContent = r.exam.code + " " + r.exam.title + " · summary and integrity records";
    var st = r.stats;
    el("statAvg").textContent = st.average === null ? "—" : st.average + "%";
    el("statPass").textContent = st.passingRate === null ? "—" : st.passingRate + "%";
    el("statLow").textContent = st.lowest === null ? "—" : st.lowest + "%";
    el("statViol").textContent = String(st.totalViolations);

    el("violationPie").innerHTML = r.violationsByType.length
      ? renderMedia({ kind: "pie-chart", data: r.violationsByType })
      : '<p class="muted text-sm">No violations recorded.</p>';

    var bins = Object.keys(r.scoreBins).map(function (k) { return { label: k, value: r.scoreBins[k] }; });
    el("scoreBars").innerHTML = bins.some(function (b) { return b.value > 0; })
      ? renderMedia({ kind: "bar-chart", data: bins })
      : '<p class="muted text-sm">No graded submissions yet.</p>';

    body.innerHTML = r.students.length ? r.students.map(function (s) {
      return (
        "<tr>" +
        '<td><p style="font-weight:600">' + esc(s.name) + "</p>" +
        '<p class="text-xs muted">' + esc(s.studentNo) + "</p></td>" +
        '<td class="muted">' + esc(s.section || "") + "</td>" +
        "<td>" + esc(s.progress) + "%</td>" +
        '<td class="' + (s.violations ? "danger" : "muted") + '" style="font-weight:600">' + esc(s.violations) + "</td>" +
        '<td class="muted">' + esc(s.lastFlag) + "</td>" +
        "<td>" + (s.score === null ? "—" : esc(s.score) + "%") + "</td>" +
        '<td class="muted capitalize">' + esc(s.status.replace("-", " ")) + "</td>" +
        "</tr>"
      );
    }).join("") : '<tr><td colspan="7" class="muted">No submissions yet.</td></tr>';
  }

  function load(id) {
    API.get("report.php" + (id ? "?exam_id=" + id : "")).then(draw).catch(function (e) {
      body.innerHTML = '<tr><td colspan="7" class="danger">' + esc(e.message) + "</td></tr>";
    });
  }

  API.get("exam.php?action=list").then(function (r) {
    var sel = el("reportExam");
    sel.innerHTML = r.exams.map(function (e) {
      return '<option value="' + esc(e.id) + '">' + esc(e.code) + " · " + esc(e.title) + "</option>";
    }).join("");
    sel.addEventListener("change", function () { load(sel.value); });
    load(sel.value || "");
  });
}

document.addEventListener("DOMContentLoaded", function () {
  Session.ready.then(function () {
    renderProfessorDashboard();
    renderSharedDashboard();
    renderProfessorExams();
    renderReports();
  });
});
