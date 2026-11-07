# Línea base · Fase 3 (antes de migrar nada)

Cómo se comporta el POS **hoy**, para comparar después de cada corte de la
migración. Dos partes: lo automático (`scripts/smoke.php`) y lo manual (no se puede
probar solo con HTTP porque hay JavaScript, PIN local y modo sin internet de por
medio).

## Prueba automática

```bash
php web/scripts/smoke.php http://localhost/minimark
```

Recorre 16 rutas como `admin` y como `vendedor`; revisa código HTTP y que no
aparezca ningún `Warning`/`Notice`/`Fatal error` en la página.

**Resultado de hoy (2026-11-07): 32/32 sin novedad.**

Confirma el reparto de permisos documentado en `web/CLAUDE.md`:

| Página | admin | vendedor | Por qué |
|---|---|---|---|
| `pos.php`, `caja.php`, `servicios.php`, `historial_productos.php` | 200 | 302 (afuera) | módulo `ventas`, solo admin por ahora |
| `inventario.php`, `compras.php`, `promociones.php` | 200 | 200 | módulo `productos`, lo tienen los dos a propósito |
| `ingresos.php`, `gastos.php`, `negocios.php`, `usuarios.php` | 200 | 302 (afuera) | información financiera / administración, solo admin |

Si después de un corte algo cambia de esta tabla sin que lo hayamos decidido, es una
regresión.

## Checklist manual (ya verificado en sesiones anteriores; se repite después de cada corte grande)

- [ ] Abrir caja con un fondo, ver el monto reflejado en el resumen del turno.
- [ ] Vender por código de barras y con producto común (Ctrl+P).
- [ ] Vender 4 unidades de un producto con promoción "4x$1.000" → el total da $1.000,
      no $1.200.
- [ ] Cobro mixto (parte efectivo, parte tarjeta) → el vuelto se calcula solo sobre
      la parte en efectivo.
- [ ] Dejar un ticket en espera (F6) y recuperarlo.
- [ ] Pago de cuenta (F9): el monto de la cuenta entra al cajón completo; el vuelto
      es solo una calculadora, no se guarda.
- [ ] Anular una venta del turno (con la caja todavía abierta) → revierte fiado y
      stock si corresponde.
- [ ] Cerrar caja con una diferencia a propósito → el corte queda en Ingresos.
- [ ] El cerrojo de PIN se cierra al escribir el PIN correcto (bug ya corregido:
      ver commit `0169d3a`).
- [ ] Aviso de "caja lleva N horas abierta" aparece pasadas 16 horas y **no bloquea**
      la venta.
- [ ] Recargar la página a mitad de una venta → el carrito se recupera.
- [ ] Modo kiosco: F12 abre el cobro, no las herramientas del navegador (pendiente
      de confirmar en el negocio real — ver conversación previa).
- [ ] Vender con el wifi desconectado de verdad → la venta queda en cola y sube sola
      al reconectar, sin duplicarse.

Los últimos dos puntos requieren el Chrome real del negocio, no el ambiente local;
quedan marcados como pendientes del dueño, no bloquean la migración.
