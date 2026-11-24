/* =========================================================
   Professor live monitoring.

   * Polls api/monitor.php every 3 s (progress, violations, online status, camera state).
   * Shows each student's LIVE webcam video (WebRTC, see js/livecam.js). Tiles are updated in place, never rebuilt,
     so a video keeps playing while students join, leave or finish.
   * Camera problems (disconnected / blocked / no video / student offline) show on the tile, in the banner and in the event list.
   * "Auto-submitted at the violation limit" section: re-enable access (resume or new attempt) after a confirmation.
   ========================================================= */

(function () {
  var MAX_AUTO_STREAMS = 24;      // live videos opened automatically; the rest get a "Load camera" button (keeps the page light)
  var HIDDEN_PAUSE_MS = 30000;    // close the videos when this tab has been in the background for this long

  var examId = new URLSearchParams(window.location.search).get("exam_id") || "";
  var students = [];
  var threshold = 3;
  var selectedId = null;
  var tiles = {};                 // submission id -> tile parts
  var manual = {};                // submission id -> true when the professor asked for the video by hand
  var viewer = null;
  var prev = null;                // previous snapshot, to detect joins / leaves / camera changes
  var vstate = {};                // submission id -> LiveCam viewer state
  var hiddenTimer = null, pausedHidden = false;
  var examMeta = null;
  var restoreList = [];
  var restoreTarget = null;
  var lastRestoreJson = "", lastHistJson = "";
  var events = [];
  var modalBuilt = false;

  var BAD_CAM = { lost: "Camera disconnected", denied: "Camera blocked", unavailable: "No camera found" };

  function nowLabel() { return new Date().toLocaleTimeString([], { hour: "2-digit", minute: "2-digit", second: "2-digit" }); }
  function isActive(s) { return s.status === "in-progress" || s.status === "flagged"; }
  function statusText(s) { return s.status.replace("-", " "); }
  function playVideo(v) { var p = v.play(); if (p && p.catch) p.catch(function () {}); }

  /* ---------- events + banner ---------- */

  function pushEvent(kind, text) {
    events.unshift({ kind: kind, text: text, at: nowLabel() });
    if (events.length > 40) events.pop();
    var box = el("monitorEvents");
    if (!box) return;
    box.className = "event-list";
    box.innerHTML = events.map(function (e) {
      return '<li class="event-item event-item--' + esc(e.kind) + '">' + esc(e.text) + "<time>" + esc(e.at) + "</time></li>";
    }).join("");
  }

  /* One line per student that currently has a camera problem, so it cannot be missed. */
  function renderBanner() {
    var host = el("camBanner");
    if (!host) {
      host = document.createElement("div");
      host.id = "camBanner";
      host.setAttribute("role", "alert");
      host.style.cssText = "display:none;margin-bottom:.75rem;padding:.6rem .85rem;border-radius:var(--radius-sm);border:1px solid var(--destructive);background:rgba(179,38,30,.1);font-size:.85rem";
      var anchor = el("monitorNote");
      anchor.parentNode.insertBefore(host, anchor);
    }
    var bad = students.filter(function (s) { return isActive(s) && camProblem(s); });
    if (!bad.length) { host.style.display = "none"; return; }
    host.style.display = "block";
    host.innerHTML = "<b>Camera problem" + (bad.length === 1 ? "" : "s") + " (" + bad.length + "):</b> " +
      bad.map(function (s) { return esc(s.name) + " &mdash; " + esc(camProblem(s)); }).join("; ");
  }

  /* ---------- camera status of one student ---------- */

  function camProblem(s) {
    if (!isActive(s)) return "";
    if (!s.online) return "student is offline";
    if (BAD_CAM[s.cam]) return BAD_CAM[s.cam].toLowerCase();
    var v = vstate[s.id];
    if (v === "stalled") return "video feed interrupted";
    if (v === "failed") return "cannot reach the camera";
    return "";
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

  /* ---------- tiles (built once, then updated in place) ---------- */

  function buildTile(s) {
    var root = document.createElement("div");
    root.className = "monitor-tile";
    root.setAttribute("role", "button");
    root.tabIndex = 0;
    root.innerHTML =
      '<div class="monitor-tile__feed">' +
      '<video autoplay playsinline muted hidden></video>' +
      '<div class="feed-overlay"><span class="feed-overlay__title"></span><span class="feed-overlay__sub"></span><button type="button" class="btn btn--gold btn--sm" hidden></button></div>' +
      '<span class="live-pill" hidden><span class="dot"></span> LIVE</span><span class="auto-pill" hidden>AUTO-SUBMITTED</span></div>' +
      '<div class="monitor-tile__body"><p class="monitor-tile__name"></p><p class="text-xs muted tile-meta"></p>' +
      '<div class="progress"><div class="progress__bar"></div></div>' +
      '<div class="monitor-tile__foot"><span class="badge"></span><span class="cam-badge"></span><span class="tile-viol" style="font-weight:700"></span></div></div>';
    var t = {
      id: s.id, root: root,
      video: root.querySelector("video"), overlay: root.querySelector(".feed-overlay"),
      title: root.querySelector(".feed-overlay__title"), sub: root.querySelector(".feed-overlay__sub"), action: root.querySelector(".feed-overlay .btn"),
      live: root.querySelector(".live-pill"), auto: root.querySelector(".auto-pill"),
      name: root.querySelector(".monitor-tile__name"), meta: root.querySelector(".tile-meta"), bar: root.querySelector(".progress__bar"),
      badge: root.querySelector(".badge"), cam: root.querySelector(".cam-badge"), viol: root.querySelector(".tile-viol")
    };
    root.addEventListener("click", function () { selectStudent(t.id, true); });
    root.addEventListener("keydown", function (e) { if (e.key === "Enter" || e.key === " ") { e.preventDefault(); selectStudent(t.id, true); } });
    t.action.addEventListener("click", function (e) {
      e.stopPropagation();
      manual[t.id] = true;
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
    t.badge.className = "badge badge--" + s.status;
    t.badge.textContent = statusText(s);
    t.cam.className = "cam-badge cam-badge--" + info.kind;
    t.cam.textContent = info.text;
    t.viol.className = "tile-viol " + (s.violations ? "danger" : "muted");
    t.viol.textContent = s.violations + "/" + threshold;
    t.auto.hidden = s.status !== "auto-submitted";
    t.root.classList.toggle("is-selected", s.id === selectedId);

    var live = vstate[s.id] === "live" && isActive(s) && s.online && !BAD_CAM[s.cam];
    t.video.hidden = !live;
    t.live.hidden = !live;

    // The overlay covers the video whenever there is no healthy picture to show.
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
    t.overlay.hidden = live;
    t.overlay.classList.toggle("is-bad", bad);
    t.title.textContent = title;
    t.sub.textContent = sub;
    t.action.hidden = !btn;
    t.action.textContent = btn;
  }

  function visibleStudents() {
    var term = el("monitorSearch") ? el("monitorSearch").value.trim().toLowerCase() : "";
    var status = el("monitorStatus") ? el("monitorStatus").value : "all";
    return students.filter(function (s) {
      var ok = !term || s.name.toLowerCase().indexOf(term) !== -1 || s.studentNo.toLowerCase().indexOf(term) !== -1 ||
        String(s.section || "").toLowerCase().indexOf(term) !== -1;
      return ok && (status === "all" || s.status === status);
    });
  }

  function syncGrid() {
    var grid = el("monitorGrid");
    if (!grid) return;
    var rows = visibleStudents();
    var shown = {};
    rows.forEach(function (s) { shown[s.id] = true; });

    // tiles of students who are gone: close their video and remove the tile
    Object.keys(tiles).forEach(function (id) {
      if (!students.some(function (s) { return String(s.id) === id; })) {
        viewer.disconnect(Number(id));
        tiles[id].root.remove();
        delete tiles[id]; delete vstate[id]; delete manual[id];
      }
    });

    var empty = grid.querySelector(".monitor-empty");
    if (!rows.length) {
      if (!empty) { empty = document.createElement("p"); empty.className = "muted text-sm monitor-empty"; grid.appendChild(empty); }
      empty.textContent = students.length ? "No examinee matches this search." : "No students have started this exam yet.";
    } else if (empty) { empty.remove(); }

    // update in place; only move a tile when its position really changed (moving a <video> can make it blink)
    var cursor = grid.firstElementChild;
    rows.forEach(function (s) {
      var t = tiles[s.id] || buildTile(s);
      updateTile(t, s);
      t.root.hidden = false;
      if (t.root === cursor) cursor = cursor.nextElementSibling;
      else grid.insertBefore(t.root, cursor);
    });
    students.forEach(function (s) { if (!shown[s.id] && tiles[s.id]) tiles[s.id].root.hidden = true; });
  }

  /* ---------- which students get a live video ---------- */

  function reconcileStreams() {
    if (!viewer.supported()) return;
    var wanted = [];
    if (!pausedHidden) {
      var vis = {};
      visibleStudents().forEach(function (s) { vis[s.id] = true; });
      students.forEach(function (s) {
        if (!isActive(s) || !s.online || BAD_CAM[s.cam] || !vis[s.id]) return;
        wanted.push(s);
      });
    }
    var want = {}, auto = 0;
    wanted.forEach(function (s) {
      if (manual[s.id]) { want[s.id] = true; return; }
      if (auto < MAX_AUTO_STREAMS) { want[s.id] = true; auto++; }
    });
    Object.keys(tiles).forEach(function (id) {
      id = Number(id);
      if (!want[id] && viewer.has(id)) viewer.disconnect(id);
    });
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
    if (selectedId === subId) { renderModal(); renderModalFeed(); }
    renderBanner();
  }

  function onViewerStream(subId, stream) {
    var t = tiles[subId];
    if (t) {
      t.video.srcObject = stream || null;
      if (stream) playVideo(t.video);
    }
    if (selectedId === subId) renderModalFeed();
  }

  /* ---------- events from changes between two polls ---------- */

  function diffEvents(list) {
    var cur = {};
    list.forEach(function (s) { cur[s.id] = { status: s.status, online: s.online, cam: s.cam, name: s.name }; });
    if (prev) {
      list.forEach(function (s) {
        var p = prev[s.id];
        if (!p) { if (isActive(s)) pushEvent("ok", s.name + " joined the exam."); return; }
        var wasActive = p.status === "in-progress" || p.status === "flagged";
        if (wasActive && s.status === "submitted") pushEvent("ok", s.name + " submitted the exam.");
        else if (wasActive && s.status === "auto-submitted") pushEvent("bad", s.name + " was auto-submitted (violation limit).");
        else if (!wasActive && isActive(s)) pushEvent("ok", s.name + " is taking the exam again" + (s.attempt > 1 ? " (attempt " + s.attempt + ")." : "."));
        if (isActive(s) && wasActive) {
          if (p.online && !s.online) pushEvent("warn", s.name + " went offline.");
          else if (!p.online && s.online) pushEvent("ok", s.name + " is back online.");
          if (p.cam !== s.cam && BAD_CAM[s.cam]) pushEvent("bad", s.name + ": " + BAD_CAM[s.cam].toLowerCase() + ".");
          else if (BAD_CAM[p.cam] && s.cam === "on") pushEvent("ok", s.name + ": camera reconnected.");
        }
      });
      Object.keys(prev).forEach(function (id) {
        if (!cur[id]) pushEvent("warn", prev[id].name + " is no longer in this exam.");
      });
    }
    prev = cur;
  }

  /* ---------- selected student (side card + detail window) ---------- */

  function findStudent(id) {
    for (var i = 0; i < students.length; i++) if (students[i].id === id) return students[i];
    return null;
  }

  function renderDetail() {
    var s = findStudent(selectedId) || students[0] || null;
    if (!s) return;
    var info = camInfo(s);
    el("detailName").textContent = s.name;
    el("detailMeta").textContent = s.studentNo + " · " + (s.section || "");
    el("detailProgress").textContent = s.progress + "%";
    el("detailViolations").textContent = s.violations + "/" + threshold;
    el("detailFlag").textContent = s.lastFlag;
    el("detailCam").textContent = info.text;
    el("detailWarn").disabled = !isActive(s);
    el("detailForce").disabled = !isActive(s);
  }

  function selectStudent(id, openWindow) {
    selectedId = id;
    Object.keys(tiles).forEach(function (k) { tiles[k].root.classList.toggle("is-selected", Number(k) === id); });
    renderDetail();
    if (openWindow) openStudentModal();
  }

  function sendWarning(s) {
    var msg = window.prompt("Warning message to show the student:", "Please keep your eyes on the screen and stay on the exam page.");
    if (!msg) return;
    API.post("monitor.php", { action: "warn", submission_id: s.id, message: msg })
      .then(function () { window.alert("Warning sent. It appears on the student's screen within a few seconds."); })
      .catch(function (e) { window.alert(e.message); });
  }
  function forceSubmit(s) {
    if (!window.confirm("Force-submit " + s.name + "'s exam now? This cannot be undone.")) return;
    API.post("monitor.php", { action: "force_submit", submission_id: s.id })
      .then(function () { closeModal("studentModal"); refresh(); })
      .catch(function (e) { window.alert(e.message); });
  }

  function openStudentModal() {
    el("studentModalBody").innerHTML =
      '<h3 class="mt-6" id="mName"></h3><p class="text-xs muted" id="mMeta"></p>' +
      '<div class="modal-feed"><video id="mVideo" autoplay playsinline muted hidden></video>' +
      '<div class="feed-overlay" id="mOverlay"><span class="feed-overlay__title" id="mTitle"></span><span class="feed-overlay__sub" id="mSub"></span></div></div>' +
      '<dl class="detail-list" id="mInfo"></dl>' +
      '<div class="btn-row mt-6"><button type="button" class="btn btn--primary" id="mWarn">Send warning</button>' +
      '<button type="button" class="btn btn--danger" id="mForce">Force submit</button></div>' +
      '<div class="reopen-box mt-6" id="mRestore" hidden><p class="text-sm"><b>This exam was auto-submitted at the violation limit.</b></p>' +
      '<button type="button" class="btn btn--gold" id="mRestoreBtn">Re-enable Exam Access</button></div>';
    el("mWarn").addEventListener("click", function () { var s = findStudent(selectedId); if (s) sendWarning(s); });
    el("mForce").addEventListener("click", function () { var s = findStudent(selectedId); if (s) forceSubmit(s); });
    el("mRestoreBtn").addEventListener("click", function () { closeModal("studentModal"); openRestore(selectedId); });
    modalBuilt = true;
    openModal("studentModal");
    renderModal();
    renderModalFeed();
  }

  function renderModal() {
    var s = findStudent(selectedId);
    if (!modalBuilt || !s || el("studentModal").hidden) return;
    var info = camInfo(s);
    el("mName").textContent = s.name;
    el("mMeta").textContent = s.studentNo + " · " + (s.section || "") + " · " + s.exam;
    el("mInfo").innerHTML =
      '<div><dt>Status</dt><dd class="capitalize">' + esc(statusText(s)) + "</dd></div>" +
      "<div><dt>Camera</dt><dd>" + esc(info.text) + "</dd></div>" +
      "<div><dt>Progress</dt><dd>" + esc(s.progress) + "%</dd></div>" +
      "<div><dt>Violations</dt><dd>" + esc(s.violations) + "/" + esc(threshold) + (s.totalViolations > s.violations ? ' <span class="muted">(' + esc(s.totalViolations) + " in all attempts)</span>" : "") + "</dd></div>" +
      "<div><dt>Connection</dt><dd>" + esc(s.status === "submitted" || s.status === "auto-submitted" ? "Finished" : !s.online ? "Offline" : "Online" + (s.pingMs !== null ? " · " + s.pingMs + " ms" : "")) + "</dd></div>" +
      "<div><dt>Last flag</dt><dd>" + esc(s.lastFlag) + "</dd></div>" +
      "<div><dt>Attempt</dt><dd>" + esc(s.attempt) + (s.reopened ? ' <span class="muted">(access re-enabled ' + esc(s.reopened) + "×)</span>" : "") + "</dd></div>" +
      (s.autoReason ? "<div><dt>Auto-submitted because</dt><dd>" + esc(s.autoReason) + "</dd></div>" : "");
    el("mWarn").disabled = !isActive(s);
    el("mForce").disabled = !isActive(s);
    el("mRestore").hidden = !restoreList.some(function (r) { return r.id === s.id; });
  }

  function renderModalFeed() {
    if (!modalBuilt || el("studentModal").hidden) return;
    var s = findStudent(selectedId);
    if (!s) return;
    var video = el("mVideo"), overlay = el("mOverlay");
    var stream = viewer.streamOf(s.id);
    var live = !!(vstate[s.id] === "live" && isActive(s) && s.online && !BAD_CAM[s.cam] && stream);
    if (stream && video.srcObject !== stream) { video.srcObject = stream; playVideo(video); }
    video.hidden = !live;
    overlay.hidden = live;
    var t = tiles[s.id];
    el("mTitle").textContent = t ? t.title.textContent : "";
    el("mSub").textContent = t ? t.sub.textContent : "";
    overlay.classList.toggle("is-bad", camInfo(s).kind === "bad");
  }

  /* ---------- auto-submitted section: re-enable access ---------- */

  function fmtLeft(sec) { var m = Math.floor(sec / 60); return m >= 1 ? "about " + m + " min" : sec + " s"; }

  function renderRestore(list, hist) {
    var json = JSON.stringify(list);
    if (json !== lastRestoreJson) {
      lastRestoreJson = json;
      el("restoreBody").innerHTML = list.length ? list.map(function (r) {
        return '<tr><td><p style="font-weight:600">' + esc(r.name) + '</p><p class="text-xs muted">' + esc(r.studentNo) + " · " + esc(r.section || "") + "</p></td>" +
          '<td class="danger" style="font-weight:700">' + esc(r.violations) + "/" + esc(r.threshold) + "</td>" +
          '<td class="text-sm">' + esc(r.reason) + "</td>" +
          '<td><span class="badge badge--auto-submitted">auto submitted</span><p class="text-xs muted">Exam: ' + esc(r.examStatus) + "</p></td>" +
          "<td>" + esc(r.attempt) + (r.restoredBefore ? ' <span class="muted text-xs">(re-enabled ' + esc(r.restoredBefore) + "×)</span>" : "") + "</td>" +
          "<td>" + (r.canRestore
            ? '<button type="button" class="btn btn--gold btn--sm" data-restore="' + esc(r.id) + '">Re-enable Exam Access</button>'
            : '<span class="text-xs muted">Set the exam to Available to re-enable</span>') + "</td></tr>";
      }).join("") : '<tr><td colspan="6" class="muted text-sm">No student has been auto-submitted for reaching the violation limit.</td></tr>';
      var btns = el("restoreBody").querySelectorAll("[data-restore]");
      for (var i = 0; i < btns.length; i++) (function (b) { b.addEventListener("click", function () { openRestore(Number(b.getAttribute("data-restore"))); }); })(btns[i]);
    }
    var hj = JSON.stringify(hist);
    if (hj !== lastHistJson) {
      lastHistJson = hj;
      el("restoreHistoryCount").textContent = hist.length ? "(" + hist.length + ")" : "";
      el("restoreHistory").innerHTML = hist.length ? hist.map(function (h) {
        return '<tr><td class="text-sm">' + esc(h.at) + "</td><td>" + esc(h.student) + '<p class="text-xs muted">' + esc(h.studentNo) + "</p></td>" +
          '<td class="text-sm">' + (h.mode === "resume" ? "Resumed attempt " + esc(h.fromAttempt) : "New attempt " + esc(h.toAttempt)) + "</td>" +
          '<td class="text-sm">' + esc(h.professor) + "</td><td>" + esc(h.violations) + "/" + esc(h.threshold) + '</td><td class="text-sm muted">' + esc(h.note || "—") + "</td></tr>";
      }).join("") : '<tr><td colspan="6" class="muted text-sm">No access has been re-enabled for this exam.</td></tr>';
    }
  }

  function openRestore(id) {
    var r = restoreList.filter(function (x) { return x.id === id; })[0];
    if (!r) return;
    restoreTarget = r;
    el("restoreIntro").innerHTML = "Allow <b>" + esc(r.name) + "</b> (" + esc(r.studentNo) + ") back into the exam? They were auto-submitted with <b>" + esc(r.violations) + "/" + esc(r.threshold) +
      "</b> violations &mdash; " + esc(r.reason) + ".";
    var resume = document.querySelector('input[name="restoreMode"][value="resume"]');
    var fresh = document.querySelector('input[name="restoreMode"][value="new_attempt"]');
    resume.disabled = !r.canResume;
    resume.checked = r.canResume; fresh.checked = !r.canResume;
    el("restoreResumeNote").textContent = r.canResume
      ? "Their answers come back and the clock continues with " + fmtLeft(r.resumeSecondsLeft) + " left."
      : "Not possible: less than a minute of the exam time was left.";
    el("restoreNote").value = "";
    el("restoreError").hidden = true;
    el("restoreConfirm").disabled = false;
    openModal("restoreModal");
  }

  function confirmRestore() {
    if (!restoreTarget) return;
    var target = restoreTarget;
    var mode = document.querySelector('input[name="restoreMode"]:checked').value;
    var btn = el("restoreConfirm");
    btn.disabled = true;
    API.post("monitor.php", { action: "restore", submission_id: target.id, mode: mode, confirm: true, note: el("restoreNote").value.trim() })
      .then(function () {
        pushEvent("ok", "You re-enabled access for " + target.name + (mode === "resume" ? " (resume)." : " (new attempt)."));
        closeModal("restoreModal");
        restoreTarget = null;
        return refresh();
      })
      .catch(function (e) {
        btn.disabled = false;
        el("restoreError").hidden = false;
        el("restoreError").textContent = e.message;
      });
  }

  /* ---------- alerts + polling ---------- */

  function renderAlerts(alerts) {
    var feed = el("monitorAlerts");
    if (!feed) return;
    feed.innerHTML = alerts.length ? alerts.map(function (a) {
      return '<li class="alert-item alert-item--' + esc(a.severity) + '"><p class="alert-item__name">' + esc(a.student) + "</p>" +
        '<p class="alert-item__meta">' + esc(a.type) + '</p><p class="alert-item__time">' + esc(a.time) + "</p></li>";
    }).join("") : '<li class="muted text-sm">No alerts yet.</li>';
  }

  function refresh() {
    return API.get("monitor.php" + (examId ? "?exam_id=" + encodeURIComponent(examId) : "")).then(function (r) {
      var newId = r.exam ? String(r.exam.id) : "";
      if (examMeta && newId !== String(examMeta.id)) {       // another exam: start from a clean grid
        viewer.disconnectAll();
        Object.keys(tiles).forEach(function (k) { tiles[k].root.remove(); });
        tiles = {}; vstate = {}; manual = {}; prev = null; selectedId = null; events = [];
        el("monitorEvents").innerHTML = '<li class="muted text-sm">Nothing yet.</li>';
      }
      examMeta = r.exam;
      students = r.students;
      restoreList = r.restorable || [];
      if (r.exam) {
        threshold = Number(r.exam.violation_threshold);
        var sel = el("monitorExam");
        if (sel && sel.value !== newId) sel.value = newId;
        el("monitorSubtitle").textContent = r.exam.code + " " + r.exam.title + " · " + r.exam.status + " · auto-submit at " + threshold + " violations";
        el("monitorNote").textContent = viewer.supported()
          ? "Live camera video goes directly from each student's browser to yours (encrypted) while they take the exam. Up to " + MAX_AUTO_STREAMS + " videos open automatically; use \"Load camera\" for any others."
          : "This browser cannot show live video (WebRTC is missing).";
      } else {
        el("monitorSubtitle").textContent = "No examination selected.";
      }
      if (selectedId === null && students.length) selectedId = students[0].id;
      diffEvents(students);
      reconcileStreams();
      syncGrid();
      renderBanner();
      renderDetail();
      renderAlerts(r.alerts);
      renderRestore(restoreList, r.restorations || []);
      renderModal();
      renderModalFeed();
    }).catch(function () {});
  }

  document.addEventListener("DOMContentLoaded", function () {
    if (!el("monitorGrid")) return;
    viewer = LiveCam.viewer({ onState: onViewerState, onStream: onViewerStream });
    el("monitorSearch").addEventListener("input", function () { syncGrid(); reconcileStreams(); });
    el("monitorStatus").addEventListener("change", function () { syncGrid(); reconcileStreams(); });
    el("detailWarn").addEventListener("click", function () { var s = findStudent(selectedId); if (s) sendWarning(s); });
    el("detailForce").addEventListener("click", function () { var s = findStudent(selectedId); if (s) forceSubmit(s); });
    el("restoreConfirm").addEventListener("click", confirmRestore);

    var examSel = el("monitorExam");
    examSel.addEventListener("change", function () { examId = examSel.value; refresh(); });

    // Videos only run while this tab is in front: a tab left in the background for a while closes them to spare the students' connections.
    document.addEventListener("visibilitychange", function () {
      if (document.hidden) {
        hiddenTimer = window.setTimeout(function () { pausedHidden = true; reconcileStreams(); syncGrid(); }, HIDDEN_PAUSE_MS);
      } else {
        if (hiddenTimer) window.clearTimeout(hiddenTimer);
        if (pausedHidden) { pausedHidden = false; refresh(); }
      }
    });

    Session.ready.then(function () {
      API.get("exam.php?action=list").then(function (r) {
        examSel.innerHTML = r.exams.length ? r.exams.slice().reverse().map(function (e) {
          return '<option value="' + esc(e.id) + '">' + esc(e.code) + " · " + esc(e.title) + " (" + esc(e.status) + ")</option>";
        }).join("") : '<option value="">No examinations</option>';
        if (examId) examSel.value = examId;
      }).catch(function () {});
      refresh();
      window.setInterval(refresh, 3000);
    });
  });
})();
