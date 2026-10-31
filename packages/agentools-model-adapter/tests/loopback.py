#!/usr/bin/env python3
"""One focused native HTTP test file against disposable verified loopback TLS."""
from http.server import ThreadingHTTPServer, BaseHTTPRequestHandler
from pathlib import Path
import json
import os
import ssl
import subprocess
import sys
import tempfile
import threading
import time

ROOT = Path(__file__).resolve().parents[3]
class Handler(BaseHTTPRequestHandler):
    def log_message(self, *args): pass
    def do_POST(self):
        body = self.rfile.read(int(self.headers.get('content-length', '0')))
        if self.path == '/redirect':
            self.send_response(302)
            self.send_header('Location', '/must-not-follow')
            self.send_header('Content-Length', '0')
            self.end_headers()
            return
        if self.path == '/messages':
            request = json.loads(body)
            message = {'id':'msg-loopback','type':'message','role':'assistant','model':'loopback-model','content':[{'type':'text','text':'loopback'}],'stop_reason':'end_turn','usage':{'input_tokens':4,'output_tokens':2}}
            if request['stream']:
                start = dict(message, content=[], stop_reason=None, usage={'input_tokens':4})
                events = [{'type':'message_start','message':start},
                          {'type':'content_block_start','index':0,'content_block':{'type':'text','text':''}},
                          {'type':'content_block_delta','index':0,'delta':{'type':'text_delta','text':'loopback'}},
                          {'type':'content_block_stop','index':0},
                          {'type':'message_delta','delta':{'stop_reason':'end_turn'},'usage':{'output_tokens':2}},
                          {'type':'message_stop'}]
                payload = ''.join('event: '+e['type']+'\n'+'data: '+json.dumps(e)+'\n\n' for e in events).encode()
            else: payload = json.dumps(message).encode()
            self.send_response(200); self.send_header('request-id','req-loopback');self.send_header('Content-Length',str(len(payload)));self.end_headers()
            try:
                self.wfile.write(payload[:127]);self.wfile.flush();time.sleep(0.05);self.wfile.write(payload[127:]);self.wfile.flush()
            except (BrokenPipeError, ConnectionResetError, ssl.SSLError): pass
            return
        self.send_response(200)
        self.send_header('request-id', 'req-loopback')
        self.send_header('Content-Type', 'application/octet-stream')
        self.send_header('Content-Length', '9')
        self.end_headers()
        try:
            self.wfile.write(b'first'); self.wfile.flush()
            time.sleep(2 if self.path == '/stall' else 0.15)
            self.wfile.write(b'last'); self.wfile.flush()
        except (BrokenPipeError, ConnectionResetError, ssl.SSLError): pass

def main():
    with tempfile.TemporaryDirectory(prefix='agentools_model_tls_') as folder:
        folder = Path(folder); cert, key = folder/'cert.pem', folder/'key.pem'
        generation = subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1',
            '-subj','/CN=localhost','-addext','subjectAltName=DNS:localhost,IP:127.0.0.1',
            '-keyout',str(key),'-out',str(cert)], capture_output=True, text=True)
        if generation.returncode:
            print(generation.stderr, file=sys.stderr); return generation.returncode
        server = ThreadingHTTPServer(('127.0.0.1',0), Handler)
        context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER); context.load_cert_chain(cert,key)
        server.socket = context.wrap_socket(server.socket, server_side=True)
        thread = threading.Thread(target=server.serve_forever, daemon=True); thread.start()
        try:
            env = dict(os.environ, AGENTOOLS_MODEL_TEST_TLS_URL=f'https://localhost:{server.server_port}', AGENTOOLS_MODEL_TEST_CA=str(cert))
            process = subprocess.run(['php','packages/agentools-model-adapter/tests/run.php','curl'], cwd=ROOT,env=env)
            return process.returncode
        finally:
            server.shutdown(); server.server_close(); thread.join()

if __name__ == '__main__': sys.exit(main())
