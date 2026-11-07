<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Router minimo: asocia metodo+ruta con una funcion o [Clase, 'metodo'].
 * Soporta parametros con llaves ("/cliente/{id}"). Sin coincidencia -> 404.
 */
final class Router
{
    /** @var array<int, array{metodo:string, patron:string, regex:string, parametros:array<int,string>, destino:mixed}> */
    private array $rutas = [];

    public function get(string $ruta, callable|array $destino): void
    {
        $this->agregar('GET', $ruta, $destino);
    }

    public function post(string $ruta, callable|array $destino): void
    {
        $this->agregar('POST', $ruta, $destino);
    }

    private function agregar(string $metodo, string $ruta, callable|array $destino): void
    {
        $parametros = [];
        $regex = preg_replace_callback('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', function ($m) use (&$parametros) {
            $parametros[] = $m[1];
            return '([^/]+)';
        }, $ruta);
        $this->rutas[] = [
            'metodo' => $metodo,
            'patron' => $ruta,
            'regex' => '#^' . $regex . '$#',
            'parametros' => $parametros,
            'destino' => $destino,
        ];
    }

    /** true si encontro y ejecuto una ruta; false si no hubo coincidencia (-> 404). */
    public function despachar(string $metodo, string $ruta): bool
    {
        foreach ($this->rutas as $r) {
            if ($r['metodo'] !== $metodo) {
                continue;
            }
            if (!preg_match($r['regex'], $ruta, $m)) {
                continue;
            }
            array_shift($m);
            $args = array_combine($r['parametros'], $m);
            if (is_array($r['destino'])) {
                [$clase, $accion] = $r['destino'];
                (new $clase())->$accion($args);
            } else {
                ($r['destino'])($args);
            }
            return true;
        }
        return false;
    }
}
