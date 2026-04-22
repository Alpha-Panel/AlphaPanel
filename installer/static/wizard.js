const steps = ["welcome", "system", "domains", "creds", "admin", "summary", "progress", "done"];
const form = {};
let currentIdx = 0;

function show(idx) {
  steps.forEach((s, i) => {
    document.getElementById(`step-${s}`).hidden = i !== idx;
  });
  currentIdx = idx;
}

function next() { show(currentIdx + 1); }
function prev() { show(currentIdx - 1); }

async function init() {
  const r = await fetch("/api/state");
  const { state } = await r.json();
  if (state && state.current_phase && state.current_phase !== "done") {
    const banner = document.getElementById("resume-banner");
    banner.hidden = false;
    if (state.last_error) {
      document.getElementById("resume-error").textContent =
        `Failed at ${state.last_error.phase}: ${state.last_error.message}`;
    }
    if (state.form) {
      Object.assign(form, state.form);
    }
  }

  const sys = await (await fetch("/api/detect", { method: "POST" })).json();
  const dl = document.getElementById("system-info");
  dl.innerHTML = `
    <dt>OS</dt><dd>${sys.os.pretty}</dd>
    <dt>Private IP</dt><dd>${sys.private_ip}</dd>
    <dt>Public IP</dt><dd>${sys.public_ip}</dd>`;
  form.private_ip = sys.private_ip;
  form.public_ip = sys.public_ip;

  bindNav();
  bindDomainsAutofill();
  bindStart();
  bindReset();
}

function bindNav() {
  document.querySelectorAll(".next").forEach(btn => {
    btn.addEventListener("click", async () => {
      const formId = btn.dataset.form;
      if (formId) {
        const el = document.getElementById(formId);
        if (!el.reportValidity()) return;
        for (const input of el.querySelectorAll("input")) {
          form[input.name] = input.value;
        }
        if (formId === "form-creds") {
          const resp = await fetch("/api/verify-cf-token", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ token: form.cf_api_token }),
          });
          if (!resp.ok) {
            const err = await resp.json();
            alert(`Cloudflare token invalid: ${err.message}`);
            return;
          }
        }
      }
      if (steps[currentIdx + 1] === "summary") renderSummary();
      next();
    });
  });
  document.querySelectorAll(".prev").forEach(btn => btn.addEventListener("click", prev));
}

function bindDomainsAutofill() {
  const base = document.querySelector("input[name='base_domain']");
  base.addEventListener("blur", () => {
    if (!base.value) return;
    const mapping = {
      panel_domain: `server.${base.value}`,
      pma_domain: `pma.${base.value}`,
      code_server_domain: `file.${base.value}`,
      vaultwarden_domain: `password.${base.value}`,
      n8n_domain: `n8n.${base.value}`,
      portainer_domain: `portainer.${base.value}`,
      jenkins_domain: `jenkins.${base.value}`,
    };
    for (const [name, value] of Object.entries(mapping)) {
      const input = document.querySelector(`input[name='${name}']`);
      if (input && !input.value) input.value = value;
    }
  });
}

function renderSummary() {
  const hidden = ["cf_api_token", "portainer_admin_password", "panel_admin_password"];
  const rows = Object.entries(form)
    .filter(([k]) => !hidden.includes(k))
    .map(([k, v]) => `<tr><td>${k}</td><td>${v}</td></tr>`)
    .join("");
  document.getElementById("summary-body").innerHTML = `<table>${rows}</table>`;
}

function bindStart() {
  document.getElementById("btn-start").addEventListener("click", async () => {
    show(steps.indexOf("progress"));
    await fetch("/api/submit", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(form),
    });
    streamProgress();
  });
}

function streamProgress() {
  const log = document.getElementById("log");
  const phaseLabel = document.getElementById("current-phase");
  const errorPanel = document.getElementById("progress-error");
  const es = new EventSource("/api/progress");
  es.onmessage = (e) => {
    const msg = JSON.parse(e.data);
    if (msg.type === "line") {
      log.textContent += msg.text + "\n";
      log.scrollTop = log.scrollHeight;
    } else if (msg.type === "phase") {
      phaseLabel.textContent = `> ${msg.name}`;
    } else if (msg.type === "error") {
      errorPanel.hidden = false;
      errorPanel.innerHTML = `<p>Failed at <strong>${msg.phase}</strong>: ${msg.message}</p>
        <button onclick="location.reload()">Reload and resume</button>`;
      es.close();
    } else if (msg.type === "done") {
      es.close();
      renderDone(msg.panel_url);
      show(steps.indexOf("done"));
      if (msg.panel_url) {
        window.open(msg.panel_url, "_blank");
        fetch("/api/shutdown", { method: "POST" });
      }
    }
  };
}

function renderDone(panelUrl) {
  const ul = document.getElementById("service-urls");
  const subs = {
    Panel: panelUrl,
    phpMyAdmin: `https://${form.pma_domain}:8443`,
    "File manager": `https://${form.code_server_domain}:8443`,
    Portainer: `https://${form.portainer_domain}:8443`,
    N8N: `https://${form.n8n_domain}:8443`,
    Passwords: `https://${form.vaultwarden_domain}:8443`,
  };
  ul.innerHTML = Object.entries(subs)
    .map(([k, v]) => `<li>${k}: <a href="${v}">${v}</a></li>`)
    .join("");
}

function bindReset() {
  document.getElementById("btn-reset").addEventListener("click", async () => {
    if (!confirm("This will run `docker compose down -v` and delete all .env files. Continue?")) return;
    await fetch("/api/reset", { method: "POST" });
    location.reload();
  });
  document.getElementById("btn-resume").addEventListener("click", () => {
    show(steps.indexOf("progress"));
    fetch("/api/submit", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(form),
    });
    streamProgress();
  });
}

document.addEventListener("DOMContentLoaded", init);
