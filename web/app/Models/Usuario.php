<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Usuarios del dashboard: cuenta, rol y los negocios que puede ver (roles
 * no-admin). Sin equivalente previo en ningún lib/*.php (vivía suelto en
 * usuarios.php) — SQL directo, mismo criterio que App\Models\Cliente.
 */
final class Usuario
{
    /**
     * Colores sugeridos para identificar usuarios en las tablas (nombre +
     * color). Elegidos a mano para que se distingan bien entre sí y con la
     * paleta del sistema (ver assets/estilo.css) — nada de tonos parecidos.
     */
    public const PALETA = [
        '#1565c0', '#2ecc71', '#e67e22', '#8e44ad', '#c0392b',
        '#16a085', '#d4ac0d', '#34495e', '#e84393', '#7f8c8d',
    ];

    /** El primer color de la paleta que ningún usuario esté usando todavía (o el primero, si ya se repiten todos). */
    public static function colorSugerido(): string
    {
        $usados = array_column(
            Database::todos('SELECT color FROM usuarios WHERE color IS NOT NULL'),
            'color'
        );
        foreach (self::PALETA as $color) {
            if (!in_array($color, $usados, true)) return $color;
        }
        return self::PALETA[0];
    }

    /** Personal de la casa (admin/vendedor) para usuarios.php. Los representantes de
     * proveedor (Etapa 2 de Proveedores) NO salen aquí — se gestionan aparte, desde
     * Compras -> Proveedores (ver Usuario::representantesDe()), para que esta pantalla
     * nunca pueda tocar por error una cuenta externa. */
    public static function todosConNegocios(): array
    {
        return Database::todos(
            "SELECT u.*, GROUP_CONCAT(n.nombre SEPARATOR ', ') AS negocios
               FROM usuarios u
               LEFT JOIN usuario_negocio un ON un.usuario_id = u.id
               LEFT JOIN negocios n ON n.id = un.negocio_id
              WHERE u.rol <> 'representante'
              GROUP BY u.id ORDER BY u.nombre, u.email"
        );
    }

    public static function porId(int $id): ?array
    {
        return Database::uno('SELECT * FROM usuarios WHERE id = ?', [$id]);
    }

    public static function negociosIdsDe(int $id): array
    {
        return array_map('intval', array_column(
            Database::todos('SELECT negocio_id FROM usuario_negocio WHERE usuario_id = ?', [$id]),
            'negocio_id'
        ));
    }

    /** Todos los negocios del sistema (para el checklist de asignación, no acotado a "visibles"). */
    public static function negociosTodos(): array
    {
        return Database::todos('SELECT id, nombre FROM negocios ORDER BY nombre');
    }

    public static function rolDe(int $id): ?string
    {
        $r = Database::valor('SELECT rol FROM usuarios WHERE id = ?', [$id]);
        return $r === false ? null : $r;
    }

    public static function contarAdmins(): int
    {
        return (int)Database::valor("SELECT COUNT(*) FROM usuarios WHERE rol = 'admin'");
    }

    public static function crear(string $email, string $nombre, string $rol, string $passwordHash, string $color): int
    {
        Database::consultar(
            'INSERT INTO usuarios (email, nombre, rol, password_hash, color) VALUES (?,?,?,?,?)',
            [$email, $nombre, $rol, $passwordHash, $color]
        );
        return (int)Database::pdo()->lastInsertId();
    }

    public static function actualizar(int $id, string $email, string $nombre, string $rol, string $color): void
    {
        Database::consultar(
            'UPDATE usuarios SET email=?, nombre=?, rol=?, color=? WHERE id=?',
            [$email, $nombre, $rol, $color, $id]
        );
    }

    public static function actualizarConClave(int $id, string $email, string $nombre, string $rol, string $passwordHash, string $color): void
    {
        Database::consultar(
            'UPDATE usuarios SET email=?, nombre=?, rol=?, password_hash=?, color=? WHERE id=?',
            [$email, $nombre, $rol, $passwordHash, $color, $id]
        );
    }

    public static function actualizarPin(int $id, string $pinHash): void
    {
        Database::consultar('UPDATE usuarios SET pin_hash = ? WHERE id = ?', [$pinHash, $id]);
    }

    /** Reemplaza por completo los negocios asignados (los roles no-admin trabajan solo los suyos). */
    public static function reasignarNegocios(int $id, array $negocioIds): void
    {
        Database::consultar('DELETE FROM usuario_negocio WHERE usuario_id = ?', [$id]);
        foreach ($negocioIds as $nid) {
            Database::consultar('INSERT INTO usuario_negocio (usuario_id, negocio_id) VALUES (?,?)', [$id, $nid]);
        }
    }

    public static function eliminar(int $id): void
    {
        Database::consultar('DELETE FROM usuarios WHERE id = ?', [$id]);
    }

    // ---- Representantes de proveedor (Etapa 2 de Proveedores, gestionados
    // desde Compras -> Proveedores, no desde esta pantalla de Usuarios) ----

    /** Representantes de un proveedor, con las sucursales que cada uno puede ver. */
    public static function representantesDe(int $proveedorId): array
    {
        return Database::todos(
            "SELECT u.*, GROUP_CONCAT(n.nombre SEPARATOR ', ') AS negocios
               FROM usuarios u
               LEFT JOIN usuario_negocio un ON un.usuario_id = u.id
               LEFT JOIN negocios n ON n.id = un.negocio_id
              WHERE u.rol = 'representante' AND u.proveedor_id = ?
              GROUP BY u.id ORDER BY u.nombre, u.email",
            [$proveedorId]
        );
    }

    /** El representante, solo si de verdad pertenece a ESE proveedor (evita tocar la cuenta de otro). */
    public static function porIdYProveedor(int $id, int $proveedorId): ?array
    {
        return Database::uno(
            "SELECT * FROM usuarios WHERE id = ? AND rol = 'representante' AND proveedor_id = ?",
            [$id, $proveedorId]
        );
    }

    public static function crearRepresentante(string $email, string $nombre, string $passwordHash, int $proveedorId): int
    {
        Database::consultar(
            "INSERT INTO usuarios (email, nombre, rol, password_hash, proveedor_id)
             VALUES (?, ?, 'representante', ?, ?)",
            [$email, $nombre, $passwordHash, $proveedorId]
        );
        return (int)Database::pdo()->lastInsertId();
    }

    public static function actualizarRepresentante(int $id, string $email, string $nombre, int $proveedorId): void
    {
        Database::consultar(
            "UPDATE usuarios SET email=?, nombre=?, proveedor_id=? WHERE id=? AND rol='representante'",
            [$email, $nombre, $proveedorId, $id]
        );
    }

    public static function actualizarRepresentanteConClave(
        int $id, string $email, string $nombre, int $proveedorId, string $passwordHash
    ): void {
        Database::consultar(
            "UPDATE usuarios SET email=?, nombre=?, proveedor_id=?, password_hash=? WHERE id=? AND rol='representante'",
            [$email, $nombre, $proveedorId, $passwordHash, $id]
        );
    }
}
