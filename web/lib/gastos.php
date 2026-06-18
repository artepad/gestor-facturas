<?php
/**
 * Helpers del módulo Gastos: categorías, totales del período y la generación
 * idempotente de los gastos fijos del mes. Web-nativo (la web es la fuente de
 * verdad). Las funciones reciben los IDs de negocios ya acotados a los visibles
 * del usuario desde el llamador (nunca consultan sin ese filtro).
 */

/** Lista de categorías de gasto (para selects y CRUD). */
function categorias_gasto(PDO $pdo, bool $soloActivas = true): array
{
    $sql = "SELECT * FROM categorias_gasto"
         . ($soloActivas ? " WHERE activo = 1" : "")
         . " ORDER BY orden, nombre";
    return $pdo->query($sql)->fetchAll();
}

/** Total de gastos del período sobre los negocios dados. */
function gastos_total(PDO $pdo, array $ids, string $desde, string $hasta): float
{
    if (!$ids) return 0.0;
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare(
        "SELECT COALESCE(SUM(monto),0) FROM gastos
         WHERE negocio_id IN ($ph) AND fecha BETWEEN ? AND ?"
    );
    $st->execute([...$ids, $desde, $hasta]);
    return (float)$st->fetchColumn();
}

/** Desglose de gastos por categoría en el período (mayor a menor). */
function gastos_por_categoria(PDO $pdo, array $ids, string $desde, string $hasta): array
{
    if (!$ids) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare(
        "SELECT cg.nombre, COALESCE(SUM(g.monto),0) total, COUNT(*) n
         FROM gastos g JOIN categorias_gasto cg ON cg.id = g.categoria_id
         WHERE g.negocio_id IN ($ph) AND g.fecha BETWEEN ? AND ?
         GROUP BY g.categoria_id, cg.nombre ORDER BY total DESC"
    );
    $st->execute([...$ids, $desde, $hasta]);
    return $st->fetchAll();
}

/** Mapa negocio_id => total de gastos del período (para el Home). */
function gastos_por_negocio(PDO $pdo, array $ids, string $desde, string $hasta): array
{
    if (!$ids) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare(
        "SELECT negocio_id, COALESCE(SUM(monto),0) total FROM gastos
         WHERE negocio_id IN ($ph) AND fecha BETWEEN ? AND ?
         GROUP BY negocio_id"
    );
    $st->execute([...$ids, $desde, $hasta]);
    $mapa = [];
    foreach ($st as $r) $mapa[(int)$r['negocio_id']] = (float)$r['total'];
    return $mapa;
}

/**
 * Genera los gastos del mes a partir de las plantillas fijas activas de un
 * negocio. Idempotente: solo crea el gasto si esa plantilla aún no generó uno
 * en el mes indicado. Devuelve cuántos gastos creó.
 *
 * @param string $mes primer día del mes objetivo (YYYY-MM-01)
 */
function registrar_gastos_fijos(PDO $pdo, int $negocioId, string $mes, ?int $usuarioId): int
{
    $inicio = date('Y-m-01', strtotime($mes));
    $fin    = date('Y-m-t',  strtotime($mes));

    $st = $pdo->prepare("SELECT * FROM gastos_fijos WHERE negocio_id = ? AND activo = 1");
    $st->execute([$negocioId]);
    $plantillas = $st->fetchAll();
    if (!$plantillas) return 0;

    $existe = $pdo->prepare(
        "SELECT COUNT(*) FROM gastos
         WHERE gasto_fijo_id = ? AND fecha BETWEEN ? AND ?"
    );
    $insertar = $pdo->prepare(
        "INSERT INTO gastos (negocio_id, categoria_id, fecha, monto, descripcion, gasto_fijo_id, creado_por)
         VALUES (?,?,?,?,?,?,?)"
    );

    $creados = 0;
    foreach ($plantillas as $p) {
        $existe->execute([(int)$p['id'], $inicio, $fin]);
        if ((int)$existe->fetchColumn() > 0) continue;   // ya generado este mes

        // Fecha = día sugerido del mes, acotado a los días reales del mes.
        $dia = (int)($p['dia_mes'] ?? 0);
        $maxDia = (int)date('t', strtotime($mes));
        $dia = $dia >= 1 && $dia <= $maxDia ? $dia : 1;
        $fecha = date('Y-m-', strtotime($mes)) . str_pad((string)$dia, 2, '0', STR_PAD_LEFT);

        $insertar->execute([
            $negocioId, (int)$p['categoria_id'], $fecha,
            $p['monto_estimado'], $p['descripcion'], (int)$p['id'], $usuarioId,
        ]);
        $creados++;
    }
    return $creados;
}
