<?php
/**
 * Base de Datos de Productos: carga del catálogo desde el Excel de Eleventa,
 * estado de actualización y búsqueda. Lo usan herramienta_productos.php (la
 * administración) y productos_buscar.php (el autocompletar del Gestor de
 * Etiquetas).
 *
 * El Excel (.xlsx) se parsea con ZipArchive + SimpleXML nativos de PHP: un
 * .xlsx es un .zip con XML adentro. Así NO hace falta Composer ni PhpSpreadsheet,
 * coherente con el proyecto "PHP plano sin frameworks". El parseo ocurre una sola
 * vez al subir el archivo (no en cada búsqueda): los datos quedan en MySQL.
 */

require_once __DIR__ . '/db.php';

// Encabezados del export de Eleventa -> columna en la tabla productos. La clave
// es el texto del encabezado normalizado (minúsculas, sin tildes ni espacios
// extra); así el orden de las columnas en el Excel puede cambiar sin romper nada.
const PRODUCTOS_COLUMNAS = [
    'codigo'        => 'codigo',
    'producto'      => 'nombre',
    'p. costo'      => 'precio_costo',
    'p. venta'      => 'precio_venta',
    'p. mayoreo'    => 'precio_mayoreo',
    'departamento'  => 'departamento',
    'tipo de venta' => 'tipo_venta',
];

/** Normaliza un encabezado para compararlo: minúsculas, sin tildes ni dobles espacios. */
function _prod_norm_encabezado(string $s): string
{
    $s = trim(mb_strtolower($s, 'UTF-8'));
    $s = strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n']);
    return preg_replace('/\s+/', ' ', $s);
}

/**
 * Convierte una referencia de celda (ej. "C12") en índice de columna 0-based
 * (A=0, B=1, ... Z=25, AA=26, ...).
 */
function _prod_col_indice(string $ref): int
{
    $letras = preg_replace('/[0-9]+/', '', $ref);
    $n = 0;
    $len = strlen($letras);
    for ($i = 0; $i < $len; $i++) {
        $n = $n * 26 + (ord($letras[$i]) - 64); // 'A' = 65
    }
    return $n - 1;
}

/** Parsea un precio del Excel (texto chileno "$2.000") a número entero o null. */
function _prod_precio($v): ?float
{
    if ($v === null) return null;
    $s = preg_replace('/[^\d]/', '', (string)$v);  // deja solo dígitos
    return $s === '' ? null : (float)$s;
}

/**
 * Lee un .xlsx de Eleventa y devuelve los productos.
 * Devuelve ['filas' => [['codigo','nombre','precio_costo',...], ...],
 *           'total' => N, 'omitidos' => M].
 * Lanza RuntimeException con un mensaje claro si el archivo no se puede leer o
 * no tiene los encabezados esperados (no es el export de Eleventa).
 */
function parsear_excel_productos(string $ruta): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('El servidor no tiene la extensión Zip de PHP habilitada.');
    }
    $zip = new ZipArchive();
    if ($zip->open($ruta) !== true) {
        throw new RuntimeException('No se pudo abrir el archivo. ¿Es un Excel .xlsx válido?');
    }

    // 1) Tabla de cadenas compartidas (las celdas de texto apuntan acá).
    $compartidas = [];
    $ss = $zip->getFromName('xl/sharedStrings.xml');
    if ($ss !== false) {
        $xml = @simplexml_load_string($ss);
        if ($xml !== false) {
            foreach ($xml->si as $si) {
                if (isset($si->t)) {
                    $compartidas[] = (string)$si->t;
                } else {                       // texto enriquecido: une los <r><t>
                    $txt = '';
                    foreach ($si->r as $r) $txt .= (string)$r->t;
                    $compartidas[] = $txt;
                }
            }
        }
    }

    // 2) Primera hoja.
    $hoja = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if ($hoja === false) {
        throw new RuntimeException('El Excel no tiene hojas legibles.');
    }
    $xml = @simplexml_load_string($hoja);
    if ($xml === false) {
        throw new RuntimeException('No se pudo leer el contenido del Excel.');
    }

    // Devuelve el valor (ya resuelto) de una celda <c>.
    $valor = function ($c) use ($compartidas) {
        $t = (string)$c['t'];
        $v = (string)$c->v;
        if ($t === 's') return $compartidas[(int)$v] ?? '';   // cadena compartida
        if ($t === 'inlineStr') return (string)$c->is->t;     // cadena en línea
        return $v;                                            // número/texto directo
    };

    $filas = [];
    $omitidos = 0;
    $mapa = null;        // índice de columna -> nombre de campo (se arma en la fila 1)

    foreach ($xml->sheetData->row as $row) {
        // Junta las celdas de la fila por índice de columna.
        $celdas = [];
        foreach ($row->c as $c) {
            $celdas[_prod_col_indice((string)$c['r'])] = trim($valor($c));
        }
        if (!$celdas) continue;

        // La primera fila con datos define el mapa de columnas (encabezados).
        if ($mapa === null) {
            $mapa = [];
            foreach ($celdas as $idx => $texto) {
                $clave = _prod_norm_encabezado($texto);
                if (isset(PRODUCTOS_COLUMNAS[$clave])) {
                    $mapa[$idx] = PRODUCTOS_COLUMNAS[$clave];
                }
            }
            if (!in_array('codigo', $mapa, true) || !in_array('nombre', $mapa, true)) {
                throw new RuntimeException(
                    'El Excel no parece el export de productos de Eleventa: '
                    . 'faltan las columnas "Código" y/o "Producto".'
                );
            }
            continue;
        }

        // Filas de datos.
        $fila = ['codigo'=>'', 'nombre'=>'', 'precio_costo'=>null, 'precio_venta'=>null,
                 'precio_mayoreo'=>null, 'departamento'=>null, 'tipo_venta'=>null];
        foreach ($mapa as $idx => $campo) {
            $celda = $celdas[$idx] ?? '';
            if ($campo === 'codigo' || $campo === 'nombre') {
                $fila[$campo] = $celda;
            } elseif (in_array($campo, ['precio_costo','precio_venta','precio_mayoreo'], true)) {
                $fila[$campo] = _prod_precio($celda);
            } else {
                $fila[$campo] = ($celda === '' || $celda === '-') ? null : mb_substr($celda, 0, 150);
            }
        }

        // Una fila sin código o sin nombre no sirve para etiquetas: se omite.
        if ($fila['codigo'] === '' || $fila['nombre'] === '') {
            $omitidos++;
            continue;
        }
        $fila['codigo'] = mb_substr($fila['codigo'], 0, 40);
        $fila['nombre'] = mb_substr($fila['nombre'], 0, 255);
        $filas[] = $fila;
    }

    // Deduplica por código (gana la última aparición), como hace Eleventa.
    $porCodigo = [];
    foreach ($filas as $f) $porCodigo[$f['codigo']] = $f;
    $filas = array_values($porCodigo);

    return ['filas' => $filas, 'total' => count($filas), 'omitidos' => $omitidos];
}

/**
 * Reemplaza por completo el catálogo de un negocio con las filas parseadas.
 * Transacción atómica: registra la carga, borra el catálogo viejo e inserta el
 * nuevo por lotes. Si algo falla, hace rollBack y el catálogo anterior queda
 * intacto. Devuelve el id de la carga creada.
 */
function reemplazar_catalogo(PDO $pdo, int $negocioId, array $parse, array $meta): int
{
    $filas = $parse['filas'] ?? [];
    if (!$filas) {
        // Salvaguarda anti-borrado: nunca vaciamos el catálogo con un archivo vacío.
        throw new RuntimeException('El Excel no contiene productos válidos; no se cambió nada.');
    }

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare(
            "INSERT INTO producto_cargas (negocio_id, archivo_nombre, total_productos, total_omitidos, cargado_por)
             VALUES (?, ?, ?, ?, ?)"
        );
        $st->execute([
            $negocioId,
            mb_substr((string)($meta['archivo'] ?? ''), 0, 255),
            $parse['total'] ?? count($filas),
            $parse['omitidos'] ?? 0,
            $meta['usuario_id'] ?? null,
        ]);
        $cargaId = (int)$pdo->lastInsertId();

        $del = $pdo->prepare("DELETE FROM productos WHERE negocio_id = ?");
        $del->execute([$negocioId]);

        // Inserta por lotes de 500 filas (9 columnas por fila) para que sea rápido.
        $cols = 9;
        $porLote = 500;
        foreach (array_chunk($filas, $porLote) as $lote) {
            $marca = implode(',', array_fill(0, count($lote), '(' . implode(',', array_fill(0, $cols, '?')) . ')'));
            $sql = "INSERT INTO productos
                    (negocio_id, codigo, nombre, precio_costo, precio_venta, precio_mayoreo, departamento, tipo_venta, carga_id)
                    VALUES $marca";
            $valores = [];
            foreach ($lote as $f) {
                $valores[] = $negocioId;
                $valores[] = $f['codigo'];
                $valores[] = $f['nombre'];
                $valores[] = $f['precio_costo'];
                $valores[] = $f['precio_venta'];
                $valores[] = $f['precio_mayoreo'];
                $valores[] = $f['departamento'];
                $valores[] = $f['tipo_venta'];
                $valores[] = $cargaId;
            }
            $pdo->prepare($sql)->execute($valores);
        }

        $pdo->commit();
        return $cargaId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Vacía el catálogo de un negocio (zona de peligro). Devuelve cuántos borró. */
function vaciar_catalogo(PDO $pdo, int $negocioId): int
{
    $st = $pdo->prepare("DELETE FROM productos WHERE negocio_id = ?");
    $st->execute([$negocioId]);
    return $st->rowCount();
}

/** Datos de la última carga de un negocio (o null si nunca cargó). */
function ultima_carga(PDO $pdo, int $negocioId): ?array
{
    $st = $pdo->prepare(
        "SELECT c.*, u.nombre AS usuario_nombre
         FROM producto_cargas c
         LEFT JOIN usuarios u ON u.id = c.cargado_por
         WHERE c.negocio_id = ?
         ORDER BY c.cargado_en DESC, c.id DESC LIMIT 1"
    );
    $st->execute([$negocioId]);
    $c = $st->fetch();
    return $c ?: null;
}

/** Cuántos productos tiene cargados un negocio. */
function contar_productos(PDO $pdo, int $negocioId): int
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM productos WHERE negocio_id = ?");
    $st->execute([$negocioId]);
    return (int)$st->fetchColumn();
}

/**
 * Estado de actualización del catálogo según la fecha de la última carga.
 * Umbrales: < 7 días = ok (verde); 7–14 = naranja; > 14 = rojo; sin carga = vacío.
 * Devuelve ['nivel','dias','texto'].
 */
function estado_actualizacion(?string $cargadoEn): array
{
    if (!$cargadoEn) {
        return ['nivel' => 'vacio', 'dias' => null, 'texto' => 'No hay una base de datos disponible'];
    }
    $ts = strtotime($cargadoEn);
    $dias = (int)floor((time() - $ts) / 86400);
    if ($dias <= 6) {
        $nivel = 'ok';
        $texto = $dias <= 0 ? 'Actualizada hoy' : "Actualizada hace $dias día" . ($dias === 1 ? '' : 's');
    } elseif ($dias <= 14) {
        $nivel = 'naranja';
        $texto = "Desactualizada: hace $dias días sin actualizar";
    } else {
        $nivel = 'rojo';
        $texto = "Muy desactualizada: hace $dias días sin actualizar";
    }
    return ['nivel' => $nivel, 'dias' => $dias, 'texto' => $texto];
}

/**
 * Busca productos de un negocio por código (prefijo, si la consulta es numérica
 * y larga) o por nombre (LIKE). Devuelve [['codigo','nombre','precio_venta'], ...].
 */
function buscar_productos(PDO $pdo, int $negocioId, string $q, int $limite = 20): array
{
    $q = trim($q);
    if ($q === '') return [];
    $limite = max(1, min(50, $limite));

    // Código de barras: solo dígitos y al menos 4 → match por prefijo de código.
    if (ctype_digit($q) && strlen($q) >= 4) {
        $st = $pdo->prepare(
            "SELECT codigo, nombre, precio_venta FROM productos
             WHERE negocio_id = ? AND codigo LIKE ?
             ORDER BY (codigo = ?) DESC, codigo LIMIT $limite"
        );
        $st->execute([$negocioId, $q . '%', $q]);
        return $st->fetchAll();
    }

    // Texto: busca en el nombre (los que empiezan con el término primero).
    $st = $pdo->prepare(
        "SELECT codigo, nombre, precio_venta FROM productos
         WHERE negocio_id = ? AND nombre LIKE ?
         ORDER BY (nombre LIKE ?) DESC, nombre LIMIT $limite"
    );
    $st->execute([$negocioId, '%' . $q . '%', $q . '%']);
    return $st->fetchAll();
}

/** Departamentos distintos del catálogo de un negocio (para el filtro). */
function departamentos_de_negocio(PDO $pdo, int $negocioId): array
{
    $st = $pdo->prepare(
        "SELECT DISTINCT departamento FROM productos
         WHERE negocio_id = ? AND departamento IS NOT NULL AND departamento <> ''
         ORDER BY departamento"
    );
    $st->execute([$negocioId]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Consulta el catálogo de un negocio con TODOS los campos (para la herramienta
 * Consultar Catálogo). Busca por código (si la consulta es numérica) o por
 * nombre, y opcionalmente filtra por departamento. Permite consulta vacía cuando
 * hay un departamento elegido (lista ese departamento). Devuelve filas completas.
 */
function consultar_catalogo(PDO $pdo, int $negocioId, string $q, string $departamento = '', int $limite = 30): array
{
    $q = trim($q);
    $departamento = trim($departamento);
    if ($q === '' && $departamento === '') return [];
    $limite = max(1, min(50, $limite));

    $cols = "codigo, nombre, precio_costo, precio_venta, precio_mayoreo, departamento, tipo_venta";
    $where = ["negocio_id = ?"];
    $args  = [$negocioId];
    $orden = "nombre";

    if ($q !== '') {
        if (ctype_digit($q) && strlen($q) >= 4) {        // código de barras
            $where[] = "codigo LIKE ?";
            $args[]  = $q . '%';
            $orden   = "(codigo = " . $pdo->quote($q) . ") DESC, codigo";
        } else {                                          // texto en el nombre
            $where[] = "nombre LIKE ?";
            $args[]  = '%' . $q . '%';
            $orden   = "(nombre LIKE " . $pdo->quote($q . '%') . ") DESC, nombre";
        }
    }
    if ($departamento !== '') {
        $where[] = "departamento = ?";
        $args[]  = $departamento;
    }

    $sql = "SELECT $cols FROM productos WHERE " . implode(' AND ', $where)
         . " ORDER BY $orden LIMIT $limite";
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}
