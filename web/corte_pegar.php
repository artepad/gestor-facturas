<?php
/**
 * Ingreso manual de un corte de Eleventa: el admin pega el contenido del
 * correo (y opcionalmente el asunto) y se procesa con el mismo parser que
 * usa el cron. Sirve de respaldo si el correo automático falla y para
 * probar el módulo sin tener la casilla configurada.
 */

require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/ui.php';
require __DIR__ . '/lib/eleventa.php';

$usuario = exigir_permiso('ingresos');
$pdo = obtener_pdo();

$negocios = negocios_visibles($usuario);
$error = '';
$exito = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $negocioId = (int)($_POST['negocio_id'] ?? 0);
    $asunto    = trim($_POST['asunto'] ?? '');
    $cuerpo    = trim($_POST['cuerpo'] ?? '');

    if (!$negocioId || !puede_ver_negocio($usuario, $negocioId)) {
        $error = 'Elige un negocio válido.';
    } elseif ($cuerpo === '') {
        $error = 'Pega el contenido del correo del corte.';
    } else {
        // Guardamos el correo crudo (mismo flujo que el cron). El hash del
        // contenido evita duplicar si se pega dos veces el mismo correo.
        $msgId = 'manual-' . sha1($negocioId . '|' . $cuerpo);
        $st = $pdo->prepare("SELECT id FROM correos_corte WHERE message_id = ?");
        $st->execute([$msgId]);
        $correoId = $st->fetchColumn();
        if ($correoId) {
            $pdo->prepare("UPDATE correos_corte SET asunto = ?, cuerpo = ?, estado = 'pendiente', error = NULL WHERE id = ?")
                ->execute([$asunto ?: null, $cuerpo, $correoId]);
        } else {
            $pdo->prepare(
                "INSERT INTO correos_corte (negocio_id, message_id, asunto, cuerpo, origen, recibido_en)
                 VALUES (?, ?, ?, ?, 'manual', NOW())"
            )->execute([$negocioId, $msgId, $asunto ?: null, $cuerpo]);
            $correoId = (int)$pdo->lastInsertId();
        }

        $st = $pdo->prepare("SELECT * FROM correos_corte WHERE id = ?");
        $st->execute([$correoId]);
        $correo = $st->fetch();
        $correo['negocio_id'] = $negocioId;   // en manual el negocio lo elige el admin

        $corteId = procesar_correo_corte($pdo, $correo);
        if ($corteId) {
            header("Location: corte.php?id=$corteId");
            exit;
        }
        $st = $pdo->prepare("SELECT error FROM correos_corte WHERE id = ?");
        $st->execute([$correoId]);
        $error = 'No se pudo leer el corte: ' . ($st->fetchColumn() ?: 'error desconocido');
    }
}
?>
<?php cabecera_dashboard($usuario, 'ingresos', 'Registrar corte'); ?>
    <div class="contenido">
        <div class="form-pagina form-pagina-ancha">
            <div class="cab-acciones">
                <h2>Registrar corte manualmente</h2>
                <a class="btn gris" href="ingresos.php">Volver</a>
            </div>
            <?php if ($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>
            <div class="panel">
                <form method="post">
                    <div class="campo">
                        <label>Negocio *</label>
                        <select name="negocio_id" required>
                            <?php foreach ($negocios as $n): ?>
                                <option value="<?= (int)$n['id'] ?>"
                                    <?= (int)($_POST['negocio_id'] ?? 0) === (int)$n['id'] ? 'selected' : '' ?>>
                                    <?= h($n['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="campo">
                        <label>Asunto del correo (opcional)</label>
                        <p class="campo-ayuda">Del asunto se obtiene el nombre de la caja (ej. "Caja Principal").</p>
                        <input type="text" name="asunto" value="<?= h($_POST['asunto'] ?? '') ?>"
                               placeholder="Corte del turno de ... de la Caja Principal">
                    </div>
                    <div class="campo">
                        <label>Contenido del correo *</label>
                        <p class="campo-ayuda">Abre el correo del corte, selecciona todo su contenido (Ctrl+A), cópialo y pégalo aquí.</p>
                        <textarea name="cuerpo" rows="14" required
                                  placeholder="Corte del turno&#10;04/Jun/2026 9:53 am al 05/Jun/2026 1:05 am&#10;Cajero : ..."><?= h($_POST['cuerpo'] ?? '') ?></textarea>
                    </div>
                    <div class="form-acciones">
                        <button class="btn" type="submit">Registrar corte</button>
                        <a class="btn gris" href="ingresos.php">Cancelar</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php pie_dashboard(); ?>
