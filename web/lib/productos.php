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

        // UPSERT: actualiza lo que ya existe y agrega lo nuevo. NUNCA borra.
        // Antes esto hacía DELETE + INSERT del catálogo completo; con el
        // catálogo compartido eso habría vaciado el de los dos negocios, y
        // además se llevaría el stock por delante (ver docs/POS_DISENO.md 7.1).
        $antes = (int)$pdo->query("SELECT COUNT(*) FROM productos")->fetchColumn();
        $usuarioId = $meta['usuario_id'] ?? null;

        // Inserta por lotes de 500 filas (10 columnas por fila) para que sea rápido.
        $cols = 10;
        $porLote = 500;
        foreach (array_chunk($filas, $porLote) as $lote) {
            $marca = implode(',', array_fill(0, count($lote), '(' . implode(',', array_fill(0, $cols, '?')) . ')'));
            $sql = "INSERT INTO productos
                    (codigo, nombre, precio_costo, precio_venta, precio_mayoreo,
                     departamento, tipo_venta, carga_id, creado_por, actualizado_por)
                    VALUES $marca
                    ON DUPLICATE KEY UPDATE
                      nombre          = VALUES(nombre),
                      precio_costo    = VALUES(precio_costo),
                      precio_venta    = VALUES(precio_venta),
                      precio_mayoreo  = VALUES(precio_mayoreo),
                      departamento    = VALUES(departamento),
                      tipo_venta      = VALUES(tipo_venta),
                      carga_id        = VALUES(carga_id),
                      actualizado_por = VALUES(actualizado_por)";
            $valores = [];
            foreach ($lote as $f) {
                $valores[] = $f['codigo'];
                $valores[] = $f['nombre'];
                $valores[] = $f['precio_costo'];
                $valores[] = $f['precio_venta'];
                $valores[] = $f['precio_mayoreo'];
                $valores[] = $f['departamento'];
                $valores[] = $f['tipo_venta'];
                $valores[] = $cargaId;
                $valores[] = $usuarioId;    // creado_por (solo si es nuevo)
                $valores[] = $usuarioId;    // actualizado_por
            }
            $pdo->prepare($sql)->execute($valores);
        }

        // Cuántos entraron nuevos y cuántos se actualizaron (para avisar al usuario).
        $despues = (int)$pdo->query("SELECT COUNT(*) FROM productos")->fetchColumn();
        $nuevos = $despues - $antes;
        $pdo->prepare(
            "UPDATE producto_cargas SET total_nuevos = ?, total_actualizados = ? WHERE id = ?"
        )->execute([$nuevos, count($filas) - $nuevos, $cargaId]);

        $pdo->commit();
        return $cargaId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Vacía el catálogo de un negocio (zona de peligro). Devuelve cuántos borró. */
function vaciar_catalogo(PDO $pdo): int
{
    // El catalogo es UNO SOLO para los dos negocios: esto los deja sin
    // productos a ambos. Por eso la UI exige escribir ELIMINAR.
    return (int)$pdo->exec("DELETE FROM productos");
}

/** Datos de la última carga de un negocio (o null si nunca cargó). */
function ultima_carga(PDO $pdo): ?array
{
    $st = $pdo->prepare(
        "SELECT c.*, u.nombre AS usuario_nombre
         FROM producto_cargas c
         LEFT JOIN usuarios u ON u.id = c.cargado_por
         ORDER BY c.cargado_en DESC, c.id DESC LIMIT 1"
    );
    $st->execute();
    $c = $st->fetch();
    return $c ?: null;
}

/** Cuántos productos tiene cargados un negocio. */
function contar_productos(PDO $pdo): int
{
    return (int)$pdo->query("SELECT COUNT(*) FROM productos")->fetchColumn();
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
function buscar_productos(PDO $pdo, string $q, int $limite = 20): array
{
    $q = trim($q);
    if ($q === '') return [];
    $limite = max(1, min(50, $limite));

    // Código de barras: solo dígitos y al menos 4 → match por prefijo de código.
    if (ctype_digit($q) && strlen($q) >= 4) {
        $st = $pdo->prepare(
            "SELECT codigo, nombre, precio_venta FROM productos
             WHERE codigo LIKE ?
             ORDER BY (codigo = ?) DESC, codigo LIMIT $limite"
        );
        $st->execute([$q . '%', $q]);
        return $st->fetchAll();
    }

    // Texto: busca en el nombre (los que empiezan con el término primero).
    $st = $pdo->prepare(
        "SELECT codigo, nombre, precio_venta FROM productos
         WHERE nombre LIKE ?
         ORDER BY (nombre LIKE ?) DESC, nombre LIMIT $limite"
    );
    $st->execute(['%' . $q . '%', $q . '%']);
    return $st->fetchAll();
}

/** Departamentos distintos del catálogo de un negocio (para el filtro). */
function departamentos_de_negocio(PDO $pdo): array
{
    $st = $pdo->prepare(
        "SELECT DISTINCT departamento FROM productos
         WHERE departamento IS NOT NULL AND departamento <> ''
         ORDER BY departamento"
    );
    $st->execute();
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Consulta el catálogo de un negocio con TODOS los campos (para la herramienta
 * Consultar Catálogo). Busca por código (si la consulta es numérica) o por
 * nombre, y opcionalmente filtra por departamento. Permite consulta vacía cuando
 * hay un departamento elegido (lista ese departamento). Devuelve filas completas.
 */
function consultar_catalogo(PDO $pdo, string $q, string $departamento = '', int $limite = 30): array
{
    $q = trim($q);
    $departamento = trim($departamento);
    if ($q === '' && $departamento === '') return [];
    $limite = max(1, min(50, $limite));

    $cols = "codigo, nombre, precio_costo, precio_venta, precio_mayoreo, departamento, tipo_venta";
    $where = ["1=1"];
    $args  = [];
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

/**
 * Genera un .xlsx (formato Eleventa: Codigo/Producto/P. Costo/P. Venta/
 * P. Mayoreo/Departamento/Tipo de venta) a partir de filas ['codigo','nombre',
 * 'precio_costo','precio_venta','precio_mayoreo','departamento','tipo_venta'].
 * Mismo criterio de "sin Composer ni PhpSpreadsheet" que parsear_excel_productos:
 * un .xlsx es un .zip con XML adentro, armado acá a mano con ZipArchive.
 * Devuelve los bytes del archivo (ZipArchive solo sabe escribir a una ruta real,
 * así que se arma en un temporal y se lee de vuelta).
 */
function generar_excel_productos(array $filas): string
{
    $encabezados = ['Codigo', 'Producto', 'P. Costo', 'P. Venta', 'P. Mayoreo', 'Departamento', 'Tipo de venta'];
    $columnas = ['codigo', 'nombre', 'precio_costo', 'precio_venta', 'precio_mayoreo', 'departamento', 'tipo_venta'];

    $compartidas = [];
    $idx = function (string $s) use (&$compartidas): int {
        $i = array_search($s, $compartidas, true);
        if ($i !== false) return $i;
        $compartidas[] = $s;
        return count($compartidas) - 1;
    };
    $celdaXml = function (int $col, int $fila, $valor) use ($idx): string {
        $ref = chr(65 + $col) . $fila;
        if ($valor === null || $valor === '') return "<c r=\"$ref\"/>";
        if (is_numeric($valor)) return "<c r=\"$ref\"><v>$valor</v></c>";
        return "<c r=\"$ref\" t=\"s\"><v>" . $idx((string)$valor) . "</v></c>";
    };

    $filasXml = '<row r="1">';
    foreach ($encabezados as $c => $texto) $filasXml .= $celdaXml($c, 1, $texto);
    $filasXml .= '</row>';
    foreach ($filas as $i => $f) {
        $filasXml .= '<row r="' . ($i + 2) . '">';
        foreach ($columnas as $c => $campo) {
            $valor = $f[$campo] ?? null;
            // La BD guarda los precios con ".00" (columnas DECIMAL), pero el CLP no
            // tiene centavos y _prod_precio() interpreta ese punto como separador de
            // miles al reimportar (690.00 -> 69000). Se escribe como entero para que
            // el ciclo exportar->reimportar no multiplique el precio por 100.
            if (in_array($campo, ['precio_costo', 'precio_venta', 'precio_mayoreo'], true)
                && $valor !== null && $valor !== '') {
                $valor = (int)round((float)$valor);
            }
            $filasXml .= $celdaXml($c, $i + 2, $valor);
        }
        $filasXml .= '</row>';
    }

    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . "<sheetData>$filasXml</sheetData></worksheet>";

    $sst = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'
        . count($compartidas) . '" uniqueCount="' . count($compartidas) . '">';
    foreach ($compartidas as $s) {
        $sst .= '<si><t xml:space="preserve">' . htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</t></si>';
    }
    $sst .= '</sst>';

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
        . '</Types>';

    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';

    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Catalogo" sheetId="1" r:id="rId1"/></sheets></workbook>';

    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
        . '</Relationships>';

    $tmp = tempnam(sys_get_temp_dir(), 'catalogo_xlsx_');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('No se pudo generar el archivo Excel.');
    }
    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $rootRels);
    $zip->addFromString('xl/workbook.xml', $workbook);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $zip->addFromString('xl/sharedStrings.xml', $sst);
    $zip->close();

    $bytes = file_get_contents($tmp);
    unlink($tmp);
    return $bytes;
}

/** Catálogo completo (todos los productos, cualquier estado), para exportar a Excel. */
function catalogo_completo(PDO $pdo): array
{
    return $pdo->query(
        "SELECT codigo, nombre, precio_costo, precio_venta, precio_mayoreo, departamento, tipo_venta
           FROM productos ORDER BY nombre"
    )->fetchAll();
}

/**
 * Unificar catálogos de dos negocios (ej. cuando cada local trae su propio
 * Excel de Eleventa con productos y precios distintos). Compara por código de
 * barras primero; lo que no calza por código se vuelve a comparar por nombre
 * normalizado, para detectar el mismo producto con códigos distintos.
 *
 * Nunca escribe en `productos` directamente: arma una comparación que el
 * usuario revisa y decide (tabla `producto_unificaciones` +
 * `producto_unificacion_items`), y solo al confirmar (aplicar_unificacion)
 * se escribe el catálogo real.
 */

/** Normaliza un nombre de producto para comparar: minúsculas, sin tildes, sin espacios dobles. */
function _prod_norm_nombre(string $s): string
{
    $s = trim(mb_strtolower($s, 'UTF-8'));
    $s = strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','ü'=>'u']);
    $s = preg_replace('/[^a-z0-9 ]/', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}

/** ¿Dos montos son iguales? (null y 0 no son el mismo valor; tolera centavos). */
function _prod_montos_iguales(?float $a, ?float $b): bool
{
    if ($a === null || $b === null) return $a === $b;
    return abs($a - $b) < 0.01;
}

/** El más largo de dos nombres (el más descriptivo), como sugerencia. */
function _prod_nombre_mas_largo(string $a, string $b): string
{
    return mb_strlen(trim($a)) >= mb_strlen(trim($b)) ? trim($a) : trim($b);
}

/**
 * Compara los productos de dos Excel ya parseados (ver parsear_excel_productos).
 * Devuelve ['igual'=>[...], 'conflicto_precio'=>[...], 'conflicto_nombre'=>[...],
 * 'solo_a'=>[...], 'solo_b'=>[...], 'revisar'=>[...]]; cada elemento trae los
 * campos "_a"/"_b" tal como venían, y en 'igual'/'solo_a'/'solo_b' además los
 * "_final" ya resueltos (no hay nada que decidir). Los demás grupos quedan con
 * "_final" en null: el usuario los resuelve en la pantalla de revisión.
 *
 * Un producto con el mismo código pero precio de venta distinto va a
 * 'conflicto_precio' aunque el nombre TAMBIÉN difiera — el precio es el dato
 * más sensible y se prioriza sobre el nombre (se resuelve primero, en su
 * propia tabla). Solo si el precio es igual y únicamente cambia el nombre cae
 * en 'conflicto_nombre' (menor riesgo).
 */
function comparar_catalogos(array $filasA, array $filasB): array
{
    $porCodigoA = [];
    foreach ($filasA as $f) $porCodigoA[$f['codigo']] = $f;
    $porCodigoB = [];
    foreach ($filasB as $f) $porCodigoB[$f['codigo']] = $f;

    $resultado = ['igual' => [], 'conflicto_precio' => [], 'conflicto_nombre' => [],
        'solo_a' => [], 'solo_b' => [], 'revisar' => []];

    $campos = ['nombre', 'precio_costo', 'precio_venta', 'precio_mayoreo', 'departamento', 'tipo_venta'];
    $armarItem = function (?array $a, ?array $b) use ($campos): array {
        $item = [];
        foreach ($campos as $c) {
            $item[$c . '_a'] = $a[$c] ?? null;
            $item[$c . '_b'] = $b[$c] ?? null;
        }
        $item['codigo_a'] = $a['codigo'] ?? null;
        $item['codigo_b'] = $b['codigo'] ?? null;
        return $item;
    };

    // 1) Códigos presentes en los dos Excel: ¿son el mismo dato o hay conflicto?
    $soloA = $porCodigoA;
    $soloB = $porCodigoB;
    foreach ($porCodigoA as $codigo => $a) {
        if (!isset($porCodigoB[$codigo])) continue;
        $b = $porCodigoB[$codigo];
        unset($soloA[$codigo], $soloB[$codigo]);

        $item = $armarItem($a, $b);
        $mismoNombre = _prod_norm_nombre($a['nombre']) === _prod_norm_nombre($b['nombre']);
        $mismoPrecio = _prod_montos_iguales($a['precio_venta'], $b['precio_venta']);

        if ($mismoNombre && $mismoPrecio) {
            $item['codigo_final']         = $codigo;
            $item['nombre_final']         = $a['nombre'];
            $item['precio_costo_final']   = $a['precio_costo'];
            $item['precio_venta_final']   = $a['precio_venta'];
            $item['precio_mayoreo_final'] = $a['precio_mayoreo'];
            $item['departamento_final']   = $a['departamento'];
            $item['tipo_venta_final']     = $a['tipo_venta'];
            $resultado['igual'][] = $item;
        } elseif (!$mismoPrecio) {
            $item['nombre_sugerido'] = _prod_nombre_mas_largo($a['nombre'], $b['nombre']);
            $resultado['conflicto_precio'][] = $item;
        } else {
            $item['nombre_sugerido'] = _prod_nombre_mas_largo($a['nombre'], $b['nombre']);
            $resultado['conflicto_nombre'][] = $item;
        }
    }

    // 2) Entre lo que quedó solo en un lado, busca el mismo nombre en el otro
    //    (mismo producto, código distinto o mal tipeado) antes de darlo por
    //    exclusivo de ese negocio.
    $nombreANorm = [];
    foreach ($soloA as $codigo => $a) $nombreANorm[_prod_norm_nombre($a['nombre'])][] = $codigo;
    foreach ($soloB as $codigo => $b) {
        $norm = _prod_norm_nombre($b['nombre']);
        if (empty($nombreANorm[$norm])) continue;
        $codigoA = array_shift($nombreANorm[$norm]);
        $a = $soloA[$codigoA];
        unset($soloA[$codigoA], $soloB[$codigo]);

        $item = $armarItem($a, $b);
        $item['nombre_sugerido'] = _prod_nombre_mas_largo($a['nombre'], $b['nombre']);
        $resultado['revisar'][] = $item;
    }

    // 3) Lo que sigue sin pareja es exclusivo de un negocio.
    foreach ($soloA as $codigo => $a) {
        $item = $armarItem($a, null);
        $item['codigo_final']         = $codigo;
        $item['nombre_final']         = $a['nombre'];
        $item['precio_costo_final']   = $a['precio_costo'];
        $item['precio_venta_final']   = $a['precio_venta'];
        $item['precio_mayoreo_final'] = $a['precio_mayoreo'];
        $item['departamento_final']   = $a['departamento'];
        $item['tipo_venta_final']     = $a['tipo_venta'];
        $resultado['solo_a'][] = $item;
    }
    foreach ($soloB as $codigo => $b) {
        $item = $armarItem(null, $b);
        $item['codigo_final']         = $codigo;
        $item['nombre_final']         = $b['nombre'];
        $item['precio_costo_final']   = $b['precio_costo'];
        $item['precio_venta_final']   = $b['precio_venta'];
        $item['precio_mayoreo_final'] = $b['precio_mayoreo'];
        $item['departamento_final']   = $b['departamento'];
        $item['tipo_venta_final']     = $b['tipo_venta'];
        $resultado['solo_b'][] = $item;
    }

    return $resultado;
}

/** Crea una unificación (cabecera + ítems) a partir de una comparación ya armada. Devuelve el id. */
function crear_unificacion(PDO $pdo, int $negocioAId, int $negocioBId, array $comparacion, array $meta): int
{
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare(
            "INSERT INTO producto_unificaciones
               (negocio_a_id, negocio_b_id, archivo_a_nombre, archivo_b_nombre,
                total_igual, total_conflicto_precio, total_conflicto_nombre,
                total_solo_a, total_solo_b, total_revisar, creado_por)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)"
        );
        $st->execute([
            $negocioAId, $negocioBId,
            mb_substr((string)($meta['archivo_a'] ?? ''), 0, 255),
            mb_substr((string)($meta['archivo_b'] ?? ''), 0, 255),
            count($comparacion['igual']), count($comparacion['conflicto_precio']),
            count($comparacion['conflicto_nombre']),
            count($comparacion['solo_a']), count($comparacion['solo_b']), count($comparacion['revisar']),
            $meta['usuario_id'] ?? null,
        ]);
        $id = (int)$pdo->lastInsertId();

        $campos = ['codigo_a','codigo_b','nombre_a','nombre_b',
            'precio_costo_a','precio_costo_b','precio_venta_a','precio_venta_b',
            'precio_mayoreo_a','precio_mayoreo_b','departamento_a','departamento_b',
            'tipo_venta_a','tipo_venta_b'];
        $camposFinal = ['codigo_final','nombre_final','precio_costo_final',
            'precio_venta_final','precio_mayoreo_final','departamento_final','tipo_venta_final'];

        $ins = $pdo->prepare(
            "INSERT INTO producto_unificacion_items
               (unificacion_id, grupo, " . implode(',', $campos) . ", nombre_sugerido,
                " . implode(',', $camposFinal) . ", resuelto)
             VALUES (?,?," . implode(',', array_fill(0, count($campos), '?')) . ",?,"
                            . implode(',', array_fill(0, count($camposFinal), '?')) . ",?)"
        );

        foreach (['igual', 'conflicto_precio', 'conflicto_nombre', 'solo_a', 'solo_b', 'revisar'] as $grupo) {
            $resuelto = in_array($grupo, ['igual', 'solo_a', 'solo_b'], true) ? 1 : 0;
            foreach ($comparacion[$grupo] as $item) {
                $valores = [$id, $grupo];
                foreach ($campos as $c) $valores[] = $item[$c] ?? null;
                $valores[] = $item['nombre_sugerido'] ?? null;
                foreach ($camposFinal as $c) $valores[] = $resuelto ? ($item[$c] ?? null) : null;
                $valores[] = $resuelto;
                $ins->execute($valores);
            }
        }

        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** La cabecera de una unificación (con nombres de negocios/usuario), o null si no existe. */
function obtener_unificacion(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare(
        "SELECT u.*, na.nombre AS negocio_a_nombre, nb.nombre AS negocio_b_nombre,
                uc.nombre AS creado_por_nombre, ua.nombre AS aplicado_por_nombre
           FROM producto_unificaciones u
           JOIN negocios na ON na.id = u.negocio_a_id
           JOIN negocios nb ON nb.id = u.negocio_b_id
      LEFT JOIN usuarios uc ON uc.id = u.creado_por
      LEFT JOIN usuarios ua ON ua.id = u.aplicado_por
          WHERE u.id = ?"
    );
    $st->execute([$id]);
    $u = $st->fetch();
    return $u ?: null;
}

/** Ítems de una unificación, opcionalmente filtrados por grupo. */
function items_unificacion(PDO $pdo, int $id, ?string $grupo = null): array
{
    $sql = "SELECT * FROM producto_unificacion_items WHERE unificacion_id = ?";
    $args = [$id];
    if ($grupo !== null) { $sql .= " AND grupo = ?"; $args[] = $grupo; }
    $sql .= " ORDER BY id";
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

/** Cuántos ítems (conflicto/revisar) todavía no tienen una decisión. */
function contar_pendientes_unificacion(PDO $pdo, int $id): int
{
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM producto_unificacion_items WHERE unificacion_id = ? AND resuelto = 0"
    );
    $st->execute([$id]);
    return (int)$st->fetchColumn();
}

/**
 * Guarda la decisión del usuario sobre un ítem en conflicto o a revisar.
 * $decision: 'a' (usar los datos del negocio A), 'b' (usar los del B),
 * 'personalizado' (nombre y/o precio propios, solo para 'conflicto_precio'/
 * 'conflicto_nombre'), o 'distinto' (son productos distintos, solo para
 * 'revisar': cada código entra por separado, como si nunca se hubieran
 * emparejado).
 */
function resolver_item_unificacion(PDO $pdo, int $itemId, string $decision, array $personalizado = []): void
{
    $item = $pdo->prepare("SELECT * FROM producto_unificacion_items WHERE id = ?");
    $item->execute([$itemId]);
    $it = $item->fetch();
    if (!$it) throw new RuntimeException('El producto a resolver ya no existe.');

    if ($decision === 'distinto') {
        if ($it['grupo'] !== 'revisar') {
            throw new RuntimeException('Esa opción solo aplica a los productos marcados "a revisar".');
        }
        $pdo->prepare("UPDATE producto_unificacion_items SET decision = 'distinto', resuelto = 1 WHERE id = ?")
            ->execute([$itemId]);
        return;
    }

    if ($decision === 'a' || $decision === 'b') {
        $suf = $decision;
        $codigo = $it["codigo_$suf"] ?? null;
        if ($codigo === null) {
            throw new RuntimeException('Ese negocio no tiene este producto; elige la otra opción.');
        }
        $pdo->prepare(
            "UPDATE producto_unificacion_items SET decision = ?, resuelto = 1,
                codigo_final = ?, nombre_final = ?, precio_costo_final = ?,
                precio_venta_final = ?, precio_mayoreo_final = ?,
                departamento_final = ?, tipo_venta_final = ?
              WHERE id = ?"
        )->execute([
            $decision, $codigo, $it["nombre_$suf"], $it["precio_costo_$suf"],
            $it["precio_venta_$suf"], $it["precio_mayoreo_$suf"],
            $it["departamento_$suf"], $it["tipo_venta_$suf"], $itemId,
        ]);
        return;
    }

    if ($decision === 'personalizado') {
        if (!in_array($it['grupo'], ['conflicto_precio', 'conflicto_nombre'], true)) {
            throw new RuntimeException('Personalizar solo está disponible para los conflictos de precio o de nombre.');
        }
        $nombre = trim((string)($personalizado['nombre'] ?? ''));
        // En un conflicto de nombre el precio ya es el mismo en los dos negocios
        // (es justamente por qué cayó ahí): si no se escribió uno nuevo, se deja
        // ese precio tal cual y solo se decide el nombre.
        $precioVenta = $personalizado['precio_venta'] ?? null;
        if ($precioVenta === null && $it['grupo'] === 'conflicto_nombre') {
            $precioVenta = $it['precio_venta_a'];
        }
        if ($nombre === '' || $precioVenta === null || $precioVenta < 0) {
            throw new RuntimeException('Ingresa un nombre y un precio de venta válidos.');
        }
        // El resto de los datos (costo, mayoreo, departamento, tipo de venta) se
        // completan con los del negocio A, que siempre existen en un conflicto.
        $pdo->prepare(
            "UPDATE producto_unificacion_items SET decision = 'personalizado', resuelto = 1,
                codigo_final = codigo_a, nombre_final = ?, precio_venta_final = ?,
                precio_costo_final = precio_costo_a, precio_mayoreo_final = precio_mayoreo_a,
                departamento_final = departamento_a, tipo_venta_final = tipo_venta_a
              WHERE id = ?"
        )->execute([$nombre, $precioVenta, $itemId]);
        return;
    }

    throw new RuntimeException('Decisión no reconocida.');
}

/** Descarta una unificación en curso: no toca el catálogo real. */
function descartar_unificacion(PDO $pdo, int $id): void
{
    $pdo->prepare("UPDATE producto_unificaciones SET estado = 'descartada' WHERE id = ? AND estado = 'revisando'")
        ->execute([$id]);
}

/**
 * Aplica una unificación ya resuelta: escribe el catálogo real (upsert por
 * código, igual que reemplazar_catalogo — nunca borra) en una sola
 * transacción. Lanza RuntimeException si queda algo sin decidir.
 * Devuelve ['nuevos'=>N, 'actualizados'=>M].
 */
function aplicar_unificacion(PDO $pdo, int $id, array $meta): array
{
    $unif = obtener_unificacion($pdo, $id);
    if (!$unif) throw new RuntimeException('La unificación no existe.');
    if ($unif['estado'] !== 'revisando') throw new RuntimeException('Esta unificación ya fue aplicada o descartada.');
    if (contar_pendientes_unificacion($pdo, $id) > 0) {
        throw new RuntimeException('Todavía hay productos sin resolver. Decide cada conflicto antes de aplicar.');
    }

    $pdo->beginTransaction();
    try {
        $nuevos = 0;
        $actualizados = 0;
        $usuarioId = $meta['usuario_id'] ?? null;

        $existe = $pdo->prepare("SELECT id FROM productos WHERE codigo = ?");
        $insertar = $pdo->prepare(
            "INSERT INTO productos
               (codigo, nombre, precio_costo, precio_venta, precio_mayoreo,
                departamento, tipo_venta, creado_por, actualizado_por)
             VALUES (?,?,?,?,?,?,?,?,?)"
        );
        $actualizar = $pdo->prepare(
            "UPDATE productos SET nombre=?, precio_costo=?, precio_venta=?, precio_mayoreo=?,
                departamento=?, tipo_venta=?, actualizado_por=? WHERE codigo=?"
        );

        $guardarFila = function (string $codigo, string $nombre, ?float $costo, ?float $venta,
                                  ?float $mayoreo, ?string $depto, ?string $tipo)
            use ($existe, $insertar, $actualizar, $usuarioId, &$nuevos, &$actualizados): void {
            $existe->execute([$codigo]);
            if ($existe->fetch()) {
                $actualizar->execute([$nombre, $costo, $venta, $mayoreo, $depto, $tipo, $usuarioId, $codigo]);
                $actualizados++;
            } else {
                $insertar->execute([$codigo, $nombre, $costo, $venta, $mayoreo, $depto, $tipo, $usuarioId, $usuarioId]);
                $nuevos++;
            }
        };

        foreach (items_unificacion($pdo, $id) as $it) {
            if ($it['grupo'] === 'revisar' && $it['decision'] === 'distinto') {
                // Resultaron ser dos productos distintos: cada código entra por su cuenta.
                $guardarFila($it['codigo_a'], $it['nombre_a'], $it['precio_costo_a'],
                    $it['precio_venta_a'], $it['precio_mayoreo_a'], $it['departamento_a'], $it['tipo_venta_a']);
                $guardarFila($it['codigo_b'], $it['nombre_b'], $it['precio_costo_b'],
                    $it['precio_venta_b'], $it['precio_mayoreo_b'], $it['departamento_b'], $it['tipo_venta_b']);
                continue;
            }
            $guardarFila($it['codigo_final'], $it['nombre_final'], $it['precio_costo_final'],
                $it['precio_venta_final'], $it['precio_mayoreo_final'],
                $it['departamento_final'], $it['tipo_venta_final']);
        }

        $pdo->prepare(
            "UPDATE producto_unificaciones
                SET estado = 'aplicada', aplicado_por = ?, aplicado_en = NOW(),
                    total_nuevos = ?, total_actualizados = ?
              WHERE id = ?"
        )->execute([$usuarioId, $nuevos, $actualizados, $id]);

        $pdo->commit();
        return ['nuevos' => $nuevos, 'actualizados' => $actualizados];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Últimas N unificaciones (cualquier estado), para el historial. */
function historial_unificaciones(PDO $pdo, int $limite = 8): array
{
    $st = $pdo->prepare(
        "SELECT u.*, na.nombre AS negocio_a_nombre, nb.nombre AS negocio_b_nombre,
                uc.nombre AS creado_por_nombre
           FROM producto_unificaciones u
           JOIN negocios na ON na.id = u.negocio_a_id
           JOIN negocios nb ON nb.id = u.negocio_b_id
      LEFT JOIN usuarios uc ON uc.id = u.creado_por
       ORDER BY u.creado_en DESC, u.id DESC LIMIT $limite"
    );
    $st->execute();
    return $st->fetchAll();
}
