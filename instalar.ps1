# =====================================================================
# Instalador del Sistema de Gestión de Facturas
# =====================================================================
# Uso: clic derecho → "Ejecutar con PowerShell"
# Requiere: Python 3.10+ ya instalado, conexión a internet.
# =====================================================================

#Requires -Version 5.1

$ErrorActionPreference = "Stop"
$REPO_ZIP = "https://github.com/artepad/gestor-facturas/archive/refs/heads/main.zip"

# --- 1. Verificar permisos de administrador, reabrir como admin si falta ---
function Test-Administrator {
    $usuario = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = New-Object Security.Principal.WindowsPrincipal($usuario)
    return $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

if (-not (Test-Administrator)) {
    Write-Host "Se necesitan permisos de administrador. Reabriendo elevado..." -ForegroundColor Yellow
    Start-Process powershell.exe -Verb RunAs -ArgumentList "-NoProfile","-ExecutionPolicy","Bypass","-File","`"$PSCommandPath`""
    exit
}

# --- 2. Banner ---
Clear-Host
Write-Host "================================================================" -ForegroundColor Cyan
Write-Host "  Instalador del Sistema de Gestion de Facturas" -ForegroundColor Cyan
Write-Host "================================================================" -ForegroundColor Cyan
Write-Host ""

# --- 3. Verificar Python ---
Write-Host "[1/8] Verificando Python..." -ForegroundColor Green
$py = Get-Command py -ErrorAction SilentlyContinue
if (-not $py) {
    $py = Get-Command python -ErrorAction SilentlyContinue
}
if (-not $py) {
    Write-Host "  ERROR: Python no esta instalado o no esta en el PATH." -ForegroundColor Red
    Write-Host "  Instala Python 3.10+ desde https://www.python.org/downloads/" -ForegroundColor Red
    Write-Host "  (Marca 'Add Python to PATH' durante la instalacion)" -ForegroundColor Red
    Read-Host "Presiona Enter para salir"
    exit 1
}
$pyVersion = & $py.Source --version 2>&1
Write-Host "  OK: $pyVersion encontrado" -ForegroundColor Gray

# --- 4. Preguntas al usuario ---
Write-Host ""
Write-Host "[2/8] Configuracion inicial" -ForegroundColor Green
$dirInstall = Read-Host "  Carpeta de instalacion [C:\AdminFacturas]"
if ([string]::IsNullOrWhiteSpace($dirInstall)) {
    $dirInstall = "C:\AdminFacturas"
}
$dirInstall = $dirInstall.TrimEnd("\")

if (Test-Path "$dirInstall\programa") {
    Write-Host "  AVISO: Ya existe una instalacion en $dirInstall\programa" -ForegroundColor Yellow
    $r = Read-Host "  ¿Reinstalar reemplazando solo el codigo? (datos y facturas se conservan) [s/N]"
    if ($r -ne "s" -and $r -ne "S") {
        Write-Host "  Cancelado." -ForegroundColor Yellow
        Read-Host "Presiona Enter para salir"
        exit 0
    }
}

Write-Host ""
$apiKey = Read-Host "  Pega tu API key de Anthropic (sk-ant-...)"
if ([string]::IsNullOrWhiteSpace($apiKey)) {
    Write-Host "  ERROR: La API key es obligatoria." -ForegroundColor Red
    Read-Host "Presiona Enter para salir"
    exit 1
}

Write-Host ""
$autoarranque = Read-Host "  ¿Iniciar automaticamente con Windows? [S/n]"
$autoarranque = ($autoarranque -ne "n" -and $autoarranque -ne "N")

# --- 5. Crear estructura de carpetas ---
Write-Host ""
Write-Host "[3/8] Creando estructura de carpetas..." -ForegroundColor Green
$carpetas = @(
    "$dirInstall",
    "$dirInstall\programa",
    "$dirInstall\datos",
    "$dirInstall\datos\logs",
    "$dirInstall\datos\respaldos_automaticos",
    "$dirInstall\Facturas",
    "$dirInstall\Facturas\_entrada",
    "$dirInstall\Facturas\_revisar",
    "$dirInstall\Facturas\_errores",
    "$dirInstall\Facturas\_reemplazadas",
    "$dirInstall\Facturas\_no_facturas"
)
foreach ($c in $carpetas) {
    if (-not (Test-Path $c)) {
        New-Item -ItemType Directory -Path $c -Force | Out-Null
    }
}
Write-Host "  OK" -ForegroundColor Gray

# --- 6. Descargar el codigo desde GitHub ---
Write-Host ""
Write-Host "[4/8] Descargando el codigo desde GitHub..." -ForegroundColor Green
$tmpZip = [System.IO.Path]::GetTempFileName() + ".zip"
$tmpDir = [System.IO.Path]::Combine($env:TEMP, "gestor-facturas-" + [System.Guid]::NewGuid().ToString("N"))
try {
    Invoke-WebRequest -Uri $REPO_ZIP -OutFile $tmpZip -UseBasicParsing
    Write-Host "  Descarga completada, descomprimiendo..." -ForegroundColor Gray
    Expand-Archive -Path $tmpZip -DestinationPath $tmpDir -Force
    # Carpeta extraida: gestor-facturas-main
    $extraida = Get-ChildItem $tmpDir -Directory | Select-Object -First 1
    if (-not $extraida) {
        throw "No se pudo extraer el ZIP del repositorio."
    }
    Write-Host "  OK" -ForegroundColor Gray
} catch {
    Write-Host "  ERROR descargando: $_" -ForegroundColor Red
    Read-Host "Presiona Enter para salir"
    exit 1
}

# --- 7. Copiar codigo a programa\ ---
Write-Host ""
Write-Host "[5/8] Copiando codigo a $dirInstall\programa\..." -ForegroundColor Green
# Borrar src\ anterior si existe (reinstall) — preserva config.yaml y .env si ya estaban
if (Test-Path "$dirInstall\programa\src") {
    Remove-Item -Path "$dirInstall\programa\src" -Recurse -Force
}
Copy-Item -Path "$($extraida.FullName)\src" -Destination "$dirInstall\programa\src" -Recurse -Force
Copy-Item -Path "$($extraida.FullName)\requirements.txt" -Destination "$dirInstall\programa\" -Force
# Plantilla de config y archivos auxiliares
$templatePath = "$($extraida.FullName)\config.template.yaml"
if (Test-Path $templatePath) {
    Copy-Item -Path $templatePath -Destination "$dirInstall\programa\config.template.yaml" -Force
}
# El instalador mismo se copia para futuras reinstalaciones
Copy-Item -Path "$($extraida.FullName)\instalar.ps1" -Destination "$dirInstall\programa\actualizar.ps1" -Force -ErrorAction SilentlyContinue
Write-Host "  OK" -ForegroundColor Gray

# --- 8. Generar config.yaml y .env ---
Write-Host ""
Write-Host "[6/8] Generando config.yaml y .env..." -ForegroundColor Green
$configFinal = "$dirInstall\programa\config.yaml"
$envFinal = "$dirInstall\programa\.env"

# Solo (re)escribir config.yaml si no existe (no pisar configuracion del usuario)
if (-not (Test-Path $configFinal)) {
    $template = Get-Content -Path "$dirInstall\programa\config.template.yaml" -Raw
    $template = $template.Replace("{{INSTALL_DIR}}", $dirInstall)
    Set-Content -Path $configFinal -Value $template -Encoding UTF8
    Write-Host "  config.yaml generado" -ForegroundColor Gray
} else {
    Write-Host "  config.yaml ya existe, se conserva" -ForegroundColor Gray
}

# .env: solo reescribir si no existe
if (-not (Test-Path $envFinal)) {
    Set-Content -Path $envFinal -Value "ANTHROPIC_API_KEY=$apiKey" -Encoding UTF8
    Write-Host "  .env generado" -ForegroundColor Gray
} else {
    Write-Host "  .env ya existe, se conserva" -ForegroundColor Gray
}

# --- 9. Instalar dependencias de Python ---
Write-Host ""
Write-Host "[7/8] Instalando dependencias de Python (puede tardar)..." -ForegroundColor Green
Push-Location "$dirInstall\programa"
try {
    & $py.Source -m pip install --upgrade pip 2>&1 | Out-Null
    & $py.Source -m pip install -r requirements.txt
    if ($LASTEXITCODE -ne 0) {
        throw "pip install fallo (codigo $LASTEXITCODE)"
    }
    Write-Host "  OK" -ForegroundColor Gray
} catch {
    Write-Host "  ERROR instalando dependencias: $_" -ForegroundColor Red
    Pop-Location
    Read-Host "Presiona Enter para salir"
    exit 1
}
Pop-Location

# --- 10. Crear lanzadores .bat ---
$pyw = $py.Source -replace "py\.exe$", "pyw.exe" -replace "python\.exe$", "pythonw.exe"
if (-not (Test-Path $pyw)) { $pyw = $py.Source }  # fallback

$batBandeja = @"
@echo off
start "" "$pyw" "$dirInstall\programa\src\main.py" --tray
"@
Set-Content -Path "$dirInstall\programa\iniciar_bandeja.bat" -Value $batBandeja -Encoding ASCII

$batAdmin = @"
@echo off
start "" "$pyw" "$dirInstall\programa\src\buscador.py"
"@
Set-Content -Path "$dirInstall\programa\iniciar_admin.bat" -Value $batAdmin -Encoding ASCII

# --- 11. Crear accesos directos ---
Write-Host ""
Write-Host "[8/8] Creando accesos directos..." -ForegroundColor Green
$wsh = New-Object -ComObject WScript.Shell

function New-Shortcut($ruta, $target, $args, $workDir, $descripcion) {
    $sc = $wsh.CreateShortcut($ruta)
    $sc.TargetPath = $target
    $sc.Arguments = $args
    $sc.WorkingDirectory = $workDir
    $sc.Description = $descripcion
    $sc.Save()
}

$desktop = [Environment]::GetFolderPath("Desktop")
$startMenu = [Environment]::GetFolderPath("Programs")
$startup = [Environment]::GetFolderPath("Startup")

# Accesos directos en escritorio
New-Shortcut "$desktop\Administrador de Facturas.lnk" `
    $pyw "`"$dirInstall\programa\src\buscador.py`"" `
    "$dirInstall\programa" "Sistema de Gestion de Facturas"

# Acceso en menu inicio
$dirMenu = "$startMenu\Sistema de Gestion de Facturas"
if (-not (Test-Path $dirMenu)) {
    New-Item -ItemType Directory -Path $dirMenu | Out-Null
}
New-Shortcut "$dirMenu\Administrador de Facturas.lnk" `
    $pyw "`"$dirInstall\programa\src\buscador.py`"" `
    "$dirInstall\programa" "Administrador"
New-Shortcut "$dirMenu\Iniciar vigilancia (bandeja).lnk" `
    $pyw "`"$dirInstall\programa\src\main.py`" --tray" `
    "$dirInstall\programa" "Vigilancia de facturas en la bandeja"

# Autoarranque
if ($autoarranque) {
    New-Shortcut "$startup\Sistema Gestion Facturas (bandeja).lnk" `
        $pyw "`"$dirInstall\programa\src\main.py`" --tray" `
        "$dirInstall\programa" "Vigilancia de facturas (autoarranque)"
    Write-Host "  Autoarranque configurado" -ForegroundColor Gray
}
Write-Host "  OK" -ForegroundColor Gray

# --- 12. Limpieza ---
Remove-Item -Path $tmpZip -Force -ErrorAction SilentlyContinue
Remove-Item -Path $tmpDir -Recurse -Force -ErrorAction SilentlyContinue

# --- 13. Resumen ---
Write-Host ""
Write-Host "================================================================" -ForegroundColor Green
Write-Host "  INSTALACION COMPLETADA" -ForegroundColor Green
Write-Host "================================================================" -ForegroundColor Green
Write-Host ""
Write-Host "  Carpeta:        $dirInstall" -ForegroundColor White
Write-Host "  Escaneo deja PDFs en: $dirInstall\Facturas\_entrada\" -ForegroundColor White
Write-Host "  Base de datos:  $dirInstall\datos\facturas.db" -ForegroundColor White
Write-Host ""
Write-Host "  Accesos creados:" -ForegroundColor White
Write-Host "    - Escritorio: 'Administrador de Facturas'" -ForegroundColor Gray
Write-Host "    - Menu Inicio: 'Sistema de Gestion de Facturas'" -ForegroundColor Gray
if ($autoarranque) {
    Write-Host "    - Autoarranque al iniciar sesion" -ForegroundColor Gray
}
Write-Host ""
$iniciar = Read-Host "¿Iniciar la vigilancia (bandeja) ahora? [S/n]"
if ($iniciar -ne "n" -and $iniciar -ne "N") {
    Start-Process $pyw -ArgumentList "`"$dirInstall\programa\src\main.py`"","--tray" `
        -WorkingDirectory "$dirInstall\programa"
    Write-Host "  El icono debe aparecer en la bandeja del sistema." -ForegroundColor Green
}
Write-Host ""
Read-Host "Presiona Enter para terminar"
