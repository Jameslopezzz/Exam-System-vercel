/* Admin console: accounts, sign-up approvals, departments, school years. */
var users = [];
var depts = [];
var programs = [];

function fail(e) { alert(e.message); }
function show(id, on) { var n = el(id); if (n) n.hidden = !on; }

/* ---------- Tabs ---------- */
function openTab(name) {
  ["accounts", "approvals", "departments", "schoolyear", "graduation", "activity"].forEach(function (t) {
    show("tab-" + t, t === name);
  });
  [].forEach.call(document.querySelectorAll(".tab"), function (b) { b.classList.toggle("is-active", b.dataset.tab === name); });
  if (name === "schoolyear") loadSchoolYears();
  if (name === "graduation") loadGraduation();
  if (name === "activity") loadActivity();
  if (window.location.hash.slice(1) !== name) { try { history.replaceState(null, "", "#" + name); } catch (e) {} }
}

/* ---------- Helpers for role-dependent fields ---------- */
function applyRole(role, prefix) {      // prefix "u" (new account form) or "e" (edit modal)
  var cls = prefix === "u" ? "u" : "e";
  [].forEach.call(document.querySelectorAll("." + cls + "-dept"), function (n) { n.hidden = role === "admin"; });
  [].forEach.call(document.querySelectorAll("." + cls + "-student"), function (n) { n.hidden = role !== "student"; });
}
function pill(text, kind) { return '<span class="pill pill--' + kind + '">' + esc(text) + "</span>"; }
function statusOf(u) {
  if (u.approval_status === "pending") return pill("Pending", "warn");
  if (u.approval_status === "rejected") return pill("Rejected", "bad");
  if (u.is_active != 1) return pill("Disabled", "bad");
  if (u.graduation_status === "graduated") return pill("Graduated", "ok");
  return pill("Active", "ok");
}

/* ---------- Load ---------- */
function loadUsers() {
  return API.get("users.php?action=list").then(function (r) {
    users = r.users;
    renderAccounts(); renderApprovals(); renderDepartments();
  }).catch(fail);
}
function loadDepartments() {
  return API.get("departments.php?action=list").then(function (r) {
    depts = r.departments; programs = r.programs;
    renderDepartments();
    fillDeptFilter();
    return Catalog.refresh().then(function () {      // keep the form drop-downs in step with added / renamed departments
      ["uDept", "eDept"].forEach(function (id) { var sel = el(id), v = sel.value; Catalog.fillDepartments(sel); sel.value = v; });
    });
  });
}
function reloadAll() { return Promise.all([loadDepartments(), loadUsers()]); }

/* ---------- Accounts ---------- */
function fillDeptFilter() {
  var sel = el("fDept"), cur = sel.value;
  sel.innerHTML = '<option value="">All departments</option>' + depts.map(function (d) { return '<option value="' + d.id + '">' + esc(d.code) + "</option>"; }).join("");
  sel.value = cur;
}
/* Case-insensitive comparison helpers: "ALICE", "alice" and "Alice" are the same text everywhere in the filters. */
function norm(v) { return String(v === undefined || v === null ? "" : v).toLowerCase().replace(/\s+/g, " ").trim(); }
function typeLabel(u) { return u.role !== "student" ? "—" : (u.student_type === "regular" ? "Regular" : u.student_type === "irregular" ? "Irregular" : "Not set"); }
function gradLabel(s) { return { not_eligible: "Not Yet Eligible", eligible: "Eligible for Graduation", graduated: "Graduated" }[s] || "—"; }

function renderAccounts() {
  var role = norm(el("fRole").value), dept = el("fDept").value, q = norm(el("fSearch").value);
  var rows = users.filter(function (u) {
    if (u.approval_status !== "approved") return false;
    if (role && norm(u.role) !== role) return false;
    if (dept && String(u.department_id) !== dept) return false;
    if (q) {
      var hay = norm([u.account_no, u.full_name, u.role, u.dept_code, u.program_code, u.year_level, u.section, typeLabel(u), gradLabel(u.graduation_status)].join(" "));
      if (hay.indexOf(q) === -1) return false;
    }
    return true;
  });
  el("userBody").innerHTML = rows.map(function (u) {
    var self = Session.user && Number(Session.user.id) === Number(u.id);
    return "<tr><td>" + esc(u.account_no) + "</td><td>" + esc(u.full_name) + "</td><td>" + esc(u.role) + "</td>" +
      "<td>" + esc(u.dept_code || "—") + "</td><td>" + esc(u.program_code || "—") + "</td>" +
      "<td>" + esc(u.year_level || "—") + (u.section ? " <span class='muted'>(" + esc(u.section) + ")</span>" : "") + "</td>" +
      "<td>" + esc(typeLabel(u)) + "</td>" +
      "<td>" + statusOf(u) + "</td><td class='row-actions'>" +
      "<button class='btn btn--outline btn--sm' data-edit='" + u.id + "'>Edit</button> " +
      "<button class='btn btn--outline btn--sm' data-toggle='" + u.id + "'" + (self ? " disabled" : "") + ">" + (u.is_active == 1 ? "Disable" : "Enable") + "</button> " +
      "<button class='btn btn--outline btn--sm' data-reset='" + u.id + "'>Reset PIN</button> " +
      "<button class='btn btn--danger btn--sm' data-delacct='" + u.id + "'" + (self ? " disabled title='You cannot delete your own account'" : "") + ">Delete</button></td></tr>";
  }).join("") || "<tr><td colspan='9' class='muted'>No accounts match.</td></tr>";
}

/* ---------- Approvals ---------- */
function renderApprovals() {
  var pending = users.filter(function (u) { return u.approval_status === "pending"; });
  var rejected = users.filter(function (u) { return u.approval_status === "rejected"; });
  var c = el("pendingCount"); c.textContent = pending.length; c.hidden = pending.length === 0;
  el("reqBody").innerHTML = pending.map(function (u) {
    return "<tr><td>" + esc(u.account_no) + "</td><td>" + esc(u.full_name) + "</td><td>" + esc(u.role) + "</td><td>" + esc(u.dept_code || "—") +
      "</td><td>" + esc(u.program_code || "—") + "</td><td>" + esc(u.year_level || "—") + "</td><td class='muted'>" + esc(u.created_at) + "</td>" +
      "<td class='row-actions'><button class='btn btn--primary btn--sm' data-approve='" + u.id + "'>Approve</button> " +
      "<button class='btn btn--danger btn--sm' data-reject='" + u.id + "'>Reject</button></td></tr>";
  }).join("");
  show("reqEmpty", pending.length === 0);
  el("rejBody").innerHTML = rejected.map(function (u) {
    return "<tr><td>" + esc(u.account_no) + "</td><td>" + esc(u.full_name) + "</td><td>" + esc(u.role) + "</td><td>" + esc(u.dept_code || "—") +
      "</td><td class='row-actions'><button class='btn btn--outline btn--sm' data-approve-rejected='" + u.id + "'>Approve anyway</button> " +
      "<button class='btn btn--danger btn--sm' data-delacct='" + u.id + "'>Delete</button></td></tr>";
  }).join("");
  show("rejEmpty", rejected.length === 0);
}

/* ---------- Departments ---------- */
function renderDepartments() {
  el("deptList").innerHTML = depts.map(function (d) {
    var progs = programs.filter(function (p) { return p.department_id == d.id; });
    var profs = users.filter(function (u) { return u.role === "professor" && u.department_id == d.id && u.approval_status === "approved"; });
    return '<section class="card dept-card"><div class="card__body">' +
      '<div class="dept-head"><div><h2 style="margin:0">' + esc(d.code) + ' <span class="muted" style="font-weight:400;font-size:.95rem">' + esc(d.name) + '</span></h2>' +
      '<p class="muted text-sm">' + profs.length + " professor(s) · " + d.students + " student(s)</p></div>" +
      '<div class="btn-row"><button class="btn btn--outline btn--sm" data-dedit="' + d.id + '">Edit</button>' +
      (d.protected ? '<span class="pill pill--ok" title="The CITCS department cannot be deleted">Protected</span>' : '<button class="btn btn--danger btn--sm" data-ddel="' + d.id + '">Delete</button>') + '</div></div>' +
      '<div class="dept-body"><div><h3>Professors</h3><ul class="plain-list">' +
      (profs.map(function (p) { return "<li><span>" + esc(p.full_name) + ' <span class="muted">' + esc(p.account_no) + "</span></span>" + (p.is_active == 1 ? "" : pill("Disabled", "bad")) + "</li>"; }).join("") || '<li class="muted">No professors yet.</li>') +
      '</ul></div><div><h3>Programs</h3><ul class="plain-list">' +
      (progs.map(function (p) { return "<li><span><b>" + esc(p.code) + "</b> " + esc(p.name) + ' <span class="muted">(' + p.years + " yrs, " + p.students + ' students)</span></span><span><button class="btn btn--outline btn--sm" data-pedit="' + p.id + '">Edit</button> <button class="btn btn--danger btn--sm" data-pdel="' + p.id + '">Remove</button></span></li>'; }).join("") || '<li class="muted">No programs yet.</li>') +
      '</ul><form class="form-grid" data-progform="' + d.id + '" style="margin-top:.6rem;grid-template-columns:90px 1fr 70px auto">' +
      '<input class="input" name="code" placeholder="Code" maxlength="20" required /><input class="input" name="name" placeholder="Program name" required />' +
      '<input class="input" name="years" type="number" min="1" max="5" value="4" title="Years" /><button class="btn btn--outline btn--sm" type="submit">Add</button></form>' +
      "</div></div></div></section>";
  }).join("") || '<p class="muted">No departments yet.</p>';
}

/* ---------- School years ---------- */
function loadSchoolYears() {
  API.get("school_year.php?action=list").then(function (r) {
    el("syBody").innerHTML = r.school_years.map(function (s) {
      var status = s.is_current == 1 ? pill("Current", "ok") : (s.promoted == 1 ? pill("Ended · students promoted", "warn") : pill(s.start_date > r.today ? "Upcoming" : "Open", "warn"));
      var actions = "";
      if (s.promoted != 1 && s.start_date <= r.today) actions += "<button class='btn btn--outline btn--sm' data-syend='" + s.id + "'>End now &amp; promote</button> ";
      if (s.is_current != 1) actions += "<button class='btn btn--danger btn--sm' data-sydel='" + s.id + "'>Delete</button>";
      return "<tr><td><b>" + esc(s.label) + "</b></td><td>" + esc(s.start_date) + "</td><td>" + esc(s.end_date) + "</td><td>" + status + "</td><td class='row-actions'>" + actions + "</td></tr>";
    }).join("") || "<tr><td colspan='5' class='muted'>No school years yet.</td></tr>";
    loadUsers();   // promotion may have changed year levels
  }).catch(fail);
}

/* ---------- Edit modal ---------- */
function openEdit(id) {
  var u = users.filter(function (x) { return x.id == id; })[0];
  if (!u) return;
  el("eRole").value = u.role; el("eAcct").value = u.account_no; el("eName").value = u.full_name;
  el("eDept").value = u.department_id || "";
  Catalog.fillPrograms(el("eProg"), u.department_id || "");
  el("eProg").value = u.program_id || ""; el("eYear").value = u.year_level || "";
  el("eSection").value = u.section || ""; el("eType").value = u.student_type || "regular";
  el("eGradInfo").textContent = u.role === "student" ? "Graduation status: " + gradLabel(u.graduation_status) + " · " + (u.completed_years || 0) + (u.program_years ? " of " + u.program_years : "") + " academic year(s) completed. Change it in the Graduation tab." : "";
  el("eNote").textContent = ""; el("editForm").dataset.id = u.id;
  applyRole(u.role, "e"); openModal("editModal");
}

/* ---------- Wiring ---------- */
document.addEventListener("DOMContentLoaded", function () {
  Session.ready.then(function () {
    Catalog.load().then(function () {
      Catalog.link(el("uDept"), el("uProg")); Catalog.fillYears(el("uYear"));
      Catalog.link(el("eDept"), el("eProg")); Catalog.fillYears(el("eYear"));
      applyRole("student", "u");
    });
    reloadAll();
    var startTab = window.location.hash.slice(1);
    if (["accounts", "approvals", "departments", "schoolyear", "graduation", "activity"].indexOf(startTab) !== -1) openTab(startTab);
    window.addEventListener("hashchange", function () { var t = window.location.hash.slice(1); if (t && el("tab-" + t)) openTab(t); });
    wireExtras();

    [].forEach.call(document.querySelectorAll(".tab"), function (b) { b.onclick = function () { openTab(b.dataset.tab); }; });
    el("uRole").onchange = function () { applyRole(this.value, "u"); };
    el("eRole").onchange = function () { applyRole(this.value, "e"); };
    ["fRole", "fDept"].forEach(function (id) { el(id).onchange = renderAccounts; });
    el("fSearch").oninput = renderAccounts;

    el("userForm").addEventListener("submit", function (e) {
      e.preventDefault();
      var role = el("uRole").value;
      API.post("users.php?action=create", {
        role: role, account_no: el("uAcct").value.trim(), full_name: el("uName").value.trim(),
        department_id: el("uDept").value, program_id: el("uProg").value, year_level: el("uYear").value,
        section: el("uSection").value.trim(), student_type: el("uType").value, password: el("uPass").value
      }).then(function () { el("uNote").style.color = ""; el("uNote").textContent = "Account created."; el("userForm").reset(); Catalog.link(el("uDept"), el("uProg")); applyRole("student", "u"); loadDepartments(); loadUsers(); })
        .catch(function (e2) { el("uNote").style.color = "var(--destructive)"; el("uNote").textContent = e2.message; });
    });

    el("editForm").addEventListener("submit", function (e) {
      e.preventDefault();
      API.post("users.php?action=update", {
        id: +this.dataset.id, role: el("eRole").value, account_no: el("eAcct").value.trim(), full_name: el("eName").value.trim(),
        department_id: el("eDept").value, program_id: el("eProg").value, year_level: el("eYear").value,
        section: el("eSection").value.trim(), student_type: el("eType").value
      }).then(function () { closeModal("editModal"); loadDepartments(); loadUsers(); })
        .catch(function (e2) { el("eNote").textContent = e2.message; });
    });

    el("deptForm").addEventListener("submit", function (e) {
      e.preventDefault();
      API.post("departments.php?action=create_department", { code: el("dCode").value, name: el("dName").value.trim() })
        .then(function () { el("dNote").style.color = ""; el("dNote").textContent = "Department added."; el("deptForm").reset(); loadDepartments(); })
        .catch(function (e2) { el("dNote").style.color = "var(--destructive)"; el("dNote").textContent = e2.message; });
    });

    el("syForm").addEventListener("submit", function (e) {
      e.preventDefault();
      API.post("school_year.php?action=create", { label: el("syLabel").value.trim(), start_date: el("syStart").value, end_date: el("syEnd").value })
        .then(function (r) { el("syNote").style.color = ""; el("syNote").textContent = r.historical ? "Added as a past school year (no promotion)." : "School year added."; el("syForm").reset(); loadSchoolYears(); })
        .catch(function (e2) { el("syNote").style.color = "var(--destructive)"; el("syNote").textContent = e2.message; });
    });

    // One click handler for every row button (the tables are re-rendered often).
    document.addEventListener("click", function (e) {
      var b = e.target.closest("button"); if (!b) return;
      var d = b.dataset;
      if (d.edit) return openEdit(d.edit);
      if (d.toggle) return API.post("users.php?action=toggle", { id: +d.toggle }).then(loadUsers).catch(fail);
      if (d.reset) {
        var p = prompt("New PIN (min 6 characters):"); if (!p) return;
        return API.post("users.php?action=reset_pin", { id: +d.reset, password: p }).then(function () { alert("PIN updated."); }).catch(fail);
      }
      if (d.approve) return API.post("users.php?action=approve", { id: +d.approve }).then(loadUsers).catch(function (er) { fail(er); loadUsers(); });
      if (d.approveRejected) return API.post("users.php?action=approve_rejected", { id: +d.approveRejected }).then(loadUsers).catch(function (er) { fail(er); loadUsers(); });
      if (d.delacct) return openDeleteAccount(+d.delacct);
      if (d.pedit) return editProgram(+d.pedit);
      if (d.grad) return openGraduation(+d.grad);
      if (d.reject) return API.post("users.php?action=reject", { id: +d.reject }).then(loadUsers).catch(function (er) { fail(er); loadUsers(); });
      if (d.dedit) {
        var dep = depts.filter(function (x) { return x.id == d.dedit; })[0];
        var code = prompt("Department code:", dep.code); if (!code) return;
        var name = prompt("Department name:", dep.name); if (!name) return;
        return API.post("departments.php?action=update_department", { id: +d.dedit, code: code, name: name }).then(reloadAll).catch(fail);
      }
      if (d.ddel) return openDeleteDepartment(+d.ddel);
      if (d.pdel) return openDeleteProgram(+d.pdel);
      if (d.syend) { if (confirm("End this school year today and move all students up one year level now? This cannot be undone.")) API.post("school_year.php?action=end_now", { id: +d.syend }).then(function (r) { alert(r.promoted + " student(s) promoted."); loadSchoolYears(); }).catch(fail); return; }
      if (d.sydel) { if (confirm("Delete this school year?")) API.post("school_year.php?action=delete", { id: +d.sydel }).then(loadSchoolYears).catch(fail); return; }
    });
    document.addEventListener("submit", function (e) {
      var f = e.target; if (!f.dataset || !f.dataset.progform) return;
      e.preventDefault();
      API.post("departments.php?action=create_program", { department_id: +f.dataset.progform, code: f.code.value, name: f.name.value.trim(), years: +f.years.value })
        .then(reloadAll).catch(fail);
    });
  });
});

/* =========================================================
   Confirmation dialog + delete flows + graduation + activity
   ========================================================= */

/* One reusable dialog. opts: title, html, confirmText, danger, hideConfirm, onConfirm(done, fail) -> may return a promise. */
var Dlg = (function () {
  var handler = null;
  function open(opts) {
    el("dlgTitle").textContent = opts.title;
    el("dlgBody").innerHTML = opts.html;
    el("dlgError").hidden = true;
    var ok = el("dlgConfirm");
    ok.textContent = opts.confirmText || "Confirm";
    ok.className = "btn " + (opts.danger === false ? "btn--primary" : "btn--danger");
    ok.hidden = !!opts.hideConfirm; ok.disabled = false;
    handler = opts.onConfirm || null;
    openModal("dlgModal");
  }
  function error(msg) { var n = el("dlgError"); n.textContent = msg; n.hidden = false; el("dlgConfirm").disabled = false; }
  function close() { closeModal("dlgModal"); handler = null; }
  function wire() {
    el("dlgConfirm").addEventListener("click", function () {
      if (!handler) return;
      this.disabled = true;
      el("dlgError").hidden = true;
      var r = handler();
      if (r && r.then) r.catch(function (e) { error(e.message || "Something went wrong."); });
    });
  }
  return { open: open, error: error, close: close, wire: wire };
})();

function plural(n, one, many) { return n + " " + (n === 1 ? one : many); }

/* ---------- delete account ---------- */
function openDeleteAccount(id) {
  var u = users.filter(function (x) { return Number(x.id) === id; })[0];
  if (!u) return;
  API.post("users.php?action=delete_check", { id: id }).then(function (r) {
    var i = r.impact, html = "<p>You are about to <b>permanently delete</b> the " + esc(i.role) + " account <b>" + esc(i.name) + "</b> (" + esc(i.account_no) + "). This cannot be undone.</p>";
    if (i.blocked) {
      Dlg.open({ title: "Account cannot be deleted", html: html + '<p class="danger" style="margin-top:.5rem">' + esc(i.blocked) + "</p>", hideConfirm: true });
      return;
    }
    if (i.role === "student" && i.attempts) {
      html += '<p class="danger" style="margin-top:.5rem"><b>' + plural(i.attempts, "examination attempt", "examination attempts") + "</b> of this student" + (i.violations ? " (with " + plural(i.violations, "violation", "violations") + ")" : "") +
        " will be deleted too, including answers and scores. To keep the records, cancel and use <b>Disable</b> instead.</p>" +
        '<label class="check" style="margin-top:.5rem"><input type="checkbox" id="dForce" /> <span>I understand that these examination records are deleted permanently.</span></label>';
    }
    if (i.role === "professor" && i.exams) {
      html += '<p class="danger" style="margin-top:.5rem">This professor created <b>' + plural(i.exams, "examination", "examinations") + "</b> (" + plural(i.exam_attempts, "student attempt", "student attempts") +
        "). Choose who takes them over, so no examination or result is lost.</p>" +
        '<label class="field"><span class="field__label">Transfer the examinations to</span><select class="select" id="dReassign"><option value="">Select a professor</option>' +
        i.candidates.map(function (c) { return '<option value="' + esc(c.id) + '">' + esc(c.name) + " (" + esc(c.account_no) + (c.dept_code ? " · " + esc(c.dept_code) : "") + ")</option>"; }).join("") + "</select></label>";
    }
    Dlg.open({
      title: "Delete account?", html: html, confirmText: "Delete permanently",
      onConfirm: function () {
        var body = { id: id, confirm: true };
        if (el("dForce")) { if (!el("dForce").checked) { Dlg.error("Tick the box to confirm that the examination records are deleted."); return; } body.force_records = true; }
        if (el("dReassign")) { if (!el("dReassign").value) { Dlg.error("Choose the professor who takes over the examinations."); return; } body.reassign_to = +el("dReassign").value; }
        return API.post("users.php?action=delete", body).then(function () { Dlg.close(); reloadAll(); });
      }
    });
  }).catch(fail);
}

/* ---------- delete department / program ---------- */
function openDeleteDepartment(id) {
  API.get("departments.php?action=department_check&id=" + id).then(function (r) {
    var i = r.impact, html = "<p>Delete the department <b>" + esc(i.code) + "</b> (" + esc(i.name) + ")?</p>";
    if (i.blocked) {
      Dlg.open({ title: "Department cannot be deleted", html: html + '<p class="danger" style="margin-top:.5rem">' + esc(i.blocked) + "</p>", hideConfirm: true });
      return;
    }
    html += "<p class='muted text-sm' style='margin-top:.5rem'>It has no students, professors or examinations." + (i.programs ? " Its " + plural(i.programs, "empty program", "empty programs") + " will be deleted with it." : "") + " This cannot be undone.</p>";
    Dlg.open({ title: "Delete department?", html: html, confirmText: "Delete department",
      onConfirm: function () { return API.post("departments.php?action=delete_department", { id: id, confirm: true }).then(function () { Dlg.close(); reloadAll(); }); } });
  }).catch(fail);
}

function openDeleteProgram(id) {
  API.get("departments.php?action=program_check&id=" + id).then(function (r) {
    var i = r.impact, html = "<p>Remove the program <b>" + esc(i.code) + "</b> (" + esc(i.name) + ")?</p>";
    if (i.requires_transfer) {
      if (!i.candidates.length) {
        Dlg.open({ title: "Program cannot be removed", html: html + '<p class="danger" style="margin-top:.5rem">' + plural(i.students, "student account is", "student accounts are") + " still enrolled and " + esc(i.dept_code) +
          " has no other program to move them to. Add another program first.</p>", hideConfirm: true });
        return;
      }
      html += '<p class="danger" style="margin-top:.5rem">' + plural(i.students, "student account is", "student accounts are") + " enrolled (" + i.active + " active" + (i.graduated ? ", " + i.graduated + " graduated" : "") +
        "). Their records are kept: choose the program they move to. Their graduation eligibility is re-checked against the new program.</p>" +
        '<label class="field"><span class="field__label">Move the students to</span><select class="select" id="pMove"><option value="">Select a program</option>' +
        i.candidates.map(function (c) { return '<option value="' + esc(c.id) + '">' + esc(c.code) + " · " + esc(c.name) + " (" + esc(c.years) + " yrs)</option>"; }).join("") + "</select></label>";
    }
    Dlg.open({ title: "Remove program?", html: html, confirmText: "Remove program",
      onConfirm: function () {
        var body = { id: id, confirm: true };
        if (i.requires_transfer) { if (!el("pMove").value) { Dlg.error("Choose the program the students move to."); return; } body.transfer_to = +el("pMove").value; }
        return API.post("departments.php?action=delete_program", body).then(function () { Dlg.close(); reloadAll(); });
      } });
  }).catch(fail);
}

function editProgram(id) {
  var p = programs.filter(function (x) { return Number(x.id) === id; })[0];
  if (!p) return;
  Dlg.open({ title: "Edit program " + p.code, danger: false, confirmText: "Save program",
    html: '<label class="field"><span class="field__label">Name</span><input class="input" id="pName" maxlength="150" value="' + esc(p.name) + '" /></label>' +
      '<label class="field"><span class="field__label">Required duration (years)</span><input class="input" id="pYears" type="number" min="1" max="5" value="' + esc(p.years) + '" /></label>' +
      '<p class="muted text-xs">The duration decides when Regular students of this program become eligible for graduation.</p>',
    onConfirm: function () {
      return API.post("departments.php?action=update_program", { id: id, name: el("pName").value.trim(), years: +el("pYears").value }).then(function () { Dlg.close(); reloadAll(); });
    } });
}

/* ---------- graduation ---------- */
var gradRows = [], gradPrograms = [];

function loadGraduation() {
  return API.get("graduation.php?action=list").then(function (r) {
    gradRows = r.students; gradPrograms = r.programs;
    var sel = el("gProgram"), cur = sel.value;
    sel.innerHTML = '<option value="">All programs</option>' + gradPrograms.map(function (p) { return '<option value="' + esc(p.code) + '">' + esc(p.code) + " (" + esc(p.years) + " yrs)</option>"; }).join("");
    sel.value = cur;
    renderGraduation();
  }).catch(fail);
}

function gradPill(s) {
  return pill(gradLabel(s), s === "graduated" ? "ok" : s === "eligible" ? "warn" : "bad");
}

function renderGraduation() {
  var st = el("gStatus").value, ty = el("gType").value, pr = el("gProgram").value, needs = el("gNeeds").checked, q = norm(el("gSearch").value);
  var waiting = gradRows.filter(function (s) { return s.needs_verification || s.graduation_status === "eligible"; }).length;
  var c = el("gradCount"); c.textContent = waiting; c.hidden = waiting === 0;
  var rows = gradRows.filter(function (s) {
    if (st && s.graduation_status !== st) return false;
    if (ty === "none" ? s.student_type : (ty && s.student_type !== ty)) return false;
    if (pr && s.program_code !== pr) return false;
    if (needs && !(s.needs_verification || s.graduation_status === "eligible")) return false;
    if (q && norm([s.account_no, s.full_name, s.program_code, s.year_level, s.section, s.dept_code].join(" ")).indexOf(q) === -1) return false;
    return true;
  });
  el("gradBody").innerHTML = rows.map(function (s) {
    var years = s.required_years === null ? "—" : s.completed_years + " / " + s.required_years;
    var note = s.needs_verification ? '<p class="text-xs danger">Duration met · needs verification</p>' : "";
    return "<tr><td>" + esc(s.full_name) + '<p class="text-xs muted">' + esc(s.account_no) + (s.section ? " · " + esc(s.section) : "") + "</p></td>" +
      "<td>" + esc(s.program_code || "—") + '<p class="text-xs muted">' + esc(s.dept_code || "") + "</p></td><td>" + esc(s.year_level || "—") + "</td>" +
      "<td>" + esc(s.student_type === "regular" ? "Regular" : s.student_type === "irregular" ? "Irregular" : "Not set") + "</td>" +
      "<td>" + esc(years) + note + "</td><td>" + gradPill(s.graduation_status) + (s.graduation_locked ? '<p class="text-xs muted">Set by an administrator</p>' : "") + "</td>" +
      '<td class="text-xs muted">' + (s.graduation_verified_at ? esc(s.graduation_verified_at) + (s.verified_by ? "<br>" + esc(s.verified_by) : "") : "—") + "</td>" +
      "<td class='row-actions'><button class='btn btn--outline btn--sm' data-grad='" + s.id + "'>Review</button></td></tr>";
  }).join("") || "<tr><td colspan='8' class='muted'>No students match.</td></tr>";
}

function openGraduation(id) {
  var s = gradRows.filter(function (x) { return Number(x.id) === id; })[0];
  if (!s) return;
  var irregular = s.student_type === "irregular";
  var options = [];
  if (s.graduation_status === "graduated") options.push(["revoke", "Revoke graduation"]);
  else {
    if (s.graduation_status !== "eligible") options.push(["eligible", "Verify → Eligible for Graduation"]);
    options.push(["graduated", "Verify → Graduated"]);
    if (s.graduation_status === "eligible") options.push(["not_eligible", "Set back to Not Yet Eligible"]);
    if (s.graduation_locked) options.push(["auto", "Hand back to the automatic evaluation"]);
  }
  var html = "<p><b>" + esc(s.full_name) + "</b> (" + esc(s.account_no) + ")</p>" +
    '<dl class="detail-list"><div><dt>Program</dt><dd>' + esc(s.program_code || "none") + (s.required_years ? " · " + s.required_years + " years" : "") + "</dd></div>" +
    "<div><dt>Student type</dt><dd>" + esc(s.student_type === "regular" ? "Regular" : s.student_type === "irregular" ? "Irregular" : "Not set") + "</dd></div>" +
    "<div><dt>Year level</dt><dd>" + esc(s.year_level || "—") + "</dd></div>" +
    "<div><dt>Status</dt><dd>" + gradPill(s.graduation_status) + "</dd></div></dl>";
  if (s.blockers.length) html += '<p class="text-sm danger">' + s.blockers.map(esc).join("<br>") + "</p>";
  if (s.notes.length) html += '<p class="text-sm">' + s.notes.map(esc).join("<br>") + "</p>";
  if (s.graduation_status !== "graduated") {
    html += '<label class="field"><span class="field__label">Academic years completed (verified)</span><span style="display:flex;gap:.5rem"><input class="input" id="gYears" type="number" min="0" max="10" value="' + esc(s.completed_years) +
      '" style="max-width:6rem" /><button type="button" class="btn btn--outline btn--sm" id="gYearsSave">Save years</button></span></label>';
  }
  html += '<label class="field"><span class="field__label">Decision</span><select class="select" id="gAction">' + options.map(function (o) { return '<option value="' + o[0] + '">' + esc(o[1]) + "</option>"; }).join("") + "</select></label>" +
    '<label class="field"><span class="field__label">Note (optional)</span><input class="input" id="gNote" maxlength="255" /></label>' +
    (irregular ? '<label class="check"><input type="checkbox" id="gRecords" /> <span>I verified this irregular student\'s academic records.</span></label>' : "") +
    '<label class="check"><input type="checkbox" id="gConfirm" /> <span>I confirm this decision. It is recorded with my name and the date.</span></label>';
  Dlg.open({ title: "Graduation review", html: html, confirmText: "Apply decision", danger: false,
    onConfirm: function () {
      if (!el("gConfirm").checked) { Dlg.error("Tick the confirmation box first."); return; }
      return API.post("graduation.php?action=apply", { id: id, target: el("gAction").value, note: el("gNote").value.trim(), confirm: true,
        confirm_records: el("gRecords") ? el("gRecords").checked : false, expected_status: s.graduation_status })
        .then(function () { Dlg.close(); loadGraduation(); loadUsers(); });
    } });
  var save = el("gYearsSave");
  if (save) save.addEventListener("click", function () {
    API.post("graduation.php?action=set_years", { id: id, years: +el("gYears").value, note: el("gNote").value.trim() })
      .then(function () { Dlg.close(); return loadGraduation(); }).then(function () { openGraduation(id); }).catch(function (e) { Dlg.error(e.message); });
  });
}

/* ---------- activity log + announcements ---------- */
function loadActivity() {
  API.get("activity.php?action=list").then(function (r) {
    el("logBody").innerHTML = r.log.map(function (l) {
      var det = "";
      if (l.details) { try { var o = JSON.parse(l.details); det = Object.keys(o).map(function (k) { return k + ": " + o[k]; }).join(" · "); } catch (e) { det = l.details; } }
      return "<tr><td class='text-xs'>" + esc(l.at) + "</td><td>" + esc(l.actor_name || "System") + "</td><td>" + esc(String(l.action).replace(/_/g, " ")) + "</td><td>" + esc(l.target_label || "") + "</td><td class='text-xs muted'>" + esc(det) + "</td></tr>";
    }).join("") || "<tr><td colspan='5' class='muted'>No activity recorded yet.</td></tr>";
  }).catch(fail);
}

function wireExtras() {
  Dlg.wire();
  ["gStatus", "gType", "gProgram", "gNeeds"].forEach(function (id) { el(id).addEventListener("change", renderGraduation); });
  el("gSearch").addEventListener("input", renderGraduation);
  el("gradEvaluate").addEventListener("click", function () {
    API.post("graduation.php?action=evaluate").then(function (r) { alert(r.eligible + " student(s) newly eligible, " + r.reverted + " set back to Not Yet Eligible."); loadGraduation(); }).catch(fail);
  });
  loadGraduation();   // fills the tab badge

  var sel = el("aDept");
  Catalog.load().then(function (c) { sel.innerHTML = '<option value="">All departments</option>' + c.departments.map(function (d) { return '<option value="' + esc(d.id) + '">' + esc(d.code) + "</option>"; }).join(""); });
  el("annForm").addEventListener("submit", function (e) {
    e.preventDefault();
    API.post("activity.php?action=announce", { audience: el("aAud").value, department_id: el("aDept").value, title: el("aTitle").value.trim(), message: el("aMsg").value.trim() })
      .then(function (r) { el("aNote").style.color = ""; el("aNote").textContent = "Sent to " + r.recipients + " people."; el("annForm").reset(); loadActivity(); })
      .catch(function (er) { el("aNote").style.color = "var(--destructive)"; el("aNote").textContent = er.message; });
  });
}
