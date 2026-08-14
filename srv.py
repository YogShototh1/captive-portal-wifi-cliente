import http.server, socketserver, time, os
os.chdir(os.path.dirname(os.path.abspath(__file__)))
class H(http.server.SimpleHTTPRequestHandler):
    def do_GET(self):
        if self.path.startswith('/lento'):
            time.sleep(8)                    # a internet do cliente, a 0,1 Mbps
            self.send_response(504); self.end_headers(); return
        if self.path.startswith('/md5.js') or self.path.startswith('/macs.js') \
           or self.path.startswith('/logo.img') or self.path.startswith('/ad.img'):
            self.send_response(404); self.end_headers(); return
        super().do_GET()
    def log_message(self,*a): pass
socketserver.TCPServer.allow_reuse_address=True
socketserver.TCPServer(("127.0.0.1",8877),H).serve_forever()
