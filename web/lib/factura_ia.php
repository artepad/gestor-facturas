<?php
/**
 * Extracción de los datos de cabecera de una factura con IA (Claude),
 * llamada directo desde la web vía curl a la API de Anthropic — sin pasar
 * por el programa de escritorio. Mismo modelo y mismo criterio que usa
 * src/classifier.py + src/validacion.py + src/main.py del lado de Python
 * (ver web/CLAUDE.md, sección "Facturas"): se decide primero si el PDF es
 * realmente una factura, se extraen proveedor/RUT/folio/fecha/total, y si el
 * total sale sospechosamente bajo o la fecha queda en el futuro, se hace una
 * segunda lectura enfocada solo en ese dato antes de entregar el resultado.
 *
 * A diferencia del programa de escritorio (que renderiza la primera página
 * a imagen con PyMuPDF), acá se manda el PDF completo como documento nativo
 * a la API de Claude: el propio modelo lo lee entero, sin necesitar una
 * librería de PDF en PHP.
 *
 * El detalle de productos (línea por línea) queda fuera de este primer
 * paso a propósito — es la parte menos confiable de la IA, y por eso en el
 * programa de escritorio también es un paso manual aparte.
 */

/** Tope de tamaño del PDF para esta subida (más chico que el de la sincronización: acá es en vivo, no en cola). */
const FACTURA_IA_MAX_BYTES = 15 * 1024 * 1024;

/**
 * Convierte un monto en texto a número, entendiendo la convención chilena
 * (el punto separa miles, no decimales). Puerto directo de
 * src/validacion.py::parsear_monto_chileno, sección de strings (la IA
 * siempre devuelve el monto como texto).
 */
function parsear_monto_clp(?string $valor): ?float
{
    if ($valor === null) return null;
    $texto = trim(str_replace("\u{00A0}", ' ', $valor));
    if ($texto === '') return null;

    if (!preg_match('/[-+]?\d[\d\s.,]*/', $texto, $m)) {
        return null;
    }
    $numero = preg_replace('/\s+/', '', $m[0]);
    if ($numero === '') return null;

    $tienePunto = strpos($numero, '.') !== false;
    $tieneComa = strpos($numero, ',') !== false;

    if ($tienePunto && $tieneComa) {
        $normalizado = strrpos($numero, ',') > strrpos($numero, '.')
            ? str_replace(',', '.', str_replace('.', '', $numero))
            : str_replace(',', '', $numero);
    } elseif ($tienePunto) {
        // CLP: el punto siempre es separador de miles.
        $normalizado = str_replace('.', '', $numero);
    } elseif ($tieneComa) {
        $partes = explode(',', $numero);
        $ultima = end($partes);
        $normalizado = (count($partes) > 1 && strlen($ultima) === 3)
            ? str_replace(',', '', $numero)
            : str_replace(',', '.', $numero);
    } else {
        $normalizado = $numero;
    }

    if (!is_numeric($normalizado)) return null;
    return round((float)$normalizado);
}

/**
 * Convierte la fecha devuelta por la IA (DD-MM-YYYY, u otros formatos
 * usuales) a YYYY-MM-DD para guardar en la BD. Puerto de
 * src/validacion.py::parsear_fecha_factura. Null si no se puede leer.
 */
function fecha_factura_a_iso(?string $fecha): ?string
{
    if ($fecha === null) return null;
    $texto = trim($fecha);
    if ($texto === '') return null;

    foreach (['d-m-Y', 'd/m/Y', 'd.m.Y', 'Y-m-d', 'Y/m/d'] as $formato) {
        $dt = DateTime::createFromFormat('!' . $formato, $texto);
        if ($dt && $dt->format($formato) === $texto) {
            return $dt->format('Y-m-d');
        }
    }
    if (preg_match('/\b(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{2,4})\b/', $texto, $m)) {
        $dia = (int)$m[1];
        $mes = (int)$m[2];
        $anio = (int)$m[3];
        if ($anio < 100) $anio += 2000;
        if (checkdate($mes, $dia, $anio)) {
            return sprintf('%04d-%02d-%02d', $anio, $mes, $dia);
        }
    }
    return null;
}

/**
 * true si la fecha (YYYY-MM-DD) es razonable para una factura: no futura
 * (con 1 día de tolerancia, igual que el programa de escritorio) ni
 * anterior al año 2000.
 */
function fecha_factura_es_razonable(string $fechaIso): bool
{
    $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', $fechaIso);
    if (!$fecha) return false;
    $limite = new DateTimeImmutable('tomorrow');
    return $fecha <= $limite && (int)$fecha->format('Y') >= 2000;
}

function factura_ia_bloque_pdf(string $rutaPdf): array
{
    $datos = file_get_contents($rutaPdf);
    if ($datos === false) {
        throw new RuntimeException('No se pudo leer el PDF para analizarlo.');
    }
    return [
        'type' => 'document',
        'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode($datos)],
    ];
}

/** Llamada genérica a la API de Mensajes de Anthropic forzando una sola herramienta. */
function factura_ia_llamar(array $cfgIa, string $prompt, array $herramienta, array $contenidoUsuario, int $maxTokens): array
{
    $payload = [
        'model' => $cfgIa['modelo'],
        'max_tokens' => $maxTokens,
        'system' => $prompt,
        'tools' => [$herramienta],
        'tool_choice' => ['type' => 'tool', 'name' => $herramienta['name']],
        'messages' => [['role' => 'user', 'content' => $contenidoUsuario]],
    ];

    // Por streams (file_get_contents + contexto HTTP) en vez de ext-curl: no
    // depende de que el hosting tenga curl habilitado (allow_url_fopen sí
    // está activo por defecto en prácticamente cualquier PHP, incluido
    // HostGator) y evita además un problema puntual de este XAMPP local,
    // donde Apache no carga la extensión curl aunque la CLI sí.
    $contexto = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", [
                'Content-Type: application/json',
                'x-api-key: ' . $cfgIa['api_key'],
                'anthropic-version: 2023-06-01',
            ]),
            'content' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'timeout' => 90,
            'ignore_errors' => true, // para poder leer el body incluso si la API devuelve 4xx/5xx
        ],
    ]);
    $crudo = @file_get_contents('https://api.anthropic.com/v1/messages', false, $contexto);
    if ($crudo === false) {
        $error = error_get_last()['message'] ?? 'motivo desconocido';
        throw new RuntimeException('No se pudo contactar al servicio de IA: ' . $error);
    }
    $codigo = 0;
    foreach ($http_response_header ?? [] as $cabecera) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $cabecera, $m)) {
            $codigo = (int)$m[1];
        }
    }

    $respuesta = json_decode($crudo, true);
    if ($codigo !== 200 || !is_array($respuesta)) {
        $msg = is_array($respuesta) ? ($respuesta['error']['message'] ?? null) : null;
        throw new RuntimeException($msg ?? "El servicio de IA respondió con un error (HTTP $codigo).");
    }

    foreach ($respuesta['content'] ?? [] as $bloque) {
        if (($bloque['type'] ?? '') === 'tool_use' && ($bloque['name'] ?? '') === $herramienta['name']) {
            return is_array($bloque['input'] ?? null) ? $bloque['input'] : [];
        }
    }
    throw new RuntimeException('El servicio de IA no devolvió los datos esperados.');
}

const FACTURA_IA_HERRAMIENTA = [
    'name' => 'registrar_factura',
    'description' => 'Registra los datos de cabecera extraídos de una factura comercial chilena.',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'es_factura' => [
                'type' => 'boolean',
                'description' => 'true SOLO si el documento es una FACTURA comercial chilena real '
                    . '(factura, factura electrónica, factura exenta) con detalle de productos o '
                    . 'servicios, RUT del emisor, número de folio y montos (neto/IVA/total o '
                    . 'exento/total). false si es otro tipo de documento: comprobante de pago o '
                    . 'transferencia, voucher, cotización, orden de compra, guía de despacho, '
                    . 'estado de cuenta, recibo, certificado, contrato, contraseña, ticket, o '
                    . 'cualquier PDF que no sea una factura. Si hay dudas o falta el detalle de '
                    . 'productos con precios, marca false.',
            ],
            'tipo_documento' => [
                'type' => 'string',
                'description' => "Tipo real del documento, en minúsculas. Ejemplos: 'factura', "
                    . "'factura electronica', 'factura exenta', 'boleta', 'comprobante de "
                    . "transferencia', 'comprobante de pago', 'voucher', 'cotizacion', 'orden de "
                    . "compra', 'guia de despacho', 'estado de cuenta', 'recibo', 'certificado', "
                    . "'contrato', 'otro'.",
            ],
            'proveedor' => [
                'type' => 'string',
                'description' => "Nombre comercial corto de la MARCA visible en el logo de la "
                    . "factura. Usa solo el nombre núcleo de la marca: omite palabras genéricas "
                    . "como 'Comercial', 'Compañía', 'Distribuidora' o 'Sociedad' (ej: si el logo "
                    . "dice 'Comercial CCU', responde 'CCU'). Sin tildes, puntos ni sufijos legales "
                    . "(S.A., Ltda., EIRL). Sin espacios al inicio/fin.",
            ],
            'razon_social' => [
                'type' => ['string', 'null'],
                'description' => "Razón social legal completa del emisor según el SII (ej: "
                    . "'Comercial Santa Elena S.A.'). Null si no es legible.",
            ],
            'rut_emisor' => [
                'type' => ['string', 'null'],
                'description' => 'RUT del emisor con formato XX.XXX.XXX-X. Es el identificador legal '
                    . 'único de la empresa: extráelo con máxima precisión, dígito por dígito. Null '
                    . 'solo si es realmente ilegible.',
            ],
            'fecha' => [
                'type' => ['string', 'null'],
                'description' => 'Fecha de EMISIÓN de la factura en formato DD-MM-YYYY. No uses '
                    . 'fecha de vencimiento, recepción, timbre electrónico, resolución del SII, '
                    . 'autorización, acuse ni despacho. Null si no es legible.',
            ],
            'numero_factura' => [
                'type' => ['string', 'null'],
                'description' => 'Número o folio de la factura. Null si no es legible.',
            ],
            'total' => [
                'type' => ['string', 'null'],
                'description' => "Monto total a pagar. Para CLP conserva el texto del monto tal "
                    . "como aparece en la factura, incluyendo puntos de miles si existen (ej: "
                    . "'221.713'). No lo conviertas a decimal, no quites ceros y no redondees.",
            ],
            'moneda' => [
                'type' => ['string', 'null'],
                'description' => 'Código de moneda: CLP, USD, EUR, etc.',
            ],
            'confianza' => [
                'type' => 'number',
                'description' => 'Nivel de certeza global de 0.0 a 1.0.',
            ],
            'notas' => [
                'type' => ['string', 'null'],
                'description' => 'Observaciones si algo es dudoso o ilegible.',
            ],
        ],
        'required' => ['es_factura', 'tipo_documento', 'proveedor', 'fecha', 'confianza'],
    ],
];

const FACTURA_IA_PROMPT = 'Eres un asistente experto en extraer datos de facturas comerciales '
    . "chilenas. Te entrego el PDF de la factura (puede ser un escaneo).\n\n"
    . "PASO 1 — Decide si el documento ES una factura comercial chilena.\n"
    . "Una factura DEBE tener todos estos elementos: encabezado 'FACTURA' / 'FACTURA "
    . "ELECTRÓNICA' / 'FACTURA EXENTA', RUT del emisor, número de folio, y un DETALLE DE "
    . "PRODUCTOS o servicios con cantidades y precios (no solo un total). Si el documento es "
    . "un comprobante de pago/transferencia, voucher, cotización, orden de compra, guía de "
    . "despacho, estado de cuenta, recibo, certificado o cualquier otro PDF que NO sea una "
    . "factura comercial, pon `es_factura=false` y describe qué es en `tipo_documento`. NO "
    . "inventes datos en los campos siguientes; rellénalos con lo que veas o déjalos en null. "
    . "Sin detalle de productos con precios = no es factura.\n\n"
    . "PASO 2 — Solo si es factura, extrae los datos.\n"
    . "Identifica los datos del EMISOR de la factura (NO del cliente que la recibe). Devuelve "
    . "TANTO la marca comercial visible en el logo (campo `proveedor`) COMO la razón social "
    . "legal y RUT del emisor (campos `razon_social` y `rut_emisor`). Si la marca y la razón "
    . "social son la misma empresa, igual repite el nombre en ambos campos.\n\n"
    . "El RUT del emisor es el dato MÁS importante: es lo que permite identificar a la empresa "
    . "de forma única aunque la marca aparezca escrita de distintas maneras. Léelo con cuidado "
    . "dígito por dígito.\n\n"
    . "La fecha debe ser la FECHA DE EMISIÓN de la factura. No confundas esa fecha con fechas "
    . "de vencimiento, recepción, despacho, resolución/autorización SII, timbre electrónico, "
    . "acuse de recibo ni vencimiento del CAF. Si una fecha parece estar en el futuro respecto "
    . "de la fecha actual indicada por el sistema, no la uses como fecha de emisión salvo que "
    . "el documento diga claramente que lo es.\n\n"
    . "Para montos en pesos chilenos, el punto normalmente separa miles y la coma separa "
    . "decimales. Por ejemplo, '221.713' son doscientos veintiún mil setecientos trece pesos, "
    . "no 221,713. Conserva el total como texto exacto si aparece con separadores.\n\n"
    . 'Devuelve los datos llamando a la herramienta `registrar_factura`. Si algún campo no se '
    . 'puede leer con certeza, devuelve null en ese campo y menciónalo en `notas`. La confianza '
    . 'debe reflejar honestamente qué tan seguro estás.';

const FACTURA_IA_HERRAMIENTA_FECHA = [
    'name' => 'verificar_fecha_emision',
    'description' => 'Verifica la fecha de emisión visible en una factura chilena.',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'fecha' => [
                'type' => ['string', 'null'],
                'description' => 'Fecha de EMISIÓN en formato DD-MM-YYYY. No uses fecha de '
                    . 'vencimiento, referencia, recepción, timbre, resolución ni CAF.',
            ],
            'evidencia' => [
                'type' => ['string', 'null'],
                'description' => "Texto o zona visible que respalda la fecha, por ejemplo "
                    . "'FECHA EMISION: 27-02-2026'.",
            ],
            'confianza' => ['type' => 'number', 'description' => 'Certeza de 0.0 a 1.0 solo para la fecha.'],
        ],
        'required' => ['fecha', 'confianza'],
    ],
];

const FACTURA_IA_HERRAMIENTA_TOTAL = [
    'name' => 'verificar_total_factura',
    'description' => 'Verifica el monto total visible en una factura chilena.',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'total' => [
                'type' => ['string', 'null'],
                'description' => "Monto TOTAL final tal como aparece en la factura. Conserva "
                    . "puntos de miles y ceros finales. Ej: '12.800' o '178.510'.",
            ],
            'evidencia' => [
                'type' => ['string', 'null'],
                'description' => "Texto o zona visible que respalda el total, por ejemplo "
                    . "'TOTAL FACTURA 12.800'.",
            ],
            'confianza' => ['type' => 'number', 'description' => 'Certeza de 0.0 a 1.0 solo para el total.'],
        ],
        'required' => ['total', 'confianza'],
    ],
];

/** Primera lectura: clasifica el documento y extrae los datos de cabecera. */
function factura_ia_extraer(array $cfgIa, string $rutaPdf): array
{
    $hoy = date('d-m-Y');
    $contenido = [
        factura_ia_bloque_pdf($rutaPdf),
        ['type' => 'text', 'text' => "Fecha actual del sistema: $hoy."],
    ];
    return factura_ia_llamar($cfgIa, FACTURA_IA_PROMPT, FACTURA_IA_HERRAMIENTA, $contenido, 1024);
}

/** Segunda lectura enfocada solo en la fecha (igual que Clasificador.verificar_fecha). */
function factura_ia_verificar_fecha(array $cfgIa, string $rutaPdf, ?string $fechaPrevia): array
{
    $hoy = date('d-m-Y');
    $textoPrevio = $fechaPrevia
        ? "La lectura previa fue $fechaPrevia. Revísala contra el documento antes de responder.\n\n"
        : '';
    $contenido = [
        factura_ia_bloque_pdf($rutaPdf),
        ['type' => 'text', 'text' => "Fecha actual del sistema: $hoy.\n\n$textoPrevio"],
    ];
    $prompt = 'Eres un verificador estricto de fechas en facturas chilenas escaneadas. Tu única '
        . "tarea es leer la FECHA DE EMISIÓN visible en la factura.\n\n"
        . 'No uses fecha de vencimiento, referencia, recepción, despacho, resolución SII, timbre '
        . 'electrónico, CAF, acuse, orden de compra ni fecha del archivo. Si hay varias fechas, '
        . 'elige solamente la que esté rotulada como emisión, emisión factura, fecha factura o '
        . "equivalente.\n\nSi una lectura previa aparece como futura respecto de la fecha actual "
        . 'indicada, sospecha de error de lectura del año y vuelve a mirar dígito por dígito. '
        . 'Devuelve la respuesta llamando a `verificar_fecha_emision`.';
    return factura_ia_llamar($cfgIa, $prompt, FACTURA_IA_HERRAMIENTA_FECHA, $contenido, 512);
}

/** Segunda lectura enfocada solo en el total (igual que Clasificador.verificar_total). */
function factura_ia_verificar_total(array $cfgIa, string $rutaPdf, ?string $totalPrevio): array
{
    $textoPrevio = $totalPrevio !== null
        ? "La lectura previa fue $totalPrevio. Si parece menor a mil pesos, sospecha que se "
            . "interpretó mal el punto de miles.\n\n"
        : '';
    $contenido = [
        factura_ia_bloque_pdf($rutaPdf),
        ['type' => 'text', 'text' => $textoPrevio],
    ];
    $prompt = 'Eres un verificador estricto de montos en facturas chilenas escaneadas. Tu única '
        . "tarea es leer el monto TOTAL FINAL de la factura.\n\n"
        . "En Chile, el punto separa miles en pesos: '12.800' significa doce mil ochocientos "
        . "pesos, y '178.510' significa ciento setenta y ocho mil quinientos diez pesos. No "
        . 'interpretes el punto como decimal, no redondees y no quites ceros finales. Devuelve '
        . "el total como texto exactamente como aparece en la factura.\n\n"
        . 'No uses subtotal, neto, IVA, descuentos, retenciones, total unidades ni valores de '
        . 'líneas. Si hay varios montos, elige el rotulado TOTAL, TOTAL FACTURA o monto final '
        . 'equivalente. Devuelve la respuesta llamando a `verificar_total_factura`.';
    return factura_ia_llamar($cfgIa, $prompt, FACTURA_IA_HERRAMIENTA_TOTAL, $contenido, 512);
}

/**
 * Orquesta la lectura completa de la cabecera de una factura: primera
 * lectura + las segundas lecturas enfocadas cuando el total sale
 * sospechosamente bajo o la fecha queda en el futuro (mismo criterio que
 * src/main.py). Devuelve un arreglo ya normalizado, listo para mostrar en
 * el pop-up de verificación.
 */
function procesar_factura_con_ia(array $cfgIa, string $rutaPdf): array
{
    $bruto = factura_ia_extraer($cfgIa, $rutaPdf);

    $esFactura = (bool)($bruto['es_factura'] ?? true);
    $resultado = [
        'es_factura' => $esFactura,
        'tipo_documento' => $bruto['tipo_documento'] ?? null,
        'proveedor' => trim((string)($bruto['proveedor'] ?? '')),
        'razon_social' => $bruto['razon_social'] ?? null,
        'rut_emisor' => $bruto['rut_emisor'] ?? null,
        'numero_factura' => $bruto['numero_factura'] ?? null,
        'moneda' => $bruto['moneda'] ?? 'CLP',
        'confianza' => (float)($bruto['confianza'] ?? 0.0),
        'notas' => $bruto['notas'] ?? null,
    ];

    if (!$esFactura) {
        $resultado['fecha'] = null;
        $resultado['total'] = null;
        return $resultado;
    }

    // --- Total: segunda lectura si sale sospechosamente bajo (igual que el programa de escritorio) ---
    $total = parsear_monto_clp($bruto['total'] ?? null);
    if ($total !== null && $total > 0 && $total < 1000) {
        try {
            $verif = factura_ia_verificar_total($cfgIa, $rutaPdf, (string)($bruto['total'] ?? ''));
            $totalVerif = parsear_monto_clp($verif['total'] ?? null);
            if ($totalVerif !== null && (float)($verif['confianza'] ?? 0) >= 0.75) {
                $total = $totalVerif;
                $resultado['notas'] = trim(($resultado['notas'] ?? '') . "\nTotal corregido por segunda lectura enfocada.");
            }
        } catch (Throwable $e) {
            error_log('factura_ia: no se pudo verificar total sospechoso: ' . $e->getMessage());
        }
    }
    $resultado['total'] = $total;

    // --- Fecha: segunda lectura si no se pudo leer o quedó en el futuro ---
    $fechaIso = fecha_factura_a_iso($bruto['fecha'] ?? null);
    if ($fechaIso === null || !fecha_factura_es_razonable($fechaIso)) {
        try {
            $verif = factura_ia_verificar_fecha($cfgIa, $rutaPdf, $bruto['fecha'] ?? null);
            $fechaVerifIso = fecha_factura_a_iso($verif['fecha'] ?? null);
            if ($fechaVerifIso !== null && (float)($verif['confianza'] ?? 0) >= 0.75) {
                $fechaIso = $fechaVerifIso;
                $resultado['notas'] = trim(($resultado['notas'] ?? '') . "\nFecha corregida por segunda lectura enfocada.");
            }
        } catch (Throwable $e) {
            error_log('factura_ia: no se pudo verificar fecha dudosa: ' . $e->getMessage());
        }
    }
    $resultado['fecha'] = $fechaIso;

    return $resultado;
}
