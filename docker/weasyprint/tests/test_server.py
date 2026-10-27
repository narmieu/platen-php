"""The weasyprint-platen-server command: the protocol, timeouts and isolation.

The real server runs in its own process, so its patches stay out of this one; the other
tests serve a fake render function from a forked process.
"""

import json
import os
import signal
import socket
import subprocess
import sys
import time
import warnings

import pytest

from platen_weasyprint.server import Server


def request(path, argv, html=b"<p>x</p>", env=None, timeout=30):
    with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as client:
        client.connect(str(path))
        header = {"argv": argv, "env": env or {}, "timeout": timeout}
        client.sendall(json.dumps(header).encode() + b"\n" + html)
        client.shutdown(socket.SHUT_WR)
        answer = b""
        while chunk := client.recv(65536):
            answer += chunk
    return json.loads(answer) if answer else None


def wait_for(path, running):
    for _ in range(200):
        if path.exists():
            return
        assert running(), "the server exited"
        time.sleep(0.05)
    raise AssertionError("the server did not start")


@pytest.fixture
def serve(tmp_path):
    pids = []

    def start(render):
        path = tmp_path / f"fake-{len(pids)}.sock"
        pid = os.fork()
        if pid == 0:
            try:
                Server(str(path), render, max_children=4).serve_forever()
            finally:
                os._exit(0)
        pids.append(pid)
        wait_for(path, lambda: os.waitpid(pid, os.WNOHANG) == (0, 0))
        return path

    yield start
    for pid in pids:
        os.kill(pid, signal.SIGKILL)
        os.waitpid(pid, 0)


@pytest.mark.parametrize("prince", [False, True])
def test_the_real_server_renders_and_reports_errors(tmp_path, prince):
    path = tmp_path / "server.sock"
    command = [sys.executable, "-m", "platen_weasyprint.server", "--socket", str(path)]
    process = subprocess.Popen(command + (["--prince"] if prince else []), stderr=subprocess.PIPE, text=True)
    try:
        wait_for(path, lambda: process.poll() is None)
        assert oct(path.stat().st_mode & 0o777) == "0o600"

        out = tmp_path / "out.pdf"
        assert request(path, ["--encoding=utf-8", "-", str(out)]) == {"exit": 0, "stderr": ""}
        assert out.read_bytes().startswith(b"%PDF")

        # What the weasyprint command does with an option it does not know.
        answer = request(path, ["--no-such-option", "-", str(out)])
        assert answer["exit"] == 2 and "--no-such-option" in answer["stderr"]
    finally:
        process.terminate()
        process.wait(10)
    assert not path.exists()


def test_environment_is_applied_for_one_document_only(serve, monkeypatch):
    monkeypatch.setenv("PLATEN_TEST_KEPT", "server")

    def render(argv, stdin):
        print(os.environ.get("PLATEN_TEST_SET"), os.environ.get("PLATEN_TEST_KEPT"), stdin.read().decode(), file=sys.stderr)

    path = serve(render)
    answer = request(path, [], html=b"<p>doc</p>", env={"PLATEN_TEST_SET": "child", "PLATEN_TEST_KEPT": None})
    assert answer == {"exit": 0, "stderr": "child None <p>doc</p>\n"}
    assert request(path, [], html=b"next") == {"exit": 0, "stderr": "None server next\n"}


def test_pythonwarnings_ignore_silences_warnings(serve):
    def render(argv, stdin):
        with warnings.catch_warnings(record=True) as caught:
            warnings.warn("noise")
        print(len(caught), file=sys.stderr)

    path = serve(render)
    assert request(path, [], env={"PYTHONWARNINGS": "ignore"}) == {"exit": 0, "stderr": "0\n"}
    assert request(path, []) == {"exit": 0, "stderr": "1\n"}


def test_exit_codes_and_exceptions_are_reported(serve):
    def render(argv, stdin):
        if argv == ["exit"]:
            sys.exit(3)
        if argv == ["message"]:
            sys.exit("bad input")
        raise MemoryError()

    path = serve(render)
    assert request(path, ["exit"]) == {"exit": 3, "stderr": ""}
    assert request(path, ["message"]) == {"exit": 1, "stderr": "bad input\n"}
    answer = request(path, ["raise"])
    assert answer["exit"] == 1 and "MemoryError" in answer["stderr"]


def test_a_document_past_its_timeout_is_killed(serve):
    path = serve(lambda argv, stdin: time.sleep(30))
    started = time.monotonic()
    assert request(path, [], timeout=1) is None
    assert time.monotonic() - started < 5


def test_a_bad_request_is_answered(serve):
    path = serve(lambda argv, stdin: None)
    with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as client:
        client.connect(str(path))
        client.sendall(b"not json\n")
        client.shutdown(socket.SHUT_WR)
        answer = json.loads(client.makefile("rb").readline())
    assert answer["exit"] == 2 and "bad request" in answer["stderr"]
