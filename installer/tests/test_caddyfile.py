from installer.steps.caddyfile import (
    write_base_domain_caddyfile,
    write_jenkins_caddyfile,
)


def test_base_domain_caddyfile_created_when_missing(tmp_path):
    target = tmp_path / "example.com" / "Caddyfile"
    write_base_domain_caddyfile(target, base_domain="example.com")
    content = target.read_text()
    assert "example.com" in content
    assert "*.example.com" in content


def test_base_domain_caddyfile_idempotent(tmp_path):
    target = tmp_path / "example.com" / "Caddyfile"
    write_base_domain_caddyfile(target, base_domain="example.com")
    target.write_text("CUSTOM\n")
    write_base_domain_caddyfile(target, base_domain="example.com")
    assert target.read_text() == "CUSTOM\n"


def test_jenkins_caddyfile_open_mode_when_no_admin_ips(tmp_path):
    target = tmp_path / "jenkins.example.com" / "Caddyfile"
    write_jenkins_caddyfile(
        target,
        base_domain="example.com",
        jenkins_domain="jenkins.example.com",
        admin_ips="",
    )
    content = target.read_text()
    assert "jenkins.example.com:443" in content
    assert "reverse_proxy jenkins:8080" in content
    assert "client_ip" not in content
    assert "respond 403" not in content


def test_jenkins_caddyfile_restricts_to_admin_cidrs(tmp_path):
    target = tmp_path / "jenkins.example.com" / "Caddyfile"
    write_jenkins_caddyfile(
        target,
        base_domain="example.com",
        jenkins_domain="jenkins.example.com",
        admin_ips="1.2.3.4, 10.0.0.0/24",
    )
    content = target.read_text()
    assert "@admin client_ip 1.2.3.4/32 10.0.0.0/24" in content
    assert "respond 403" in content
