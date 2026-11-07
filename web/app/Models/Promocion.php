<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Promociones automáticas del POS ("4 x $1.000"). Corte 2a de la Fase 6:
 * solo lo que necesita la pantalla para mostrarlas al instante mientras se
 * arma la venta. El servidor igual las recalcula al cobrar (eso vive en
 * pos_api.php, corte 2b) — nunca se confía en lo que calculó el navegador.
 *
 * El CRUD de abajo (crear/editar/eliminar/listar) se agregó en el corte de
 * la pantalla promociones.php (menor riesgo: solo son filas de catálogo,
 * no dinero en movimiento).
 */
final class Promocion
{
    public const TIPOS = [
        'pack'       => ['N por $M',        'Ej. 4 yogures por $1.000. Lo que no completa un grupo va a precio normal.'],
        'nxm'        => ['Lleve N pague M', 'Ej. lleve 3 pague 2.'],
        'precio'     => ['Precio especial', 'Ej. la Coca a $1.500 en vez de $2.000, sin importar cuántas lleve.'],
        'porcentaje' => ['% de descuento',  'Ej. 20% en todo el departamento de Lácteos.'],
    ];

    private static function cargar(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/pos.php';
    }

    public static function vigentes(int $negocioId): array
    {
        self::cargar();
        return \promociones_vigentes(Database::pdo(), $negocioId);
    }

    /**
     * Total de un carrito YA con las promociones aplicadas. El servidor
     * SIEMPRE lo recalcula al cobrar: nunca se confía en el total que
     * manda el navegador (podría venir manipulado o desactualizado).
     */
    public static function totalDelCarrito(int $negocioId, array $items): float
    {
        self::cargar();
        return \total_con_promociones(Database::pdo(), $negocioId, $items);
    }

    /** Texto corto para mostrar la oferta ("4 x $1.000", "20% dcto."...). */
    public static function texto(array $promo): string
    {
        self::cargar();
        return \texto_promocion($promo);
    }

    public static function porId(int $id): ?array
    {
        return Database::uno(
            "SELECT p.*, pr.codigo, pr.nombre AS producto_nombre
               FROM promociones p LEFT JOIN productos pr ON pr.id = p.producto_id
              WHERE p.id = ?",
            [$id]
        );
    }

    public static function todas(): array
    {
        return Database::todos(
            "SELECT p.*, pr.codigo, pr.nombre AS producto_nombre, n.nombre AS negocio_nombre
               FROM promociones p
               LEFT JOIN productos pr ON pr.id = p.producto_id
               LEFT JOIN negocios  n  ON n.id  = p.negocio_id
              ORDER BY p.activo DESC, p.id DESC"
        );
    }

    public static function departamentosDisponibles(): array
    {
        return Database::todos(
            "SELECT DISTINCT departamento FROM productos
              WHERE departamento IS NOT NULL AND departamento <> '' ORDER BY departamento"
        );
    }

    public static function productoPorCodigo(string $codigo): ?array
    {
        return Database::uno('SELECT id, nombre FROM productos WHERE codigo = ?', [$codigo]);
    }

    public static function crear(array $d, int $creadoPor): int
    {
        Database::consultar(
            "INSERT INTO promociones (negocio_id, nombre, tipo, producto_id, departamento,
                                      cantidad, valor, desde, hasta, activo, creado_por)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)",
            [$d['negocio_id'], $d['nombre'], $d['tipo'], $d['producto_id'], $d['departamento'],
             $d['cantidad'], $d['valor'], $d['desde'], $d['hasta'], $d['activo'], $creadoPor]
        );
        return (int)Database::pdo()->lastInsertId();
    }

    public static function actualizar(int $id, array $d): void
    {
        Database::consultar(
            "UPDATE promociones SET negocio_id=?, nombre=?, tipo=?, producto_id=?, departamento=?,
                    cantidad=?, valor=?, desde=?, hasta=?, activo=? WHERE id=?",
            [$d['negocio_id'], $d['nombre'], $d['tipo'], $d['producto_id'], $d['departamento'],
             $d['cantidad'], $d['valor'], $d['desde'], $d['hasta'], $d['activo'], $id]
        );
    }

    public static function eliminar(int $id): void
    {
        Database::consultar('DELETE FROM promociones WHERE id = ?', [$id]);
    }
}
