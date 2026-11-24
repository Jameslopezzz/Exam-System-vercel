/* =========================================================
   Exam Builder - STEP 1: exam setup (no questions here).
   Create: professor-exam-setup.html        Edit: professor-exam-setup.html?id=ID
   The department is the professor's own (set by the server). Questions are added on professor-exam-questions.html.
   ========================================================= */
document.addEventListener("DOMContentLoaded", function () {
  var form = el("setupForm");
  if (!form) return;

  var editId = new URLSearchParams(window.location.search).get("id");
  var locked = false, picker = null, previewTimer = null, busy = false;
  var RULE_IDS = { requireCamera: "ruleCamera", faceAngle: "ruleAngle", eyeGaze: "ruleGaze", multiFace: "ruleMulti", copyPaste: "ruleCopy", tabSwitch: "ruleTab" };

  function fail(msg) {
    var n = el("setupError");
    n.hidden = !msg; n.textContent = msg || "";
    if (msg) n.scrollIntoView({ block: "nearest", behavior: "smooth" });
  }
  function checkedYears() {
    return Array.prototype.slice.call(el("fYears").querySelectorAll("input:checked")).map(function (c) { return c.value; });
  }
  function on(id) { return !!el(id).checked; }

  /* live count of eligible students (same department only) */
  function preview() {
    window.clearTimeout(previewTimer);
    previewTimer = window.setTimeout(function () {
      var years = checkedYears(), box = el("eligibleBox");
      if (!years.length) { box.textContent = "Select the year level(s) to see how many students are eligible."; return; }
      var secs = picker ? picker.get() : [];
      API.post("exam.php?action=eligible", { year_levels: years, sections: secs || [] }).then(function (r) {
        var parts = Object.keys(r.by_year).map(function (k) { return k + ": " + r.by_year[k]; });
        box.textContent = r.count + " eligible student" + (r.count === 1 ? "" : "s") + " in " + r.department.code +
          (parts.length ? " (" + parts.join(", ") + ")" : "") + ".";
      }).catch(function () {});
    }, 250);
  }

  function buildYears(levels) {
    el("fYears").innerHTML = levels.map(function (y) {
      return '<label class="check"><input type="checkbox" value="' + esc(y) + '" /> ' + esc(y) + "</label>";
    }).join("");
    el("fYears").addEventListener("change", preview);
  }

  picker = SectionPicker(el("fSections"), preview);

  function applyLock(lock) {
    locked = !!(lock && lock.locked);
    var ln = el("setupLock");
    ln.hidden = !locked;
    ln.textContent = locked ? lock.reason + " You can still change the title, description, schedule, year levels and sections." : "";
    ["fDuration", "thresholdInput"].concat(Object.keys(RULE_IDS).map(function (k) { return RULE_IDS[k]; })).forEach(function (id) { el(id).disabled = locked; });
  }

  function payload() {
    var sections = picker.get();
    var p = {
      title: el("fTitle").value.trim(), code: el("fCode").value.trim(), subject: el("fSubject").value.trim(),
      description: el("fDescription").value.trim(), schedule: el("fSchedule").value, available_until: el("fUntil").value,
      year_levels: checkedYears(), sections: sections || []
    };
    if (!locked) {
      p.duration = Number(el("fDuration").value) || 45;
      p.threshold = Number(el("thresholdInput").value) || 3;
      p.rules = {};
      Object.keys(RULE_IDS).forEach(function (k) { p.rules[k] = on(RULE_IDS[k]); });
    }
    return p;
  }

  function check(p) {
    if (!p.title || !p.code) return "Title and subject code are required.";
    if (!p.year_levels.length) return "Select at least one eligible year level.";
    if (picker.get() === null) return "Tick at least one section, or choose All sections.";
    if (p.schedule && p.available_until && p.available_until <= p.schedule) return "The availability must end after the exam date and time.";
    return "";
  }

  function save(goNext) {
    if (busy) return;
    fail("");
    var p = payload(), bad = check(p);
    if (bad) return fail(bad);
    if (editId) p.id = Number(editId);
    busy = true;
    el("btnSaveContinue").disabled = true; el("btnSaveOnly").disabled = true;
    API.post("exam.php?action=" + (editId ? "update" : "save"), p).then(function (r) {
      if (goNext || !editId) { window.location.href = r.next; return; }
      var n = el("setupError");
      n.hidden = false; n.className = "notice notice--ok"; n.textContent = "Setup saved.";
      window.setTimeout(function () { n.hidden = true; n.className = "notice notice--bad"; }, 2500);
      busy = false; el("btnSaveContinue").disabled = false; el("btnSaveOnly").disabled = false;
    }).catch(function (e) {
      fail(e.message);
      busy = false; el("btnSaveContinue").disabled = false; el("btnSaveOnly").disabled = false;
    });
  }

  form.addEventListener("submit", function (e) { e.preventDefault(); save(true); });
  el("btnSaveOnly").addEventListener("click", function () { save(false); });

  function disableAll(msg) {
    fail(msg);
    Array.prototype.slice.call(form.querySelectorAll("input,textarea,select,button")).forEach(function (c) { c.disabled = true; });
  }

  Session.ready.then(function () {
    return Promise.all([API.get("auth.php?action=catalog"), loadSectionCatalog()]);
  }).then(function (res) {
    buildYears(res[0].year_levels);
    if (sectionDept) el("fDept").textContent = sectionDept.code + " · " + sectionDept.name;
    else { el("fDept").textContent = "Not assigned"; if (!editId) return disableAll(sectionError || "Your account has no department yet. Ask an administrator to set it."); }

    if (!editId) return null;
    el("setupHeading").textContent = "Edit exam setup";
    el("btnSaveContinue").textContent = "Save & manage questions";
    el("btnSaveOnly").hidden = false;
    return API.get("exam.php?action=get&id=" + encodeURIComponent(editId)).then(function (r) {
      var e = r.exam;
      el("fTitle").value = e.title; el("fCode").value = e.code; el("fSubject").value = e.subject;
      el("fDescription").value = e.description || ""; el("fSchedule").value = e.schedule || ""; el("fUntil").value = e.available_until || "";
      el("fDuration").value = e.duration; el("thresholdInput").value = e.threshold;
      Object.keys(RULE_IDS).forEach(function (k) { el(RULE_IDS[k]).checked = !!e.rules[k]; });
      if (e.department) el("fDept").textContent = e.department.code + " · " + e.department.name;
      Array.prototype.slice.call(el("fYears").querySelectorAll("input")).forEach(function (c) { c.checked = e.year_levels.indexOf(c.value) !== -1; });
      picker.set(e.sections || []);
      var st = el("setupStatus"); st.hidden = false; st.className = "badge badge--" + e.status; st.textContent = e.status;
      applyLock(r.lock);
      var two = el("stepTwoLabel");
      two.innerHTML = '<a href="professor-exam-questions.html?exam=' + esc(e.id) + '">Questions (' + esc(e.question_count) + ")</a>";
      el("stepTwo").classList.add("is-done");
      if (!e.year_levels.length) fail("This older exam has no year level yet. Select the eligible year level(s) before saving.");
      preview();
    }).catch(function (err) {
      if (err.status === 403) { window.location.replace("professor-exam-questions.html?exam=" + encodeURIComponent(editId)); return; }
      disableAll(err.message);
    });
  });
});
