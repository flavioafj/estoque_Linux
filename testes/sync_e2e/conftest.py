"""Integração HTTP/PHP com duas bases SQLite descartáveis, sem ler o .env."""
import contextlib
import json
import os
from pathlib import Path
import shutil
import socket
import sqlite3
import subprocess
import tempfile
import time
import urllib.error
import urllib.request
import uuid

import pytest

ROOT = Path(__file__).resolve().parents[2]
SUPPORT = Path(__file__).parent / "support"
PHP = ["php", "-n", "-d", "extension=pdo", "-d", "extension=pdo_sqlite", "-d", "extension=curl"]
# Ordem das dependências: filhos antes de pais. A limpeza usa as PKs geradas.
TABLES = ("alertas_estoque", "auditoria", "sync_recebimentos", "sincronizacao", "itens_nota",
          "notas_fiscais", "movimentacao_itens", "estoques", "movimentacoes", "inventarios",
          "produtos", "fornecedores", "tipos_movimentacao", "unidades_medida", "categorias", "usuarios")


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def http(url, body=None, headers=None):
    data = None if body is None else json.dumps(body).encode()
    req = urllib.request.Request(url, data=data, headers={"Content-Type": "application/json", **(headers or {})})
    try:
        response = urllib.request.build_opener(NoRedirect, urllib.request.ProxyHandler({})).open(req, timeout=10)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        return response.code, response.read().decode()


class Base:
    def __init__(self, path):
        self.path = path
        self.conn = sqlite3.connect(path)
        self.conn.row_factory = sqlite3.Row
        self.conn.execute("PRAGMA foreign_keys = ON")
        self.conn.executescript((SUPPORT / "schema.sql").read_text())
        self.baseline = self.snapshot()

    def rows(self, sql, args=()):
        return [dict(row) for row in self.conn.execute(sql, args)]

    def one(self, sql, args=()):
        rows = self.rows(sql, args)
        assert len(rows) == 1, f"Esperada uma linha, obtidas {len(rows)}: {sql}"
        return rows[0]

    def scalar(self, sql, args=()):
        return self.conn.execute(sql, args).fetchone()[0]

    def snapshot(self):
        return {table: self.rows(f'SELECT * FROM "{table}" ORDER BY rowid') for table in TABLES}

    def cleanup(self):
        # Remove apenas PKs ausentes no snapshot inicial desta base temporária.
        with self.conn:
            for table in TABLES:
                if table == "sync_recebimentos":
                    keys = ("origem_hash", "evento_id")
                else:
                    keys = ("id",)
                baseline = {tuple(row[key] for key in keys) for row in self.baseline[table]}
                for row in self.rows(f'SELECT * FROM "{table}"'):
                    values = tuple(row[key] for key in keys)
                    if values not in baseline:
                        where = " AND ".join(f'"{key}" = ?' for key in keys)
                        self.conn.execute(f'DELETE FROM "{table}" WHERE {where}', values)
        assert self.snapshot() == self.baseline, "Limpeza incompleta ou dados anteriores ao teste foram alterados."
        assert self.rows("PRAGMA foreign_key_check") == [], "Vínculos inválidos após a limpeza."


class Environment:
    def __init__(self, work):
        self.work = work
        self.app = work / "app"
        self.token = uuid.uuid4().hex
        self.prefix = "PYTEST_SYNC_" + uuid.uuid4().hex[:12]
        self.bases = {}
        self.servers = []
        self.urls = {}
        self.envs = {}

    def setup(self):
        for directory in ("config", "logs", "sessions", "templates"):
            (self.app / directory).mkdir(parents=True, exist_ok=True)
        (self.app / ".pytest-sync-sandbox").touch()
        shutil.copytree(ROOT / "src", self.app / "src")
        (self.app / "vendor").symlink_to(ROOT / "vendor", target_is_directory=True)
        for template in ("header.php", "navigation.php", "footer.php"):
            (self.app / "templates" / template).write_text("<?php // Apresentação dispensada no teste de integração.\n")
        for file in ("scripts/sync2.php", "public/api/sync.php", "public/product/index.php",
                     "public/movimentacao/registrar_entrada.php", "public/movimentacao/registrar_entrada_nfe.php",
                     "public/inventory_confirm.php", "public/inventory_undo.php"):
            target = self.app / file
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(ROOT / file, target)
        shutil.copy2(SUPPORT / "config.php", self.app / "config/config.php")
        shutil.copy2(SUPPORT / "router.php", self.app / "router.php")
        for side in ("local", "web"):
            self.bases[side] = Base(self.work / f"{side}.sqlite")
            env = os.environ.copy()
            env.update(PYTEST_SYNC_DB=str(self.bases[side].path), PYTEST_SYNC_TOKEN=self.token,
                       PYTEST_SYNC_URL="", NO_PROXY="127.0.0.1,localhost", no_proxy="127.0.0.1,localhost")
            self.envs[side] = env
            with socket.socket() as sock:
                sock.bind(("127.0.0.1", 0))
                port = sock.getsockname()[1]
            self.urls[side] = f"http://127.0.0.1:{port}"
            log = open(self.work / f"{side}.log", "w+")
            process = subprocess.Popen([*PHP, "-S", f"127.0.0.1:{port}", str(self.app / "router.php")],
                                       cwd=self.app, env=env, stdout=log, stderr=log)
            self.servers.append((process, log))
            for _ in range(100):
                if process.poll() is not None:
                    log.seek(0)
                    raise RuntimeError("Servidor PHP encerrou: " + log.read())
                try:
                    if http(self.urls[side] + "/health")[0] == 200:
                        break
                except (OSError, urllib.error.URLError):
                    time.sleep(0.03)
            else:
                raise RuntimeError("Servidor PHP não iniciou.")

    def action(self, side, action, data):
        status, body = http(self.urls[side] + "/actions/" + action, data, {"X-Test-Token": self.token})
        assert status in (200, 302), f"{side}/{action}: HTTP {status}: {body[:600]}"
        assert "Fatal error" not in body, body[:600]
        return body

    def api(self, side, payload=None, query=""):
        status, body = http(self.urls[side] + "/api/sync.php" + query, payload,
                            {"X-Api-Token": "Bearer " + self.token})
        assert status == 200, f"API {side}: HTTP {status}: {body[:600]}"
        result = json.loads(body)
        assert result["success"] is True, result
        return result

    def sync(self):
        # O equipamento local executa PUSH e PULL contra a API web real.
        env = {**self.envs["local"], "PYTEST_SYNC_URL": self.urls["web"] + "/api/sync.php"}
        result = subprocess.run([*PHP, str(self.app / "scripts/sync2.php")], cwd=self.app,
                                env=env, capture_output=True, text=True, timeout=60)
        assert result.returncode == 0, result.stdout + result.stderr
        assert "Falhas: 0." in result.stdout, result.stdout + result.stderr
        return result.stdout

    def assert_drained(self):
        for side, db in self.bases.items():
            assert db.scalar("SELECT COUNT(*) FROM sincronizacao WHERE sincronizado = 0") == 0, side
            assert db.scalar("SELECT COUNT(*) FROM sincronizacao WHERE tentativas != 0 OR erro_mensagem IS NOT NULL") == 0, side
            assert db.scalar("SELECT COUNT(*) FROM sync_recebimentos WHERE confirmado_em IS NULL") == 0, side

    def close(self):
        errors = []
        for process, log in self.servers:
            process.terminate()
            try:
                process.wait(timeout=5)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait(timeout=5)
            log.close()
        for side, db in self.bases.items():
            try:
                db.cleanup()
            except Exception as error:
                errors.append(f"{side}: {error}")
            finally:
                db.conn.close()
        if errors:
            raise AssertionError("; ".join(errors))


@contextlib.contextmanager
def temporary_environment(parent):
    with tempfile.TemporaryDirectory(prefix="sync-e2e-", dir=parent) as directory:
        instance = Environment(Path(directory))
        try:
            instance.setup()
            yield instance
        finally:
            instance.close()


@pytest.fixture
def environment(tmp_path):
    with temporary_environment(tmp_path) as instance:
        yield instance
