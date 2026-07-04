/* 912 Console service worker — minimal, install-only.
   Deliberately does NOT cache index.php or /api responses, so auth state and
   live Zoho data always stay fresh. Its job is just to make the app installable
   and give a tiny offline fallback for navigations. */
const OFFLINE_HTML =
  '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' +
  '<style>body{font-family:Inter,system-ui,sans-serif;background:#0F1B2D;color:#E7EDF5;display:grid;place-items:center;height:100vh;margin:0;text-align:center}' +
  'div{max-width:280px}b{color:#F56F00}</style>' +
  '<div><h2><b>912</b> Console</h2><p>You appear to be offline. Reconnect and reopen the app.</p></div>';

self.addEventListener('install', (e) => { self.skipWaiting(); });
self.addEventListener('activate', (e) => { e.waitUntil(self.clients.claim()); });

self.addEventListener('fetch', (e) => {
  const req = e.request;
  // Only intervene for top-level navigations; everything else uses the network as normal.
  if (req.mode === 'navigate') {
    e.respondWith(fetch(req).catch(() =>
      new Response(OFFLINE_HTML, { headers: { 'Content-Type': 'text/html' } })
    ));
  }
});
