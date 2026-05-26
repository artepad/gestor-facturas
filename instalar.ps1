# =====================================================================
# Instalador del Sistema de Gestion de Facturas
# =====================================================================
# Uso desde PowerShell:
#   iwr https://raw.githubusercontent.com/artepad/gestor-facturas/main/instalar.ps1 -OutFile $env:TEMP\inst.ps1; `
#       Start-Process powershell -Verb RunAs -ArgumentList "-NoProfile","-ExecutionPolicy","Bypass","-File","$env:TEMP\inst.ps1"
#
# El script:
#  - Pide permisos de admin si no los tiene.
#  - Detecta Python; si falta o es viejo, ofrece instalarlo automaticamente.
#  - Crea estructura en C:\AdminFacturas, baja codigo, instala dependencias.
#  - Crea accesos directos y autoarranque.
#  - Lanza la vigilancia al final (sin la elevacion de admin).
#  - NUNCA cierra la ventana por error: siempre espera Enter.
#  - Guarda log completo en %TEMP%\admin-facturas-install.log
# =====================================================================

#Requires -Version 5.1

$ErrorActionPreference = "Stop"
$REPO_ZIP   = "https://github.com/artepad/gestor-facturas/archive/refs/heads/main.zip"
$PYTHON_URL = "https://www.python.org/ftp/python/3.12.7/python-3.12.7-amd64.exe"
$PYTHON_MIN_MAJOR = 3
$PYTHON_MIN_MINOR = 10
$LOG_PATH = "$env:TEMP\admin-facturas-install.log"

# Empezar log
try { Start-Transcript -Path $LOG_PATH -Force | Out-Null } catch {}

# Helpers ---------------------------------------------------------------

function Stop-OnExit {
    try { Stop-Transcript | Out-Null } catch {}
    Write-Host ""
    Write-Host "Log completo: $LOG_PATH" -ForegroundColor Gray
    Read-Host "Presiona Enter para cerrar esta ventana"
}

function Test-Administrator {
    $u = [Security.Principal.WindowsIdentity]::GetCurrent()
    return (New-Object Security.Principal.WindowsPrincipal($u)).IsInRole(
        [Security.Principal.WindowsBuiltInRole]::Administrator)
}

function Refresh-Path {
    $env:Path = [System.Environment]::GetEnvironmentVariable("Path","Machine") +
                ";" +
                [System.Environment]::GetEnvironmentVariable("Path","User")
}

function Get-Python {
    # Devuelve el ejecutable de Python si la version es aceptable; si no, $null
    Refresh-Path
    $candidates = @()
    $cmd = Get-Command py -ErrorAction SilentlyContinue
    if ($cmd) { $candidates += $cmd.Source }
    $cmd = Get-Command python -ErrorAction SilentlyContinue
    if ($cmd) { $candidates += $cmd.Source }

    foreach ($exe in $candidates) {
        try {
            $vstr = & $exe --version 2>&1
            if ($vstr -match "Python (\d+)\.(\d+)") {
                $maj = [int]$Matches[1]
                $min = [int]$Matches[2]
                if ($maj -gt $PYTHON_MIN_MAJOR -or
                    ($maj -eq $PYTHON_MIN_MAJOR -and $min -ge $PYTHON_MIN_MINOR)) {
                    return @{ Exe = $exe; Version = $vstr }
                }
            }
        } catch {}
    }
    return $null
}

function Install-Python {
    Write-Host "  Descargando Python 3.12.7..." -ForegroundColor Gray
    $instPath = "$env:TEMP\python-installer.exe"
    Invoke-WebRequest -Uri $PYTHON_URL -OutFile $instPath -UseBasicParsing
    Write-Host "  Ejecutando instalador de Python (1-2 minutos, sin ventana)..." -ForegroundColor Gray
    # Instalacion silenciosa para todos los usuarios, agregando al PATH
    $argsPy = @(
        "/quiet",
        "InstallAllUsers=1",
        "PrependPath=1",
        "Include_test=0",
        "Include_launcher=1",
        "Include_pip=1"
    )
    $p = Start-Process -FilePath $instPath -ArgumentList $argsPy -Wait -PassThru
    Remove-Item -Path $instPath -Force -ErrorAction SilentlyContinue
    if ($p.ExitCode -ne 0) {
        throw "El instalador de Python termino con codigo $($p.ExitCode)."
    }
    Refresh-Path
}

function New-Shortcut($ruta, $target, $arguments, $workDir, $descripcion) {
    # OJO: el parametro NO puede llamarse $args, que es variable automatica
    # en PowerShell (array). Si se llama $args, el COM recibe Object[] en vez
    # de string y revienta con "no se puede convertir Object[] a string".
    $wsh = New-Object -ComObject WScript.Shell
    $sc = $wsh.CreateShortcut($ruta)
    $sc.TargetPath = $target
    $sc.Arguments = [string]$arguments
    $sc.WorkingDirectory = $workDir
    $sc.Description = $descripcion
    $sc.Save()
}

# Cuerpo principal ------------------------------------------------------

try {
    # --- Elevacion ---
    if (-not (Test-Administrator)) {
        Write-Host "Reabriendo con permisos de administrador..." -ForegroundColor Yellow
        try { Stop-Transcript | Out-Null } catch {}
        Start-Process powershell.exe -Verb RunAs -ArgumentList `
            "-NoProfile","-ExecutionPolicy","Bypass","-File","`"$PSCommandPath`""
        exit
    }

    Clear-Host
    Write-Host "================================================================" -ForegroundColor Cyan
    Write-Host "  Instalador del Sistema de Gestion de Facturas" -ForegroundColor Cyan
    Write-Host "================================================================" -ForegroundColor Cyan
    Write-Host ""

    # --- 1. Verificar / instalar Python ---
    Write-Host "[1/8] Verificando Python..." -ForegroundColor Green
    $py = Get-Python
    if (-not $py) {
        Write-Host "  Python no se encuentra o la version es inferior a 3.10." -ForegroundColor Yellow
        Write-Host ""
        $r = Read-Host "  Instalar Python 3.12.7 automaticamente desde python.org? [S/n]"
        if ($r -eq "n" -or $r -eq "N") {
            throw "Python es requisito. Instalalo manualmente desde https://www.python.org/downloads/ marcando 'Add to PATH', y vuelve a correr el instalador."
        }
        Install-Python
        $py = Get-Python
        if (-not $py) {
            throw "Python se instalo pero no es detectable. Reinicia el PC y vuelve a correr el instalador."
        }
        Write-Host "  Python instalado: $($py.Version)" -ForegroundColor Gray
    } else {
        Write-Host "  OK: $($py.Version)" -ForegroundColor Gray
    }

    # --- 2. Configuracion ---
    Write-Host ""
    Write-Host "[2/8] Configuracion" -ForegroundColor Green
    $dirInstall = Read-Host "  Carpeta de instalacion [C:\AdminFacturas]"
    if ([string]::IsNullOrWhiteSpace($dirInstall)) { $dirInstall = "C:\AdminFacturas" }
    $dirInstall = $dirInstall.TrimEnd("\")

    if (Test-Path "$dirInstall\programa") {
        Write-Host "  Ya existe una instalacion en $dirInstall\programa" -ForegroundColor Yellow
        $r = Read-Host "  Reinstalar codigo (datos y facturas se conservan)? [s/N]"
        if ($r -ne "s" -and $r -ne "S") {
            throw "Cancelado por el usuario."
        }
    }

    Write-Host ""
    $apiKey = Read-Host "  Pega tu API key de Anthropic (sk-ant-...)"
    if ([string]::IsNullOrWhiteSpace($apiKey)) {
        throw "La API key es obligatoria."
    }

    Write-Host ""
    $autoarranque = Read-Host "  Iniciar automaticamente con Windows? [S/n]"
    $autoarranque = ($autoarranque -ne "n" -and $autoarranque -ne "N")

    # --- 3. Crear estructura ---
    Write-Host ""
    Write-Host "[3/8] Creando estructura de carpetas..." -ForegroundColor Green
    $carpetas = @(
        $dirInstall,
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
        if (-not (Test-Path $c)) { New-Item -ItemType Directory -Path $c -Force | Out-Null }
    }
    Write-Host "  OK" -ForegroundColor Gray

    # --- 4. Descargar codigo desde GitHub ---
    Write-Host ""
    Write-Host "[4/8] Descargando codigo desde GitHub..." -ForegroundColor Green
    $tmpZip = [System.IO.Path]::GetTempFileName() + ".zip"
    $tmpDir = [System.IO.Path]::Combine($env:TEMP, "gestor-facturas-" + [System.Guid]::NewGuid().ToString("N"))
    try {
        Invoke-WebRequest -Uri $REPO_ZIP -OutFile $tmpZip -UseBasicParsing
    } catch {
        throw "No se pudo descargar el codigo. Revisa tu conexion a internet. ($_)"
    }
    Expand-Archive -Path $tmpZip -DestinationPath $tmpDir -Force
    $extraida = Get-ChildItem $tmpDir -Directory | Select-Object -First 1
    if (-not $extraida) { throw "El ZIP descargado esta vacio o no tiene contenido." }
    Write-Host "  OK" -ForegroundColor Gray

    # --- 5. Copiar codigo ---
    Write-Host ""
    Write-Host "[5/8] Copiando codigo a $dirInstall\programa\..." -ForegroundColor Green
    if (Test-Path "$dirInstall\programa\src") {
        Remove-Item -Path "$dirInstall\programa\src" -Recurse -Force
    }
    Copy-Item -Path "$($extraida.FullName)\src" -Destination "$dirInstall\programa\src" -Recurse -Force
    Copy-Item -Path "$($extraida.FullName)\requirements.txt" -Destination "$dirInstall\programa\" -Force
    if (Test-Path "$($extraida.FullName)\config.template.yaml") {
        Copy-Item -Path "$($extraida.FullName)\config.template.yaml" -Destination "$dirInstall\programa\config.template.yaml" -Force
    }
    Copy-Item -Path "$($extraida.FullName)\instalar.ps1"     -Destination "$dirInstall\programa\actualizar.ps1"   -Force -ErrorAction SilentlyContinue
    Copy-Item -Path "$($extraida.FullName)\desinstalar.ps1"  -Destination "$dirInstall\programa\desinstalar.ps1"  -Force -ErrorAction SilentlyContinue
    Write-Host "  OK" -ForegroundColor Gray

    # --- 6. Generar config.yaml y .env (UTF-8 sin BOM) ---
    Write-Host ""
    Write-Host "[6/8] Generando config.yaml y .env..." -ForegroundColor Green
    $configFinal = "$dirInstall\programa\config.yaml"
    $envFinal    = "$dirInstall\programa\.env"
    $utf8NoBom   = New-Object System.Text.UTF8Encoding($false)

    if (-not (Test-Path $configFinal)) {
        $template = Get-Content -Path "$dirInstall\programa\config.template.yaml" -Raw
        $template = $template.Replace("{{INSTALL_DIR}}", $dirInstall)
        [System.IO.File]::WriteAllText($configFinal, $template, $utf8NoBom)
        Write-Host "  config.yaml generado" -ForegroundColor Gray
    } else {
        Write-Host "  config.yaml conservado (ya existia)" -ForegroundColor Gray
    }
    if (-not (Test-Path $envFinal)) {
        [System.IO.File]::WriteAllText($envFinal, "ANTHROPIC_API_KEY=$apiKey`r`n", $utf8NoBom)
        Write-Host "  .env generado" -ForegroundColor Gray
    } else {
        Write-Host "  .env conservado (ya existia)" -ForegroundColor Gray
    }

    # --- 7. Instalar dependencias ---
    Write-Host ""
    Write-Host "[7/8] Instalando dependencias de Python (1-2 minutos)..." -ForegroundColor Green
    Push-Location "$dirInstall\programa"
    try {
        & $py.Exe -m pip install --upgrade pip --no-cache-dir 2>&1 | Out-Null
        & $py.Exe -m pip install --no-cache-dir -r requirements.txt
        if ($LASTEXITCODE -ne 0) {
            Write-Host "  Reintentando sin cache..." -ForegroundColor Yellow
            & $py.Exe -m pip cache purge 2>&1 | Out-Null
            & $py.Exe -m pip install --no-cache-dir --force-reinstall -r requirements.txt
            if ($LASTEXITCODE -ne 0) { throw "pip install fallo (codigo $LASTEXITCODE)." }
        }
        Write-Host "  OK" -ForegroundColor Gray
    } finally {
        Pop-Location
    }

    # --- 8. Crear lanzadores .bat y accesos directos ---
    Write-Host ""
    Write-Host "[8/8] Creando accesos directos y autoarranque..." -ForegroundColor Green

    # Calcular pyw.exe (sin consola). Si py.exe es el launcher, junto esta pyw.exe.
    $pyw = $py.Exe -replace "py\.exe$","pyw.exe" -replace "python\.exe$","pythonw.exe"
    if (-not (Test-Path $pyw)) { $pyw = $py.Exe }

    $batBandeja = "@echo off`r`nstart `"`" `"$pyw`" `"$dirInstall\programa\src\main.py`" --tray`r`n"
    $batAdmin   = "@echo off`r`nstart `"`" `"$pyw`" `"$dirInstall\programa\src\buscador.py`"`r`n"
    [System.IO.File]::WriteAllText("$dirInstall\programa\iniciar_bandeja.bat", $batBandeja, [System.Text.Encoding]::ASCII)
    [System.IO.File]::WriteAllText("$dirInstall\programa\iniciar_admin.bat",   $batAdmin,   [System.Text.Encoding]::ASCII)

    $desktop   = [Environment]::GetFolderPath("Desktop")
    $startMenu = [Environment]::GetFolderPath("Programs")
    $startup   = [Environment]::GetFolderPath("Startup")

    New-Shortcut "$desktop\Administrador de Facturas.lnk" `
        $pyw "`"$dirInstall\programa\src\buscador.py`"" `
        "$dirInstall\programa" "Sistema de Gestion de Facturas"

    $dirMenu = "$startMenu\Sistema de Gestion de Facturas"
    if (-not (Test-Path $dirMenu)) { New-Item -ItemType Directory -Path $dirMenu | Out-Null }
    New-Shortcut "$dirMenu\Administrador de Facturas.lnk" `
        $pyw "`"$dirInstall\programa\src\buscador.py`"" `
        "$dirInstall\programa" "Administrador"
    New-Shortcut "$dirMenu\Iniciar vigilancia.lnk" `
        $pyw "`"$dirInstall\programa\src\main.py`" --tray" `
        "$dirInstall\programa" "Vigilancia en bandeja"

    if ($autoarranque) {
        $accAuto = "$startup\Sistema Gestion Facturas (bandeja).lnk"
        New-Shortcut $accAuto `
            $pyw "`"$dirInstall\programa\src\main.py`" --tray" `
            "$dirInstall\programa" "Autoarranque"
        # Reaseguro: tambien escribimos al HKCU\Run para que dispare aunque
        # algunas politicas de Windows ignoren la carpeta Startup.
        $rk = "HKCU:\Software\Microsoft\Windows\CurrentVersion\Run"
        if (-not (Test-Path $rk)) { New-Item -Path $rk -Force | Out-Null }
        Set-ItemProperty -Path $rk -Name "AdminFacturasBandeja" `
            -Value "`"$pyw`" `"$dirInstall\programa\src\main.py`" --tray"
        Write-Host "  Autoarranque configurado (Startup + HKCU\Run)" -ForegroundColor Gray
    }
    Write-Host "  OK" -ForegroundColor Gray

    # --- Limpieza ---
    Remove-Item -Path $tmpZip -Force -ErrorAction SilentlyContinue
    Remove-Item -Path $tmpDir -Recurse -Force -ErrorAction SilentlyContinue

    # --- Resumen ---
    Write-Host ""
    Write-Host "================================================================" -ForegroundColor Green
    Write-Host "  INSTALACION COMPLETADA" -ForegroundColor Green
    Write-Host "================================================================" -ForegroundColor Green
    Write-Host ""
    Write-Host "  Carpeta:        $dirInstall" -ForegroundColor White
    Write-Host "  Escaner deja PDFs en: $dirInstall\Facturas\_entrada\" -ForegroundColor White
    Write-Host "  Base de datos:  $dirInstall\datos\facturas.db" -ForegroundColor White
    Write-Host ""

    # --- Arranque automatico (sin elevacion para que la bandeja se vea bien) ---
    Write-Host "  Iniciando vigilancia en segundo plano..." -ForegroundColor Gray
    # IMPORTANTE: ErrorActionPreference="Continue" en este bloque porque schtasks
    # emite warnings inofensivos en stderr que con "Stop" abortarian el script.
    $prevPref = $ErrorActionPreference
    $ErrorActionPreference = "Continue"
    try {
        # Tecnica: schtasks ONCE /IT lanza como el usuario interactivo, sin elevacion.
        # /ST debe ser futuro o schtasks da warning; usamos 2 minutos en adelante.
        $taskName = "AdminFacturasFirstLaunch_" + [System.Guid]::NewGuid().ToString("N").Substring(0,8)
        $stFuturo = (Get-Date).AddMinutes(2).ToString("HH:mm")
        cmd /c "schtasks /Create /TN `"$taskName`" /TR `"\`"$dirInstall\programa\iniciar_bandeja.bat\`"`" /SC ONCE /ST $stFuturo /IT /F >nul 2>&1"
        cmd /c "schtasks /Run /TN `"$taskName`" >nul 2>&1"
        Start-Sleep -Seconds 2
        cmd /c "schtasks /Delete /TN `"$taskName`" /F >nul 2>&1"
    } catch {
        # Fallback: lanzar directo (puede heredar elevacion, pero al menos arranca)
        Start-Process -FilePath "cmd.exe" `
            -ArgumentList "/c","`"$dirInstall\programa\iniciar_bandeja.bat`"" `
            -WindowStyle Hidden -ErrorAction SilentlyContinue
    } finally {
        $ErrorActionPreference = $prevPref
    }
    Write-Host "  El icono debe aparecer en la bandeja (junto al reloj)." -ForegroundColor Green
    Write-Host "  Si no lo ves, clic en la flecha que muestra los iconos ocultos." -ForegroundColor Gray

    Stop-OnExit
}
catch {
    Write-Host ""
    Write-Host "================================================================" -ForegroundColor Red
    Write-Host "  ERROR EN LA INSTALACION" -ForegroundColor Red
    Write-Host "================================================================" -ForegroundColor Red
    Write-Host ""
    Write-Host "  $_" -ForegroundColor Yellow
    Write-Host ""
    Write-Host "  Que hacer:" -ForegroundColor White
    Write-Host "  1. Revisa el log completo." -ForegroundColor Gray
    Write-Host "  2. Si es un error de conexion, intenta de nuevo." -ForegroundColor Gray
    Write-Host "  3. Si es un error de Python, instala 3.10+ manualmente desde python.org" -ForegroundColor Gray
    Write-Host "     y marca 'Add Python to PATH'." -ForegroundColor Gray
    Stop-OnExit
    exit 1
}
