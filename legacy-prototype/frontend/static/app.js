// app.js -- shared across all pages. Vanilla JS, no build step, no CDN dependency
// (deliberate: this sandbox's own network egress to CDNs returned 403 during
// development, so nothing here risks failing to load for the same reason
// wherever this zip is eventually opened).

// Implemented on XMLHttpRequest rather than fetch(): this file is executed
// both by real browsers (which all support fetch fine) and, during
// development, by a WebKit-based headless renderer used to screenshot-verify
// these pages, which has no Fetch API at all. XHR-wrapped-in-a-Promise works
// identically in both, so this is the safer common denominator, not a
// downgrade for real users.
function xhrRequest(method, path, body) {
  return new Promise(function (resolve, reject) {
    var xhr = new XMLHttpRequest();
    xhr.open(method, path, true);
    xhr.setRequestHeader("Content-Type", "application/json");
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) return;
      if (xhr.status >= 200 && xhr.status < 300) {
        try { resolve(xhr.responseText ? JSON.parse(xhr.responseText) : null); }
        catch (e) { reject(e); }
      } else {
        reject(new Error(method + " " + path + " failed: " + xhr.status));
      }
    };
    xhr.onerror = function () { reject(new Error(method + " " + path + " network error")); };
    xhr.send(body ? JSON.stringify(body) : null);
  });
}

const API = {
  get: function (path) { return xhrRequest("GET", path); },
  post: function (path, body) { return xhrRequest("POST", path, body || {}); },
};

function money(n, opts = {}) {
  if (n === null || n === undefined) return "--";
  const sign = n < 0 ? "-" : (opts.forceSign ? "+" : "");
  const fixed = Math.abs(n).toFixed(2);
  const parts = fixed.split(".");
  // Manual thousands-separator: some older JS engines (confirmed during
  // testing against a WebKit-based headless renderer) silently ignore the
  // options object passed to toLocaleString and return the bare number.
  // This regex-based grouping has no such dependency and is verified to
  // render identically everywhere.
  parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ",");
  return sign + "$" + parts.join(".");
}

function pct(n, opts = {}) {
  if (n === null || n === undefined) return "--";
  const sign = n > 0 ? "+" : "";
  return sign + n.toFixed(2) + "%";
}

function timeAgo(ts) {
  const diff = Date.now() / 1000 - ts;
  if (diff < 60) return "just now";
  if (diff < 3600) return Math.floor(diff / 60) + "m ago";
  if (diff < 86400) return Math.floor(diff / 3600) + "h ago";
  return Math.floor(diff / 86400) + "d ago";
}

function fmtClock(ts) {
  return new Date(ts * 1000).toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
}

const NAV_ITEMS = [
  { href: "index.html", label: "Dashboard", icon: "\u25A6" },
  { href: "agents.html", label: "AI Agents", icon: "\u2699" },
  { href: "positions.html", label: "Positions", icon: "\u25A4" },
  { href: "performance.html", label: "Performance", icon: "\u2197" },
];

function renderShell(activeHref) {
  const nav = NAV_ITEMS.map(
    (item) => `<a class="nav-item ${item.href === activeHref ? "active" : ""}" href="${item.href}">
        <span class="ic">${item.icon}</span><span>${item.label}</span>
      </a>`
  ).join("");

  document.getElementById("sidebar-mount").innerHTML = `
    <div class="logo">
      <div class="mark">A</div>
      <div><div class="name">AURA</div><div class="sub">AI TRADING AGENT</div></div>
    </div>
    <div class="nav-section">${nav}</div>
    <div class="sidebar-footer">
      <div class="agent-status-mini">
        <div class="label">Agent status</div>
        <div class="value" id="mini-agent-status"><span class="dot"></span> Loading...</div>
        <button class="btn-mini danger" id="kill-switch-btn">Pause all agents</button>
      </div>
      <div class="powered-by">Powered by <b>Mock Alpaca Adapter</b><br/>Paper Trading &middot; Demo Data</div>
    </div>
  `;

  document.getElementById("topbar-mount").innerHTML = `
    <div class="badge-paper"><span class="dot"></span> Paper Trading &middot; Demo Data</div>
    <div class="user">
      <div style="text-align:right;">
        <div style="font-weight:600;">Demo Trader</div>
        <div style="color:var(--text-muted); font-size:11.5px;">Pro Trader (Simulated)</div>
      </div>
      <div class="avatar">DT</div>
    </div>
  `;

  document.getElementById("disclaimer-mount").innerHTML = `
    Paper trading only. Educational demonstration &mdash; not investment advice. All prices and P&amp;L on this page are simulated.
  `;

  document.getElementById("kill-switch-btn").addEventListener("click", function () {
    API.get("/api/account").then(function (acc) {
      return API.post("/api/kill-switch", { killed: !acc.killed });
    }).then(function (result) {
      return refreshAgentMini().then(function () { return result; });
    }).then(function (result) {
      if (window.onKillSwitchToggled) window.onKillSwitchToggled(result.killed);
    }).catch(function (e) { console.error(e); });
  });

  refreshAgentMini();
}

function refreshAgentMini() {
  return API.get("/api/account").then(function (acc) {
    const el = document.getElementById("mini-agent-status");
    const btn = document.getElementById("kill-switch-btn");
    if (acc.killed) {
      el.innerHTML = `<span class="dot paused"></span> Paused`;
      btn.textContent = "Resume all agents";
    } else {
      el.innerHTML = `<span class="dot"></span> All systems active`;
      btn.textContent = "Pause all agents";
    }
  }).catch(function (e) {
    console.error("Failed to refresh agent status", e);
  });
}
