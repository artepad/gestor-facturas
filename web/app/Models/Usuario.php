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
    public static function todosConNegocios(): array
    {
        return Database::todos(
            "SELECT u.*, GROUP_CONCAT(n.nombre SEPARATOR ', ') AS negocios
               FROM usuarios u
               LEFT JOIN usuario_negocio un ON un.usuario_id = u.id
               LEFT JOIN negocios n ON n.id = un.negocio_id
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

    public static function crear(string $email, string $nombre, string $rol, string $passwordHash): int
    {
        Database::consultar(
            'INSERT INTO usuarios (email, nombre, rol, password_hash) VALUES (?,?,?,?)',
            [$email, $nombre, $rol, $passwordHash]
        );
        return (int)Database::pdo()->lastInsertId();
    }

    public static function actualizar(int $id, string $email, string $nombre, string $rol): void
    {
        Database::consultar('UPDATE usuarios SET email=?, nombre=?, rol=? WHERE id=?', [$email, $nombre, $rol, $id]);
    }

    public static function actualizarConClave(int $id, string $email, string $nombre, string $rol, string $passwordHash): void
    {
        Database::consultar(
            'UPDATE usuarios SET email=?, nombre=?, rol=?, password_hash=? WHERE id=?',
            [$email, $nombre, $rol, $passwordHash, $id]
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
}
