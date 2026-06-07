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
    'carpeta_pdf' => __DIR__ . '/almacen_pdf',
];
