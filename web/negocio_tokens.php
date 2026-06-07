<?php
/**
 * Gestión de PCs (máquinas) y tokens de un negocio. Solo admin.
 *   negocio_tokens.php?id=N
 *
 * - Lista los PCs vinculados con su última sincronización.
 * - Genera un token nuevo para un PC (se muestra UNA sola vez).
 * - Permite eliminar un PC (revoca su token).
 */

require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/ui.php';

$usuario = exigir_login();
if (($usuario['rol'] ?? '') !== 'admin') {
    header('Location: panel.php');
    exit;
}

$pdo = obtener_pdo();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$st = $pdo->prepare("SELECT * FROM negocios WHERE id = ?");
$st->execute([$id]);
$negocio = $st->fetch();
if (!$negocio) { header('Location: negocios.php'); exit; }

$tokenNuevo = '';     // se llena solo tras generar uno
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $accion = $_POST['accion'] ?? '';
    if ($accion === 'generar') {
        $nombrePc = trim($_POST['nombre_pc'] ?? '') ?: 'PC';
        $tokenNuevo = bin2hex(random_bytes(24));
        $hash = hash('sha256', $tokenNuevo);
        $pdo->prepare(
            "INSERT INTO maquinas (negocio_id, nombre, token_hash) VALUES (?,?,?)"
        )->execute([$id, $nombrePc, $hash]);
    } elseif ($accion === 'eliminar') {
        $mid = (int)($_POST['maquina_id'] ?? 0);
        $pdo->prepare("DELETE FROM maquinas WHERE id = ? AND negocio_id = ?")
            ->execute([$mid, $id]);
    }
}

$maquinas = $pdo->prepare("SELECT * FROM maquinas WHERE negocio_id = ? ORDER BY id");
$maquinas->execute([$id]);
$maquinas = $maquinas->fetchAll();

function h($v) { return htmlspecialchars((string)($v ?? '')); }
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tokens · <?= h($negocio['nombre']) ?></title>
    <link rel="stylesheet" href="assets/estilo.css">
</head>
<body>
    <?php cabecera_dashboard($usuario, 'negocios'); ?>

    <div class="contenido">
        <div class="cab-acciones">
            <h2>PCs y tokens · <?= h($negocio['nombre']) ?></h2>
            <a class="btn gris" href="negocios.php">Volver</a>
        </div>

        <?php if ($tokenNuevo): ?>
            <div class="panel" style="border-color:#27ae60">
                <h2 style="color:#1a7a3a">Token generado — cópialo ahora</h2>
                <p style="font-size:13px;color:#6c757d">
                    Este token NO se vuelve a mostrar. Pégalo en el
                    <code>config.yaml</code> del PC, en <code>sincronizacion.token</code>.
                </p>
                <div style="background:#0f1b2d;color:#7ee787;padding:14px 16px;
                            border-radius:8px;font-family:Consolas,monospace;
                            font-size:15px;word-break:break-all">
                    <?= h($tokenNuevo) ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="panel">
            <h2>Generar token para un PC</h2>
            <form method="post" class="filtros">
                <input type="hidden" name="accion" value="generar">
                <div class="campo">
                    <label>Nombre del PC (ej. Caja Principal)</label>
                    <input type="text" name="nombre_pc" placeholder="PC Principal" style="min-width:240px">
                </div>
                <div class="campo">
                    <label>&nbsp;</label>
                    <button class="btn verde" type="submit">Generar token</button>
                </div>
            </form>
        </div>

        <div class="panel">
            <h2>PCs vinculados</h2>
            <?php if (!$maquinas): ?>
                <p class="vacio">Aún no hay PCs. Genera un token arriba.</p>
            <?php else: ?>
                <div class="tabla-wrap">
                    <table>
                        <thead><tr>
                            <th>PC</th><th>Última sincronización</th><th>Token</th><th></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($maquinas as $m): ?>
                            <tr>
                                <td><?= h($m['nombre']) ?></td>
                                <td><?= $m['ultima_sync'] ? h($m['ultima_sync']) : '—' ?></td>
                                <td style="color:#8a939e">•••••• (oculto por seguridad)</td>
                                <td>
                                    <form method="post" onsubmit="return confirm('¿Eliminar este PC? Su token dejará de funcionar.')" style="margin:0">
                                        <input type="hidden" name="accion" value="eliminar">
                                        <input type="hidden" name="maquina_id" value="<?= (int)$m['id'] ?>">
                                        <button class="btn sm" type="submit" style="background:#dc3545">Eliminar</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php pie_dashboard(); ?>
</body>
</html>
