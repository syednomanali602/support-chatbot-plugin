/**
 * Support Chatbot — front-end widget.
 *
 * Vanilla JS, no dependencies. The server renders only an empty #dvcb-root;
 * everything else is built here from window.DVCB_CONFIG, so if this file
 * never loads the page is simply unchanged.
 *
 * Message text from the visitor always goes in via textContent. The only
 * innerHTML is bot answers, which the server has already passed through
 * wp_kses with a small allow-list.
 */
(function () {
  "use strict";

  var C = window.DVCB_CONFIG;
  var root = document.getElementById("dvcb-root");
  if (!C || !root || !window.fetch) return;

  var L = C.l10n || {};
  var reduceMotion =
    window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  var STORE_KEY = "dvcb_state_v1";
  var MAX_MSGS = 40;
  var HANDOFF_HASH = "#dvcb-handoff";

  /* ------------------------------------------------------------------ */
  /* State (kept in sessionStorage so the chat survives page navigation)  */
  /* ------------------------------------------------------------------ */

  function loadState() {
    try {
      var raw = window.sessionStorage.getItem(STORE_KEY);
      var s = raw ? JSON.parse(raw) : null;
      if (s && Array.isArray(s.msgs)) {
        // A form that was open when the page changed can't be resumed
        // meaningfully; the visitor can reopen it from the offer button.
        s.msgs = s.msgs.filter(function (m) {
          return !(m.kind === "form");
        });
        return s;
      }
    } catch (e) {}
    return { open: false, msgs: [], nextId: 1, lastQ: "" };
  }

  var state = loadState();
  var isOpen = false;
  var busy = false;

  function save() {
    try {
      window.sessionStorage.setItem(STORE_KEY, JSON.stringify(state));
    } catch (e) {}
  }

  /* ------------------------------------------------------------------ */
  /* Tiny DOM helpers                                                    */
  /* ------------------------------------------------------------------ */

  function el(tag, attrs, kids) {
    var n = document.createElement(tag);
    if (attrs) {
      Object.keys(attrs).forEach(function (k) {
        if (k === "class") n.className = attrs[k];
        else if (k === "text") n.textContent = attrs[k];
        else n.setAttribute(k, attrs[k]);
      });
    }
    (kids || []).forEach(function (kid) {
      if (kid) n.appendChild(kid);
    });
    return n;
  }

  var ICON = {
    chat: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/></svg>',
    close: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18"/></svg>',
    bot: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="5" y="8" width="14" height="11" rx="3"/><path d="M12 8V5M9.5 13h.01M14.5 13h.01"/></svg>',
    send: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>',
    up: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M7 10v11H3V10h4zm0 0l4-8a3 3 0 0 1 3 3v3h5.5a2 2 0 0 1 2 2.3l-1.3 7A2 2 0 0 1 18.2 21H7"/></svg>',
    down: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M17 14V3h4v11h-4zm0 0l-4 8a3 3 0 0 1-3-3v-3H4.5a2 2 0 0 1-2-2.3l1.3-7A2 2 0 0 1 5.8 3H17"/></svg>',
  };

  function iconBtn(cls, label, svg) {
    var b = el("button", { type: "button", class: cls, "aria-label": label });
    b.innerHTML = svg;
    return b;
  }

  function htmlToText(html) {
    var d = document.createElement("div");
    d.innerHTML = html;
    return (d.textContent || "").replace(/\s+/g, " ").trim();
  }

  function timeLabel(ts) {
    try {
      return new Date(ts).toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
    } catch (e) {
      return "";
    }
  }

  function validContact(v) {
    v = (v || "").trim();
    if (!v) return false;
    if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) return true;
    var digits = v.replace(/\D/g, "");
    return digits.length >= 7 && digits.length <= 15;
  }

  /* ------------------------------------------------------------------ */
  /* API                                                                 */
  /* ------------------------------------------------------------------ */

  function api(path, body) {
    return fetch(C.rest + path, {
      method: "POST",
      headers: { "Content-Type": "application/json", Accept: "application/json" },
      body: JSON.stringify(body),
    }).then(
      function (r) {
        return r.json().then(
          function (json) {
            if (!r.ok) {
              var e = new Error((json && json.message) || L.error);
              e.status = r.status;
              throw e;
            }
            return json;
          },
          function () {
            // Body wasn't JSON (a caching layer or firewall answered).
            throw new Error(L.error);
          },
        );
      },
      function () {
        var e = new Error(L.offline);
        e.offline = true;
        throw e;
      },
    );
  }

  /* ------------------------------------------------------------------ */
  /* Build the shell                                                     */
  /* ------------------------------------------------------------------ */

  var launcher, panel, body, input, sendBtn;

  function resolveTheme() {
    if (C.mode === "light" || C.mode === "dark") return C.mode;
    // "Follow site": sites with a light/dark toggle usually add a "light" class to <html>.
    return document.documentElement.classList.contains("light") ? "light" : "dark";
  }

  function build() {
    root.setAttribute("data-theme", resolveTheme());
    if (C.mode === "auto" && window.MutationObserver) {
      new MutationObserver(function () {
        root.setAttribute("data-theme", resolveTheme());
      }).observe(document.documentElement, { attributes: true, attributeFilter: ["class"] });
    }

    launcher = el("button", {
      type: "button",
      class: "dvcb-launcher",
      "aria-label": L.open,
      "aria-expanded": "false",
      "aria-controls": "dvcb-panel",
    });
    launcher.innerHTML =
      '<span class="dvcb-ico dvcb-ico--chat">' + ICON.chat + "</span>" +
      '<span class="dvcb-ico dvcb-ico--close">' + ICON.close + "</span>";

    var closeBtn = iconBtn("dvcb-close", L.close, ICON.close);

    var avatar = el("div", { class: "dvcb-avatar" });
    avatar.innerHTML = ICON.bot;

    var head = el("div", { class: "dvcb-head" }, [
      avatar,
      el("div", { class: "dvcb-heading" }, [
        el("div", { class: "dvcb-title", text: C.name }),
        el("div", { class: "dvcb-sub" }, [
          el("span", { class: "dvcb-dot", "aria-hidden": "true" }),
          el("span", { text: C.subtitle }),
        ]),
      ]),
      closeBtn,
    ]);

    // data-lenis-prevent: themes that use Lenis smooth scroll would
    // otherwise capture wheel/touch input over this panel and scroll the
    // page behind it instead of the message list.
    body = el("div", {
      class: "dvcb-body",
      role: "log",
      "aria-live": "polite",
      "aria-label": L.chatLog,
      tabindex: "0",
      "data-lenis-prevent": "",
    });

    input = el("input", {
      type: "text",
      class: "dvcb-input",
      maxlength: "500",
      autocomplete: "off",
      placeholder: C.placeholder,
      "aria-label": C.placeholder,
    });
    sendBtn = iconBtn("dvcb-send", L.send, ICON.send);
    sendBtn.type = "submit";

    var composer = el("form", { class: "dvcb-composer" }, [input, sendBtn]);

    panel = el(
      "div",
      {
        id: "dvcb-panel",
        class: "dvcb-panel",
        role: "dialog",
        "aria-label": C.name,
        "data-lenis-prevent": "",
        hidden: "",
      },
      [head, body, composer],
    );

    root.appendChild(panel);
    root.appendChild(launcher);

    launcher.addEventListener("click", function () {
      setOpen(!isOpen);
    });
    closeBtn.addEventListener("click", function () {
      setOpen(false);
    });
    composer.addEventListener("submit", function (e) {
      e.preventDefault();
      var text = input.value.trim();
      if (!text || busy) return;
      input.value = "";
      userSays(text);
    });
    panel.addEventListener("keydown", function (e) {
      if (e.key === "Escape") {
        e.stopPropagation();
        setOpen(false);
      }
    });
    body.addEventListener("click", onBodyClick);
  }

  /* ------------------------------------------------------------------ */
  /* Open / close                                                        */
  /* ------------------------------------------------------------------ */

  // On a phone, focusing the input the moment the chat opens raises the
  // keyboard over the starter questions, which are the point of the opening
  // screen. Only desktop-style pointers get automatic focus.
  function canAutofocus() {
    return !(window.matchMedia && window.matchMedia("(pointer: coarse)").matches);
  }

  function setOpen(open, focusInput) {
    if (open === isOpen) return;
    isOpen = open;
    state.open = open;
    save();

    launcher.setAttribute("aria-expanded", String(open));
    launcher.setAttribute("aria-label", open ? L.close : L.open);
    root.classList.toggle("is-open", open);

    if (open) {
      panel.hidden = false;
      ensureStarted();
      window.requestAnimationFrame(function () {
        panel.classList.add("is-open");
        scrollBottom(true);
      });
      if (focusInput !== false && canAutofocus()) {
        window.setTimeout(function () {
          input.focus();
        }, reduceMotion ? 0 : 80);
      }
    } else {
      panel.classList.remove("is-open");
      var hide = function () {
        if (!isOpen) panel.hidden = true;
      };
      if (reduceMotion) hide();
      else window.setTimeout(hide, 220);
      if (panel.contains(document.activeElement)) launcher.focus();
    }
  }

  function ensureStarted() {
    if (state.msgs.length) return;
    addMsg({ role: "bot", kind: "text", text: C.greeting });
    if (C.chips && C.chips.length) {
      addMsg({ role: "bot", kind: "chips", chips: C.chips, starter: true });
    }
  }

  /* ------------------------------------------------------------------ */
  /* Messages                                                            */
  /* ------------------------------------------------------------------ */

  function scrollBottom(instant) {
    window.requestAnimationFrame(function () {
      var top = body.scrollHeight;
      if (!instant && !reduceMotion && body.scrollTo) {
        body.scrollTo({ top: top, behavior: "smooth" });
      } else {
        body.scrollTop = top;
      }
    });
  }

  function addMsg(m) {
    m.id = state.nextId++;
    m.ts = m.ts || Date.now();
    state.msgs.push(m);
    if (state.msgs.length > MAX_MSGS) state.msgs = state.msgs.slice(-MAX_MSGS);
    save();
    var node = renderMessage(m);
    if (node) body.appendChild(node);
    scrollBottom();
    return node;
  }

  function removeWhere(test) {
    state.msgs = state.msgs.filter(function (m) {
      if (test(m)) {
        var n = body.querySelector('[data-mid="' + m.id + '"]');
        if (n && n.parentNode) n.parentNode.removeChild(n);
        return false;
      }
      return true;
    });
    save();
  }

  // Starter questions, suggestions and the "talk to a person" offer are
  // one-shot choices; once the visitor does anything else they go away.
  function clearChoices() {
    removeWhere(function (m) {
      return m.kind === "chips";
    });
  }

  // Only the latest answer asks "was this helpful?".
  function retireFeedback() {
    state.msgs.forEach(function (m) {
      if (m.kind === "answer" && !m.fb) m.fb = "skip";
    });
    var rows = body.querySelectorAll(".dvcb-fb");
    Array.prototype.forEach.call(rows, function (r) {
      r.parentNode.removeChild(r);
    });
    save();
  }

  function decorateLinks(scope) {
    var links = scope.querySelectorAll("a[href]");
    Array.prototype.forEach.call(links, function (a) {
      var href = a.getAttribute("href") || "";
      if (href.charAt(0) === "#") return;
      try {
        var u = new URL(a.href, window.location.href);
        if (u.host !== window.location.host) {
          a.setAttribute("target", "_blank");
          a.setAttribute("rel", "noopener noreferrer");
        }
      } catch (e) {}
    });
  }

  function bubble(m) {
    var b = el("div", { class: "dvcb-bubble" });
    if (m.html) {
      b.innerHTML = m.html;
      decorateLinks(b);
    } else {
      b.textContent = m.text || "";
    }
    return b;
  }

  function actionNode(action) {
    if (!action || !action.url || !action.label) return null;
    if (action.url === HANDOFF_HASH) {
      var btn = el("button", { type: "button", class: "dvcb-action", text: action.label });
      btn.addEventListener("click", function () {
        showForm();
      });
      return btn;
    }
    var a = el("a", { class: "dvcb-action", href: action.url, text: action.label });
    decorateLinks(el("div", null, [a]));
    return a;
  }

  function feedbackNode(m) {
    var row = el("div", { class: "dvcb-fb" });
    row.appendChild(el("span", { class: "dvcb-fb-q", text: L.helpful }));
    var yes = iconBtn("dvcb-fb-btn", L.yes, ICON.up);
    var no = iconBtn("dvcb-fb-btn", L.no, ICON.down);
    row.appendChild(yes);
    row.appendChild(no);

    function answer(helpful) {
      m.fb = helpful ? "up" : "down";
      save();
      api("feedback", { faq_id: m.faq_id, helpful: helpful }).catch(function () {});
      row.innerHTML = "";
      row.appendChild(el("span", { class: "dvcb-fb-q", text: L.thanksFb }));
      if (!helpful) {
        botSay(L.sorry);
        addMsg({ role: "bot", kind: "chips", chips: [], offer: true });
      }
    }
    yes.addEventListener("click", function () {
      answer(true);
    });
    no.addEventListener("click", function () {
      answer(false);
    });
    return row;
  }

  function chipsNode(m) {
    var wrap = el("div", { class: "dvcb-chips", role: "group", "aria-label": L.suggestions });
    (m.chips || []).forEach(function (c) {
      var b = el("button", { type: "button", class: "dvcb-choice", text: c.q });
      b.addEventListener("click", function () {
        userSays(null, c.id, c.q);
      });
      wrap.appendChild(b);
    });
    if (m.offer) {
      var o = el("button", { type: "button", class: "dvcb-choice dvcb-choice--alt", text: C.handoffLabel });
      o.addEventListener("click", function () {
        clearChoices();
        showForm();
      });
      wrap.appendChild(o);
    }
    return wrap;
  }

  function renderMessage(m) {
    var wrap = el("div", { class: "dvcb-msg dvcb-msg--" + (m.role === "user" ? "user" : "bot"), "data-mid": String(m.id) });

    if (m.kind === "chips") {
      wrap.appendChild(chipsNode(m));
      return wrap;
    }
    if (m.kind === "form") {
      wrap.appendChild(formNode(m, wrap));
      return wrap;
    }

    wrap.appendChild(bubble(m));
    if (m.kind === "answer") {
      var act = actionNode(m.action);
      if (act) wrap.appendChild(act);
    }
    wrap.appendChild(el("div", { class: "dvcb-time", text: timeLabel(m.ts) }));

    if (m.kind === "answer" && m.faq_id && !m.fb) {
      var last = true;
      for (var i = state.msgs.length - 1; i >= 0; i--) {
        if (state.msgs[i].id > m.id && state.msgs[i].role === "user") {
          last = false;
          break;
        }
      }
      if (last) wrap.appendChild(feedbackNode(m));
    }
    return wrap;
  }

  function botSay(text) {
    return addMsg({ role: "bot", kind: "text", text: text });
  }

  function onBodyClick(e) {
    var a = e.target.closest ? e.target.closest("a[href]") : null;
    if (!a) return;
    var href = a.getAttribute("href") || "";
    if (href === HANDOFF_HASH) {
      e.preventDefault();
      showForm();
      return;
    }
    // In-page anchors (e.g. #contact-form) are handled by the
    // page or theme; the chat just steps aside so it isn't left covering the result.
    if (href.charAt(0) === "#") {
      window.setTimeout(function () {
        setOpen(false, false);
      }, 0);
    }
  }

  /* ------------------------------------------------------------------ */
  /* Conversation                                                        */
  /* ------------------------------------------------------------------ */

  function typingNode() {
    var dots = el("div", { class: "dvcb-bubble dvcb-typing", "aria-hidden": "true" }, [
      el("span"), el("span"), el("span"),
    ]);
    var n = el("div", { class: "dvcb-msg dvcb-msg--bot dvcb-msg--typing" }, [
      dots,
      el("span", { class: "dvcb-sr", text: L.typing }),
    ]);
    body.appendChild(n);
    scrollBottom();
    return n;
  }

  function setBusy(v) {
    busy = v;
    sendBtn.disabled = v;
    panel.setAttribute("aria-busy", String(v));
  }

  function userSays(text, faqId, label) {
    if (busy) return;
    clearChoices();
    retireFeedback();
    var shown = label || text;
    state.lastQ = shown;
    addMsg({ role: "user", kind: "text", text: shown });
    ask(faqId ? { faq_id: faqId } : { message: text });
  }

  function ask(payload) {
    setBusy(true);
    var typing = typingNode();
    var started = Date.now();

    // A reply that lands instantly feels like a glitch; hold it briefly.
    function pause() {
      var wait = reduceMotion ? 0 : Math.max(0, 450 - (Date.now() - started));
      return new Promise(function (resolve) {
        window.setTimeout(resolve, wait);
      });
    }

    api("ask", payload)
      .then(function (res) {
        return pause().then(function () {
          return res;
        });
      })
      .then(function (res) {
        typing.parentNode && typing.parentNode.removeChild(typing);
        handle(res);
      })
      .catch(function (err) {
        typing.parentNode && typing.parentNode.removeChild(typing);
        botSay(err && err.message ? err.message : L.error);
      })
      .then(function () {
        setBusy(false);
        if (isOpen && canAutofocus()) input.focus();
      });
  }

  function handle(res) {
    switch (res.type) {
      case "answer":
        addMsg({ role: "bot", kind: "answer", html: res.html, action: res.action || null, faq_id: res.faq_id });
        break;
      case "suggest":
        addMsg({ role: "bot", kind: "text", html: res.html });
        addMsg({ role: "bot", kind: "chips", chips: res.suggestions || [], offer: true });
        break;
      case "fallback":
        addMsg({ role: "bot", kind: "text", html: res.html });
        if (res.offer_handoff) addMsg({ role: "bot", kind: "chips", chips: [], offer: true });
        break;
      case "handoff":
        addMsg({ role: "bot", kind: "text", html: res.html });
        showForm(true);
        break;
      default:
        addMsg({ role: "bot", kind: "text", html: res.html });
    }
  }

  /* ------------------------------------------------------------------ */
  /* Talk to a person                                                    */
  /* ------------------------------------------------------------------ */

  function transcript() {
    var rows = [];
    state.msgs.forEach(function (m) {
      if (m.kind === "chips" || m.kind === "form") return;
      var t = m.html ? htmlToText(m.html) : m.text || "";
      if (t) rows.push({ r: m.role === "user" ? "u" : "b", t: t });
    });
    return rows.slice(-12);
  }

  function showForm(promptAlreadyShown) {
    var existing = body.querySelector(".dvcb-form");
    if (existing) {
      var first = existing.querySelector("input");
      if (first) first.focus();
      return;
    }
    clearChoices();
    if (!promptAlreadyShown) botSay(C.handoffPrompt);
    addMsg({ role: "bot", kind: "form" });
    var f = body.querySelector(".dvcb-form input");
    if (f) f.focus();
  }

  function formNode(m, wrap) {
    var uid = "dvcb-f" + m.id;
    var form = el("form", { class: "dvcb-form", novalidate: "" });

    function field(id, label, control) {
      var lab = el("label", { class: "dvcb-flabel", for: id, text: label });
      return el("div", { class: "dvcb-field" }, [lab, control]);
    }

    var name = el("input", { type: "text", id: uid + "-name", class: "dvcb-finput", maxlength: "100", autocomplete: "name", required: "" });
    var contact = el("input", { type: "text", id: uid + "-contact", class: "dvcb-finput", maxlength: "120", autocomplete: "email", inputmode: "email", required: "" });
    var message = el("textarea", { id: uid + "-msg", class: "dvcb-finput dvcb-ftext", rows: "3", maxlength: "1500" });
    if (state.lastQ) message.value = state.lastQ;

    // Honeypot — invisible to people, tempting to bots.
    var hp = el("input", { type: "text", name: "website", tabindex: "-1", autocomplete: "off" });
    var hpWrap = el("div", { class: "dvcb-hp", "aria-hidden": "true" }, [hp]);

    var err = el("p", { class: "dvcb-ferr", role: "alert", hidden: "" });
    var submit = el("button", { type: "submit", class: "dvcb-submit", text: L.submit });

    form.appendChild(field(uid + "-name", L.nameLabel, name));
    form.appendChild(field(uid + "-contact", L.contactLabel, contact));
    form.appendChild(field(uid + "-msg", L.messageLabel, message));
    form.appendChild(hpWrap);
    form.appendChild(err);
    form.appendChild(submit);

    function showErr(text, ctl) {
      err.textContent = text;
      err.hidden = !text;
      if (ctl) ctl.focus();
    }

    form.addEventListener("submit", function (e) {
      e.preventDefault();
      showErr("");
      if (!name.value.trim()) return showErr(L.errName, name);
      if (!validContact(contact.value)) return showErr(L.errContact, contact);

      submit.disabled = true;
      submit.textContent = L.sending;

      api("handoff", {
        name: name.value.trim(),
        contact: contact.value.trim(),
        message: message.value.trim(),
        website: hp.value,
        // No #fragment: it only records which button the visitor last
        // pressed (e.g. #contact-form) and is noise in the staff email.
        page: window.location.href.split("#")[0],
        transcript: transcript(),
      }).then(
        function (res) {
          removeWhere(function (x) {
            return x.id === m.id;
          });
          addMsg({ role: "bot", kind: "text", html: res.html });
        },
        function (e2) {
          showErr(e2 && e2.message ? e2.message : L.error);
          submit.disabled = false;
          submit.textContent = L.submit;
        },
      );
    });

    return form;
  }

  /* ------------------------------------------------------------------ */
  /* Boot                                                                */
  /* ------------------------------------------------------------------ */

  build();

  state.msgs.forEach(function (m) {
    var n = renderMessage(m);
    if (n) body.appendChild(n);
  });

  if (state.open) setOpen(true, false);

  // Anything on the page can open the chat:
  //   <a href="#dvcb-open">  or  <button data-dvcb-open>
  document.addEventListener("click", function (e) {
    var t = e.target.closest ? e.target.closest("[data-dvcb-open], a[href='#dvcb-open']") : null;
    if (!t) return;
    e.preventDefault();
    setOpen(true);
  });

  window.DVCB = {
    open: function () {
      setOpen(true);
    },
    close: function () {
      setOpen(false);
    },
    toggle: function () {
      setOpen(!isOpen);
    },
  };
})();
