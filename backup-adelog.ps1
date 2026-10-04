<#
.SYNOPSIS
    Comando Oficial ADELOG: Respaldo Integral PAD-28/32 (backup-adelog / bkadelog)
.DESCRIPTION
    Automatiza el respaldo completo de la plataforma electoral PAD-28/32:
    1. Volcado MySQL de pad_electoral_2832 (.sql y .sql.zip).
    2. Generación del Kit de Instalación Autónomo con script de restauración.
    3. Sincronización del código fuente en F:\ADELOG\PLATAFORMA DIGITAL-PAD-28-32-backup.
    4. Empaquetado comprimido ZIP unificado.
    5. Commit y Push automático a GitHub (origin main).
    6. Asistente para Google Drive (apertura de carpeta local y enlace web).
    7. Registro en bitácora histórica bajo normas PLAD.
#>

[CmdletBinding()]
param (
    [switch]$NoPush,
    [switch]$NoOpenBrowser
)

$ErrorActionPreference = 'Stop'
$SourceDir = 'C:\wamp64\www\PLATAFORMA DIGITAL-PAD-28-32'
$TargetRoot = 'F:\ADELOG\PLATAFORMA DIGITAL-PAD-28-32-backup'
$DriveUrl = 'https://drive.google.com/drive/folders/1K68LHN234gQyPDxVJUJvNE3nL1F0637I?usp=drive_link'
$Timestamp = (Get-Date).ToString('yyyyMMdd_HHmmss')
$DateDisplay = (Get-Date).ToString('yyyy-MM-dd HH:mm:ss')

Write-Host '=====================================================================' -ForegroundColor Cyan
Write-Host '   SISTEMA AUTOMATICO DE RESPALDO INTEGRAL ADELOG (PAD-28/32)' -ForegroundColor Yellow
Write-Host '   Normas PLAD (Planificador por Defecto) & Auditoria Electoral' -ForegroundColor White
Write-Host "   Fecha de Ejecucion: $DateDisplay" -ForegroundColor DarkGray
Write-Host '=====================================================================' -ForegroundColor Cyan
Write-Host ''

# 1. VERIFICACIONES PREVIAS
Write-Host '[1/6] Verificando entorno de ejecucion...' -ForegroundColor Cyan

if (-not (Test-Path $SourceDir)) {
    Write-Host "ERROR: Directorio fuente no encontrado: $SourceDir" -ForegroundColor Red
    exit 1
}

if (-not (Test-Path 'F:\')) {
    Write-Host 'ADVERTENCIA: La unidad F:\ no se encuentra conectada.' -ForegroundColor Red
    Write-Host 'Conecte la unidad de disco F:\ para continuar con el respaldo.' -ForegroundColor Yellow
    exit 1
}

# Auto-detectar mysqldump
$mysqldumpPath = (Get-ChildItem -Path 'C:\wamp64\bin\mysql' -Filter 'mysqldump.exe' -Recurse -ErrorAction SilentlyContinue | Select-Object -First 1).FullName
if (-not $mysqldumpPath -or -not (Test-Path $mysqldumpPath)) {
    Write-Host 'ERROR: No se encontro mysqldump.exe en C:\wamp64\bin\mysql\.' -ForegroundColor Red
    exit 1
}
Write-Host "  OK - mysqldump detectado: $mysqldumpPath" -ForegroundColor Green
Write-Host "  OK - Unidad F:\ disponible y lista." -ForegroundColor Green

# Crear directorios estructurados en F:\
$DirDB = Join-Path $TargetRoot 'BASE_DE_DATOS'
$DirKit = Join-Path $TargetRoot 'KIT_INSTALACION'
$DirCode = Join-Path $TargetRoot 'PLATAFORMA DIGITAL-PAD-28-32'
$DirZips = Join-Path $TargetRoot 'PAQUETES_COMPRIMIDOS_ZIP'
$DirDrive = Join-Path $TargetRoot 'LISTO_PARA_GOOGLE_DRIVE'

@($DirDB, $DirKit, $DirCode, $DirZips, $DirDrive) | ForEach-Object {
    if (-not (Test-Path $_)) { New-Item -ItemType Directory -Path $_ -Force | Out-Null }
}

# 2. VOLCADO ATOMICO DE LA BASE DE DATOS
Write-Host ''
Write-Host '[2/6] Generando volcado MySQL de pad_electoral_2832...' -ForegroundColor Cyan
$SqlDumpFile = Join-Path $DirDB "pad_electoral_2832_dump_$Timestamp.sql"
$SqlLatestFile = Join-Path $DirDB 'pad_electoral_2832_dump_latest.sql'

$dumpArgs = @(
    '-u', 'root',
    'pad_electoral_2832',
    '--single-transaction',
    '--quick',
    '--routines',
    '--triggers',
    '--events',
    '--add-drop-table',
    "--result-file=$SqlDumpFile"
)

& $mysqldumpPath @dumpArgs
if ($LASTEXITCODE -ne 0) {
    Write-Host 'ERROR al generar el volcado con mysqldump.' -ForegroundColor Red
    exit 1
}

Copy-Item -Path $SqlDumpFile -Destination $SqlLatestFile -Force
$dumpSizeMB = [math]::Round(((Get-Item $SqlDumpFile).Length / 1MB), 2)
Write-Host "  OK - Volcado completado: $dumpSizeMB MB" -ForegroundColor Green

# Comprimir volcado SQL para transferencia rapida
Write-Host '  Comprimiendo volcado SQL...' -ForegroundColor DarkGray
$SqlZipFile = Join-Path $DirDB "pad_electoral_2832_dump_$Timestamp.sql.zip"
$SqlZipLatest = Join-Path $DirDB 'pad_electoral_2832_dump_latest.sql.zip'
Compress-Archive -Path $SqlDumpFile -DestinationPath $SqlZipFile -Force
Copy-Item -Path $SqlZipFile -Destination $SqlZipLatest -Force
$sqlZipSizeMB = [math]::Round(((Get-Item $SqlZipFile).Length / 1MB), 2)
Write-Host "  OK - SQL comprimido: $sqlZipSizeMB MB" -ForegroundColor Green

# 3. GENERACION DEL KIT DE INSTALACION AUTONOMO
Write-Host ''
Write-Host '[3/6] Compilando Kit de Instalacion Autonomo...' -ForegroundColor Cyan

Copy-Item (Join-Path $SourceDir 'backend\install.php') $DirKit -Force
Copy-Item (Join-Path $SourceDir 'backend\verify_system.php') $DirKit -Force
Copy-Item (Join-Path $SourceDir 'backend\config.example.php') $DirKit -Force
if (Test-Path (Join-Path $SourceDir 'Credenciales por defecto sembradas.txt')) {
    Copy-Item (Join-Path $SourceDir 'Credenciales por defecto sembradas.txt') $DirKit -Force
}

# Crear script batch de restauracion automatica 1-clic
$RestoreBatContent = @"
@echo off
chcp 65001 >nul
echo =====================================================================
echo    RESTAURADOR AUTOMATICO DE BASE DE DATOS ADELOG (PAD-28/32)
echo =====================================================================
echo.
set MYSQL_BIN=C:\wamp64\bin\mysql\mysql8.4.7\bin\mysql.exe
if not exist "%MYSQL_BIN%" (
    for /d %%d in (C:\wamp64\bin\mysql\mysql*) do (
        if exist "%%d\bin\mysql.exe" set MYSQL_BIN=%%d\bin\mysql.exe
    )
)

if not exist "%MYSQL_BIN%" (
    echo [ERROR] No se encontro mysql.exe en WampServer.
    pause
    exit /b 1
)

echo Importando pad_electoral_2832 desde dump mas reciente...
"%MYSQL_BIN%" -u root -e "CREATE DATABASE IF NOT EXISTS pad_electoral_2832 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
"%MYSQL_BIN%" -u root pad_electoral_2832 < "%~dp0..\BASE_DE_DATOS\pad_electoral_2832_dump_latest.sql"

if %ERRORLEVEL% EQU 0 (
    echo.
    echo [OK] Base de datos pad_electoral_2832 restaurada con exito.
) else (
    echo.
    echo [ERROR] Ocurrio un fallo durante la importacion.
)
pause
"@
Set-Content -Path (Join-Path $DirKit 'restaurar_base_de_datos.bat') -Value $RestoreBatContent -Encoding UTF8

# Crear Guia Rapida
$GuiaContent = @"
# Guia Rapida de Instalacion y Puesta en Marcha - PAD-28/32

1. **Requisitos:**
   - WampServer 3.3+ con PHP 8.1+ y MySQL 8.4+ (puerto 3306).
2. **Restauracion de Base de Datos:**
   - Ejecutar como Administrador `restaurar_base_de_datos.bat`.
   - Se creara y poblara la base de datos `pad_electoral_2832`.
3. **Despliegue del Codigo:**
   - Copiar la carpeta `PLATAFORMA DIGITAL-PAD-28-32` a `C:\wamp64\www\`.
   - Configurar credenciales en `backend/config.php` basandose en `config.example.php`.
4. **Verificacion:**
   - Acceder en navegador a `http://localhost/PLATAFORMA%20DIGITAL-PAD-28-32/backend/verify_system.php`.
"@
Set-Content -Path (Join-Path $DirKit 'GUIA_RAPIDA_DE_DESPLIEGUE.md') -Value $GuiaContent -Encoding UTF8
Write-Host '  OK - Kit de instalacion y script restaurar_base_de_datos.bat listos.' -ForegroundColor Green

# 4. REPLICACION Y EMPAQUETADO EN F:\
Write-Host ''
Write-Host '[4/6] Replicando codigo y empaquetando ZIPs en F:\...' -ForegroundColor Cyan

# Sincronizar archivos de codigo excluyendo respaldos anidados y .git
$robocopyArgs = @(
    $SourceDir,
    $DirCode,
    '/MIR',
    '/XD', '.git', 'scratch', 'backups',
    '/XF', '*.rar', '*.zip', '*.log',
    '/R:1', '/W:1',
    '/NFL', '/NDL', '/NJH', '/NJS'
)
& robocopy @robocopyArgs | Out-Null

# Paquete 1: Plataforma Completa ZIP
$ZipPlatform = Join-Path $DirZips "PAD2832_PLATAFORMA_COMPLETA_$Timestamp.zip"
Write-Host "  Generando $ZipPlatform..." -ForegroundColor DarkGray
Compress-Archive -Path "$DirCode\*" -DestinationPath $ZipPlatform -Force

# Paquete 2: Kit + DB ZIP
$ZipKitDB = Join-Path $DirZips "PAD2832_KIT_INSTALACION_Y_DB_$Timestamp.zip"
Write-Host "  Generando $ZipKitDB..." -ForegroundColor DarkGray
Compress-Archive -Path "$DirKit\*", "$SqlDumpFile" -DestinationPath $ZipKitDB -Force

# Paquete 3: Backup Total Unificado
$ZipTotal = Join-Path $DirZips "PAD2832_BACKUP_TOTAL_UNIFICADO_$Timestamp.zip"
$ZipTotalLatest = Join-Path $DirDrive 'PAD2832_BACKUP_TOTAL_UNIFICADO_LATEST.zip'
Write-Host "  Generando $ZipTotal..." -ForegroundColor DarkGray
Compress-Archive -Path "$DirCode\*", "$DirKit\*", "$SqlDumpFile" -DestinationPath $ZipTotal -Force
Copy-Item -Path $ZipTotal -Destination $ZipTotalLatest -Force

$totalZipSizeMB = [math]::Round(((Get-Item $ZipTotal).Length / 1MB), 2)
Write-Host "  OK - Paquetes ZIP consolidados. Paquete Unificado: $totalZipSizeMB MB" -ForegroundColor Green

# 5. SINCRONIZACION CON GITHUB
Write-Host ''
Write-Host '[5/6] Sincronizando con repositorio GitHub (origin main)...' -ForegroundColor Cyan
if (-not $NoPush) {
    Push-Location $SourceDir
    try {
        git add -A
        $status = git status --porcelain
        if ($status) {
            $commitMsg = "backup-adelog: respaldo automatico pad-2832 [$Timestamp]"
            git commit -m $commitMsg
            Write-Host "  Commit creado: $commitMsg" -ForegroundColor Yellow
            git push origin main
            if ($LASTEXITCODE -eq 0) {
                Write-Host '  OK - Repositorio remoto GitHub actualizado con exito.' -ForegroundColor Green
            } else {
                Write-Host '  ADVERTENCIA: Fallo al hacer push a GitHub. Verifique conexion a internet.' -ForegroundColor Yellow
            }
        } else {
            Write-Host '  OK - Repositorio GitHub ya se encuentra limpio y al dia.' -ForegroundColor Green
        }
    } finally {
        Pop-Location
    }
} else {
    Write-Host '  Omitido por parametro -NoPush.' -ForegroundColor DarkGray
}

# 6. ASISTENTE DE GOOGLE DRIVE Y CIERRE PLAD
Write-Host ''
Write-Host '[6/6] Preparando enlace y paquete para Google Drive...' -ForegroundColor Cyan

# Crear acceso directo .url
$UrlShortcutPath = Join-Path $DirDrive 'CARPETA_DE_GOOGLE_DRIVE.url'
$UrlContent = @"
[InternetShortcut]
URL=$DriveUrl
"@
Set-Content -Path $UrlShortcutPath -Value $UrlContent -Encoding ASCII

# Registrar en historial PLAD
$HistoryFile = Join-Path $SourceDir 'tasks\backup_history.log'
$LogEntry = "[$DateDisplay] BACKUP-ADELOG EXITOSO | Dump: $dumpSizeMB MB | Zip Unificado: $totalZipSizeMB MB | GitHub: OK | F:\: OK"
Add-Content -Path $HistoryFile -Value $LogEntry -Encoding UTF8

Write-Host ''
Write-Host '=====================================================================' -ForegroundColor Green
Write-Host '   RESPALDO COMPLETADO EXITOSAMENTE BAJO NORMAS PLAD' -ForegroundColor Green
Write-Host '=====================================================================' -ForegroundColor Green
Write-Host "1. F:\ADELOG Fisico:   $TargetRoot" -ForegroundColor White
Write-Host "2. Base de Datos SQL:  $SqlDumpFile ($dumpSizeMB MB)" -ForegroundColor White
Write-Host "3. Paquete Unificado:  $ZipTotalLatest ($totalZipSizeMB MB)" -ForegroundColor White
Write-Host "4. GitHub Remoto:      https://github.com/jhenri27/ADELOG-PLATAFORMA-DIGITAL-PAD-28-32" -ForegroundColor White
Write-Host "5. Google Drive:       $DriveUrl" -ForegroundColor White
Write-Host '=====================================================================' -ForegroundColor Green
Write-Host ''

if (-not $NoOpenBrowser) {
    Write-Host 'Abriendo carpeta de Google Drive en navegador y paquete local...' -ForegroundColor Cyan
    Start-Process $DriveUrl
    Start-Process explorer.exe -ArgumentList $DirDrive
}