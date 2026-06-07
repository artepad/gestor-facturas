<?php
require __DIR__ . '/lib/auth.php';
cerrar_sesion();
header('Location: login.php');
exit;
