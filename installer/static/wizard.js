const steps = ["welcome", "system", "domains", "creds", "admin", "mail", "summary", "progress", "done"];
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
  document.getElementById("detected-os-label").textContent = `Detected OS: ${sys.os.pretty}`;
  const privateInput = document.querySelector("input[name='private_ip']");
  const publicInput = document.querySelector("input[name='public_ip']");
  if (privateInput && !privateInput.value) privateInput.value = form.private_ip || sys.private_ip || "";
  if (publicInput && !publicInput.value) publicInput.value = form.public_ip || sys.public_ip || "";

  bindNav();
  bindDomainsAutofill();
  bindMailToggle();
  bindStart();
  bindReset();
}

function bindMailToggle() {
  const toggle = document.getElementById("mail-enabled-toggle");
  const fields = document.getElementById("mail-fields");
  const mailDomain = document.querySelector("input[name='mail_domain']");
  const mailHostname = document.querySelector("input[name='mail_hostname']");
  if (!toggle || !fields) return;

  const sync = () => {
    fields.disabled = !toggle.checked;
    if (mailDomain) mailDomain.required = toggle.checked;
    if (toggle.checked) {
      const base = form.base_domain || document.querySelector("input[name='base_domain']")?.value;
      if (base) {
        if (mailDomain && !mailDomain.value) mailDomain.value = base;
        if (mailHostname && !mailHostname.value) mailHostname.value = `mail.${base}`;
      }
    }
  };
  toggle.addEventListener("change", sync);
  sync();
}

function bindNav() {
  document.querySelectorAll(".next").forEach(btn => {
    btn.addEventListener("click", async () => {
      const formId = btn.dataset.form;
      if (formId) {
        const el = document.getElementById(formId);
        if (!el.reportValidity()) return;
        for (const input of el.querySelectorAll("input, select")) {
          if (!input.name) continue;
          if (input.type === "checkbox") {
            form[input.name] = input.checked;
          } else {
            form[input.name] = input.value;
          }
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
  const hidden = ["cf_api_token", "portainer_admin_password", "panel_admin_password", "mail_relay_password"];
  const rows = Object.entries(form)
    .filter(([k]) => !hidden.includes(k))
    .filter(([k, v]) => {
      // mail_* alanları sadece mail_enabled iken göster
      if (k.startsWith("mail_") && k !== "mail_enabled" && !form.mail_enabled) return false;
      return v !== "" && v !== undefined;
    })
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
  if (form.mail_enabled) {
    const mailHost = form.mail_hostname || (form.mail_domain ? `mail.${form.mail_domain}` : null);
    if (mailHost) {
      subs["Webmail"] = `https://${mailHost}`;
      subs["Mail admin"] = `https://${mailHost}/admin`;
    }
  }
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
