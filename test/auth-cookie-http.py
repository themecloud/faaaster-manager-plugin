"""Check actual Set-Cookie headers from the plugin on a local PHP server."""
import hashlib
import hmac
import os
from pathlib import Path
import socket
import subprocess
import tempfile
import time
import unittest
from urllib.request import Request, urlopen

PHP = r"""<?php
require getenv('AUTH_COOKIE_CLASS');
define('WP_API_KEY', 'unit-test-key');
function is_user_logged_in() { return !isset($_GET['anonymous']); }
function current_user_can($cap) { return !isset($_GET['subscriber']); }
function get_current_user_id() { return 42; }
function is_wp_error($response) { return false; }
$m = new FaaasterAuthCookieManager();
$m->refreshRestResponse(new class {
    public function get_status() { return (int) ($_GET['status'] ?? 200); }
});
echo 'ok';
"""


def token(remaining, uid=42, level='admin', key='unit-test-key'):
    expiry = int(time.time()) + remaining
    message = f'fstr_auth|v1|{uid}|{level}|{expiry}'
    mac = hmac.new(key.encode(), message.encode(), hashlib.sha256).hexdigest()
    return f'v1.{uid}.{level}.{expiry}.{mac}'


class RenewalTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.directory = tempfile.TemporaryDirectory()
        router = Path(cls.directory.name) / 'router.php'
        router.write_text(PHP)
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        cls.url = f'http://127.0.0.1:{port}'
        env = dict(os.environ, AUTH_COOKIE_CLASS=str(
            Path(__file__).resolve().parents[1] / 'class/auth-cookie.php'))
        cls.server = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', str(router)],
                                      env=env, stdout=subprocess.DEVNULL,
                                      stderr=subprocess.DEVNULL)
        cls.addClassCleanup(cls.cleanup)
        for _ in range(50):
            try:
                with urlopen(cls.url, timeout=1):
                    return
            except OSError:
                time.sleep(0.05)
        raise RuntimeError('PHP test server did not start')

    @classmethod
    def cleanup(cls):
        cls.server.terminate()
        cls.server.wait(timeout=5)
        cls.directory.cleanup()

    def cookies(self, value=None, query=''):
        headers = {} if value is None else {'Cookie': 'fstr_auth=' + value}
        with urlopen(Request(self.url + '/?' + query, headers=headers), timeout=2) as response:
            return response.headers.get_all('Set-Cookie') or []

    def test_new_proof_lasts_30_minutes(self):
        before = int(time.time())
        cookies = self.cookies()
        self.assertEqual(len(cookies), 1)
        value = cookies[0].split(';')[0].split('=', 1)[1]
        self.assertTrue(before + 1800 <= int(value.split('.')[3]) <= int(time.time()) + 1800)
        for flag in ['path=/', 'secure', 'HttpOnly', 'SameSite=Lax']:
            self.assertIn(flag, cookies[0])

    def test_valid_proof_above_threshold_is_not_reissued(self):
        self.assertEqual(self.cookies(token(1200)), [])

    def test_near_expiry_and_expired_proofs_are_renewed(self):
        for remaining in [899, 1, -1]:
            with self.subTest(remaining=remaining):
                self.assertEqual(len(self.cookies(token(remaining))), 1)

    def test_invalid_or_wrong_identity_proof_does_not_suppress_renewal(self):
        for value in [token(1200, key='wrong'), token(1200, uid=7),
                      token(1200, level='editor'), token(3600), 'invalid']:
            with self.subTest(value=value[:25]):
                self.assertEqual(len(self.cookies(value)), 1)

    def test_permissions_are_checked_before_reusing_proof(self):
        for query in ['anonymous=1', 'subscriber=1']:
            with self.subTest(query=query):
                self.assertEqual(self.cookies(query=query), [])
                cookies = self.cookies(token(1200), query)
                self.assertEqual(len(cookies), 1)
                self.assertIn('Max-Age=0', cookies[0])

    def test_rest_authentication_failure_never_renews(self):
        for status in [401, 403, 500]:
            self.assertEqual(self.cookies(token(1), f'status={status}'), [])


if __name__ == '__main__':
    unittest.main()
