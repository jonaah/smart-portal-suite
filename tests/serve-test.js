/**
 * Smart Portal Suite - Local Test Server (Node.js)
 *
 * NUR für Layout, Slider-Verhalten und UI-Schrittwechsel (ohne WP-Abhängigkeiten).
 * Starten mit: node tests/serve-test.js
 */

const http = require('http');
const fs = require('fs');
const path = require('path');

const PORT = process.env.PORT || 3000;
const BASE_DIR = path.resolve(__dirname, '..');

const MIME_TYPES = {
  '.html': 'text/html; charset=UTF-8',
  '.css': 'text/css; charset=UTF-8',
  '.js': 'application/javascript; charset=UTF-8',
  '.json': 'application/json; charset=UTF-8',
  '.svg': 'image/svg+xml',
};

const server = http.createServer((req, res) => {
  let reqPath = req.url.split('?')[0];

  if (reqPath === '/' || reqPath === '/index.html') {
    res.writeHead(200, { 'Content-Type': 'text/html; charset=UTF-8' });
    res.end(`<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <title>SPS UI Testbench</title>
  <link rel="stylesheet" href="/assets/css/portal-base.css">
  <style>
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f1f5f9; padding: 2rem; }
  </style>
</head>
<body>
  <h1>Smart Portal Suite - Testbench</h1>
  <div class="sps-form-container" data-sps-form-id="gebaeude_check"></div>

  <script src="/assets/js/osm-autocomplete.js"></script>
  <script src="/assets/js/calculations.js"></script>
  <script>
    window.spsFormData_gebaeude_check = {
      ajaxUrl: '#',
      nonce: 'mock-nonce',
      iconsUrl: '/assets/icons/portal-icons.svg',
      schema: ${fs.readFileSync(path.join(BASE_DIR, 'config/forms/gebaeude-check.json'), 'utf-8')}
    };
  </script>
  <script src="/assets/js/form-engine.js"></script>
</body>
</html>`);
    return;
  }

  const filePath = path.join(BASE_DIR, reqPath);
  if (!filePath.startsWith(BASE_DIR)) {
    res.writeHead(403);
    res.end('Forbidden');
    return;
  }

  fs.stat(filePath, (err, stats) => {
    if (err || !stats.isFile()) {
      res.writeHead(404, { 'Content-Type': 'text/plain' });
      res.end('404 Not Found');
      return;
    }

    const ext = path.extname(filePath).toLowerCase();
    const mime = MIME_TYPES[ext] || 'application/octet-stream';
    res.writeHead(200, { 'Content-Type': mime });
    fs.createReadStream(filePath).pipe(res);
  });
});

server.listen(PORT, () => {
  console.log(`[SPS Test Server] Läuft unter http://localhost:${PORT}`);
});
