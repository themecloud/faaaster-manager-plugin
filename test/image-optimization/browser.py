"""Exercise real PHP-rewritten HTML in a fresh headless Chrome profile, against local image fixtures."""
import argparse
import http.server
import json
import os
from pathlib import Path
import re
import select
import subprocess
import struct
import tempfile
import threading
import time
from urllib.parse import unquote, urlsplit


class Chrome:
    """Use Chrome's native DevTools pipe, so IntersectionObserver runs in real time."""
    def __init__(self, executable, profile):
        read_from_chrome, chrome_write = os.pipe()
        chrome_read, write_to_chrome = os.pipe()

        def pipes():
            os.dup2(chrome_read, 3)
            os.dup2(chrome_write, 4)

        self.process = subprocess.Popen([
            executable, '--headless=new', '--disable-gpu', '--no-first-run',
            '--no-default-browser-check', '--disable-background-networking', '--disable-extensions',
            f'--user-data-dir={profile}', '--remote-debugging-pipe'
        ], preexec_fn=pipes, close_fds=False, stdin=subprocess.DEVNULL,
            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        os.close(chrome_write)
        os.close(chrome_read)
        self.input = write_to_chrome
        self.output = read_from_chrome
        self.buffer = b''
        self.sequence = 0

    def call(self, method, params=None, session=None):
        self.sequence += 1
        command = {'id': self.sequence, 'method': method, 'params': params or {}}
        if session:
            command['sessionId'] = session
        os.write(self.input, json.dumps(command).encode() + b'\0')
        deadline = time.monotonic() + 10
        while time.monotonic() < deadline:
            if b'\0' not in self.buffer:
                ready, _, _ = select.select([self.output], [], [], max(0, deadline - time.monotonic()))
                if not ready:
                    break
                chunk = os.read(self.output, 65536)
                if not chunk:
                    break
                self.buffer += chunk
                continue
            message, self.buffer = self.buffer.split(b'\0', 1)
            response = json.loads(message)
            if response.get('id') == self.sequence:
                if 'error' in response:
                    raise AssertionError(response['error'])
                return response.get('result', {})
        raise AssertionError(f'Chrome DevTools timeout: {method}')

    def fixture(self, url, dpr, width):
        target = self.call('Target.createTarget', {'url': 'about:blank'})['targetId']
        session = self.call('Target.attachToTarget', {'targetId': target, 'flatten': True})['sessionId']
        self.call('Page.enable', session=session)
        self.call('Emulation.setDeviceMetricsOverride', {'width': width, 'height': 900,
                                                       'deviceScaleFactor': dpr, 'mobile': False}, session)
        self.call('Page.navigate', {'url': url}, session)
        deadline = time.monotonic() + 8
        while time.monotonic() < deadline:
            result = self.call('Runtime.evaluate', {'expression': "document.documentElement.getAttribute('data-test-result')",
                                                   'returnByValue': True}, session)
            value = result.get('result', {}).get('value')
            if value:
                return json.loads(unquote(value))
            time.sleep(0.05)
        raise AssertionError('Chrome did not finish the fixture')

    def close(self):
        try:
            os.write(self.input, json.dumps({'id': 99999, 'method': 'Browser.close'}).encode() + b'\0')
            self.process.wait(timeout=10)
        finally:
            if self.process.poll() is None:
                self.process.terminate()
                self.process.wait(timeout=5)
            os.close(self.input)
            os.close(self.output)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--chrome', required=True, help='Chrome/Chromium executable')
    parser.add_argument('--php', default='php')
    parser.add_argument('--case', action='append', help='Run only the named fixture(s)')
    args = parser.parse_args()
    plugin = Path(__file__).resolve().parents[2]
    with tempfile.TemporaryDirectory(prefix='faaaster-browser-') as temporary:
        export = Path(temporary) / 'fixtures.json'
        env = dict(os.environ, IMAGE_TEST_EXPORT=str(export), TMPDIR=temporary)
        subprocess.run([args.php, str(plugin / 'test/image-optimization/run.php'), 'active'],
                       env=env, check=True, timeout=30)
        data = json.loads(export.read_text())
        uploads = Path(data['root']) / 'uploads'
        requests = []
        failures = []
        request_lock = threading.Lock()

        class Handler(http.server.BaseHTTPRequestHandler):
            protocol_version = 'HTTP/1.1'

            def log_message(self, *_):
                pass

            def send_error(self, code, message=None, explain=None):
                if '/wp-content/uploads/' in self.path:
                    with request_lock:
                        failures.append((code, self.path))
                super().send_error(code, message, explain)

            def do_GET(self):
                path = urlsplit(self.path).path
                with request_lock:
                    requests.append(path)
                if path.startswith('/fixture/'):
                    name = path.split('/')[-1]
                    fixture = data['fixtures'][name]
                    page = fixture['html'].replace('https://client.test',
                                                         f'http://127.0.0.1:{self.server.server_port}')
                    script = r'''<script>
                    let before=[];
                    setTimeout(()=>{
                        before=performance.getEntriesByType('resource').map(e=>e.name)
                          .filter(u=>u.includes('/_faaaster/images/')||u.includes('/wp-content/uploads/'));
                        if(location.pathname.includes('background-lazy')){window.scrollTo(0,3100);}
                        for(const node of document.querySelectorAll('[data-bg]')){
                            const value=node.getAttribute('data-bg');
                            node.style.backgroundImage=value.startsWith('url(')?value:'url("'+value+'")';
                        }
                    },100);
                    setTimeout(()=>{
                        const image=document.querySelector('img');
                        const bg=document.querySelector('[data-faaaster-bg]');
                        document.documentElement.setAttribute('data-test-result',encodeURIComponent(JSON.stringify({
                            before, dpr:devicePixelRatio, width:innerWidth,
                            src:image?image.getAttribute('src'):null,
                            currentSrc:image?image.currentSrc:null,
                            background:bg?getComputedStyle(bg).backgroundImage:null,
                            classes:bg?bg.className:null, inline:bg?bg.getAttribute('style'):null,
                            scroll:scrollY, top:bg?bg.getBoundingClientRect().top:null
                        })));
                    },500);
                    </script>'''
                    self.send_response(200)
                    self.send_header('Content-Type', 'text/html; charset=utf-8')
                    if fixture['link']:
                        self.send_header('Link', fixture['link'][6:].replace('https://client.test', f'http://127.0.0.1:{self.server.server_port}'))
                    body = page.replace('</body>', script + '</body>').encode()
                    self.send_header('Content-Length', str(len(body)))
                    self.end_headers()
                    self.wfile.write(body)
                    return
                marker = '/wp-content/uploads/'
                if marker not in path:
                    self.send_error(404)
                    return
                file = (uploads / unquote(path.split(marker, 1)[1])).resolve()
                if not file.is_relative_to(uploads) or not file.is_file():
                    self.send_error(404)
                    return
                if path.startswith('/_faaaster/'):
                    variant = re.fullmatch(r'/_faaaster/images/v1/webp/q80/w(\d+)/m(\d+)-s(\d+)/wp-content/uploads/.+', path)
                    if not variant or len(path) > 1024:
                        self.send_error(404)
                        return
                    width, mtime, size = map(int, variant.groups())
                    physical_width = struct.unpack('>I', file.read_bytes()[16:20])[0]
                    if width not in data['widths'] or width > physical_width or mtime != int(file.stat().st_mtime) or size != file.stat().st_size:
                        self.send_error(404)
                        return
                self.send_response(200)
                # The test simulates image delivery; production encoding is covered by the shared-service tests.
                self.send_header('Content-Type', 'image/png')
                self.send_header('Content-Length', str(file.stat().st_size))
                self.end_headers()
                self.wfile.write(file.read_bytes())

        server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        cases = [('img', 1, 1280), ('img', 2, 1280), ('img', 4, 1280),
                 ('img-density', 1, 1280), ('img-density', 3, 1280), ('picture', 2, 1280),
                 ('background', 1, 1280), ('background', 1.25, 1280), ('background', 1.5, 1280),
                 ('background', 2, 1280), ('background', 2.5, 1280), ('background', 3, 1280),
                 ('background', 5, 1280), ('background-groups', 2, 400), ('background-groups', 2, 1280),
                 ('background-cover', 1, 390), ('background-cover', 3, 390),
                 ('background-important', 2, 1280), ('background-overridden', 2, 1280),
                 ('background-lazy', 2, 1280), ('background-lazy-important', 2, 1280),
                 ('background-layers', 2, 1280), ('data-bg', 2, 1280), ('data-bg-layers', 2, 1280),
                 ('data-bg-dormant', 2, 1280), ('background-cold', 2, 1280)]
        try:
            for name, dpr, width in cases:
                if args.case and name not in args.case:
                    continue
                with request_lock:
                    requests.clear()
                    failures.clear()
                profile = Path(temporary) / f'chrome-{name}-{dpr}-{width}'
                chrome = Chrome(args.chrome, profile)
                try:
                    observed = chrome.fixture(f'http://127.0.0.1:{server.server_port}/fixture/{name}', dpr, width)
                finally:
                    chrome.close()
                with request_lock:
                    assets = [p for p in requests if '/wp-content/uploads/' in p]
                    assert not failures, (name, failures)
                assert assets, (name, 'no image requested', observed)
                assert len(assets) == len(set(assets)), (name, 'duplicate image downloads', assets)
                assert all(p.startswith('/_faaaster/images/') for p in assets), (name, 'original fetched', assets)
                if name.startswith('img') or name == 'picture':
                    assert '/wp-content/uploads/' in observed['src'] and '/_faaaster/' not in observed['src'], observed
                    assert '/_faaaster/images/' in observed['currentSrc'], observed
                elif name == 'background-cold':
                    assert '/w0/' in observed['background'], observed
                else:
                    expected = {1: 480, 1.25: 640, 1.5: 768, 2: 960, 2.5: 1280, 3: 1536, 5: 1536}[dpr]
                    if name == 'background-groups' and width == 400:
                        expected = 640
                    if name == 'background-cover':
                        expected = 1536 if dpr == 1 else 0
                    assert f'/w{expected}/' in observed['background'], observed
                    if name in ('data-bg-dormant', 'background-lazy', 'background-lazy-important'):
                        assert not observed['before'], ('data-bg loaded before its loader', observed)
                    if name.endswith('layers'):
                        assert len(set(assets)) == 2, (name, assets)
                    else:
                        assert len(set(assets)) == 1, ('preload/CSS mismatch', name, assets)
                print(f'OK Chrome {name}, viewport {width}, DPR {dpr}: {len(set(assets))} variant(s), no original')
        finally:
            server.shutdown()
            thread.join(timeout=5)
            server.server_close()


if __name__ == '__main__':
    main()
