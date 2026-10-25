/* MyAduan portal service worker: push notifications. Served from the portal path so its scope is the portal. */
'use strict';
const ICON = '__ICON__';

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));

self.addEventListener('push', (e) => {
  let d = {};
  try { d = e.data ? e.data.json() : {}; } catch (_) { d = { body: e.data ? e.data.text() : '' }; }
  const title = d.title || 'MyAduan';
  e.waitUntil(Promise.all([
    self.registration.showNotification(title, {
      body: d.body || '', icon: ICON, badge: ICON, tag: d.tag || undefined, renotify: !!d.tag,
      data: { url: d.url || self.registration.scope },
    }),
    // Open portal windows refresh their bell.
    self.clients.matchAll({ type: 'window' }).then((list) => list.forEach((c) => c.postMessage({ type: 'myaduan-push' }))),
  ]));
});

self.addEventListener('notificationclick', (e) => {
  e.notification.close();
  const url = (e.notification.data && e.notification.data.url) || self.registration.scope;
  e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
    for (const c of list) {
      if (c.url.startsWith(self.registration.scope)) {
        return c.focus().then((w) => (w && 'navigate' in w ? w.navigate(url) : w));
      }
    }
    return self.clients.openWindow(url);
  }));
});
