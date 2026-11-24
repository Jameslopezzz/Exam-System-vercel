/* =========================================================
   Notification bell (all roles). Loaded by Session.decorate() on every page that has the account menu.
   Polls api/notifications.php every 15 s (and when the tab becomes visible again). The server only ever returns the
   signed-in person's own notifications.
   ========================================================= */
(function () {
  "use strict";
  var POLL_MS = 15000;
  var box = document.querySelector(".user-box, .user-menu");
  if (!box || document.getElementById("bellWrap")) return;

  var wrap = document.createElement("div");
  wrap.className = "bell";
  wrap.id = "bellWrap";
  wrap.innerHTML =
    '<button type="button" class="bell__btn" id="bellBtn" aria-haspopup="true" aria-expanded="false" aria-controls="bellPanel" aria-label="Notifications">' +
      '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
      '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>' +
      '<span class="bell__badge" id="bellBadge" hidden>0</span></button>' +
    '<div class="bell__panel" id="bellPanel" role="dialog" aria-label="Notifications" hidden>' +
      '<div class="bell__head"><strong>Notifications</strong><button type="button" class="bell__all" id="bellAll">Mark all as read</button></div>' +
      '<ul class="bell__list" id="bellList"><li class="bell__empty">Loading…</li></ul></div>';
  var group = document.createElement("div");      // bell + account menu stay together on the right of the header
  group.className = "header-tools";
  box.parentNode.insertBefore(group, box);
  group.appendChild(wrap);
  group.appendChild(box);

  var btn = document.getElementById("bellBtn"), panel = document.getElementById("bellPanel"),
      badge = document.getElementById("bellBadge"), list = document.getElementById("bellList"),
      allBtn = document.getElementById("bellAll");
  var items = [], unread = 0, lastLatest = null, busy = false;

  function setOpen(open) {
    panel.hidden = !open;
    btn.setAttribute("aria-expanded", open ? "true" : "false");
    if (open) load();
  }

  function render() {
    badge.hidden = unread <= 0;
    badge.textContent = unread > 99 ? "99+" : String(unread);
    btn.setAttribute("aria-label", "Notifications" + (unread ? ", " + unread + " unread" : ""));
    allBtn.disabled = unread === 0;
    if (!items.length) { list.innerHTML = '<li class="bell__empty">You have no notifications yet.</li>'; return; }
    list.innerHTML = items.map(function (n) {
      var inner =
        '<span class="bell__dot" aria-hidden="true"></span><span class="bell__text"><strong>' + esc(n.title) + "</strong>" +
        "<span>" + esc(n.message) + "</span><time>" + esc(n.date) + " · " + esc(n.time) + "</time></span>";
      return '<li class="bell__item bell__item--' + esc(n.type) + (n.is_read ? "" : " is-unread") + '">' +
        '<button type="button" class="bell__open" data-id="' + n.id + '"' + (n.link ? ' data-link="' + esc(n.link) + '"' : "") + ">" + inner + "</button>" +
        (n.is_read ? "" : '<button type="button" class="bell__mark" data-read="' + n.id + '" title="Mark as read" aria-label="Mark as read">&#10003;</button>') + "</li>";
    }).join("");
  }

  function load() {
    if (busy) return Promise.resolve();
    busy = true;
    return API.get("notifications.php?action=list").then(function (r) {
      items = r.notifications; unread = r.unread;
      lastLatest = r.latest_id;
      render();
    }).catch(function () {}).then(function () { busy = false; });
  }

  function markRead(id) {
    return API.post("notifications.php?action=read", { id: id }).then(function () {
      items.forEach(function (n) { if (n.id === id && !n.is_read) { n.is_read = true; unread = Math.max(0, unread - 1); } });
      render();
    }).catch(function () {});
  }

  btn.addEventListener("click", function (e) { e.stopPropagation(); setOpen(panel.hidden); });
  document.addEventListener("click", function (e) { if (!wrap.contains(e.target)) setOpen(false); });
  document.addEventListener("keydown", function (e) { if (e.key === "Escape" && !panel.hidden) { setOpen(false); btn.focus(); } });
  allBtn.addEventListener("click", function () {
    API.post("notifications.php?action=read_all").then(function () {
      items.forEach(function (n) { n.is_read = true; }); unread = 0; render();
    }).catch(function () {});
  });
  list.addEventListener("click", function (e) {
    var m = e.target.closest("[data-read]");
    if (m) { markRead(Number(m.getAttribute("data-read"))); return; }
    var o = e.target.closest(".bell__open");
    if (!o) return;
    var id = Number(o.getAttribute("data-id")), link = o.getAttribute("data-link");
    markRead(id).then(function () {
      if (!link) return;
      var here = window.location.pathname.split("/").pop();
      window.location.href = link;
      if (link.split("#")[0].split("?")[0] === here) window.location.reload();
    });
  });

  load();
  window.setInterval(load, POLL_MS);
  document.addEventListener("visibilitychange", function () { if (!document.hidden) load(); });
})();
