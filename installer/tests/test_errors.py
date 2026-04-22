from installer.errors import InstallerError


def test_installer_error_carries_phase_and_detail():
    err = InstallerError(phase="portainer", message="admin init failed", detail={"status": 409})
    assert err.phase == "portainer"
    assert err.message == "admin init failed"
    assert err.detail == {"status": 409}
    assert "portainer" in str(err)
    assert "admin init failed" in str(err)


def test_installer_error_detail_defaults_to_empty_dict():
    err = InstallerError(phase="x", message="y")
    assert err.detail == {}
