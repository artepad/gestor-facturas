# Instalación del Sistema de Gestión de Facturas

## Requisitos previos

- **Windows 10 u 11**.
- **Python 3.10 o superior** instalado y marcado "Add Python to PATH".
  Descarga: https://www.python.org/downloads/
- **Conexión a internet** (solo durante la instalación, para bajar el código y dependencias).
- **Tu API key de Anthropic** (la que paga las llamadas a Claude que clasifican las facturas).

## Instalación (3 pasos, 5 minutos)

1. **Abre PowerShell**: Win+S → escribe `powershell` → Enter.
2. **Copia y pega este comando** (todo en una línea) y presiona Enter:
   ```powershell
   iwr https://raw.githubusercontent.com/artepad/gestor-facturas/main/instalar.ps1 -OutFile $env:TEMP\inst.ps1; Start-Process powershell -Verb RunAs -ArgumentList "-NoProfile","-ExecutionPolicy","Bypass","-File","$env:TEMP\inst.ps1"
   ```
3. Acepta el prompt de admin de Windows. En la ventana del instalador responde 3 preguntas:
   - **Carpeta de instalación**: Enter para `C:\AdminFacturas` (recomendado).
   - **API key de Anthropic**: pega tu key.
   - **Autoarranque con Windows**: Enter para **Sí**.

El instalador trabaja solo (1-2 minutos):
- Descarga el código desde GitHub.
- Crea las carpetas.
- Instala las dependencias de Python.
- Crea accesos directos.
- Al terminar, ofrece arrancar la vigilancia inmediatamente.

**Listo**. El ícono aparece en la bandeja del sistema (junto al reloj).

## Estructura instalada

```
C:\AdminFacturas\
├── programa\               código del sistema (se reemplaza al actualizar)
├── datos\                  base de datos + logs + respaldos automáticos
└── Facturas\
    ├── _entrada\           PDFs nuevos del escáner se dejan aquí
    ├── _revisar\           facturas que requieren revisión manual
    ├── _errores\
    ├── _no_facturas\
    ├── _reemplazadas\
    └── 2026\Mayo\CCU\...   facturas archivadas (AÑO/Mes/Marca)
```

## Uso diario

- **Escanear**: el escáner Brother DS-640 (o cualquier otro) debe dejar los PDFs en
  `C:\AdminFacturas\Facturas\_entrada\`. El sistema los procesa solo en segundos.
- **Buscar/editar facturas**: doble clic en el ícono "Administrador de Facturas" del escritorio.
- **Pausar/reanudar vigilancia**: clic derecho en el ícono de la bandeja.
- **Respaldos**: dentro del Administrador, abre el menú hamburguesa (☰) del footer →
  Configuración → Exportar / Importar.

## Actualizar a una nueva versión

1. Doble clic en `C:\AdminFacturas\programa\actualizar.ps1`.
2. El script descarga la última versión desde GitHub y reemplaza solo el código.
3. **Datos, facturas y API key se conservan intactos** — no se tocan nunca.

## Mover a otro PC

Opción más limpia:

1. En el PC actual: abre el Administrador → ☰ → Configuración → Exportar respaldo
   (marca "Incluir API key"). Se genera un `.zip`.
2. Copia el `.zip` al PC nuevo (USB, OneDrive, etc.).
3. En el PC nuevo: instala Python → corre `instalar.ps1`.
4. Abre el Administrador → ☰ → Configuración → Importar respaldo → elige el `.zip`.
5. Cierra y reabre el programa. Todo restaurado.

## Desinstalar

Mismo flujo que la instalación, en una línea:

```powershell
iwr https://raw.githubusercontent.com/artepad/gestor-facturas/main/desinstalar.ps1 -OutFile $env:TEMP\des.ps1; Start-Process powershell -Verb RunAs -ArgumentList "-NoProfile","-ExecutionPolicy","Bypass","-File","$env:TEMP\des.ps1"
```

El desinstalador te ofrece dos opciones:

- **Opción 1 (recomendada)**: borra solo el programa y los accesos directos. **Conserva la BD y los PDFs** en `C:\AdminFacturas\datos\` y `C:\AdminFacturas\Facturas\`. Útil si vas a reinstalar después.
- **Opción 2**: borra TODO, incluida la base de datos y los PDFs. Te pide escribir `BORRAR` para confirmar (es irreversible).

Antes de elegir Opción 2, te recomiendo **exportar un respaldo** desde el Administrador → ☰ → Configuración → Exportar respaldo. Así puedes restaurarlo después si te arrepientes.

## Solución de problemas

- **"Python no esta instalado"**: instala Python desde https://www.python.org/downloads/
  y marca "Add Python to PATH" al instalar.
- **"pip install fallo"**: revisa que tienes internet. Si el error persiste, abre PowerShell
  como administrador y ejecuta manualmente:
  ```
  cd C:\AdminFacturas\programa
  py -m pip install -r requirements.txt
  ```
- **El ícono de la bandeja no aparece**: corre manualmente `iniciar_bandeja.bat` desde
  `C:\AdminFacturas\programa\`. Si funciona ahí pero no al iniciar Windows, revisa la
  carpeta `Startup` (Win+R → `shell:startup`).
