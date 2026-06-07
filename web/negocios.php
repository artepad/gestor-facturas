<?php
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/ui.php';

$usuario = exigir_login();
if (($usuario['rol'] ?? '') !== 'admin') {
    header('Location: panel.php');   // sucursal no gestiona negocios
    exit;
}

$pdo = obtener_pdo();
// Negocios + conteo de facturas (no eliminadas) y de máquinas
$negocios = $pdo->query(
    "SELECT n.*,
            (SELECT COUNT(*) FROM facturas f
             WHERE f.negocio_id = n.id AND f.eliminada_en IS NULL) AS n_facturas,
            (SELECT COUNT(*) FROM maquinas m WHERE m.negocio_id = n.id) AS n_maquinas
     FROM negocios n
     ORDER BY n.activo DESC, n.nombre"
)->fetchall();

function h($v) { return htmlspecialchars((string)($v ?? '')); }
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Negocios · Sistema de Gestión</title>
    <link rel="stylesheet" href="assets/estilo.css">
</head>
<body>
    <?php cabecera_dashboard($usuario, 'negocios'); ?>

    <div class="contenido">
        <div class="cab-acciones">
            <h2>Negocios</h2>
            <a class="btn" href="negocio_form.php">+ Crear negocio</a>
        </div>

        <?php if (!$negocios): ?>
            <div class="panel"><p class="vacio">
                Aún no hay negocios. Crea el primero con el botón de arriba.
            </p></div>
        <?php else: ?>
            <div class="grid-negocios">
            <?php foreach ($negocios as $n): ?>
                <div class="negocio-card <?= $n['activo'] ? '' : 'inactivo' ?>">
                    <h3>
                        <?= h($n['nombre']) ?>
                        <?php if (!$n['activo']): ?>
                            <span class="badge-inactivo">inactivo</span>
                        <?php endif; ?>
                    </h3>
                    <div class="rut"><?= $n['rut'] ? 'RUT ' . h($n['rut']) : h($n['slug']) ?></div>

                    <?php if ($n['telefono']): ?>
                        <div class="dato"><span class="ic">📞</span><?= h($n['telefono']) ?></div>
                    <?php endif; ?>
                    <?php if ($n['direccion']): ?>
                        <div class="dato"><span class="ic">📍</span><?= h($n['direccion']) ?></div>
                    <?php endif; ?>
                    <?php if ($n['correo']): ?>
                        <div class="dato"><span class="ic">📧</span><?= h($n['correo']) ?></div>
                    <?php endif; ?>

                    <div class="nfact">
                        <?= (int)$n['n_facturas'] ?> factura(s) ·
                        <?= (int)$n['n_maquinas'] ?> PC(s) vinculado(s)
                    </div>

                    <div class="acciones">
                        <a class="btn sm" href="panel.php?negocio=<?= (int)$n['id'] ?>">Ver facturas</a>
                        <a class="btn sm gris" href="negocio_form.php?id=<?= (int)$n['id'] ?>">Editar</a>
                        <a class="btn sm verde" href="negocio_tokens.php?id=<?= (int)$n['id'] ?>">Tokens (PCs)</a>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php pie_dashboard(); ?>
</body>
</html>
