<?php
/**
 * Helpers del módulo Gastos: categorías y totales del período. Web-nativo (la
 * web es la fuente de verdad). Las funciones reciben los IDs de negocios ya
 * acotados a los visibles del usuario desde el llamador (nunca consultan sin
 * ese filtro).
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
