# =====================================================================
# Desinstalador del Sistema de Gestion de Facturas
# =====================================================================
# Uso: clic derecho -> "Ejecutar con PowerShell"
# Pregunta si conservar los datos (BD y facturas) o borrar todo.
# =====================================================================

#Requires -Version 5.1

$ErrorActionPreference = "Stop"

# Elevar a admin si hace falta
function Test-Administrator {
    $u = [Security.Principal.WindowsIdentity]::GetCurrent()
    return (New-Object Security.Principal.WindowsPrincipal($u)).IsInRole(
        [Security.Principal.WindowsBuiltInRole]::Administrator)
}
if (-not (Test-Administrator)) {
    Write-Host "Reabriendo elevado..." -ForegroundColor Yellow
    Start-Process powershell.exe -Verb RunAs -ArgumentList "-NoProfile","-ExecutionPolicy","Bypass","-File","`"$PSCommandPath`""
    exit
}

Clear-Host
Write-Host "================================================================" -ForegroundColor Red
Write-Host "  Desinstalador del Sistema de Gestion de Facturas" -ForegroundColor Red
Write-Host "================================================================" -ForegroundColor Red
Write-Host ""

$dirInstall = Read-Host "  Carpeta instalada [C:\AdminFacturas]"
if ([string]::IsNullOrWhiteSpace($dirInstall)) {
    $dirInstall = "C:\AdminFacturas"
}
$dirInstall = $dirInstall.TrimEnd("\")

if (-not (Test-Path $dirInstall)) {
    Write-Host "  No se encontro $dirInstall, nada que desinstalar." -ForegroundColor Yellow
    Read-Host "Presiona Enter"
    exit 0
}

Write-Host ""
Write-Host "  Se desinstalara desde: $dirInstall" -ForegroundColor White
Write-Host ""
Write-Host "  Opciones:" -ForegroundColor White
Write-Host "    1. Borrar SOLO el programa, conservar BD y facturas (recomendado)" -ForegroundColor Gray
Write-Host "    2. Borrar TODO, incluida la base de datos y los PDFs" -ForegroundColor Gray
Write-Host "    3. Cancelar" -ForegroundColor Gray
$opcion = Read-Host "  Elige [1/2/3]"

if ($opcion -eq "3" -or [string]::IsNullOrWhiteSpace($opcion)) {
    Write-Host "  Cancelado." -ForegroundColor Yellow
    Read-Host "Presiona Enter"
    exit 0
}

# --- 1. Detener procesos en uso ---
Write-Host ""
Write-Host "[1/4] Cerrando procesos..." -ForegroundColor Green
$nombres = @("pythonw", "pyw", "python", "py")
foreach ($n in $nombres) {
    Get-Process -Name $n -ErrorAction SilentlyContinue | Where-Object {
        try { $_.MainModule.FileName -and $_.CommandLine -like "*$dirInstall*" } catch { $false }
    } | ForEach-Object {
        Write-Host "  Cerrando $($_.Name) (PID $($_.Id))" -ForegroundColor Gray
        $_ | Stop-Process -Force -ErrorAction SilentlyContinue
    }
}
# Forzar cierre de cualquier python que tenga la BD abierta (rudo pero efectivo)
Start-Sleep -Milliseconds 500
Write-Host "  OK" -ForegroundColor Gray

# --- 2. Eliminar accesos directos ---
Write-Host ""
Write-Host "[2/4] Eliminando accesos directos..." -ForegroundColor Green
$desktop = [Environment]::GetFolderPath("Desktop")
$startMenu = [Environment]::GetFolderPath("Programs")
$startup = [Environment]::GetFolderPath("Startup")

$accesos = @(
    "$desktop\Administrador de Facturas.lnk",
    "$startup\Sistema Gestion Facturas (bandeja).lnk",
    "$startMenu\Sistema de Gestion de Facturas"
)
foreach ($a in $accesos) {
    if (Test-Path $a) {
        Remove-Item -Path $a -Recurse -Force -ErrorAction SilentlyContinue
        Write-Host "  Borrado: $a" -ForegroundColor Gray
    }
}
Write-Host "  OK" -ForegroundColor Gray

# --- 3. Borrar segun la opcion elegida ---
Write-Host ""
if ($opcion -eq "1") {
    Write-Host "[3/4] Borrando solo el programa..." -ForegroundColor Green
    if (Test-Path "$dirInstall\programa") {
        Remove-Item -Path "$dirInstall\programa" -Recurse -Force
    }
    Write-Host "  OK" -ForegroundColor Gray
    Write-Host ""
    Write-Host "  CONSERVADOS:" -ForegroundColor Cyan
    Write-Host "    $dirInstall\datos\        (base de datos + respaldos)" -ForegroundColor White
    Write-Host "    $dirInstall\Facturas\     (todos los PDFs)" -ForegroundColor White
} else {
    Write-Host "[3/4] BORRANDO TODO en $dirInstall ..." -ForegroundColor Red
    $conf = Read-Host "  Esto es IRREVERSIBLE. Escribe BORRAR para confirmar"
    if ($conf -ne "BORRAR") {
        Write-Host "  Cancelado: no escribiste BORRAR. Datos intactos." -ForegroundColor Yellow
        Read-Host "Presiona Enter"
        exit 0
    }
    Remove-Item -Path $dirInstall -Recurse -Force
    Write-Host "  Carpeta completa eliminada." -ForegroundColor Gray
}

# --- 4. Final ---
Write-Host ""
Write-Host "[4/4] Listo." -ForegroundColor Green
Write-Host ""
Write-Host "================================================================" -ForegroundColor Green
Write-Host "  DESINSTALACION COMPLETADA" -ForegroundColor Green
Write-Host "================================================================" -ForegroundColor Green
Write-Host ""
Read-Host "Presiona Enter para terminar"
