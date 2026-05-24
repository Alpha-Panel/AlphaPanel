"""Unit tests for the zimbra installer step."""

from __future__ import annotations

from installer.steps import zimbra


def test_disabled_returns_no_errors() -> None:
    assert zimbra.validate_zimbra_form({"zimbra_enabled": False}) == []


def test_enabled_requires_all_fields() -> None:
    errors = zimbra.validate_zimbra_form({"zimbra_enabled": True})
    assert any("ZIMBRA_ADMIN_URL" in e for e in errors)
    assert any("ZIMBRA_ADMIN_USER" in e for e in errors)
    assert any("ZIMBRA_ADMIN_PASSWORD" in e for e in errors)
    assert any("ZIMBRA_DEFAULT_MX_HOST" in e for e in errors)


def test_admin_url_must_be_http_or_https() -> None:
    errors = zimbra.validate_zimbra_form(
        {
            "zimbra_enabled": True,
            "zimbra_admin_url": "ftp://zimbra.example.com",
            "zimbra_admin_user": "admin@example.com",
            "zimbra_admin_password": "secret",
            "zimbra_default_mx_host": "zimbra.example.com",
        }
    )
    assert any("http://" in e for e in errors)


def test_valid_form_returns_no_errors() -> None:
    errors = zimbra.validate_zimbra_form(
        {
            "zimbra_enabled": True,
            "zimbra_admin_url": "https://zimbra.example.com:7071/service/admin/soap",
            "zimbra_admin_user": "admin@example.com",
            "zimbra_admin_password": "secret",
            "zimbra_default_mx_host": "zimbra.example.com",
            "zimbra_default_mx_priority": 10,
            "zimbra_timeout_seconds": 15,
        }
    )
    assert errors == []


def test_invalid_priority_caught() -> None:
    errors = zimbra.validate_zimbra_form(
        {
            "zimbra_enabled": True,
            "zimbra_admin_url": "https://zimbra.example.com",
            "zimbra_admin_user": "admin@example.com",
            "zimbra_admin_password": "secret",
            "zimbra_default_mx_host": "zimbra.example.com",
            "zimbra_default_mx_priority": 999999,
        }
    )
    assert any("PRIORITY" in e or "priority" in e for e in errors)
