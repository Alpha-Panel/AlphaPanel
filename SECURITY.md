# Security Policy

AlphaPanel is a multi-tenant web hosting control panel. Because it manages
production domains, credentials, DNS, and container infrastructure, we take
security reports seriously and appreciate responsible disclosure.

## Supported Versions

Security fixes are provided for the latest stable release. We recommend always
running the most recent version.

| Version | Supported          |
| ------- | ------------------ |
| 1.1.x   | :white_check_mark: |
| < 1.1   | :x:                |

## Reporting a Vulnerability

**Please do not report security vulnerabilities through public GitHub issues,
pull requests, or discussions.**

Report privately through one of the following channels:

1. **GitHub Security Advisories** (preferred) — use the
   [**Report a vulnerability**](https://github.com/Alpha-Panel/AlphaPanel/security/advisories/new)
   button on the repository's Security tab. This keeps the report private and
   lets us collaborate on a fix and coordinated disclosure.
2. **Email** — send details to **muhammed@niyazialpay.com**. Use the subject
   line `[SECURITY] AlphaPanel: <short summary>`.

### What to include

A good report helps us reproduce and fix the issue quickly. Please include:

- A clear description of the vulnerability and its impact.
- The affected component, version, and configuration (e.g. panel version from
  `version.json`, relevant service versions).
- Step-by-step reproduction instructions or a proof-of-concept.
- Any relevant logs, payloads, or screenshots.
- Your assessment of severity (e.g. CVSS vector) if you have one.

Please **do not** include real customer data, live credentials, or perform
testing against systems you do not own or operate.

## Our Commitment

When you report a vulnerability, we aim to:

| Stage                        | Target                          |
| ---------------------------- | ------------------------------- |
| Acknowledge receipt          | within **48 hours**             |
| Initial assessment / triage  | within **5 business days**      |
| Status updates               | at least every **7 days**       |
| Fix for critical issues      | as soon as practical, prioritized over other work |

After a fix is released, we will publish a security advisory crediting the
reporter (unless you prefer to remain anonymous) and document the affected
versions and remediation.

## Scope

In scope:

- The Laravel control panel (`alpha-panel/`).
- Docker Compose stack configuration and the custom Dockerfiles in this
  repository.
- Custom artisan commands and services that generate/apply web server configs
  (`panel:apply`, `DomainConfigService`, `ApplyChangesService`,
  `DockerControlService`, `CloudflareService`, etc.).
- The installer (`install.sh` / `installer/`).
- Inter-tenant isolation, privilege escalation, and host-from-container
  escape paths.

Out of scope:

- Vulnerabilities in third-party upstream images (MySQL, Redis, Meilisearch,
  N8N, Jenkins, Portainer, Vaultwarden, etc.) — report those to their
  respective projects. We will, however, update pinned versions when an
  upstream fix is available.
- Issues that require physical access to the host or an already-compromised
  privileged account.
- Denial of service from unrealistic traffic volumes against a single tenant.
- Findings from automated scanners without a demonstrated, exploitable impact.

## Dependency Security

- Dependencies are monitored via GitHub Dependabot.
- Transitive dependencies that cannot be bumped automatically (e.g. pinned by a
  parent package) are forced to a patched version using npm `overrides` in
  `alpha-panel/web/httpdocs/package.json` or Composer constraints as
  appropriate.
- Run `npm audit` and `composer audit` before each release; both should report
  zero known vulnerabilities.

## Disclosure Policy

We follow coordinated disclosure. We ask that you give us a reasonable window
to release a fix before any public disclosure. We will work with you on the
timeline and credit your contribution in the published advisory.

Thank you for helping keep AlphaPanel and the sites it hosts safe.
