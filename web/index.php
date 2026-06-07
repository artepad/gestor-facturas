<?php
/**
 * Raiz de admin.minimark.cl: manda al panel si hay sesion, o al login.
 */
require __DIR__ . '/lib/auth.php';
header('Location: ' . (usuario_actual() ? 'panel.php' : 'login.php'));
exit;
