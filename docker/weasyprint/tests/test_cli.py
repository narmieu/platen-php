"""The weasyprint-platen command, the version guard and the layer contract.

These tests replace every apply() they reach, so they leave no patch behind for
the layer folders that run in the same process.
"""

import types

import pytest
import weasyprint

import platen_weasyprint
from platen_weasyprint import cli, prince, quality


def test_version_guard_names_the_supported_version(monkeypatch):
    monkeypatch.setattr(weasyprint, "__version__", "71.0")
    with pytest.raises(RuntimeError, match="supports WeasyPrint 70.0, found 71.0"):
        platen_weasyprint.check_versions()


def test_flag_is_stripped_and_prince_applied(monkeypatch, tmp_path):
    calls = []
    monkeypatch.setattr(platen_weasyprint.quality, "apply", lambda: calls.append("quality"))
    monkeypatch.setattr(platen_weasyprint.prince, "apply", lambda: calls.append("prince"))
    src = tmp_path / "in.html"
    src.write_text("<p>x</p>")
    out = tmp_path / "out.pdf"
    cli.main(["--platen-prince", str(src), str(out)])
    assert calls == ["quality", "prince"] and out.read_bytes().startswith(b"%PDF")


def test_without_flag_only_quality_applies(monkeypatch, tmp_path):
    calls = []
    monkeypatch.setattr(platen_weasyprint.quality, "apply", lambda: calls.append("quality"))
    monkeypatch.setattr(platen_weasyprint.prince, "apply", lambda: calls.append("prince"))
    src = tmp_path / "in.html"
    src.write_text("<p>x</p>")
    out = tmp_path / "out.pdf"
    cli.main([str(src), str(out)])
    assert calls == ["quality"] and out.read_bytes().startswith(b"%PDF")


def fix_module(name, calls):
    module = types.ModuleType(name)
    module.apply = lambda: calls.append(name)
    return module


@pytest.mark.parametrize("layer", [quality, prince], ids=["quality", "prince"])
def test_layer_applies_each_module_once_in_order(monkeypatch, layer):
    calls = []
    monkeypatch.setattr(layer, "MODULES", [fix_module("a", calls), fix_module("b", calls)])
    layer.apply()
    layer.apply()
    assert calls == ["a", "b"]


@pytest.mark.parametrize("layer", [quality, prince], ids=["quality", "prince"])
def test_layer_checks_versions_before_patching(monkeypatch, layer):
    calls = []
    monkeypatch.setattr(layer, "MODULES", [fix_module("a", calls)])
    monkeypatch.setattr(weasyprint, "__version__", "71.0")
    with pytest.raises(RuntimeError, match="found 71.0"):
        layer.apply()
    assert calls == []
