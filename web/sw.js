/* Service worker de la pantalla de venta (pos.php).
 *
 * REGLA DE ORO: solo se mete con la pantalla de venta y sus archivos. Todo lo
 * demás del sitio (home, facturas, fiados, gastos...) pasa de largo sin tocar,
 * para que un error acá no pueda romper el resto de la web.
 *
 * Estrategia:
 *   - pos.php            red primero, y si no hay internet, la última copia
 *                        guardada (así la caja abre igual sin conexión)
 *   - CSS / íconos / JS  copia guardada primero (son estáticos, van rapidísimo)
 *   - pos_api.php        NUNCA se guarda: cobrar y buscar siempre van a la red
 *   - cualquier otra URL no se toca
 *
 * Ver web/POS_DISENO.md (Etapa 3).
 */

// Subir esta versión invalida la caché anterior y fuerza la actualización.
const VERSION = 'pos-v1';
const ESTATICOS = [
    'assets/estilo.css',
    'assets/pos-icono.svg',
    'manifest.webmanifest',
];

// ---------- Instalación: guarda el CSS y los íconos ----------
self.addEventListener('install', (e) => {
    e.waitUntil(
        caches.open(VERSION)
            .then((c) => c.addAll(ESTATICOS.map((u) => new Request(u, { cache: 'reload' }))))
            // Si algo no se pudo guardar, no se aborta la instalación: la app
            // igual funciona, solo que sin esa pieza en caché.
            .catch(() => {})
            .then(() => self.skipWaiting())
    );
});

// ---------- Activación: borra las cachés viejas ----------
self.addEventListener('activate', (e) => {
    e.waitUntil(
        caches.keys()
            .then((ks) => Promise.all(ks.filter((k) => k !== VERSION).map((k) => caches.delete(k))))
            .then(() => self.clients.claim())
    );
});

/** ¿Es un archivo estático de los que guardamos? */
function esEstatico(url) {
    return /\/assets\/(estilo\.css|pos-icono\.svg|zxing\.min\.js)$/.test(url.pathname)
        || /\/manifest\.webmanifest$/.test(url.pathname);
}

/** ¿Es la pantalla de venta? */
function esPantallaVenta(url) {
    return /\/pos\.php$/.test(url.pathname);
}

self.addEventListener('fetch', (e) => {
    const req = e.request;
    if (req.method !== 'GET') return;              // cobrar y demás: sin tocar

    const url = new URL(req.url);
    if (url.origin !== self.location.origin) return;   // nada de otros sitios

    // El API jamás se cachea: si no hay internet debe fallar de verdad para
    // que la caja lo sepa y guarde la venta en su cola local.
    if (/\/pos_api\.php$/.test(url.pathname)) return;

    if (esEstatico(url)) {
        // Copia guardada primero; si no está, se pide y se guarda.
        e.respondWith(
            caches.match(req).then((hit) => hit || fetch(req).then((res) => {
                const copia = res.clone();
                caches.open(VERSION).then((c) => c.put(req, copia)).catch(() => {});
                return res;
            }))
        );
        return;
    }

    if (esPantallaVenta(url)) {
        // Red primero (para traer las ventas y promos al día); si no hay
        // internet, se abre la última copia guardada.
        e.respondWith(
            fetch(req)
                .then((res) => {
                    if (res && res.ok) {
                        const copia = res.clone();
                        caches.open(VERSION).then((c) => c.put(req, copia)).catch(() => {});
                    }
                    return res;
                })
                .catch(() => caches.match(req).then((hit) => hit || caches.match('pos.php')))
        );
        return;
    }

    // Todo lo demás del sitio: el service worker no interviene.
});
