"""The Prince-layer test process renders with the tests' fontconfig file."""

from support import REPO, TESTS, fontconfig_files


def test_renders_use_the_test_fontconfig_file():
    files = fontconfig_files()
    assert files[0] == str(TESTS / "fonts.conf")
    platen_conf = REPO / "docker/fontconfig/45-platen.conf"
    if platen_conf.exists():
        assert files.index(str(platen_conf)) < files.index("/etc/fonts/conf.d")
