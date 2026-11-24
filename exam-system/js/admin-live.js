/* =========================================================
   Administrator live monitoring (VIEW ONLY).
   Same data and same live webcam (WebRTC, js/livecam.js) the professor sees, for any examination:
   student, exam, status, progress, violations and the camera feed. No warning / force-submit / re-enable buttons exist here,
   and api/monitor.php refuses those actions for administrators.
   ========================================================= */
(function () {
  var MAX_AUTO_STREAMS = 24, HIDDEN_PAUSE_MS = 30000;
  var examId = new URLSearchParams(window.location.search).get("exam_id") || "";
  var students = [], threshold = 3, selectedId = null, tiles = {}, manual = {}, vstate = {}, prev = null, events = [];
  var viewer = null, examMeta = null, pausedHidden = false, hiddenTimer = null;
  var BAD_CAM = { lost: "Camera disconnected", denied: "Camera blocked", unavailable: "No camera found" };

  function nowLabel() { return new Date().toLocaleTimeString([], { hour: "2-digit", minute: "2-digit", second: "2-digit" }); }
  function isActive(s) { return s.status === "in-progress" || s.status === "flagged"; }
  function statusText(s) { return s.status.replace("-", " "); }
  function playVideo(v) { var p = v.play(); if (p && p.catch) p.catch(function () {}); }
  function findStudent(id) { for (var i = 0; i < students.length; i++) if (students[i].id === id) return students[i]; return null; }

  function pushEvent(kind, text) {
    events.unshift({ kind: kind, text: text, at: nowLabel() });
    if (events.length > 40) events.pop();
    var box = el("monitorEvents");
    box.className = "event-list";
    box.innerHTML = events.map(function (e) { return '<li class="event-item event-item--' + esc(e.kind) + '">' + esc(e.text) + "<time>" + esc(e.at) + "</time></li>"; }).join("");
  }

  function camInfo(s) {
    if (!isActive(s)) return { kind: "idle", text: s.status === "auto-submitted" ? "Auto-submitted" : "Finished" };
    if (!s.online) return { kind: "bad", text: "Offline" };
    if (BAD_CAM[s.cam]) return { kind: "bad", text: BAD_CAM[s.cam] };
    var v = vstate[s.id];
    if (v === "live") return { kind: "live", text: "Live" };
    if (v === "stalled") return { kind: "bad", text: "No video" };
    if (v === "failed") return { kind: "bad", text: "Unreachable" };
    if (v === "connecting" || v === "reconnecting") return { kind: "warn", text: v === "connecting" ? "Connecting…" : "Reconnecting…" };
    return { kind: "idle", text: s.cam === "unknown" ? "Waiting for camera" : "Not loaded" };
  }

  function buildTile(s) {
    var root = document.createElement("div");
    root.className = "monitor-tile"; root.setAttribute("role", "button"); root.tabIndex = 0;
    root.innerHTML =
      '<div class="monitor-tile__feed"><video autoplay playsinline muted hidden></video>' +
      '<div class="feed-overlay"><span class="feed-overlay__title"></span><span class="feed-overlay__sub"></span><button type="button" class="btn btn--gold btn--sm" hidden></button></div>' +
      '<span class="live-pill" hidden><span class="dot"></span> LIVE</span><span class="auto-pill" hidden>AUTO-SUBMITTED</span></div>' +
      '<div class="monitor-tile__body"><p class="monitor-tile__name"></p><p class="text-xs muted tile-meta"></p><div class="progress"><div class="progress__bar"></div></div>' +
      '<div class="monitor-tile__foot"><span class="badge"></span><span class="cam-badge"></span><span class="tile-viol" style="font-weight:700"></span></div></div>';
    var t = { id: s.id, root: root, video: root.querySelector("video"), overlay: root.querySelector(".feed-overlay"),
      title: root.querySelector(".feed-overlay__title"), sub: root.querySelector(".feed-overlay__sub"), action: root.querySelector(".feed-overlay .btn"),
      live: root.querySelector(".live-pill"), auto: root.querySelector(".auto-pill"), name: root.querySelector(".monitor-tile__name"),
      meta: root.querySelector(".tile-meta"), bar: root.querySelector(".progress__bar"), badge: root.querySelector(".badge"),
      cam: root.querySelector(".cam-badge"), viol: root.querySelector(".tile-viol") };
    root.addEventListener("click", function () { selectStudent(t.id); });
    root.addEventListener("keydown", function (e) { if (e.key === "Enter" || e.key === " ") { e.preventDefault(); selectStudent(t.id); } });
    t.action.addEventListener("click", function (e) {
      e.stopPropagation(); manual[t.id] = true;
      if (viewer.has(t.id) && vstate[t.id] === "failed") viewer.retry(t.id); else viewer.connect(t.id);
    });
    tiles[s.id] = t;
    return t;
  }

  function updateTile(t, s) {
    var info = camInfo(s);
    t.name.textContent = s.name;
    t.meta.textContent = s.studentNo + " · " + (s.section || "") + (s.attempt > 1 ? " · attempt " + s.attempt : "");
    t.bar.style.width = s.progress + "%";
    t.badge.className = "badge badge--" + s.status; t.badge.textContent = statusText(s);
    t.cam.className = "cam-badge cam-badge--" + info.kind; t.cam.textContent = info.text;
    t.viol.className = "tile-viol " + (s.violations ? "danger" : "muted"); t.viol.textContent = s.violations + "/" + threshold;
    t.auto.hidden = s.status !== "auto-submitted";
    t.root.classList.toggle("is-selected", s.id === selectedId);
    var live = vstate[s.id] === "live" && isActive(s) && s.online && !BAD_CAM[s.cam];
    t.video.hidden = !live; t.live.hidden = !live;
    var title = "", sub = "", bad = info.kind === "bad", btn = "";
    if (!isActive(s)) { title = s.status === "auto-submitted" ? "Auto-submitted" : "Finished"; sub = "Camera closed"; bad = s.status === "auto-submitted"; }
    else if (!s.online) { title = "Student offline"; sub = "No signal from the student's browser"; }
    else if (BAD_CAM[s.cam]) { title = BAD_CAM[s.cam]; sub = s.cam === "denied" ? "The student has not allowed camera access" : "The student's camera is not available"; }
    else if (!viewer.supported()) { title = "Live video not supported"; sub = "Use a current Chrome, Edge, Firefox or Safari"; bad = true; }
    else if (vstate[s.id] === "failed") { title = "Cannot reach camera"; sub = "The video connection could not be made"; btn = "Retry"; }
    else if (vstate[s.id] === "stalled") { title = "Video interrupted"; sub = "The student's camera stopped sending pictures"; }
    else if (vstate[s.id] === "connecting" || vstate[s.id] === "reconnecting") { title = vstate[s.id] === "connecting" ? "Connecting to camera…" : "Reconnecting…"; }
    else if (s.cam === "unknown") { title = "Waiting for camera"; sub = "The student's camera has not reported yet"; }
    else { title = "Camera not loaded"; btn = "Load camera"; }
    t.overlay.hidden = live; t.overlay.classList.toggle("is-bad", bad);
    t.title.textContent = title; t.sub.textContent = sub; t.action.hidden = !btn; t.action.textContent = btn;
  }

  function visibleStudents() {
    var term = el("monitorSearch").value.trim().toLowerCase(), status = el("monitorStatus").value;
    return students.filter(function (s) {
      var ok = !term || s.name.toLowerCase().indexOf(term) !== -1 || s.studentNo.toLowerCase().indexOf(term) !== -1 || String(s.section || "").toLowerCase().indexOf(term) !== -1;
      return ok && (status === "all" || s.status === status);
    });
  }

  function syncGrid() {
    var grid = el("monitorGrid"), rows = visibleStudents(), shown = {};
    rows.forEach(function (s) { shown[s.id] = true; });
    Object.keys(tiles).forEach(function (id) {
      if (!students.some(function (s) { return String(s.id) === id; })) {
        viewer.disconnect(Number(id)); tiles[id].root.remove(); delete tiles[id]; delete vstate[id]; delete manual[id];
      }
    });
    var empty = grid.querySelector(".monitor-empty");
    if (!rows.length) {
      if (!empty) { empty = document.createElement("p"); empty.className = "muted text-sm monitor-empty"; grid.appendChild(empty); }
      empty.textContent = students.length ? "No examinee matches this search." : "No students have started this exam yet.";
    } else if (empty) { empty.remove(); }
    var cursor = grid.firstElementChild;
    rows.forEach(function (s) {
      var t = tiles[s.id] || buildTile(s);
      updateTile(t, s); t.root.hidden = false;
      if (t.root === cursor) cursor = cursor.nextElementSibling; else grid.insertBefore(t.root, cursor);
    });
    students.forEach(function (s) { if (!shown[s.id] && tiles[s.id]) tiles[s.id].root.hidden = true; });
  }

  function reconcileStreams() {
    if (!viewer.supported()) return;
    var wanted = [];
    if (!pausedHidden) {
      var vis = {};
      visibleStudents().forEach(function (s) { vis[s.id] = true; });
      students.forEach(function (s) { if (isActive(s) && s.online && !BAD_CAM[s.cam] && vis[s.id]) wanted.push(s); });
    }
    var want = {}, auto = 0;
    wanted.forEach(function (s) { if (manual[s.id]) want[s.id] = true; else if (auto < MAX_AUTO_STREAMS) { want[s.id] = true; auto++; } });
    Object.keys(tiles).forEach(function (id) { id = Number(id); if (!want[id] && viewer.has(id)) viewer.disconnect(id); });
    Object.keys(want).forEach(function (id) { if (!viewer.has(Number(id))) viewer.connect(Number(id)); });
  }

  function onViewerState(subId, state) {
    var old = vstate[subId];
    if (state === "closed") delete vstate[subId]; else vstate[subId] = state;
    var s = findStudent(subId);
    if (s && old !== state) {
      if (state === "stalled") pushEvent("bad", s.name + ": the camera video stopped.");
      else if (state === "failed") pushEvent("bad", s.name + ": cannot reach the camera.");
      else if (state === "live" && (old === "stalled" || old === "reconnecting" || old === "failed")) pushEvent("ok", s.name + ": camera video is back.");
    }
    if (s && tiles[subId]) updateTile(tiles[subId], s);
    if (selectedId === subId) renderModal();
  }

  function onViewerStream(subId, stream) {
    var t = tiles[subId];
    if (t) { t.video.srcObject = stream || null; if (stream) playVideo(t.video); }
    if (selectedId === subId) renderModal();
  }

  function diffEvents(list) {
    var cur = {};
    list.forEach(function (s) { cur[s.id] = { status: s.status, online: s.online, cam: s.cam, name: s.name }; });
    if (prev) {
      list.forEach(function (s) {
        var p = prev[s.id];
        if (!p) { if (isActive(s)) pushEvent("ok", s.name + " joined the exam."); return; }
        var was = p.status === "in-progress" || p.status === "flagged";
        if (was && s.status === "submitted") pushEvent("ok", s.name + " submitted the exam.");
        else if (was && s.status === "auto-submitted") pushEvent("bad", s.name + " was auto-submitted (violation limit).");
        if (isActive(s) && was) {
          if (p.online && !s.online) pushEvent("warn", s.name + " went offline.");
          else if (!p.online && s.online) pushEvent("ok", s.name + " is back online.");
          if (p.cam !== s.cam && BAD_CAM[s.cam]) pushEvent("bad", s.name + ": " + BAD_CAM[s.cam].toLowerCase() + ".");
        }
      });
    }
    prev = cur;
  }

  function selectStudent(id) {
    selectedId = id;
    Object.keys(tiles).forEach(function (k) { tiles[k].root.classList.toggle("is-selected", Number(k) === id); });
    el("studentModalBody").innerHTML =
      '<h3 id="mName"></h3><p class="text-xs muted" id="mMeta"></p>' +
      '<div class="modal-feed"><video id="mVideo" autoplay playsinline muted hidden></video><div class="feed-overlay" id="mOverlay"><span class="feed-overlay__title" id="mTitle"></span><span class="feed-overlay__sub" id="mSub"></span></div></div>' +
      '<dl class="detail-list" id="mInfo"></dl>';
    openModal("studentModal");
    renderModal();
  }

  function renderModal() {
    var s = findStudent(selectedId);
    if (!s || el("studentModal").hidden || !el("mName")) return;
    var info = camInfo(s), t = tiles[s.id], stream = viewer.streamOf(s.id);
    var live = !!(vstate[s.id] === "live" && isActive(s) && s.online && !BAD_CAM[s.cam] && stream);
    var video = el("mVideo");
    if (stream && video.srcObject !== stream) { video.srcObject = stream; playVideo(video); }
    video.hidden = !live; el("mOverlay").hidden = live;
    el("mTitle").textContent = t ? t.title.textContent : ""; el("mSub").textContent = t ? t.sub.textContent : "";
    el("mName").textContent = s.name;
    el("mMeta").textContent = s.studentNo + " · " + (s.section || "") + " · " + s.exam;
    el("mInfo").innerHTML =
      '<div><dt>Status</dt><dd class="capitalize">' + esc(statusText(s)) + "</dd></div><div><dt>Camera</dt><dd>" + esc(info.text) + "</dd></div>" +
      "<div><dt>Progress</dt><dd>" + esc(s.progress) + "%</dd></div>" +
      "<div><dt>Violations</dt><dd>" + esc(s.violations) + "/" + esc(threshold) + (s.totalViolations > s.violations ? ' <span class="muted">(' + esc(s.totalViolations) + " in all attempts)</span>" : "") + "</dd></div>" +
      "<div><dt>Last flag</dt><dd>" + esc(s.lastFlag) + "</dd></div><div><dt>Attempt</dt><dd>" + esc(s.attempt) + "</dd></div>" +
      (s.autoReason ? "<div><dt>Auto-submitted because</dt><dd>" + esc(s.autoReason) + "</dd></div>" : "");
  }

  function renderAlerts(alerts) {
    el("monitorAlerts").innerHTML = alerts.length ? alerts.map(function (a) {
      return '<li class="alert-item alert-item--' + esc(a.severity) + '"><p class="alert-item__name">' + esc(a.student) + '</p><p class="alert-item__meta">' + esc(a.type) + '</p><p class="alert-item__time">' + esc(a.time) + "</p></li>";
    }).join("") : '<li class="muted text-sm">No violations detected.</li>';
  }

  function renderInfo(e, count) {
    el("examInfo").innerHTML = e ?
      "<div><dt>Examination</dt><dd>" + esc(e.code) + " · " + esc(e.title) + "</dd></div><div><dt>Status</dt><dd class=\"capitalize\">" + esc(e.status) + "</dd></div>" +
      "<div><dt>Professor</dt><dd>" + esc(e.professor || "—") + "</dd></div><div><dt>Department</dt><dd>" + esc(e.dept_code || "—") + "</dd></div>" +
      "<div><dt>Duration</dt><dd>" + esc(e.duration_minutes) + " min</dd></div><div><dt>Violation limit</dt><dd>" + esc(e.violation_threshold) + "</dd></div>" +
      "<div><dt>Students taking it now</dt><dd>" + esc(count) + "</dd></div>" : "";
  }

  function refresh() {
    return API.get("monitor.php" + (examId ? "?exam_id=" + encodeURIComponent(examId) : "")).then(function (r) {
      var newId = r.exam ? String(r.exam.id) : "";
      if (examMeta && newId !== String(examMeta.id)) {
        viewer.disconnectAll();
        Object.keys(tiles).forEach(function (k) { tiles[k].root.remove(); });
        tiles = {}; vstate = {}; manual = {}; prev = null; selectedId = null; events = [];
        el("monitorEvents").innerHTML = '<li class="muted text-sm">Nothing yet.</li>';
      }
      examMeta = r.exam; students = r.students;
      if (r.exam) {
        threshold = Number(r.exam.violation_threshold);
        var sel = el("monitorExam");
        if (sel.value !== newId) sel.value = newId;
        el("monitorSubtitle").textContent = r.exam.code + " " + r.exam.title + " · " + r.exam.status + " · auto-submit at " + threshold + " violations";
        el("monitorNote").textContent = viewer.supported()
          ? "Live camera video goes directly from each student's browser to yours (encrypted) while they take the exam. Up to " + MAX_AUTO_STREAMS + " videos open automatically; use \"Load camera\" for any others."
          : "This browser cannot show live video (WebRTC is missing).";
      } else {
        el("monitorSubtitle").textContent = "No examination selected.";
      }
      renderInfo(r.exam, students.filter(isActive).length);
      diffEvents(students); reconcileStreams(); syncGrid(); renderAlerts(r.alerts); renderModal();
    }).catch(function () {});
  }

  document.addEventListener("DOMContentLoaded", function () {
    viewer = LiveCam.viewer({ onState: onViewerState, onStream: onViewerStream });
    el("monitorSearch").addEventListener("input", function () { syncGrid(); reconcileStreams(); });
    el("monitorStatus").addEventListener("change", function () { syncGrid(); reconcileStreams(); });
    var examSel = el("monitorExam");
    examSel.addEventListener("change", function () { examId = examSel.value; refresh(); });
    document.addEventListener("visibilitychange", function () {
      if (document.hidden) hiddenTimer = window.setTimeout(function () { pausedHidden = true; reconcileStreams(); syncGrid(); }, HIDDEN_PAUSE_MS);
      else { if (hiddenTimer) window.clearTimeout(hiddenTimer); if (pausedHidden) { pausedHidden = false; refresh(); } }
    });
    Session.ready.then(function () {
      API.get("admin_exams.php?action=options").then(function (o) {
        examSel.innerHTML = o.exams.length ? o.exams.slice().reverse().map(function (e) {
          return '<option value="' + esc(e.id) + '">' + esc(e.code) + " · " + esc(e.title) + " (" + esc(e.status) + ")</option>";
        }).join("") : '<option value="">No examinations</option>';
        if (examId) examSel.value = examId;
      }).catch(function () {});
      refresh();
      window.setInterval(refresh, 3000);
    });
  });
})();
