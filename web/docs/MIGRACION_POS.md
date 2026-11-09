# Pasar de Eleventa al POS propio

Guía del cambio. La idea central: **no se apaga Eleventa hasta que el POS haya
cuadrado varios días seguidos**. Mientras tanto los dos conviven y el negocio
nunca depende de algo sin probar.

---

## Antes de empezar: qué está y qué no

**Ya funciona** (etapas 1 a 4):

- Vender con lector, producto común, búsqueda, tickets en espera
- Cobro en efectivo, tarjeta, transferencia, fiado y **mixto**
- Promociones automáticas ("4 por $1.000")
- Anular ventas del turno
- Caja: abrir, entradas/salidas, cerrar con arqueo y diferencia
- Pago de cuentas (Klap) con saldo, recargas y comisiones
- Al cerrar caja se genera el **corte**, que alimenta Ingresos
- Catálogo compartido entre los dos negocios, con historial de cambios
- Vender sin internet, con la venta subiendo sola al reconectar
- Inventario **opcional** (nace apagado), con compras que suman stock

**Deliberadamente fuera**: boleta electrónica del SII, impresora térmica y
cajón de dinero, balanza integrada, y devoluciones de un producto suelto
(hoy se anula la venta completa). Ver la sección 13 de `POS_DISENO.md`.

---

## Paso 1 — Subir al servidor

Ver la lista de archivos y el procedimiento en `DESPLIEGUE.md`. En resumen:

1. **Respaldo de la base** desde cPanel. Sin respaldo no se sube nada.
2. Subir los archivos por el Administrador de archivos.
3. Entrar a `migrar.php` como administrador. Es **aditivo**: crea lo que falta
   y no borra nada. La única parte no aditiva (unificar el catálogo entre los
   dos negocios) **verifica primero** que sean idénticos y **aborta sin tocar
   nada** si encuentra diferencias.
4. **Borrar `migrar.php` del servidor** cuando termine.

> El POS queda **invisible para las vendedoras**: el permiso `ventas` está solo
> en `admin`. Eso se cambia recién en el Paso 5.

---

## Paso 2 — Preparar el catálogo

1. Exportar los productos desde Eleventa a Excel.
2. Subirlo en **Herramientas → Base de Datos de Productos**.
   Con el catálogo compartido **basta subirlo una vez** para los dos negocios.
3. Revisar que los precios estén al día. La carga **actualiza sin borrar**, así
   que se puede repetir cuantas veces haga falta.

---

## Paso 3 — Instalar la caja en cada PC

Seguir `INSTALAR_POS.md`. Lo importante:

- Crear el **acceso directo en modo kiosco**, que es lo que libera **F12**.
- Abrirla una vez **con internet** para que baje el catálogo (445 KB) y quede
  disponible sin conexión.
- Comprobar que **F12 abre el cobro** y no las herramientas del navegador.

---

## Paso 4 — Correr en paralelo (lo más importante)

Durante **al menos una semana**, el negocio sigue operando con Eleventa y en
paralelo se prueba el POS. Sugerencia:

1. Abrir caja en el POS con el mismo fondo que se declara en Eleventa.
2. Registrar en el POS **algunas ventas del día** (no hace falta todas).
3. Registrar **todos los pagos de cuentas** en el POS — es lo que Eleventa no
   sabía hacer y donde más se gana.
4. Al final del turno, cerrar la caja en el POS y **comparar el corte** con el
   de Eleventa en **Ingresos**. Los dos aparecen en la misma pantalla.

### Qué revisar al comparar

| Cosa | Dónde mirar |
|------|-------------|
| Ventas totales | ¿Coinciden con lo que se pasó por el POS? |
| Ventas en efectivo | ¿El arqueo cuadró? ¿La diferencia es razonable? |
| Pagos de cuentas | No deben estar sumados a las ventas |
| Ventas por departamento | ¿Se parecen a las de Eleventa? |

Si algo no calza, **anotarlo antes de seguir**. Un descuadre repetido es una
señal, no un accidente.

---

## Paso 5 — Habilitar a las vendedoras

Cuando el POS haya cuadrado varios días seguidos:

1. En `lib/permisos.php`, agregar `'ventas'` a la fila `'vendedor'`:

```php
'vendedor' => ['ventas', 'facturas', 'fiados', 'precios', 'productos', 'herramientas'],
```

2. Subir ese archivo al servidor.
3. Ponerle **PIN a cada vendedora** en Usuarios (4 a 6 números).
4. Mostrarles la pantalla y las teclas (la tabla está en `INSTALAR_POS.md`).

> **Para volver atrás basta con quitar `'ventas'` de esa fila y volver a subir
> el archivo.** El POS desaparece de su vista al instante.

---

## Paso 6 — Apagar Eleventa

Recién cuando las vendedoras lleven **varios días vendiendo solo en el POS**
sin problemas:

1. Sacar el corte final de Eleventa y guardarlo.
2. Dejar Eleventa **instalado pero sin usar** durante un par de meses, por si
   hace falta consultar algo del pasado.
3. Avisar que ya no llegarán correos de corte: desde ahora los cortes los
   genera el POS al cerrar caja.

> `corte_pegar.php` sigue existiendo por si alguna vez hay que registrar un
> corte a mano.

---

## Qué NO se migra

**El historial de ventas viejo de Eleventa.** El POS arranca en la fecha del
cambio; lo anterior queda en Eleventa para consulta. Migrarlo daría trabajo y
riesgo a cambio de datos que casi nunca se miran.

**Sí se conservan** los cortes históricos que llegaron por correo: están en
Ingresos y ahí siguen, junto a los nuevos.

---

## Si algo sale mal

| Problema | Qué hacer |
|----------|-----------|
| Una pantalla del POS falla | Quitar `'ventas'` del rol vendedor: vuelven a Eleventa al instante |
| El arqueo no cuadra | Revisar si hay pagos de cuentas sin registrar (F9) o entradas/salidas sin anotar |
| Una venta quedó mal | Anularla desde "Ventas del día" (solo si la caja sigue abierta) |
| La caja no sube las ventas | Mirar el contador naranja; se reintenta solo cada 45 s y al volver internet |
| Hay que volver todo atrás | Restaurar el respaldo de la base y quitar los archivos nuevos |

---

## Pendientes conocidos

- **Revisar el módulo Gastos**: si alguna vez se registraron **recargas de saldo
  Klap como gasto**, hay que depurarlas. No son un gasto (es plata propia
  cambiando de bolsillo) y distorsionan el balance histórico.
- **Abonos de fiado en efectivo**: hoy hay que anotarlos como *entrada de
  efectivo* (F7) para que el arqueo cuadre. La integración automática con
  Fiados queda pendiente.
- **Confirmar el modo kiosco y la venta sin internet** en el Chrome real del
  negocio: son las dos cosas que no se pudieron verificar en desarrollo.
