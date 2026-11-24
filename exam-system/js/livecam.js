/* =========================================================
   Live webcam over WebRTC.

   The student's browser sends its camera to the professor's browser directly (peer-to-peer, encrypted with DTLS-SRTP).
   The server only relays small signalling messages (api/signal.php) and checks who is allowed to talk to whom:
   a professor can only watch students who are taking one of THEIR OWN exams.

   Student side : LiveCam.student({ examId, getStream, onViewers })  - answers requests, sends a small low-bitrate video.
   Professor side: LiveCam.viewer({ onState, onStream })             - asks for one stream per student tile, reconnects when it drops.
   ========================================================= */

var LiveCam = (function () {
  "use strict";

  var SEND_BITRATE = 150000;     // bits/s per student  (320x240 @ 12 fps looks fine at this rate)
  var SEND_FPS = 12;
  var SEND_WIDTH = 320;
  var CONNECT_TIMEOUT = 14000;   // ms to wait for the first video before retrying
  var MAX_TRIES = 6;             // automatic reconnect attempts before the tile shows "Retry"
  var STALL_CHECKS = 2;          // stats checks (3 s apart) without a new frame -> "stalled"

  function noop() {}

  function rid() {
    var bytes = new Uint8Array(12), s = "", i;
    if (window.crypto && window.crypto.getRandomValues) window.crypto.getRandomValues(bytes);
    else for (i = 0; i < bytes.length; i++) bytes[i] = Math.floor(Math.random() * 256);
    for (i = 0; i < bytes.length; i++) s += (bytes[i] < 16 ? "0" : "") + bytes[i].toString(16);
    return s;   // 24 hex characters, accepted by api/signal.php
  }

  var iceCache = null;
  function iceServers() {
    if (iceCache) return Promise.resolve(iceCache);
    return API.get("signal.php?action=ice").then(function (r) {
      iceCache = r.iceServers || [];
      return iceCache;
    }).catch(function () { return []; });   // no STUN/TURN info: same-network connections still work
  }

  function supported() {
    return typeof window.RTCPeerConnection === "function";
  }

  function desc(d) { return { type: d.type, sdp: d.sdp }; }

  /* Batches outgoing signalling messages into one request every ~120 ms. */
  function Outbox(url, extra) {
    var queue = [], timer = null;
    function flush() {
      timer = null;
      if (!queue.length) return;
      var batch = queue.splice(0, 40);
      var body = { messages: batch };
      for (var k in extra) body[k] = extra[k];
      API.post(url, body).catch(noop);
      if (queue.length) timer = window.setTimeout(flush, 120);
    }
    return {
      push: function (m) { queue.push(m); if (!timer) timer = window.setTimeout(flush, 120); },
      flushNow: flush,
      drain: function () { return queue.splice(0); }
    };
  }

  /* ============================== student (sender) ============================== */

  function student(opts) {
    var examId = opts.examId;
    var conns = {};            // connId -> { pc, viewerId, pending, remoteSet, sender }
    var after = 0, timer = null, running = false;
    var out = Outbox("signal.php?action=send", { exam_id: examId });
    var MAX_CONNS = 3;

    function count() {
      var n = 0;
      for (var id in conns) if (conns[id].pc.connectionState === "connected" || conns[id].pc.iceConnectionState === "connected" || conns[id].pc.iceConnectionState === "completed") n++;
      return n;
    }
    function report() { if (opts.onViewers) opts.onViewers(count()); }

    function tune(sender, track) {
      try {
        var p = sender.getParameters();
        if (!p.encodings || !p.encodings.length) p.encodings = [{}];
        var width = (track && track.getSettings && track.getSettings().width) || 640;
        p.encodings[0].maxBitrate = SEND_BITRATE;
        p.encodings[0].maxFramerate = SEND_FPS;
        p.encodings[0].scaleResolutionDownBy = Math.max(1, width / SEND_WIDTH);
        sender.setParameters(p).catch(noop);
      } catch (e) { /* older browsers: they simply send at their default quality */ }
    }

    function close(connId) {
      var c = conns[connId];
      if (!c) return;
      delete conns[connId];
      try { c.pc.close(); } catch (e) {}
      report();
    }

    function onOffer(m) {
      if (conns[m.conn_id]) return;
      var stream = opts.getStream();
      if (!stream || !stream.getVideoTracks().length) return;       // no working camera: nothing to send
      // a professor reloading the page leaves an old connection behind - drop older ones of the same viewer, keep at most MAX_CONNS
      var ids = Object.keys(conns);
      ids.forEach(function (id) { if (conns[id].viewerId === m.viewer_id) close(id); });
      ids = Object.keys(conns);
      while (ids.length >= MAX_CONNS) close(ids.shift());

      iceServers().then(function (servers) {
        var pc = new RTCPeerConnection({ iceServers: servers });
        var c = { pc: pc, viewerId: m.viewer_id, pending: [], remoteSet: false, sender: null };
        conns[m.conn_id] = c;
        var track = stream.getVideoTracks()[0];
        c.sender = pc.addTrack(track, stream);
        pc.onicecandidate = function (e) {
          if (e.candidate) out.push({ viewer_id: c.viewerId, conn_id: m.conn_id, kind: "ice", payload: e.candidate.toJSON ? e.candidate.toJSON() : e.candidate });
        };
        pc.onconnectionstatechange = function () {
          var s = pc.connectionState;
          if (s === "connected") tune(c.sender, track);
          if (s === "failed" || s === "closed") close(m.conn_id);
          report();
        };
        pc.oniceconnectionstatechange = function () {
          if (pc.iceConnectionState === "failed") close(m.conn_id);
          else report();
        };
        pc.setRemoteDescription(m.payload).then(function () {
          c.remoteSet = true;
          return pc.createAnswer();
        }).then(function (answer) {
          return pc.setLocalDescription(answer);
        }).then(function () {
          tune(c.sender, track);
          out.push({ viewer_id: c.viewerId, conn_id: m.conn_id, kind: "answer", payload: desc(pc.localDescription) });
          c.pending.splice(0).forEach(function (cand) { pc.addIceCandidate(cand).catch(noop); });
        }).catch(function () { close(m.conn_id); });
      });
    }

    function handle(m) {
      if (m.kind === "offer") return onOffer(m);
      var c = conns[m.conn_id];
      if (!c) return;
      if (m.kind === "ice" && m.payload) {
        if (c.remoteSet) c.pc.addIceCandidate(m.payload).catch(noop); else c.pending.push(m.payload);
      } else if (m.kind === "bye") {
        close(m.conn_id);
      }
    }

    function tick() {
      if (!running) return;
      API.get("signal.php?action=pull&exam_id=" + encodeURIComponent(examId) + "&after=" + after).then(function (r) {
        after = r.last || after;
        (r.messages || []).forEach(handle);
      }).catch(function (e) {
        if (e && e.status === 409) running = false;      // the attempt ended
      }).then(function () {
        if (!running) return;
        var negotiating = Object.keys(conns).some(function (id) { return conns[id].pc.connectionState !== "connected"; });
        timer = window.setTimeout(tick, negotiating ? 1000 : 2000);
      });
    }

    return {
      start: function () {
        if (running || !supported()) return;
        running = true;
        tick();
      },
      /* The camera was restarted (or replaced): keep every open connection and just swap the video track. */
      setStream: function (stream) {
        var track = stream && stream.getVideoTracks()[0];
        if (!track) return;
        Object.keys(conns).forEach(function (id) {
          var c = conns[id];
          if (c.sender) c.sender.replaceTrack(track).then(function () { tune(c.sender, track); }).catch(noop);
        });
      },
      viewers: count,
      stop: function () {
        running = false;
        if (timer) window.clearTimeout(timer);
        Object.keys(conns).forEach(close);
      }
    };
  }

  /* ============================== professor (viewer) ============================== */

  /*
    onState(subId, state, detail)  state: "connecting" | "live" | "reconnecting" | "stalled" | "failed" | "closed"
    onStream(subId, stream|null)
  */
  function viewer(opts) {
    var peers = {};            // submissionId -> peer
    var after = 0, timer = null, running = false;
    var out = Outbox("signal.php?action=send", {});
    var statsTimer = null;

    function setState(peer, state, detail) {
      if (peer.state === state) return;
      peer.state = state;
      if (opts.onState) opts.onState(peer.subId, state, detail || "");
    }

    function stop(peer, sendBye) {
      if (peer.timeout) { window.clearTimeout(peer.timeout); peer.timeout = null; }
      if (peer.grace) { window.clearTimeout(peer.grace); peer.grace = null; }
      if (peer.retryTimer) { window.clearTimeout(peer.retryTimer); peer.retryTimer = null; }
      if (peer.pc) {
        if (sendBye && peer.connId) out.push({ submission_id: peer.subId, conn_id: peer.connId, kind: "bye" });
        try { peer.pc.close(); } catch (e) {}
        peer.pc = null;
      }
    }

    function retry(peer, why) {
      if (peers[peer.subId] !== peer || peer.closed) return;
      stop(peer, true);
      peer.tries++;
      if (peer.tries > MAX_TRIES) { setState(peer, "failed", why); return; }
      setState(peer, "reconnecting", why);
      var delay = Math.min(10000, 800 * Math.pow(2, peer.tries - 1));
      peer.retryTimer = window.setTimeout(function () { peer.retryTimer = null; open(peer); }, delay);
    }

    function evaluate(peer) {
      if (!peer.pc || peer.closed) return;
      var connected = peer.pc.connectionState === "connected" || peer.pc.iceConnectionState === "connected" || peer.pc.iceConnectionState === "completed";
      if (!connected) return;
      if (peer.flowing) { peer.tries = 0; setState(peer, "live"); }
      else if (peer.stalled) setState(peer, "stalled");
    }

    function open(peer) {
      if (peers[peer.subId] !== peer || peer.closed) return;
      stop(peer, false);
      peer.connId = rid();
      peer.pending = []; peer.remoteSet = false; peer.flowing = false; peer.stalled = false; peer.lastFrames = -1; peer.stallCount = 0;
      peer.openedAt = Date.now();
      if (peer.tries === 0) setState(peer, "connecting");
      var connId = peer.connId;

      iceServers().then(function (servers) {
        if (peers[peer.subId] !== peer || peer.closed || peer.connId !== connId) return;
        var pc = new RTCPeerConnection({ iceServers: servers });
        peer.pc = pc;
        pc.addTransceiver("video", { direction: "recvonly" });
        pc.ontrack = function (e) {
          var stream = (e.streams && e.streams[0]) || new MediaStream([e.track]);
          peer.stream = stream;
          if (opts.onStream) opts.onStream(peer.subId, stream);
        };
        pc.onicecandidate = function (e) {
          if (e.candidate) out.push({ submission_id: peer.subId, conn_id: connId, kind: "ice", payload: e.candidate.toJSON ? e.candidate.toJSON() : e.candidate });
        };
        function onConn() {
          if (peer.connId !== connId || !peer.pc) return;
          var s = pc.connectionState, ice = pc.iceConnectionState;
          if (s === "failed" || ice === "failed") { retry(peer, "The connection to the student failed."); return; }
          if (s === "disconnected" || ice === "disconnected") {
            setState(peer, "reconnecting", "The connection was interrupted.");
            if (!peer.grace) peer.grace = window.setTimeout(function () { peer.grace = null; if (pc.connectionState !== "connected") retry(peer, "The connection was interrupted."); }, 6000);
            return;
          }
          if (peer.grace && (s === "connected" || ice === "connected")) { window.clearTimeout(peer.grace); peer.grace = null; }
          evaluate(peer);
        }
        pc.onconnectionstatechange = onConn;
        pc.oniceconnectionstatechange = onConn;

        pc.createOffer().then(function (offer) {
          return pc.setLocalDescription(offer);
        }).then(function () {
          out.push({ submission_id: peer.subId, conn_id: connId, kind: "offer", payload: desc(pc.localDescription) });
          peer.timeout = window.setTimeout(function () {
            if (peer.connId === connId && !peer.flowing) retry(peer, "The student's camera did not answer.");
          }, CONNECT_TIMEOUT);
          kick();
        }).catch(function () { retry(peer, "Could not start the connection."); });
      });
    }

    function handle(m) {
      var peer = peers[m.submission_id];
      if (!peer || peer.closed || peer.connId !== m.conn_id || !peer.pc) return;
      var pc = peer.pc;
      if (m.kind === "answer" && m.payload) {
        pc.setRemoteDescription(m.payload).then(function () {
          peer.remoteSet = true;
          peer.pending.splice(0).forEach(function (c) { pc.addIceCandidate(c).catch(noop); });
        }).catch(function () { retry(peer, "The student's answer could not be used."); });
      } else if (m.kind === "ice" && m.payload) {
        if (peer.remoteSet) pc.addIceCandidate(m.payload).catch(noop); else peer.pending.push(m.payload);
      } else if (m.kind === "bye") {
        retry(peer, "The student's browser closed the stream.");
      }
    }

    function anyBusy() {
      return Object.keys(peers).some(function (id) { return peers[id].state !== "live" && peers[id].state !== "failed"; });
    }

    function tick() {
      timer = null;
      if (!running) return;
      var ids = Object.keys(peers);
      if (!ids.length) { return; }          // nothing to listen for; connect() restarts the loop
      API.get("signal.php?action=pull&after=" + after).then(function (r) {
        after = r.last || after;
        (r.messages || []).forEach(handle);
      }).catch(noop).then(function () {
        if (running && Object.keys(peers).length) timer = window.setTimeout(tick, anyBusy() ? 900 : 2500);
      });
    }
    function kick() {
      if (!running || timer) return;
      timer = window.setTimeout(tick, 250);
    }

    /* Is video actually arriving? (frames decoded must keep growing - a connected peer with a dead camera shows nothing.) */
    function checkStats() {
      Object.keys(peers).forEach(function (id) {
        var peer = peers[id];
        if (!peer.pc || peer.closed || peer.pc.connectionState !== "connected") return;
        peer.pc.getStats(null).then(function (report) {
          var frames = null;
          report.forEach(function (r) { if (r.type === "inbound-rtp" && (r.kind === "video" || r.mediaType === "video")) frames = r.framesDecoded; });
          if (frames === null || !peer.pc) return;
          if (frames > peer.lastFrames && frames > 0) {
            peer.flowing = true; peer.stalled = false; peer.stallCount = 0;
            if (peer.timeout) { window.clearTimeout(peer.timeout); peer.timeout = null; }
          } else if (peer.flowing) {
            peer.stallCount++;
            if (peer.stallCount >= STALL_CHECKS) { peer.flowing = false; peer.stalled = true; peer.stalledSince = peer.stalledSince || Date.now(); }
          }
          if (frames > peer.lastFrames) peer.stalledSince = 0;
          peer.lastFrames = frames;
          evaluate(peer);
          if (peer.stalled && peer.stalledSince && Date.now() - peer.stalledSince > 30000) { peer.stalledSince = 0; retry(peer, "No video for 30 seconds."); }
        }).catch(noop);
      });
    }

    function startLoops() {
      if (running) return;
      running = true;
      statsTimer = window.setInterval(checkStats, 3000);
    }

    window.addEventListener("pagehide", function () {
      var byes = [];
      Object.keys(peers).forEach(function (id) { if (peers[id].connId) byes.push({ submission_id: peers[id].subId, conn_id: peers[id].connId, kind: "bye" }); });
      if (byes.length && navigator.sendBeacon) {
        try { navigator.sendBeacon("api/signal.php?action=send", new Blob([JSON.stringify({ messages: byes })], { type: "application/json" })); } catch (e) {}
      }
    });

    return {
      supported: supported,
      connect: function (subId) {
        if (!supported()) return false;
        var peer = peers[subId];
        if (peer && !peer.closed) return true;
        peer = peers[subId] = { subId: subId, tries: 0, state: "idle", closed: false, stream: null };
        startLoops();
        open(peer);
        kick();
        return true;
      },
      /* manual "Retry" on a tile that gave up */
      retry: function (subId) {
        var peer = peers[subId];
        if (!peer) return this.connect(subId);
        peer.tries = 0; stop(peer, true); setState(peer, "connecting"); open(peer); kick();
      },
      disconnect: function (subId) {
        var peer = peers[subId];
        if (!peer) return;
        peer.closed = true;
        stop(peer, true);
        delete peers[subId];
        if (opts.onStream) opts.onStream(subId, null);
        if (opts.onState) opts.onState(subId, "closed", "");
        out.flushNow();
      },
      disconnectAll: function () {
        var self = this;
        Object.keys(peers).forEach(function (id) { self.disconnect(Number(id)); });
      },
      has: function (subId) { return !!peers[subId]; },
      stateOf: function (subId) { return peers[subId] ? peers[subId].state : "closed"; },
      streamOf: function (subId) { return peers[subId] ? peers[subId].stream : null; },
      count: function () { return Object.keys(peers).length; }
    };
  }

  return { student: student, viewer: viewer, supported: supported };
})();
