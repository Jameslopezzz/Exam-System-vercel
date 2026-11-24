/* =========================================================
   Exam Builder - STEP 2: manage the questions of ONE exam (professor-exam-questions.html?exam=ID).
   Every professor can open this page to READ any exam; only the creator (or a professor an administrator allowed)
   gets the add / edit / delete / reorder controls, and the server checks that again on every request.
   ========================================================= */
document.addEventListener("DOMContentLoaded", function () {
  if (!el("qList")) return;
  var params = new URLSearchParams(window.location.search);
  var examId = params.get("exam");
  var data = null, canEdit = false, locked = false, editing = null, busy = false;

  function banner(id, msg) { var n = el(id); n.hidden = !msg; n.textContent = msg || ""; }
  function fail(msg) { banner("qError", msg); if (msg) el("qError").scrollIntoView({ block: "nearest", behavior: "smooth" }); }

  /* ----- summary ----- */
  function dd(label, value) { return "<div><dt>" + esc(label) + "</dt><dd>" + value + "</dd></div>"; }

  function drawSummary() {
    var e = data.exam, p = data.permissions;
    document.title = e.code + " · Questions | EXAM SYSTEM";
    el("qHeading").textContent = e.code + " · " + e.title;
    el("qSub").textContent = "Step 2 of 2 · " + (canEdit ? "add and manage the questions of this exam." : "viewing the exam of " + e.professor + " (read-only).");
    el("qSummary").innerHTML =
      dd("Created by", esc(e.professor) + (p.is_owner ? " (you)" : "")) +
      dd("Department", esc((e.dept_code || "—") + (e.dept_name ? " · " + e.dept_name : ""))) +
      dd("Subject", esc(e.subject)) +
      dd("Eligible year level(s)", e.year_levels.length ? esc(e.year_levels.join(", ")) : "All year levels") +
      dd("Sections", e.sections.length ? esc(e.sections.join(", ")) : "All sections") +
      dd("Exam schedule", esc(scheduleText({ scheduled_at: e.scheduled_at, available_until: e.available_until }))) +
      dd("Duration", esc(e.duration) + " min") +
      dd("Violation limit", esc(e.threshold)) +
      dd("Status", '<span class="badge badge--' + esc(e.status) + '">' + esc(e.status) + "</span>");
    el("qDescription").textContent = e.description || "";

    var acts = '<a class="btn btn--outline btn--sm" href="professor-exam-creation.html' + (p.is_owner ? "" : "#all") + '">Back to exams</a>';
    if (canEdit) {
      acts += ' <a class="btn btn--outline btn--sm" href="professor-exam-setup.html?id=' + esc(e.id) + '">Edit setup</a>' +
        ' <select class="select" id="qStatus" aria-label="Status">' + statusOptions(e.status) + "</select>";
    }
    el("qActions").innerHTML = acts;
    var sel = el("qStatus");
    if (sel) sel.addEventListener("change", function () {
      fail("");
      API.post("exam.php?action=set_status", { id: Number(examId), status: sel.value }).then(load).catch(function (err) { fail(err.message); load(); });
    });

    if (canEdit) { el("stepOneLabel").innerHTML = '<a href="professor-exam-setup.html?id=' + esc(e.id) + '">Exam setup</a>'; }
    banner("qReadOnly", canEdit ? "" : "You are viewing " + e.professor + "'s examination. Only " + e.professor + " can edit it, unless an administrator allows you to. The answer key is hidden.");
    banner("qLock", canEdit && locked ? data.lock.reason + " You can view the questions but not change them." : "");
  }

  /* ----- question list ----- */
  function questionHtml(q, i, n) {
    var media = "";
    if (q.media) media = '<p class="text-xs muted">Media: ' + esc(q.media.kind || "media") + (q.media.src ? " · " + esc(q.media.src) : "") + "</p>";
    var choices = "";
    if (q.type === "multiple-choice") {
      choices = "<ul>" + q.choices.map(function (c) {
        var ok = canEdit && c === q.correct;
        return "<li" + (ok ? ' class="is-correct"' : "") + ">" + esc(c) + (ok ? " ✓" : "") + "</li>";
      }).join("") + "</ul>";
    }
    var tools = "";
    if (canEdit && !locked) {
      tools = '<div class="q-card__tools">' +
        '<button type="button" class="chip" data-up="' + i + '"' + (i === 0 ? " disabled" : "") + ' aria-label="Move up">↑ Up</button>' +
        '<button type="button" class="chip" data-down="' + i + '"' + (i === n - 1 ? " disabled" : "") + ' aria-label="Move down">↓ Down</button>' +
        '<button type="button" class="btn btn--outline btn--sm" data-edit="' + i + '">Edit</button>' +
        '<button type="button" class="btn btn--danger btn--sm" data-delete="' + i + '">Delete</button></div>';
    }
    return '<li class="q-card"><div class="q-card__head"><b>Question ' + (i + 1) + ' <span class="muted text-sm">(' + (q.type === "essay" ? "essay" : "multiple choice") + ", " + esc(q.points) + " pt" + (Number(q.points) === 1 ? "" : "s") + ")</span></b>" + tools + "</div>" +
      '<p style="white-space:pre-wrap">' + esc(q.prompt) + "</p>" + media + choices + "</li>";
  }

  function drawQuestions() {
    var qs = data.questions;
    el("qCount").textContent = String(qs.length);
    el("qPoints").textContent = String(data.exam.total_points);
    el("qList").innerHTML = qs.length ? qs.map(function (q, i) { return questionHtml(q, i, qs.length); }).join("")
      : '<li class="muted text-sm">' + (canEdit ? 'No questions yet. Click "Add question" to write the first one.' : "This exam has no questions yet.") + "</li>";
    el("btnAddQuestion").hidden = !(canEdit && !locked);
  }

  function load() {
    return API.get("exam.php?action=view&id=" + encodeURIComponent(examId)).then(function (r) {
      data = r; canEdit = !!r.permissions.can_edit; locked = !!(r.lock && r.lock.locked);
      drawSummary(); drawQuestions();
    }).catch(function (e) {
      el("qHeading").textContent = "Examination not available";
      el("qSub").textContent = "";
      fail(e.message);
    });
  }

  el("qList").addEventListener("click", function (ev) {
    var b = ev.target.closest("button");
    if (!b || busy) return;
    var qs = data.questions, idx;
    if (b.hasAttribute("data-edit")) { openForm(qs[Number(b.getAttribute("data-edit"))]); return; }
    if (b.hasAttribute("data-delete")) {
      idx = Number(b.getAttribute("data-delete"));
      if (!window.confirm("Delete question " + (idx + 1) + "? This cannot be undone.")) return;
      busy = true; fail("");
      API.post("exam.php?action=question_delete", { exam_id: Number(examId), id: qs[idx].id }).then(load).catch(function (e) { fail(e.message); }).then(function () { busy = false; });
      return;
    }
    var dir = b.hasAttribute("data-up") ? -1 : b.hasAttribute("data-down") ? 1 : 0;
    if (dir) {
      idx = Number(b.getAttribute(dir < 0 ? "data-up" : "data-down"));
      var order = qs.map(function (q) { return q.id; });
      var other = idx + dir;
      if (other < 0 || other >= order.length) return;
      var t = order[idx]; order[idx] = order[other]; order[other] = t;
      busy = true; fail("");
      API.post("exam.php?action=question_reorder", { exam_id: Number(examId), order: order }).then(load).catch(function (e) { fail(e.message); }).then(function () { busy = false; });
    }
  });

  /* ----- add / edit form ----- */
  var choiceState = [];   // [{text}]
  var correctIdx = 0, keptMedia = null;

  function drawChoices() {
    el("qfChoices").innerHTML = choiceState.map(function (c, i) {
      return '<div class="eq-choice"><input type="radio" name="qfCorrect" data-correct="' + i + '"' + (i === correctIdx ? " checked" : "") + ' aria-label="Correct answer" />' +
        '<input class="input" data-choice="' + i + '" value="' + esc(c) + '" placeholder="Choice ' + String.fromCharCode(65 + i) + '" />' +
        (choiceState.length > 2 ? '<button type="button" class="chip" data-del-choice="' + i + '">remove</button>' : "") + "</div>";
    }).join("");
  }
  function syncChoices() {
    Array.prototype.slice.call(el("qfChoices").querySelectorAll("[data-choice]")).forEach(function (inp) { choiceState[Number(inp.getAttribute("data-choice"))] = inp.value; });
  }
  el("qfChoices").addEventListener("change", function (e) { if (e.target.hasAttribute("data-correct")) correctIdx = Number(e.target.getAttribute("data-correct")); });
  el("qfChoices").addEventListener("click", function (e) {
    var b = e.target.closest("[data-del-choice]");
    if (!b) return;
    syncChoices();
    var i = Number(b.getAttribute("data-del-choice"));
    choiceState.splice(i, 1);
    if (correctIdx === i) correctIdx = 0; else if (correctIdx > i) correctIdx--;
    drawChoices();
  });
  el("qfAddChoice").addEventListener("click", function () { syncChoices(); if (choiceState.length < 10) { choiceState.push(""); drawChoices(); } });
  el("qfType").addEventListener("change", function () { el("qfChoicesBox").hidden = el("qfType").value === "essay"; });

  function ferr(msg) { var n = el("qfError"); n.hidden = !msg; n.textContent = msg || ""; }

  function openForm(q) {
    editing = q || null;
    ferr("");
    el("questionModalTitle").textContent = q ? "Edit question " + (data.questions.indexOf(q) + 1) : "Add question";
    el("qfType").value = q ? q.type : "multiple-choice";
    el("qfPoints").value = q ? q.points : 5;
    el("qfPrompt").value = q ? q.prompt : "";
    choiceState = q && q.choices && q.choices.length ? q.choices.slice() : ["", "", "", ""];
    correctIdx = q ? Math.max(0, choiceState.indexOf(q.correct)) : 0;
    el("qfChoicesBox").hidden = el("qfType").value === "essay";
    drawChoices();
    // pictures / videos are editable; a table or chart (seeded exams) is kept exactly as it is
    keptMedia = null;
    var simple = !q || !q.media || q.media.kind === "image" || q.media.kind === "video";
    el("qfMediaBox").hidden = !simple;
    el("qfMediaKept").hidden = simple;
    if (!simple) { keptMedia = q.media; el("qfMediaKept").textContent = "This question includes a " + q.media.kind + " that is kept as it is."; }
    el("qfMediaKind").value = q && q.media && simple ? q.media.kind : "";
    el("qfMediaSrc").value = q && q.media && simple ? (q.media.src || "") : "";
    el("qfSaveNew").hidden = !!q;
    openModal("questionModal");
    el("qfPrompt").focus();
  }
  el("btnAddQuestion").addEventListener("click", function () { openForm(null); });

  function collect() {
    syncChoices();
    var prompt = el("qfPrompt").value.trim();
    if (!prompt) return { error: "Write the question prompt first." };
    var q = { type: el("qfType").value, prompt: prompt, points: Math.max(1, Number(el("qfPoints").value) || 1) };
    if (q.type === "multiple-choice") {
      var correctText = (choiceState[correctIdx] || "").trim();
      var list = choiceState.map(function (c) { return c.trim(); }).filter(Boolean);
      if (list.length < 2) return { error: "Enter at least two choices." };
      var seen = {}, dup = false;
      list.forEach(function (c) { var k = c.toLowerCase(); if (seen[k]) dup = true; seen[k] = true; });
      if (dup) return { error: "Two choices have the same text. Every choice must be different." };
      if (!correctText) return { error: "Mark one of the filled-in choices as the correct answer." };
      q.choices = list; q.correct = correctText;
    }
    if (keptMedia) q.media = keptMedia;
    else {
      var kind = el("qfMediaKind").value, src = el("qfMediaSrc").value.trim();
      if (kind && src) q.media = { kind: kind, src: src, caption: "", alt: "Question media" };
    }
    return { question: q };
  }

  function saveQuestion(again) {
    if (busy) return;
    var c = collect();
    if (c.error) return ferr(c.error);
    ferr(""); busy = true;
    API.post("exam.php?action=question_save", { exam_id: Number(examId), id: editing ? editing.id : 0, question: c.question }).then(function () {
      return load();
    }).then(function () {
      if (again && !editing) openForm(null); else closeModal("questionModal");
    }).catch(function (e) { ferr(e.message); }).then(function () { busy = false; });
  }
  el("qfSave").addEventListener("click", function () { saveQuestion(false); });
  el("qfSaveNew").addEventListener("click", function () { saveQuestion(true); });

  Session.ready.then(function () {
    if (!examId) { fail("No examination selected."); el("qSub").textContent = ""; return; }
    if (params.get("new") === "1") banner("qNew", "Exam setup saved. Now add the questions for this exam.");
    return load();
  });
});
