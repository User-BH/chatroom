/**
 * Service Worker
 * فقط فایل‌های ایستای برنامه ذخیره می‌شوند؛ صفحات و پاسخ API که شامل پیام و
 * اطلاعات کاربر هستند هرگز در حافظه دستگاه نگه داشته نمی‌شوند.
 * با هر تغییر فایل‌های ایستا، شماره نسخه بالا برود.
 */
'use strict';

const CACHE_NAME = 'chatroom-static-v3';

const STATIC_FILES = [
    'offline.html',
    'manifest.webmanifest',
    'assets/css/fonts.css',
    'assets/css/chat.css',
    'assets/js/chat-app.js',
    'assets/images/icon-192.png',
    'assets/images/icon-512.png',
    'assets/fonts/Vazirmatn-Regular.woff2',
    'assets/fonts/Vazirmatn-Medium.woff2',
    'assets/fonts/Vazirmatn-SemiBold.woff2',
    'assets/fonts/Vazirmatn-Bold.woff2'
];

self.addEventListener('install', function (event) {
    event.waitUntil(caches.open(CACHE_NAME).then(function (cache) {
        return cache.addAll(STATIC_FILES);
    }).then(function () {
        return self.skipWaiting();
    }));
});

self.addEventListener('activate', function (event) {
    event.waitUntil(caches.keys().then(function (names) {
        return Promise.all(names.filter(function (name) {
            return name !== CACHE_NAME;
        }).map(function (name) {
            return caches.delete(name);
        }));
    }).then(function () {
        return self.clients.claim();
    }));
});

self.addEventListener('fetch', function (event) {
    const request = event.request;
    if (request.method !== 'GET') {
        return;
    }
    const url = new URL(request.url);
    if (url.origin !== self.location.origin) {
        return;
    }

    // صفحه از شبکه می‌آید؛ فقط وقتی سرور در دسترس نیست صفحه آفلاین نشان داده می‌شود
    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(function () {
            return caches.match('offline.html');
        }));
        return;
    }

    if (url.pathname.indexOf('/assets/') !== -1) {
        event.respondWith(caches.match(request, { ignoreSearch: true }).then(function (cached) {
            const network = fetch(request).then(function (response) {
                if (response.ok) {
                    const copy = response.clone();
                    caches.open(CACHE_NAME).then(function (cache) {
                        cache.put(request, copy);
                    });
                }
                return response;
            });
            return cached || network;
        }));
    }
});
