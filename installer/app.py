from __future__ import annotations

import json
import os
import threading
from dataclasses import asdict
from pathlib import Path

from flask import Flask, Response, jsonify, render_template, request, stream_with_context

from installer.errors import InstallerError
from installer.log_queue import LogQueue
from installer.secrets_gen import gen_all_panel_secrets
from installer.state import InstallerState, load_state, save_state
from installer.steps.caddy_reload import reload_caddy
from installer.steps.caddyfile import (
    write_base_domain_caddyfile,
    write_jenkins_caddyfile,
)
from installer.steps.cloudflare import verify_token, write_cloudflare_ini
from installer.steps.compose import compose_up
from installer.steps.database import (
    create_admin_user,
    run_migrations,
    seed_php_versions,
    wait_for_mysql,
)
from installer.steps.directories import ensure_data_directories
from installer.steps.env_writer import (
    set_portainer_credentials,
    write_laravel_env,
    write_root_env,
)
from installer.steps.portainer import (
    create_access_token,
    detect_endpoint_id,
    init_portainer_admin,
    wait_for_portainer,
)
from installer.steps.composer import composer_install
from installer.steps.mysql_setup import setup_mysql_users
from installer.steps.reset import reset_installation
from installer.steps.ssh_key import ensure_ssh_key
from installer.steps.ssl import issue_panel_certificate
from installer.steps.ssl_bootstrap import generate_self_signed
from installer.steps.stubs import materialize_stubs
from installer.steps.system import detect_os, detect_private_ip, detect_public_ip


def create_app(project_dir: Path, state_file: Path) -> Flask:
    app = Flask(
        __name__,
        template_folder=str(Path(__file__).parent / "templates"),
        static_folder=str(Path(__file__).parent / "static"),
    )

    log_queue_ref: dict[str, LogQueue | None] = {"q": None}
    install_thread_ref: dict[str, threading.Thread | None] = {"t": None}

    @app.route("/")
    def index():
        return render_template("wizard.html")

    @app.route("/api/state")
    def api_state():
        state = load_state(state_file)
        return jsonify({"state": asdict(state) if state else None})

    @app.post("/api/detect")
    def api_detect():
        return jsonify(
            {
                "os": detect_os(),
                "private_ip": detect_private_ip(),
                "public_ip": detect_public_ip(),
            }
        )

    @app.post("/api/verify-cf-token")
    def api_verify_cf():
        data = request.get_json(force=True)
        try:
            verify_token(data.get("token", ""))
            return jsonify({"valid": True})
        except InstallerError as e:
            return jsonify({"valid": False, "phase": e.phase, "message": e.message}), 400

    @app.post("/api/reset")
    def api_reset():
        q = LogQueue()
        log_queue_ref["q"] = q

        def run():
            try:
                reset_installation(project_dir=project_dir, state_file=state_file, log_queue=q)
            finally:
                q.close()

        threading.Thread(target=run, daemon=True).start()
        return jsonify({"started": True})

    @app.post("/api/submit")
    def api_submit():
        form = request.get_json(force=True)
        state = load_state(state_file) or InstallerState()
        state.form = form
        if not state.generated_secrets:
            state.generated_secrets = gen_all_panel_secrets()
        state.current_phase = "starting"
        state.last_error = None
        save_state(state_file, state)

        q = LogQueue()
        log_queue_ref["q"] = q
        t = threading.Thread(
            target=_run_install,
            args=(project_dir, state_file, state, q),
            daemon=True,
        )
        install_thread_ref["t"] = t
        t.start()
        return jsonify({"started": True})

    @app.route("/api/progress")
    def api_progress():
        q = log_queue_ref["q"]
        if q is None:
            return jsonify({"error": "no install running"}), 400

        @stream_with_context
        def stream():
            for item in q.stream():
                yield f"data: {json.dumps(item)}\n\n"
            yield 'data: {"type":"done"}\n\n'

        return Response(stream(), mimetype="text/event-stream")

    @app.post("/api/shutdown")
    def api_shutdown():
        def _exit():
            os._exit(0)

        threading.Timer(2.0, _exit).start()
        return jsonify({"shutdown_in": 2})

    return app


def _run_install(
    project_dir: Path,
    state_file: Path,
    state: InstallerState,
    q: LogQueue,
) -> None:
    form = state.form
    secrets = state.generated_secrets

    phases = [
        ("directories", lambda: ensure_data_directories(project_dir, form["base_domain"])),
        ("stubs", lambda: materialize_stubs(project_dir)),
        ("root_env", lambda: write_root_env(project_dir / ".env", form=form, secrets=secrets)),
        (
            "laravel_env",
            lambda: write_laravel_env(
                target=project_dir / "alpha-panel" / "web" / "httpdocs" / ".env",
                example=project_dir / "alpha-panel" / "web" / "httpdocs" / ".env.example",
                form=form,
                secrets=secrets,
                install_dir=str(project_dir),
            ),
        ),
        (
            "cloudflare_ini",
            lambda: write_cloudflare_ini(
                project_dir / "secrets" / "cloudflare.ini",
                token=form["cf_api_token"],
            ),
        ),
        (
            "caddyfiles",
            lambda: (
                write_base_domain_caddyfile(
                    project_dir
                    / "frankenphp"
                    / "sites-enabled"
                    / form["base_domain"]
                    / "Caddyfile",
                    base_domain=form["base_domain"],
                ),
                write_jenkins_caddyfile(
                    project_dir
                    / "frankenphp"
                    / "sites-enabled"
                    / form["jenkins_domain"]
                    / "Caddyfile",
                    base_domain=form["base_domain"],
                    jenkins_domain=form["jenkins_domain"],
                    admin_ips=form.get("jenkins_admin_ips", ""),
                ),
            ),
        ),
        (
            "ssh_key",
            lambda: ensure_ssh_key(
                key_dir=project_dir / "alpha-panel" / "web" / "ssh-keys",
                authorized_keys_path=Path("/root/.ssh/authorized_keys"),
                comment=f"alphapanel-terminal@{os.uname().nodename}",
            ),
        ),
        (
            "ssl_bootstrap",
            lambda: generate_self_signed(
                letsencrypt_dir=project_dir / "letsencrypt",
                base_domain=form["base_domain"],
            ),
        ),
        ("compose_up", lambda: compose_up(project_dir, q)),
        (
            "portainer_wait",
            lambda: wait_for_portainer(f"http://{form['private_ip']}:9000"),
        ),
        (
            "portainer_admin",
            lambda: init_portainer_admin(
                f"http://{form['private_ip']}:9000",
                form["portainer_admin_user"],
                form["portainer_admin_password"],
            ),
        ),
        ("portainer_token", lambda: _portainer_token_phase(form, project_dir)),
        ("mysql_wait", lambda: wait_for_mysql(secrets["mysql_root_password"])),
        ("mysql_setup", lambda: setup_mysql_users(secrets)),
        ("composer_install", lambda: composer_install(q)),
        ("migrate", lambda: run_migrations(q)),
        ("seed", lambda: seed_php_versions(q)),
        (
            "admin_user",
            lambda: create_admin_user(
                name=form["panel_admin_name"],
                username=form["panel_admin_username"],
                email=form["panel_admin_email"],
                password=form["panel_admin_password"],
                log_queue=q,
            ),
        ),
        (
            "ssl",
            lambda: issue_panel_certificate(
                base_domain=form["base_domain"],
                admin_email=form["admin_email"],
                token_file="/secrets/cloudflare.ini",
                container="alpha_panel_web",
                log_queue=q,
            ),
        ),
        ("caddy_reload", lambda: reload_caddy(q)),
    ]

    try:
        for name, fn in phases:
            if name in state.completed_phases:
                q.put({"type": "line", "text": f"[skip] {name} already completed"})
                continue
            q.put({"type": "phase", "name": name})
            fn()
            state.completed_phases.append(name)
            state.current_phase = name
            save_state(state_file, state)
        state.current_phase = "done"
        save_state(state_file, state)
        q.put({"type": "done", "panel_url": f"https://{form['panel_domain']}:8443"})
    except InstallerError as e:
        state.last_error = {"phase": e.phase, "message": e.message, "detail": e.detail}
        save_state(state_file, state)
        q.put({"type": "error", "phase": e.phase, "message": e.message})
    except Exception as e:
        state.last_error = {"phase": "unknown", "message": str(e)}
        save_state(state_file, state)
        q.put({"type": "error", "phase": "unknown", "message": str(e)})
    finally:
        q.close()


def _portainer_token_phase(form: dict, project_dir: Path) -> None:
    base_url = f"http://{form['private_ip']}:9000"
    token = create_access_token(
        base_url,
        form["portainer_admin_user"],
        form["portainer_admin_password"],
    )
    endpoint_id = detect_endpoint_id(base_url, token)
    set_portainer_credentials(
        laravel_env=project_dir / "alpha-panel" / "web" / "httpdocs" / ".env",
        api_key=token,
        endpoint_id=endpoint_id,
    )


def main() -> None:
    project_dir = Path(os.environ.get("ALPHAPANEL_PROJECT_DIR", "/opt/alphapanel-docker"))
    state_file = project_dir / ".installer_state.json"
    app = create_app(project_dir=project_dir, state_file=state_file)
    host = "0.0.0.0"
    port = int(os.environ.get("ALPHAPANEL_INSTALLER_PORT", "5000"))
    print(f"Installer running at http://{host}:{port}")
    app.run(host=host, port=port, threaded=True)


if __name__ == "__main__":
    main()
