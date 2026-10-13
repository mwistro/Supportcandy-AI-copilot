const CAI_CACHE = 'cai-shell-v7';
const SHELL_FILES = ['./index.html', './manifest.json'];

// Firebase Messaging: necesario para que las notificaciones lleguen aunque
// el PWA este cerrado o en segundo plano. Mismo Service Worker que el
// cascaron de la app -- Firebase permite compartirlo via importScripts.
importScripts('https://www.gstatic.com/firebasejs/10.14.1/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/10.14.1/firebase-messaging-compat.js');

firebase.initializeApp({
  apiKey: 'YOUR_FIREBASE_API_KEY',
  authDomain: 'your-project.firebaseapp.com',
  projectId: 'your-project-id',
  storageBucket: 'your-project.firebasestorage.app',
  messagingSenderId: 'YOUR_SENDER_ID',
  appId: 'YOUR_APP_ID'
});

const messaging = firebase.messaging();

// Se dispara cuando llega un push y el PWA no esta en primer plano: muestra
// la notificacion a nivel de sistema operativo.
messaging.onBackgroundMessage((payload) => {
  const notification = payload.notification || {};
  self.registration.showNotification(notification.title || 'AI Ticket Copilot', {
    body: notification.body || '',
    icon: './icon-192.png',
    data: { url: (payload.fcmOptions && payload.fcmOptions.link) || './' }
  });
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const url = (event.notification.data && event.notification.data.url) || './';
  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windowClients) => {
      for (const client of windowClients) {
        if ('focus' in client) return client.focus();
      }
      if (clients.openWindow) return clients.openWindow(url);
    })
  );
});

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CAI_CACHE).then((cache) => cache.addAll(SHELL_FILES))
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CAI_CACHE).map((k) => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

// Network-first para el cascaron (HTML/manifest): siempre intenta traer la
// version mas nueva del servidor, y solo cae al cache si no hay conexion.
// Las llamadas a /wp-json/ nunca pasan por aqui -- van directo a la red.
self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);
  if (url.pathname.includes('/wp-json/')) {
    return;
  }
  event.respondWith(
    fetch(event.request)
      .then((response) => {
        const copy = response.clone();
        caches.open(CAI_CACHE).then((cache) => cache.put(event.request, copy));
        return response;
      })
      .catch(() => caches.match(event.request))
  );
});
