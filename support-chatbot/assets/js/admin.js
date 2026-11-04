/**
 * Dashboard tester: sends a sample message to the admin-only REST route and
 * shows what the bot would do and which FAQs came closest. Everything the
 * server returns is inserted with textContent.
 */
(function () {
  "use strict";

  var cfg = window.DVCB_ADMIN;
  var form = document.getElementById("dvcb-test-form");
  var input = document.getElementById("dvcb-test-input");
  var out = document.getElementById("dvcb-test-result");
  if (!cfg || !form || !input || !out) return;

  var T = cfg.i18n || {};

  function node(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = text;
    return n;
  }

  function render(data) {
    out.innerHTML = "";

    var kind = data.type === "answer" ? "answer" : data.type === "suggest" ? "suggest" : data.type === "fallback" ? "fallback" : "";
    out.className = "dvcb-test-result" + (kind ? " is-" + kind : "");

    var verdict = T[data.type === "answer" ? "answered" : data.type] || data.type;
    out.appendChild(node("p", "dvcb-test-verdict", verdict));

    out.appendChild(node("div", "dvcb-test-head", T.reply));
    out.appendChild(node("p", "dvcb-test-reply", data.reply));

    out.appendChild(node("div", "dvcb-test-head", T.matches));
    if (!data.top || !data.top.length) {
      out.appendChild(node("p", "dvcb-muted", T.none));
    } else {
      var table = node("table", "dvcb-test-matches");
      data.top.forEach(function (row) {
        var tr = document.createElement("tr");
        tr.appendChild(node("td", "", row.q));
        var cell = node("td");
        var bar = node("span", "dvcb-bar");
        var fill = document.createElement("span");
        fill.style.width = Math.max(0, Math.min(100, row.score)) + "%";
        bar.appendChild(fill);
        cell.appendChild(bar);
        cell.appendChild(document.createTextNode(row.score + "%"));
        tr.appendChild(cell);
        table.appendChild(tr);
      });
      out.appendChild(table);
    }

    if (data.tokens && data.tokens.length) {
      out.appendChild(node("div", "dvcb-test-head", T.words));
      var tok = node("div", "dvcb-tokens");
      data.tokens.forEach(function (t) {
        tok.appendChild(node("code", "", t));
      });
      out.appendChild(tok);
    }

    var note = (T.thresholds || "").replace("%1$s", data.confident).replace("%2$s", data.suggest);
    out.appendChild(node("p", "dvcb-test-note", note));
  }

  form.addEventListener("submit", function (e) {
    e.preventDefault();
    var text = input.value.trim();
    if (!text) return;

    fetch(cfg.rest + "admin/test", {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/json", "X-WP-Nonce": cfg.nonce },
      body: JSON.stringify({ message: text }),
    })
      .then(function (r) {
        return r.json().then(function (j) {
          if (!r.ok) throw new Error((j && j.message) || T.error);
          return j;
        });
      })
      .then(render)
      .catch(function (err) {
        out.className = "dvcb-test-result is-fallback";
        out.textContent = err && err.message ? err.message : T.error;
      });
  });
})();
