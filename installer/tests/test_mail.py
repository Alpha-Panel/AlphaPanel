"""Unit tests for the mail installer step."""

from __future__ import annotations

from pathlib import Path

import pytest

from installer.steps import mail


def test_setup_mail_external_service_noop_when_disabled(tmp_path: Path) -> None:
    services = tmp_path / "external-services"
    services.mkdir()
    (services / "mailu.example.yaml").write_text("services: {}\n", encoding="utf-8")

    mail.setup_mail_external_service(tmp_path, {"mail_enabled": False})

    assert not (services / "mailu.yaml").exists()


def test_setup_mail_external_service_copies_and_appends_include(tmp_path: Path) -> None:
    services = tmp_path / "external-services"
    services.mkdir()
    (services / "mailu.example.yaml").write_text("services: {}\n", encoding="utf-8")
    (services / "local-services.yaml").write_text(
        "include:\n  - ./mongodb.yaml\n", encoding="utf-8"
    )

    mail.setup_mail_external_service(tmp_path, {"mail_enabled": True})

    assert (services / "mailu.yaml").exists()
    local = (services / "local-services.yaml").read_text(encoding="utf-8")
    assert "./mailu.yaml" in local


def test_setup_mail_external_service_is_idempotent(tmp_path: Path) -> None:
    services = tmp_path / "external-services"
    services.mkdir()
    (services / "mailu.example.yaml").write_text("services: {}\n", encoding="utf-8")
    (services / "local-services.yaml").write_text(
        "include:\n  - ./mailu.yaml\n", encoding="utf-8"
    )

    mail.setup_mail_external_service(tmp_path, {"mail_enabled": True})
    local = (services / "local-services.yaml").read_text(encoding="utf-8")
    assert local.count("./mailu.yaml") == 1


def test_validate_mail_form_requires_domain_when_enabled() -> None:
    errors = mail.validate_mail_form({"mail_enabled": True})
    assert any("MAIL_DOMAIN" in e for e in errors)


def test_validate_mail_form_passes_with_defaults() -> None:
    errors = mail.validate_mail_form(
        {
            "mail_enabled": True,
            "mail_domain": "mail.example.com",
            "mail_data_path": "/var/lib/mailu",
        }
    )
    assert errors == []
