"""The weasyprint-platen-server command: WeasyPrint kept warm, one process per document.

Starting Python and importing WeasyPrint takes most of a short render. The server does it
once: it applies the patch layers, imports WeasyPrint, then listens on a Unix socket and
forks a child for each connection. The child renders one document and exits, so a document
that hangs, crashes or leaks memory still takes nothing else down with it.

The patches change the whole process, so one server serves one set of layers: the quality
layer, or with ``--prince`` the quality and Prince layers.

A request is one line of JSON, then the HTML until the end of the stream:

    {"argv": [...], "env": {"NAME": "value" or null}, "timeout": 60}

``argv`` is WeasyPrint's command line without the program, its input ``-`` (the HTML that
follows). ``env`` sets variables for this document, such as the egress proxy, and null
unsets one. After ``timeout`` seconds the child is killed. The answer is one line of JSON:

    {"exit": 0, "stderr": "..."}

with what ``weasyprint`` would have exited with and written to its standard error.
"""

import argparse
import io
import json
import math
import os
import signal
import socketserver
import sys
import tempfile
import traceback

from . import check_versions

MAX_HEADER_BYTES = 1024 * 1024
MAX_STDERR_CHARS = 64 * 1024


def handle(request: dict, html: bytes, render) -> dict:
    """Render one request in the current (forked) process; return the answer."""
    signal.signal(signal.SIGTERM, signal.SIG_DFL)
    signal.alarm(max(1, math.ceil(float(request.get("timeout", 60)))))
    for name, value in (request.get("env") or {}).items():
        if value is None:
            os.environ.pop(name, None)
        else:
            os.environ[name] = str(value)

    # What WeasyPrint, its libraries and their C code write to standard error.
    with tempfile.TemporaryFile() as captured:
        os.dup2(captured.fileno(), 2)
        sys.stderr = io.TextIOWrapper(io.FileIO(2, "w", closefd=False), write_through=True)
        code = 0
        try:
            render(list(request["argv"]), stdin=io.BytesIO(html))
        except SystemExit as exit_:
            if exit_.code is None or isinstance(exit_.code, int):
                code = exit_.code or 0
            else:
                print(exit_.code, file=sys.stderr)
                code = 1
        except BaseException:  # noqa: BLE001 - reported as weasyprint would, then exit 1
            traceback.print_exc()
            code = 1
        sys.stderr.flush()
        captured.seek(0)
        stderr = captured.read().decode("utf-8", "replace")
    return {"exit": code, "stderr": stderr[-MAX_STDERR_CHARS:]}


class Handler(socketserver.StreamRequestHandler):
    def handle(self) -> None:
        header = self.rfile.readline(MAX_HEADER_BYTES)
        try:
            request = json.loads(header)
            if not isinstance(request, dict) or not isinstance(request.get("argv"), list):
                raise ValueError("argv is missing")
        except ValueError as error:
            answer = {"exit": 2, "stderr": f"platen-weasyprint-server: bad request: {error}\n"}
        else:
            answer = handle(request, self.rfile.read(), self.server.render)
        self.wfile.write(json.dumps(answer).encode() + b"\n")


class Server(socketserver.ForkingMixIn, socketserver.UnixStreamServer):
    # Children are forked from the warm process; each serves one connection, then exits.
    block_on_close = False

    def __init__(self, path: str, render, max_children: int):
        self.render = render
        self.max_children = max_children
        if os.path.exists(path):
            os.unlink(path)
        super().__init__(path, Handler)
        os.chmod(path, 0o600)


def main(argv: list[str] | None = None) -> None:
    parser = argparse.ArgumentParser(prog="weasyprint-platen-server", description=__doc__.split("\n\n")[0])
    parser.add_argument("--socket", required=True, help="the Unix socket to listen on")
    parser.add_argument("--prince", action="store_true", help="add the Prince layer to the quality layer")
    parser.add_argument("--max-children", type=int, default=40, help="documents rendered at once")
    args = parser.parse_args(argv)

    check_versions()
    from . import prince, quality

    quality.apply()
    if args.prince:
        prince.apply()
    # Imported after the patches, as in the weasyprint-platen command.
    from weasyprint.__main__ import main as weasyprint_main

    # A child's alarm (its timeout) kills it; SIGTERM stops the server.
    signal.signal(signal.SIGTERM, lambda *_: sys.exit(0))
    with Server(args.socket, weasyprint_main, args.max_children) as server:
        try:
            server.serve_forever()
        finally:
            if os.path.exists(args.socket):
                os.unlink(args.socket)


if __name__ == "__main__":
    main()
