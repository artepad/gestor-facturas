<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Clientes de fiados (cuenta corriente): datos de contacto, saldo (fiados -
 * abonos) y el historial de movimientos. Sin equivalente previo en
 * lib/pos.php para este CRUD administrativo, así que usa SQL directo — mismo
 * criterio que App\Models\PrecioFV.
 *
 * OJO: el POS (lib/pos.php, registrar_venta) también escribe en `fiados` al
 * cobrar una venta a crédito, y pos_api.php lee `clientes`/saldo para el
 * selector de "Fiado". Ese camino no se toca acá — este Modelo es solo para
 * las pantallas de administración de fiados (fiados.php, cliente.php, etc.).
 */
final class Cliente
{
    public static function conSaldo(array $idsVisibles, int $negocioId = 0, string $texto = ''): array
    {
        if (!$idsVisibles) return [];
        $where = [];
        $params = [];

        $ph = implode(',', array_fill(0, count($idsVisibles), '?'));
        $where[] = "c.negocio_id IN ($ph)";
        array_push($params, ...$idsVisibles);

        if ($negocioId && in_array($negocioId, $idsVisibles, true)) {
            $where[] = 'c.negocio_id = ?';
            $params[] = $negocioId;
        }
        if ($texto !== '') {
            $where[] = '(c.nombre LIKE ? OR c.apellido LIKE ? OR c.telefono LIKE ?)';
            $params[] = "%$texto%"; $params[] = "%$texto%"; $params[] = "%$texto%";
        }
        $where[] = 'c.activo = 1';

        return Database::todos(
            "SELECT c.*, n.nombre AS negocio_nombre,
                    (SELECT COALESCE(SUM(f.monto),0) FROM fiados f WHERE f.cliente_id = c.id)
                  - (SELECT COALESCE(SUM(a.monto),0) FROM abonos a WHERE a.cliente_id = c.id) AS saldo
               FROM clientes c JOIN negocios n ON n.id = c.negocio_id
              WHERE " . implode(' AND ', $where) . "
              ORDER BY saldo DESC, c.nombre, c.apellido",
            $params
        );
    }

    public static function porId(int $id): ?array
    {
        return Database::uno(
            "SELECT c.*, n.nombre AS negocio_nombre
               FROM clientes c JOIN negocios n ON n.id = c.negocio_id
              WHERE c.id = ?",
            [$id]
        );
    }

    public static function crear(array $d): int
    {
        Database::consultar(
            "INSERT INTO clientes (negocio_id, nombre, apellido, telefono, direccion, correo)
             VALUES (?,?,?,?,?,?)",
            [$d['negocio_id'], $d['nombre'], $d['apellido'], $d['telefono'], $d['direccion'], $d['correo']]
        );
        return (int)Database::pdo()->lastInsertId();
    }

    /** Al editar no se permite cambiar de negocio (evita fugas entre negocios). */
    public static function actualizar(int $id, array $d): void
    {
        Database::consultar(
            "UPDATE clientes SET nombre=?, apellido=?, telefono=?, direccion=?, correo=? WHERE id=?",
            [$d['nombre'], $d['apellido'], $d['telefono'], $d['direccion'], $d['correo'], $id]
        );
    }

    public static function eliminar(int $id): void
    {
        Database::consultar('DELETE FROM clientes WHERE id = ?', [$id]);   // cascada borra fiados/abonos
    }

    public static function totales(int $clienteId): array
    {
        $totalFiado = (float)Database::valor('SELECT COALESCE(SUM(monto),0) FROM fiados WHERE cliente_id = ?', [$clienteId]);
        $totalAbono = (float)Database::valor('SELECT COALESCE(SUM(monto),0) FROM abonos WHERE cliente_id = ?', [$clienteId]);
        return ['fiado' => $totalFiado, 'abono' => $totalAbono, 'saldo' => $totalFiado - $totalAbono];
    }

    /** Fiados y abonos del cliente fusionados en una sola línea de tiempo (más reciente primero). */
    public static function movimientos(int $clienteId): array
    {
        $fiados = Database::todos('SELECT * FROM fiados WHERE cliente_id=? ORDER BY fecha DESC, id DESC', [$clienteId]);
        $abonos = Database::todos('SELECT * FROM abonos WHERE cliente_id=? ORDER BY fecha DESC, id DESC', [$clienteId]);

        $movs = [];
        foreach ($fiados as $f) {
            $movs[] = ['tipo' => 'fiado', 'id' => (int)$f['id'], 'fecha' => $f['fecha'],
                       'texto' => $f['descripcion'], 'monto' => (float)$f['monto'], 'creado' => $f['creado_en']];
        }
        foreach ($abonos as $a) {
            $movs[] = ['tipo' => 'abono', 'id' => (int)$a['id'], 'fecha' => $a['fecha'],
                       'texto' => $a['nota'], 'monto' => (float)$a['monto'], 'creado' => $a['creado_en']];
        }
        usort($movs, fn($x, $y) => [$y['fecha'], $y['creado']] <=> [$x['fecha'], $x['creado']]);
        return $movs;
    }

    public static function fiadoPorId(int $id, int $clienteId): ?array
    {
        return Database::uno('SELECT * FROM fiados WHERE id=? AND cliente_id=?', [$id, $clienteId]);
    }

    public static function abonoPorId(int $id, int $clienteId): ?array
    {
        return Database::uno('SELECT * FROM abonos WHERE id=? AND cliente_id=?', [$id, $clienteId]);
    }

    public static function crearFiado(int $clienteId, string $fecha, float $monto, ?string $descripcion): int
    {
        Database::consultar(
            'INSERT INTO fiados (cliente_id, fecha, monto, descripcion) VALUES (?,?,?,?)',
            [$clienteId, $fecha, $monto, $descripcion]
        );
        return (int)Database::pdo()->lastInsertId();
    }

    public static function actualizarFiado(int $id, int $clienteId, string $fecha, float $monto, ?string $descripcion): void
    {
        Database::consultar(
            'UPDATE fiados SET fecha=?, monto=?, descripcion=? WHERE id=? AND cliente_id=?',
            [$fecha, $monto, $descripcion, $id, $clienteId]
        );
    }

    public static function eliminarFiado(int $id, int $clienteId): void
    {
        Database::consultar('DELETE FROM fiados WHERE id=? AND cliente_id=?', [$id, $clienteId]);
    }

    public static function crearAbono(int $clienteId, string $fecha, float $monto, ?string $nota): int
    {
        Database::consultar(
            'INSERT INTO abonos (cliente_id, fecha, monto, nota) VALUES (?,?,?,?)',
            [$clienteId, $fecha, $monto, $nota]
        );
        return (int)Database::pdo()->lastInsertId();
    }

    public static function actualizarAbono(int $id, int $clienteId, string $fecha, float $monto, ?string $nota): void
    {
        Database::consultar(
            'UPDATE abonos SET fecha=?, monto=?, nota=? WHERE id=? AND cliente_id=?',
            [$fecha, $monto, $nota, $id, $clienteId]
        );
    }

    public static function eliminarAbono(int $id, int $clienteId): void
    {
        Database::consultar('DELETE FROM abonos WHERE id=? AND cliente_id=?', [$id, $clienteId]);
    }

    /** Movimientos de un período [desde, hasta] (null = sin límite), orden cronológico (para el estado de cuenta). */
    public static function movimientosPeriodo(int $clienteId, ?string $desde, ?string $hasta): array
    {
        $cond = ''; $args = [$clienteId];
        if ($desde) { $cond .= ' AND fecha >= ?'; $args[] = $desde; }
        if ($hasta) { $cond .= ' AND fecha <= ?'; $args[] = $hasta; }

        $fiados = Database::todos("SELECT fecha, monto, descripcion AS detalle, creado_en FROM fiados WHERE cliente_id = ?$cond", $args);
        $abonos = Database::todos("SELECT fecha, monto, nota AS detalle, creado_en FROM abonos WHERE cliente_id = ?$cond", $args);

        $movs = [];
        foreach ($fiados as $f) {
            $movs[] = ['tipo' => 'fiado', 'fecha' => $f['fecha'], 'detalle' => $f['detalle'],
                       'monto' => (float)$f['monto'], 'creado' => $f['creado_en']];
        }
        foreach ($abonos as $a) {
            $movs[] = ['tipo' => 'abono', 'fecha' => $a['fecha'], 'detalle' => $a['detalle'],
                       'monto' => (float)$a['monto'], 'creado' => $a['creado_en']];
        }
        usort($movs, fn($x, $y) => [$x['fecha'], $x['creado']] <=> [$y['fecha'], $y['creado']]);
        return $movs;
    }

    /** Saldo acumulado antes de la fecha dada (el "saldo anterior" del estado de cuenta). */
    public static function saldoAntesDe(int $clienteId, string $fecha): float
    {
        return (float)Database::valor(
            "SELECT (SELECT COALESCE(SUM(monto),0) FROM fiados WHERE cliente_id=? AND fecha < ?)
                  - (SELECT COALESCE(SUM(monto),0) FROM abonos WHERE cliente_id=? AND fecha < ?)",
            [$clienteId, $fecha, $clienteId, $fecha]
        );
    }
}
