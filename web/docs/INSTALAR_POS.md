# Instalar la caja (Punto de Venta) en un PC

Guía para dejar el POS funcionando en el PC de una caja. Son 5 minutos por
equipo y se hace una sola vez.

El objetivo es que la caja **se vea y se sienta como un programa**, no como una
página web: sin barra de direcciones, sin pestañas, y con las teclas de función
(**F12**, F1…) disponibles para el POS en vez de para el navegador.

---

## Por qué hace falta el modo kiosco

En un navegador normal, **F12 abre las herramientas de programador** y **F1 la
ayuda**. Son atajos que Chrome se reserva y una página web no puede
desactivar — justo las dos teclas más usadas al cobrar.

El **modo kiosco** las libera. Sin esto, hay que cobrar con el botón verde o con
el **`+` del teclado numérico** (que siempre funciona, como respaldo).

---

## Paso 1 — Crear el acceso directo

1. Clic derecho en el **Escritorio** → **Nuevo** → **Acceso directo**.
2. En "Escriba la ubicación del elemento", pegar **una sola línea**:

```
"C:\Program Files\Google\Chrome\Application\chrome.exe" --kiosk --app=https://admin.minimark.cl/pos.php
```

> Si Chrome está en otra ruta, ajustarla. Para probar en el PC de desarrollo,
> reemplazar la dirección por `http://localhost/minimark/pos.php`.

3. **Siguiente** → ponerle de nombre **Caja** → **Finalizar**.

### Cambiarle el ícono (opcional)

Clic derecho en el acceso directo → **Propiedades** → **Cambiar icono**. Así se
distingue de un Chrome cualquiera en la barra de tareas.

---

## Paso 2 — Probar que quedó bien

Abrir el acceso directo. Debe verse:

- **Sin** barra de direcciones ni pestañas (ocupa toda la pantalla).
- Al apretar **F12** se abre el cobro (no las herramientas del navegador).

Para salir del modo kiosco: **Alt+F4**.

> ¿Se abrió con barra de direcciones? Falta el `--kiosk` en el acceso directo.
> ¿F12 abre las herramientas? El acceso directo se creó sin `--kiosk` o se abrió
> Chrome por otro lado.

---

## Paso 3 — Anclar a la barra de tareas

Con la caja abierta: clic derecho en su ícono de la barra de tareas → **Anclar a
la barra de tareas**. Así queda siempre a un clic.

---

## Alternativa: instalarla como aplicación

Sin modo kiosco, Chrome igual permite "instalar" la caja: al abrir `pos.php`
aparece un ícono de instalación en la barra de direcciones (o en el menú ⋮ →
*Instalar Minimark · Punto de Venta*). Queda en su propia ventana, sin pestañas.

Es más cómodo que una pestaña normal, pero **F12 sigue abriendo las herramientas
del navegador**. Para liberarla hace falta el modo kiosco del Paso 1.

---

## Teclas de la caja

| Tecla | Qué hace |
|-------|----------|
| *(escanear)* + `Enter` | Agregar el producto |
| `Ctrl+P` | Producto común (pan, verduras, granel) |
| `F10` | Buscar por nombre |
| `F7` / `F8` | Entrada / salida de efectivo |
| `F9` | Pago de cuenta (Klap) |
| `Supr` | Borrar la línea seleccionada (`↑` `↓` para moverse) |
| `F6` | Dejar la venta en espera |
| `F12` *o* `+` del numérico | Cobrar |
| `F2` *(en el cobro)* | Cobrar sin imprimir |
| `Esc` | Cancelar |

---

## Notas

- **La primera vez el PC necesita internet** para descargar la caja. Después
  puede abrir sin conexión.
- **HTTPS es obligatorio** para que funcione sin internet y para usar la cámara
  como lector. En `admin.minimark.cl` ya lo es. `localhost` también cuenta como
  seguro, así que las pruebas locales funcionan igual.
- Si la caja se ve rara después de una actualización, cerrarla y volver a
  abrirla: toma la versión nueva sola.
