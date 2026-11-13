<?php
/**
 * Configuracion del servidor. COPIA este archivo como "config.php" y pon
 * tus datos reales. config.php NO se sube a GitHub (esta en .gitignore).
 */

return [
    // --- Conexion a la base de datos MySQL (datos de cPanel) ---
    'db' => [
        'host'    => 'localhost',                 // en HostGator casi siempre localhost
        'nombre'  => 'migue492_minimark',
        'usuario' => 'migue492_admin',
        'clave'   => 'PON_AQUI_TU_PASSWORD',      // la que creaste en cPanel
        'charset' => 'utf8mb4',
    ],

    // --- Clave para correr setup.php una sola vez (invéntala, larga) ---
    // Se usa asi:  https://admin.minimark.cl/setup.php?key=ESTA_CLAVE
    // Borra setup.php despues de usarlo.
    'setup_key' => 'CAMBIA_ESTA_CLAVE_POR_UNA_LARGA_Y_SECRETA',

    // --- Carpeta donde se guardan los PDF subidos (relativa a este archivo) ---
    // Estructura: documentos/facturas/{slug del negocio}/{año}/{mes}/{uuid}.pdf
    'carpeta_pdf' => __DIR__ . '/documentos/facturas',

    // --- Casilla que recibe los cortes de caja de Eleventa (módulo Ingresos) ---
    // Eleventa de cada negocio envía su corte a esta casilla; procesar_cortes.php
    // (cron) la lee por IMAP. Si dejas 'usuario' vacío, la lectura IMAP se omite
    // (los cortes se pueden pegar a mano igual).
    //
    // Según el proveedor de correo, el 'host' cambia:
    //   - Titan (HostGator):   imap.titan.email
    //   - Correo del cPanel:   mail.minimark.cl
    'cortes_imap' => [
        'host'    => 'imap.titan.email',       // Titan Básico de HostGator
        'puerto'  => 993,                      // IMAP con SSL
        'usuario' => '',                       // ej. cortes@minimark.cl
        'clave'   => '',
        'carpeta' => 'INBOX',
    ],

    // --- IA para "Nueva factura" (subida directa en la web) ---
    // Misma cuenta/clave que usa el programa de escritorio (ver .env en la
    // raíz del repo, ANTHROPIC_API_KEY), solo que acá la web la llama directo
    // por su cuenta vía curl. Ver web/lib/factura_ia.php.
    'anthropic' => [
        'api_key' => 'PON_AQUI_TU_ANTHROPIC_API_KEY',
        'modelo'  => 'claude-haiku-4-5-20251001',
    ],
];
