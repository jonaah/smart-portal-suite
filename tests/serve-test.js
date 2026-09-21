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
  let [reqPath, queryStr] = req.url.split('?');
  const isProjekte = reqPath === '/projekte-mit-mir' || (queryStr && queryStr.includes('form=projekte_mit_mir'));

  if (reqPath === '/' || reqPath === '/index.html' || reqPath === '/projekte-mit-mir') {
    const formId = isProjekte ? 'projekte_mit_mir' : 'gebaeude_check';
    const schemaPath = isProjekte ? 'config/forms/projekte-mit-mir.json' : 'config/forms/gebaeude-check.json';
    const spritePath = isProjekte ? 'assets/icons/projekte-mit-mir.svg' : 'assets/icons/gebaeude-check.svg';

    res.writeHead(200, { 'Content-Type': 'text/html; charset=UTF-8' });
    res.end(`<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <title>SPS UI Testbench (${formId})</title>
  <link rel="stylesheet" href="/assets/css/portal-base.css">
  <style>
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f1f5f9; padding: 2rem; }
    .testbench-nav { margin-bottom: 1rem; display: flex; gap: 10px; }
    .testbench-nav a { padding: 6px 12px; background: #fff; border-radius: 6px; text-decoration: none; color: #333; font-size: 13px; font-weight: 500; }
    .testbench-nav a.active { background: #2b224a; color: #fff; }
  </style>
</head>
<body>
  <div class="testbench-nav">
    <a href="/?form=gebaeude_check" class="${!isProjekte ? 'active' : ''}">Gebäude-Check</a>
    <a href="/?form=projekte_mit_mir" class="${isProjekte ? 'active' : ''}">Projekte mit mir</a>
  </div>
  <div class="sps-svg-sprite-storage" style="display:none !important;" aria-hidden="true">
    ${fs.existsSync(path.join(BASE_DIR, 'assets/icons/portal-icons.svg')) ? fs.readFileSync(path.join(BASE_DIR, 'assets/icons/portal-icons.svg'), 'utf-8') : ''}
    ${fs.existsSync(path.join(BASE_DIR, spritePath)) ? fs.readFileSync(path.join(BASE_DIR, spritePath), 'utf-8') : ''}
  </div>
  <div class="sps-form-container" data-sps-form-id="${formId}"></div>

  <script src="/assets/js/osm-autocomplete.js"></script>
  <script src="/assets/js/calculations.js"></script>
  <script>
    window['spsFormData_' + '${formId}'] = {
      ajaxUrl: '#',
      nonce: 'mock-nonce',
      iconsUrl: '/assets/icons/portal-icons.svg',
      schema: ${fs.readFileSync(path.join(BASE_DIR, schemaPath), 'utf-8')}
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
