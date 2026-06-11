<?php
/**
 * Parser de los correos de "Corte del turno" que envía Eleventa (POS) y
 * registro de los cortes en la base de datos (módulo Ingresos).
 *
 * Eleventa no tiene API: el único canal automático es el correo que manda al
 * cerrar cada turno. Este archivo entiende ese correo (HTML o texto pegado),
 * extrae todas las cifras y las deja en las tablas cortes / corte_movimientos /
 * corte_departamentos / cajeros. El correo crudo siempre queda guardado en
 * correos_corte, así que si Eleventa cambia el formato se ajusta este parser
 * y se reprocesa sin perder nada.
 *
 * Formato observado (Eleventa 5.50, ver captura real):
 *   Asunto: "Corte del turno de {cajero} del dia {fecha} de la {Caja ...}"
 *   Cuerpo: secciones "Resúmen", "Dinero en Caja", "Ventas", "Entradas",
 *   "Salidas", "Ventas por Departamento", "Ingresos Contado", ...
 *   Montos chilenos: "$633.020" (punto = miles).
 */

/**
 * Convierte el cuerpo (HTML o texto plano) a líneas de texto normalizadas.
 * En el HTML de Eleventa cada dato es una fila de tabla: separamos celdas con
 * tabulador y filas con salto de línea para que "etiqueta<TAB>$monto" quede
 * en una sola línea, igual que cuando se copia/pega desde Gmail.
 */
function eleventa_html_a_texto(string $cuerpo): string
{
    $t = $cuerpo;
    if (strpos($t, '<') !== false) {
        $t = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $t);
        $t = preg_replace('/<\/(td|th)>/i', "\t", $t);
        $t = preg_replace('/<\/(tr|p|div|h[1-6]|table|li)>/i', "\n", $t);
        $t = preg_replace('/<br\s*\/?>/i', "\n", $t);
        $t = strip_tags($t);
        $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    $t = str_replace("\xC2\xA0", ' ', $t);            // nbsp -> espacio
    $t = preg_replace('/\r\n?/', "\n", $t);
    $lineas = [];
    foreach (explode("\n", $t) as $l) {
        $l = trim(preg_replace('/[ \t]+/', ' ', str_replace("\t", ' ', $l)));
        if ($l !== '') $lineas[] = $l;
    }
    return implode("\n", eleventa_unir_filas($lineas));
}

/**
 * Normaliza las filas del corte para que "etiqueta" y "monto" queden en la
 * MISMA línea, sin importar si el correo los trae juntos ("Fondo de Caja
 * $150.000") o separados en dos líneas. Algunas versiones/plantillas de
 * Eleventa los parten; sin esto, una etiqueta suelta como "Entradas" o
 * "Salidas" parece un título de sección y corta la lectura.
 * También une la hora con su movimiento ("4:55pm" + "Pan Santa E $23.400").
 */
function eleventa_unir_filas(array $lineas): array
{
    $esMonto = fn($l) => (bool)preg_match('/^[+\-]?\s*\$?\s*\d[\d.,]*$/', $l);
    $esHora  = fn($l) => (bool)preg_match('/^\d{1,2}:\d{2}\s*[ap]\.?m\.?$/i', $l);

    // Paso 1: etiqueta + monto que viene en la línea siguiente.
    $paso1 = [];
    for ($i = 0, $n = count($lineas); $i < $n; $i++) {
        $l = $lineas[$i];
        if ($i + 1 < $n && !$esMonto($l) && !$esHora($l) && $esMonto($lineas[$i + 1])) {
            $paso1[] = $l . ' ' . $lineas[$i + 1];
            $i++;
        } else {
            $paso1[] = $l;
        }
    }

    // Paso 2: hora + la fila siguiente (descripción ya con su monto).
    $paso2 = [];
    for ($i = 0, $n = count($paso1); $i < $n; $i++) {
        $l = $paso1[$i];
        if ($i + 1 < $n && $esHora($l)) {
            $paso2[] = $l . ' ' . $paso1[$i + 1];
            $i++;
        } else {
            $paso2[] = $l;
        }
    }
    return $paso2;
}

/** "$633.020" / "+ $0" / "- $73.700" -> número (punto chileno = miles). */
function eleventa_parsear_monto(?string $s): ?float
{
    if ($s === null) return null;
    $neg = strpos($s, '-') !== false;
    $d = preg_replace('/[^\d]/', '', $s);
    if ($d === '') return null;
    return (float)$d * ($neg ? -1 : 1);
}

/** "04/Jun/2026 9:53 am" (meses en español) -> "2026-06-04 09:53:00" o null. */
function eleventa_parsear_fechahora(string $s): ?string
{
    $meses = ['ene'=>1,'feb'=>2,'mar'=>3,'abr'=>4,'may'=>5,'jun'=>6,
              'jul'=>7,'ago'=>8,'sep'=>9,'oct'=>10,'nov'=>11,'dic'=>12];
    if (!preg_match('/(\d{1,2})\/(\p{L}{3})\/(\d{4})\s+(\d{1,2}):(\d{2})\s*([ap])\.?\s*m/iu', $s, $m)) {
        return null;
    }
    $mes = $meses[mb_strtolower($m[2], 'UTF-8')] ?? null;
    if ($mes === null) return null;
    $hora = (int)$m[4] % 12 + (strtolower($m[6]) === 'p' ? 12 : 0);
    return sprintf('%04d-%02d-%02d %02d:%02d:00', (int)$m[3], $mes, (int)$m[1], $hora, (int)$m[5]);
}

/**
 * Devuelve el texto de una sección del corte (entre su título y el título
 * siguiente), o null si la sección no aparece en el correo.
 */
function eleventa_seccion(string $texto, string $titulo): ?string
{
    // Títulos conocidos que delimitan secciones (cualquier variación de acentos).
    $titulos = ['Res[uú]men', 'Dinero en Caja', 'Ventas por Departamento',
                'Ingresos Contado', 'Pagos de Cr[eé]ditos', 'Clientes con m[aá]s ventas',
                'Clientes con m[aá]s ganancias', 'Ventas', 'Entradas', 'Salidas',
                'Generado por eleventa'];
    if (!preg_match('/^\s*' . $titulo . '\s*$/miu', $texto, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $ini = $m[0][1] + strlen($m[0][0]);
    $fin = strlen($texto);
    foreach ($titulos as $t) {
        if (preg_match('/^\s*' . $t . '\s*$/miu', $texto, $m2, PREG_OFFSET_CAPTURE, $ini)) {
            $fin = min($fin, $m2[0][1]);
        }
    }
    return substr($texto, $ini, $fin - $ini);
}

/** Busca "Etiqueta ... $monto" dentro de un trozo de texto. */
function eleventa_monto_de(?string $seccion, string $etiqueta): ?float
{
    if ($seccion === null) return null;
    if (!preg_match('/^' . $etiqueta . '\s*[:]?\s*([+\-]?\s*\$\s*[\d.,]+|[+\-]?\s*[\d.,]+)\s*$/miu', $seccion, $m)) {
        return null;
    }
    return eleventa_parsear_monto($m[1]);
}

/**
 * Interpreta un correo de corte de Eleventa.
 * Devuelve un arreglo con todos los campos del corte, o lanza
 * InvalidArgumentException con un mensaje claro si el texto no parece un corte.
 */
function parsear_corte_eleventa(string $cuerpo, string $asunto = ''): array
{
    $t = eleventa_html_a_texto($cuerpo);

    if (stripos($t, 'Corte del turno') === false && stripos($asunto, 'Corte del turno') === false) {
        throw new InvalidArgumentException('El texto no parece un corte de Eleventa (no dice "Corte del turno").');
    }

    // --- Cajero y rango del turno (cabecera del correo) ---
    $cajero = null;
    if (preg_match('/Cajero\s*:\s*(.+)$/miu', $t, $m)) {
        $cajero = trim($m[1]);
    } elseif (preg_match('/Corte del turno de (.+?) del d[ií]a /iu', $asunto, $m)) {
        $cajero = trim($m[1]);
    }
    if ($cajero === null || $cajero === '') {
        throw new InvalidArgumentException('No se encontró el cajero en el corte.');
    }

    $abierto = $cerrado = null;
    if (preg_match('/(\d{1,2}\/\p{L}{3}\/\d{4}\s+\d{1,2}:\d{2}\s*[ap]\.?\s*m\.?)\s+al\s+(\d{1,2}\/\p{L}{3}\/\d{4}\s+\d{1,2}:\d{2}\s*[ap]\.?\s*m\.?)/iu', $t, $m)) {
        $abierto = eleventa_parsear_fechahora($m[1]);
        $cerrado = eleventa_parsear_fechahora($m[2]);
    }
    if ($cerrado === null) {
        throw new InvalidArgumentException('No se encontró el rango de fechas del turno (ej. "04/Jun/2026 9:53 am al ...").');
    }

    // --- Caja (viene al final del asunto: "... de la Caja Principal").
    // El .* inicial es voraz para quedarnos con el ÚLTIMO " de la " (el nombre
    // del cajero también puede contener "de la"). ---
    $caja = null;
    if ($asunto !== '' && preg_match('/^.* de la (.{1,80})$/iu', trim($asunto), $m)) {
        $caja = trim($m[1]);
    }

    // --- Secciones ---
    $resumen  = eleventa_seccion($t, 'Res[uú]men');
    $dinero   = eleventa_seccion($t, 'Dinero en Caja');
    $ventas   = eleventa_seccion($t, 'Ventas');
    $entradas = eleventa_seccion($t, 'Entradas');
    $salidas  = eleventa_seccion($t, 'Salidas');
    $deptos   = eleventa_seccion($t, 'Ventas por Departamento');
    $contado  = eleventa_seccion($t, 'Ingresos Contado');

    $numVentas = null;
    if ($resumen !== null && preg_match('/N[uú]mero de ventas\s*:?\s*([\d.,]+)/iu', $resumen, $m)) {
        $numVentas = (int)eleventa_parsear_monto($m[1]);
    }

    $datos = [
        'cajero'               => $cajero,
        'caja'                 => $caja,
        'abierto_en'           => $abierto,
        'cerrado_en'           => $cerrado,
        'ventas_totales'       => eleventa_monto_de($resumen, 'Ventas Totales')
                                  ?? eleventa_monto_de($ventas, 'Total de Ventas'),
        'ganancia'             => eleventa_monto_de($resumen, 'Ganancia'),
        'numero_ventas'        => $numVentas,
        'fondo_caja'           => eleventa_monto_de($dinero, 'Fondo de Caja'),
        'ventas_efectivo'      => eleventa_monto_de($dinero, 'Ventas en Efectivo')
                                  ?? eleventa_monto_de($ventas, 'En Efectivo'),
        'abonos_efectivo'      => eleventa_monto_de($dinero, 'Abonos en Efectivo'),
        'entradas_caja'        => eleventa_monto_de($dinero, 'Entradas')
                                  ?? eleventa_monto_de($entradas, 'Total Entradas'),
        'salidas_caja'         => eleventa_monto_de($dinero, 'Salidas')
                                  ?? eleventa_monto_de($salidas, 'Total Salidas'),
        'efectivo_esperado'    => eleventa_monto_de($dinero, 'Esperado'),
        'ventas_tarjeta'       => eleventa_monto_de($ventas, 'Con Tarjeta')
                                  ?? eleventa_monto_de($contado, 'Ventas Tarjeta'),
        'ventas_credito'       => eleventa_monto_de($ventas, 'A cr[eé]dito'),
        'ventas_vales'         => eleventa_monto_de($ventas, 'Con Vales'),
        'ventas_transferencia' => eleventa_monto_de($contado, 'Ventas Transferencia'),
        'movimientos'          => [],
        'departamentos'        => [],
    ];

    // Las salidas se guardan en positivo (el signo lo da el tipo de movimiento).
    if ($datos['salidas_caja'] !== null) $datos['salidas_caja'] = abs($datos['salidas_caja']);
    if ($datos['entradas_caja'] !== null) $datos['entradas_caja'] = abs($datos['entradas_caja']);

    if ($datos['ventas_totales'] === null && $datos['efectivo_esperado'] === null) {
        throw new InvalidArgumentException('No se encontraron los montos del corte (¿se pegó el correo completo?).');
    }

    // --- Movimientos: "4:55pm Pan Santa E $23.400" en Entradas/Salidas ---
    foreach (['entrada' => $entradas, 'salida' => $salidas] as $tipo => $sec) {
        if ($sec === null) continue;
        if (preg_match_all('/^(\d{1,2}:\d{2}\s*[ap]\.?m\.?)\s+(.+?)\s+\$\s*([\d.,]+)\s*$/miu', $sec, $ms, PREG_SET_ORDER)) {
            foreach ($ms as $mm) {
                $desc = trim($mm[2]);
                if (stripos($desc, 'Total') === 0) continue;
                $datos['movimientos'][] = [
                    'tipo'        => $tipo,
                    'hora'        => str_replace(' ', '', strtolower($mm[1])),
                    'descripcion' => $desc,
                    'monto'       => eleventa_parsear_monto($mm[3]),
                ];
            }
        }
    }

    // --- Ventas por departamento: "Coca-Cola $28.650" (sin la fila Total) ---
    if ($deptos !== null) {
        if (preg_match_all('/^(.+?)\s+\$\s*([\d.,]+)\s*$/mu', $deptos, $ms, PREG_SET_ORDER)) {
            foreach ($ms as $mm) {
                $nombre = trim($mm[1], " -\t");
                if ($nombre === '' || stripos($nombre, 'Total') === 0) continue;
                if ($nombre === 'Sin Departamento') $nombre = '(Sin departamento)';
                $datos['departamentos'][] = [
                    'departamento' => $nombre,
                    'monto'        => eleventa_parsear_monto($mm[2]),
                ];
            }
        }
    }

    return $datos;
}

/**
 * Inserta o actualiza un corte (idempotente por negocio+cierre+cajero).
 * Crea el cajero si es la primera vez que aparece. Reemplaza completos los
 * movimientos y departamentos (mismo patrón que el detalle de facturas).
 * Devuelve el id del corte.
 */
function registrar_corte(PDO $pdo, int $negocioId, ?int $correoId, array $d): int
{
    $propia = !$pdo->inTransaction();
    if ($propia) $pdo->beginTransaction();
    try {
        // Cajero (auto-aprendido por negocio)
        $st = $pdo->prepare("SELECT id FROM cajeros WHERE negocio_id = ? AND nombre = ?");
        $st->execute([$negocioId, $d['cajero']]);
        $cajeroId = $st->fetchColumn();
        if (!$cajeroId) {
            $pdo->prepare("INSERT INTO cajeros (negocio_id, nombre) VALUES (?, ?)")
                ->execute([$negocioId, $d['cajero']]);
            $cajeroId = (int)$pdo->lastInsertId();
        }

        $campos = [
            'negocio_id' => $negocioId, 'correo_id' => $correoId, 'cajero_id' => $cajeroId,
            'caja' => $d['caja'], 'abierto_en' => $d['abierto_en'], 'cerrado_en' => $d['cerrado_en'],
            'ventas_totales' => $d['ventas_totales'], 'ganancia' => $d['ganancia'],
            'numero_ventas' => $d['numero_ventas'], 'fondo_caja' => $d['fondo_caja'],
            'ventas_efectivo' => $d['ventas_efectivo'], 'abonos_efectivo' => $d['abonos_efectivo'],
            'entradas_caja' => $d['entradas_caja'], 'salidas_caja' => $d['salidas_caja'],
            'efectivo_esperado' => $d['efectivo_esperado'], 'ventas_tarjeta' => $d['ventas_tarjeta'],
            'ventas_credito' => $d['ventas_credito'], 'ventas_vales' => $d['ventas_vales'],
            'ventas_transferencia' => $d['ventas_transferencia'],
        ];

        $st = $pdo->prepare("SELECT id FROM cortes WHERE negocio_id = ? AND cerrado_en = ? AND cajero_id = ?");
        $st->execute([$negocioId, $d['cerrado_en'], $cajeroId]);
        $corteId = $st->fetchColumn();

        if ($corteId) {
            $sets = implode(', ', array_map(fn($c) => "$c = :$c", array_keys($campos)));
            $campos['id'] = $corteId;
            $pdo->prepare("UPDATE cortes SET $sets WHERE id = :id")->execute($campos);
        } else {
            $cols = implode(', ', array_keys($campos));
            $ph   = implode(', ', array_map(fn($c) => ":$c", array_keys($campos)));
            $pdo->prepare("INSERT INTO cortes ($cols) VALUES ($ph)")->execute($campos);
            $corteId = (int)$pdo->lastInsertId();
        }

        $pdo->prepare("DELETE FROM corte_movimientos WHERE corte_id = ?")->execute([$corteId]);
        $ins = $pdo->prepare(
            "INSERT INTO corte_movimientos (corte_id, tipo, hora, descripcion, monto) VALUES (?, ?, ?, ?, ?)"
        );
        foreach ($d['movimientos'] as $mv) {
            $ins->execute([$corteId, $mv['tipo'], $mv['hora'], $mv['descripcion'], $mv['monto']]);
        }

        $pdo->prepare("DELETE FROM corte_departamentos WHERE corte_id = ?")->execute([$corteId]);
        $ins = $pdo->prepare(
            "INSERT INTO corte_departamentos (corte_id, departamento, monto) VALUES (?, ?, ?)"
        );
        foreach ($d['departamentos'] as $dp) {
            $ins->execute([$corteId, $dp['departamento'], $dp['monto']]);
        }

        if ($propia) $pdo->commit();
        return (int)$corteId;
    } catch (Throwable $e) {
        if ($propia && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Identifica el negocio por la dirección a la que llegó el correo. Acepta dos
 * formas según lo permita el proveedor de correo:
 *   - reenvío/alias propio:   corte-{slug}@dominio  o  corte.{slug}@dominio
 *   - subdirección (Titan):   cortes+{slug}@dominio
 * Devuelve el id del negocio cuyo slug coincide, o null.
 */
function negocio_por_destinatario(PDO $pdo, ?string $destinatario): ?int
{
    if (!$destinatario || !preg_match('/cortes?[-.+]([a-z0-9\-]+)@/i', $destinatario, $m)) {
        return null;
    }
    $st = $pdo->prepare("SELECT id FROM negocios WHERE slug = ?");
    $st->execute([strtolower($m[1])]);
    $id = $st->fetchColumn();
    return $id ? (int)$id : null;
}

/**
 * Cuando no se puede identificar el negocio por la dirección (ej. casilla
 * única sin subdirección) y existe UN solo negocio, ese es. Con dos o más
 * devuelve null para no atribuir un corte al negocio equivocado.
 */
function negocio_unico(PDO $pdo): ?int
{
    $filas = $pdo->query("SELECT id FROM negocios LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
    return count($filas) === 1 ? (int)$filas[0] : null;
}

/**
 * Procesa una fila de correos_corte: parsea el cuerpo y registra el corte.
 * Actualiza estado/error de la fila. Devuelve el id del corte o null si falló.
 */
function procesar_correo_corte(PDO $pdo, array $correo): ?int
{
    try {
        $negocioId = $correo['negocio_id'] ? (int)$correo['negocio_id']
                   : (negocio_por_destinatario($pdo, $correo['destinatario'] ?? null)
                      ?? negocio_unico($pdo));
        if (!$negocioId) {
            throw new InvalidArgumentException(
                'No se pudo identificar el negocio. Con varios negocios, configura el correo '
                . 'de cada Eleventa como cortes+{slug}@... (el slug está en la página Negocios).');
        }
        $datos = parsear_corte_eleventa((string)$correo['cuerpo'], (string)($correo['asunto'] ?? ''));
        $corteId = registrar_corte($pdo, $negocioId, (int)$correo['id'], $datos);
        $pdo->prepare(
            "UPDATE correos_corte SET negocio_id = ?, estado = 'procesado', error = NULL, procesado_en = NOW() WHERE id = ?"
        )->execute([$negocioId, $correo['id']]);
        return $corteId;
    } catch (Throwable $e) {
        $pdo->prepare(
            "UPDATE correos_corte SET estado = 'error', error = ?, procesado_en = NOW() WHERE id = ?"
        )->execute([substr($e->getMessage(), 0, 500), $correo['id']]);
        return null;
    }
}
