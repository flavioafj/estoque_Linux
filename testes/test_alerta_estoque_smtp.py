"""Execute com python3 testes/test_alerta_estoque_smtp.py (PHP com PDO SQLite e OpenSSL)."""
import base64
from email import policy
from email.parser import BytesParser
import os
import pathlib
import shutil
import socketserver
import ssl
import subprocess
import tempfile
import threading
import unittest


class SMTPHandler(socketserver.StreamRequestHandler):
    def handle(self):
        self.request.settimeout(5)
        authenticated = False
        recipients = []
        self.wfile.write(b"220 SMTP de teste\r\n")
        while line := self.rfile.readline():
            verb, _, argument = line.strip().partition(b" ")
            if verb == b"EHLO":
                capabilities = b"250 AUTH LOGIN\r\n" if isinstance(self.connection, ssl.SSLSocket) else b"250 STARTTLS\r\n"
                self.wfile.write(b"250-localhost\r\n" + capabilities)
            elif verb == b"STARTTLS":
                self.wfile.write(b"220 Iniciando TLS\r\n")
                self.rfile.close()
                self.wfile.close()
                self.connection = self.server.tls.wrap_socket(self.connection, server_side=True)
                self.setup_tls_streams()
            elif verb == b"AUTH" and isinstance(self.connection, ssl.SSLSocket):
                self.wfile.write(b"334 VXNlcm5hbWU6\r\n")
                username = base64.b64decode(self.rfile.readline().strip())
                self.wfile.write(b"334 UGFzc3dvcmQ6\r\n")
                password = base64.b64decode(self.rfile.readline().strip())
                authenticated = (username, password) == (b"smtp-teste", b"senha-teste")
                self.wfile.write(b"235 Autenticado\r\n" if authenticated else b"535 Credenciais invalidas\r\n")
            elif verb == b"MAIL" and authenticated:
                sender = argument
                self.wfile.write(b"250 OK\r\n")
            elif verb == b"RCPT" and authenticated:
                recipients.append(argument)
                self.wfile.write(b"250 OK\r\n")
            elif verb == b"DATA" and authenticated and recipients:
                self.wfile.write(b"354 Envie a mensagem\r\n")
                data = bytearray()
                while (chunk := self.rfile.readline()) and chunk != b".\r\n":
                    data.extend(chunk)
                if not chunk:
                    return
                self.server.messages.append((sender, recipients, bytes(data)))
                self.wfile.write(b"250 Mensagem aceita\r\n")
            elif verb == b"QUIT":
                self.wfile.write(b"221 Encerrando\r\n")
                return
            else:
                self.wfile.write(b"503 Comando inesperado\r\n")

    def finish(self):
        try:
            super().finish()
        finally:
            self.connection.close()

    def setup_tls_streams(self):
        self.rfile = self.connection.makefile("rb")
        self.wfile = self.connection.makefile("wb", buffering=0)


class EstoqueSMTPTest(unittest.TestCase):
    def test_envia_um_resumo_por_administrador_a_cada_lote_com_novos_alertas(self):
        root = pathlib.Path(__file__).resolve().parents[1]
        with tempfile.TemporaryDirectory(prefix="estoque-smtp-") as directory:
            sandbox = pathlib.Path(directory)
            (sandbox / "logs").mkdir()
            (sandbox / "src/Models").mkdir(parents=True)
            (sandbox / "vendor").symlink_to(root / "vendor")
            shutil.copyfile(root / "src/Models/Alert.php", sandbox / "src/Models/Alert.php")
            cert, key = sandbox / "cert.pem", sandbox / "key.pem"
            subprocess.run(
                ["openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1",
                 "-subj", "/CN=127.0.0.1", "-addext", "subjectAltName=IP:127.0.0.1",
                 "-keyout", str(key), "-out", str(cert)],
                check=True, capture_output=True, timeout=15,
            )
            with socketserver.TCPServer(("127.0.0.1", 0), SMTPHandler) as server:
                server.messages = []
                server.tls = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
                server.tls.load_cert_chain(cert, key)
                worker = threading.Thread(target=server.serve_forever, daemon=True)
                worker.start()
                try:
                    result = subprocess.run(
                        ["php", "-d", f"openssl.cafile={cert}",
                         str(root / "testes/support/alerta_estoque_fixture.php"),
                         directory, str(server.server_address[1])],
                        capture_output=True, text=True, timeout=15,
                    )
                    log = sandbox / "logs/error.log"
                    details = (log.read_text() if log.exists() else "") + result.stdout + result.stderr
                    self.assertEqual(result.returncode, 0, details)
                    self.assertEqual(len(server.messages), 4, details)
                    cli = subprocess.run(
                        ["php", "-d", f"openssl.cafile={cert}", "-d", "variables_order=EGPCS",
                         str(root / "testes/testar_smtp_alerta.php"), "teste@example.test"],
                        env={**os.environ, "SMTP_HOST": "127.0.0.1",
                             "SMTP_PORT": str(server.server_address[1]),
                             "SMTP_USER": "smtp-teste", "SMTP_PASS": "senha-teste"},
                        capture_output=True, text=True, timeout=15,
                    )
                    self.assertEqual(cli.returncode, 0, cli.stdout + cli.stderr)
                    self.assertEqual(len(server.messages), 5)
                finally:
                    server.shutdown()
                    worker.join()
            for offset in (0, 2):
                group = server.messages[offset:offset + 2]
                self.assertCountEqual([recipients for _, recipients, _ in group], [
                    [b"TO:<admin-a@example.test>"], [b"TO:<admin-b@example.test>"],
                ])
                for sender, _, raw_message in group:
                    self.assertEqual(sender, b"FROM:<no-reply@sorveteria.com>")
                    message = BytesParser(policy=policy.default).parsebytes(raw_message)
                    self.assertEqual(message["Subject"], "Alerta de Estoque Baixo")
                    body = message.get_body(("html",)).get_content()
                    heading, separator, items = body.partition("Outros produtos com estoque baixo")
                    self.assertTrue(separator)
                    self.assertIn("Produto teste está com estoque baixo.", heading)
                    self.assertNotIn("Produto teste", items)
                    if offset == 0:
                        self.assertIn("Segundo produto movimentado está com estoque baixo.", heading)
                        self.assertNotIn("Segundo produto movimentado", items)
                    else:
                        self.assertIn("Segundo produto movimentado", items)
                    self.assertIn("Segundo produto movimentado", body)
                    self.assertIn("Produto crítico sem movimentação", body)
                    self.assertNotIn("Produto acima do mínimo", body)
                    self.assertIn("Estoque Atual: 0", items)
                    self.assertIn("Estoque Mínimo: 1", items)

            _, cli_recipients, cli_message = server.messages[4]
            self.assertEqual(cli_recipients, [b"TO:<teste@example.test>"])
            self.assertEqual(
                BytesParser(policy=policy.default).parsebytes(cli_message)["Subject"],
                "[TESTE] Alerta de Estoque Baixo",
            )


if __name__ == "__main__":
    unittest.main()
