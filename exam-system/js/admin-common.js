/* Shared by the administrator's exam pages: filter bar (department, year level, professor, exam, status) and small helpers.
   Everything here is read-only: the admin pages only ever call GET endpoints. */

var Admin = (function () {
  var STATUS_LABEL = { draft: "Draft", scheduled: "Scheduled", available: "Ongoing", closed: "Closed" };
  var STATE_LABEL = { completed: "Completed", "auto-submitted": "Auto-submitted", ongoing: "Ongoing", pending: "Pending", absent: "Did not take it", "not-open": "Not open yet" };

  function qs(obj) {
    var parts = [];
    Object.keys(obj).forEach(function (k) { if (obj[k] !== "" && obj[k] !== undefined && obj[k] !== null) parts.push(encodeURIComponent(k) + "=" + encodeURIComponent(obj[k])); });
    return parts.length ? "?" + parts.join("&") : "";
  }

  function opt(value, label, selected) { return '<option value="' + esc(value) + '"' + (selected ? " selected" : "") + ">" + esc(label) + "</option>"; }

  /*
    Admin.filters({ onChange })  - wires whichever of fDept, fYear, fProf, fExam, fStatus, fState, fSearch exist on the page.
    Returns { query(): object, string(): "?a=b", ready: Promise }.
  */
  function filters(cfg) {
    var ids = { department_id: "fDept", year_level: "fYear", professor_id: "fProf", exam_id: "fExam", status: "fStatus", student_status: "fState", q: "fSearch" };
    var opts = null, timer = null;
    function node(k) { return el(ids[k]); }
    function val(k) { return node(k) ? node(k).value : ""; }

    function fill(k, html) { var n = node(k); if (!n) return; var cur = n.value; n.innerHTML = html; if (cur && n.querySelector('option[value="' + cur.replace(/"/g, "") + '"]')) n.value = cur; }

    function build() {
      if (!opts) return;
      var dept = val("department_id"), prof = val("professor_id");
      fill("department_id", opt("", "All departments") + opts.departments.map(function (d) { return opt(d.id, d.code + " · " + d.name); }).join(""));
      fill("year_level", opt("", "All year levels") + opts.year_levels.map(function (y) { return opt(y, y); }).join(""));
      fill("professor_id", opt("", "All professors") + opts.professors.filter(function (p) { return !dept || String(p.department_id) === dept; })
        .map(function (p) { return opt(p.id, p.name); }).join(""));
      prof = val("professor_id");
      fill("exam_id", opt("", "All examinations") + opts.exams.filter(function (e) { return (!dept || String(e.department_id) === dept) && (!prof || String(e.professor_id) === prof); })
        .map(function (e) { return opt(e.id, e.code + " · " + e.title); }).join(""));
      fill("status", opt("", "All statuses") + opts.statuses.map(function (s) { return opt(s, STATUS_LABEL[s]); }).join(""));
      fill("student_status", opt("", "Students who started") + opts.student_states.map(function (s) { return opt(s, STATE_LABEL[s]); }).join(""));
    }

    function query() {
      var o = {};
      Object.keys(ids).forEach(function (k) { var v = val(k); if (v !== "") o[k] = v; });
      return o;
    }
    function change(cascade) {
      if (cascade) build();
      if (timer) window.clearTimeout(timer);
      timer = window.setTimeout(function () { cfg.onChange(); }, 200);
    }

    var ready = API.get("admin_exams.php?action=options").then(function (o) {
      opts = o;
      build();
      var pre = new URLSearchParams(window.location.search);
      Object.keys(ids).forEach(function (k) { if (pre.get(k) && node(k)) { node(k).value = pre.get(k); } });
      Object.keys(ids).forEach(function (k) {
        var n = node(k);
        if (!n) return;
        n.addEventListener(n.tagName === "INPUT" ? "input" : "change", function () { change(k === "department_id" || k === "professor_id"); });
      });
      var reset = el("fReset");
      if (reset) reset.addEventListener("click", function () {
        Object.keys(ids).forEach(function (k) { if (node(k)) node(k).value = ""; });
        build(); cfg.onChange();
      });
      return o;
    });
    return { query: query, string: function () { return qs(query()); }, ready: ready };
  }

  function statusBadge(s) { return '<span class="badge badge--' + esc(s) + '">' + esc(STATUS_LABEL[s] || s) + "</span>"; }
  function stateBadge(s) { return '<span class="badge badge--' + esc(s) + '">' + esc(STATE_LABEL[s] || s) + "</span>"; }
  function yearTags(list) {
    if (!list || !list.length) return '<span class="muted text-xs">—</span>';
    return '<div class="tag-list">' + list.map(function (y) { return '<span class="tag">' + esc(y.year) + "</span>"; }).join("") + "</div>";
  }
  function stat(label, value, cls, sub) {
    return '<div class="stat"><p class="stat__label">' + esc(label) + '</p><p class="stat__value' + (cls ? " " + cls : "") + '">' + esc(value) + "</p>" + (sub ? '<p class="stat__sub">' + esc(sub) + "</p>" : "") + "</div>";
  }
  function pct(v) { return v === null || v === undefined ? "—" : v + "%"; }
  function when(s) { return s ? String(s).replace("T", " ").slice(0, 16) : "—"; }
  function participation(e) {
    if (!e.assigned) return '<span class="muted text-xs">no students</span>';
    var p = Math.round(e.started / e.assigned * 100);
    return '<div class="progress" style="width:7rem"><div class="progress__bar" style="width:' + p + '%"></div></div><span class="text-xs muted">' + esc(e.started) + "/" + esc(e.assigned) + " started</span>";
  }
  function poll(fn, ms) { fn(); window.setInterval(fn, ms); }
  function fail(node, e) { node.innerHTML = '<tr><td colspan="12" class="danger">' + esc(e.message) + "</td></tr>"; }

  return { filters: filters, statusBadge: statusBadge, stateBadge: stateBadge, yearTags: yearTags, stat: stat, pct: pct, when: when, participation: participation, poll: poll, fail: fail, qs: qs, STATUS_LABEL: STATUS_LABEL, STATE_LABEL: STATE_LABEL };
})();
