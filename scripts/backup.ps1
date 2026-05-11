# backup.ps1 - Backup MySQL de la base de datos de creditos
# Lee credenciales desde config/conexion.local.php via PHP.
# NO tiene contrasenas hardcodeadas.
#
# Uso:
#   powershell -ExecutionPolicy Bypass -File scripts\backup.ps1
#
# Programar en Windows Task Scheduler:
#   Programa: powershell.exe
#   Argumentos: -ExecutionPolicy Bypass -NonInteractive -File "C:\wamp64\www\creditos\scripts\backup.ps1"
#   Directorio inicial: C:\wamp64\www\creditos

$ErrorActionPreference = "Stop"

$BaseDir   = Split-Path -Parent $PSScriptRoot
$BackupDir = Join-Path $BaseDir "backups"
$CfgFile   = Join-Path $BaseDir "config\conexion.local.php"
$LogFile   = Join-Path $BaseDir "logs\backup.log"

function Log($msg) {
    $line = "[$([datetime]::Now.ToString('yyyy-MM-dd HH:mm:ss'))] $msg"
    Write-Host $line
    Add-Content -Path $LogFile -Value $line -Encoding UTF8
}

# Crear directorios si no existen
if (!(Test-Path $BackupDir)) { New-Item -ItemType Directory -Path $BackupDir | Out-Null }
if (!(Test-Path (Split-Path $LogFile))) { New-Item -ItemType Directory -Path (Split-Path $LogFile) | Out-Null }

$Stamp    = [datetime]::Now.ToString("yyyyMMdd_HHmm")
$OutFile  = Join-Path $BackupDir "creditos_$Stamp.sql"

Log "Iniciando backup -> $OutFile"

# Verificar archivo de credenciales
if (!(Test-Path $CfgFile)) {
    Log "ERROR: No se encontro config\conexion.local.php"
    exit 1
}

# Leer credenciales via PHP
$CfgUnix = $CfgFile -replace '\\', '/'
$phpCode = "`$c=require '$CfgUnix'; echo `$c['host'].'|'.`$c['name'].'|'.`$c['user'].'|'.`$c['pass'];"
$CredsRaw = & php -r $phpCode 2>&1
if ($LASTEXITCODE -ne 0) {
    Log "ERROR: PHP fallo al leer credenciales: $CredsRaw"
    exit 1
}
$parts  = $CredsRaw -split '\|'
$DbHost = $parts[0]
$DbName = $parts[1]
$DbUser = $parts[2]
$DbPass = $parts[3]

if (!$DbName) {
    Log "ERROR: Credenciales vacias o archivo de configuracion invalido."
    exit 1
}

Log "BD: $DbName en $DbHost (usuario: $DbUser)"

# Buscar mysqldump
$Mysqldump = $null
$SearchPaths = @(
    "C:\wamp64\bin\mysql\mysql8.4.7\bin\mysqldump.exe",
    "C:\wamp64\bin\mysql\mysql8.0.31\bin\mysqldump.exe",
    "C:\wamp64\bin\mysql\mysql8.0.27\bin\mysqldump.exe",
    "C:\xampp\mysql\bin\mysqldump.exe",
    "C:\Program Files\MySQL\MySQL Server 8.0\bin\mysqldump.exe"
)
foreach ($p in $SearchPaths) {
    if (Test-Path $p) { $Mysqldump = $p; break }
}
if (!$Mysqldump) {
    $found = Get-Command mysqldump -ErrorAction SilentlyContinue
    if ($found) { $Mysqldump = $found.Source }
}
if (!$Mysqldump) {
    Log "ERROR: mysqldump no encontrado. Ajustar las rutas en scripts\backup.ps1"
    exit 1
}
Log "Usando: $Mysqldump"

# Crear archivo de credenciales temporal para mysqldump (evita password en linea de comandos)
$MyCnf = [System.IO.Path]::GetTempFileName()
Set-Content -Path $MyCnf -Value "[client]`npassword=$DbPass" -Encoding ASCII

try {
    & $Mysqldump --defaults-extra-file="$MyCnf" --host="$DbHost" --user="$DbUser" `
        --single-transaction --routines --triggers --add-drop-table `
        $DbName > $OutFile 2>> $LogFile

    if ($LASTEXITCODE -ne 0) {
        Log "ERROR: mysqldump fallo (exit $LASTEXITCODE). Ver $LogFile"
        if (Test-Path $OutFile) { Remove-Item $OutFile }
        exit 1
    }
} finally {
    Remove-Item $MyCnf -ErrorAction SilentlyContinue
}

# Verificar que el archivo no este vacio
$Size = (Get-Item $OutFile).Length
if ($Size -eq 0) {
    Log "ERROR: El dump esta vacio."
    Remove-Item $OutFile
    exit 1
}

Log "Backup OK: $OutFile ($Size bytes)"

# Rotar archivos de mas de 30 dias
$Cutoff = (Get-Date).AddDays(-30)
$Old = Get-ChildItem $BackupDir -Filter "creditos_*.sql" | Where-Object { $_.LastWriteTime -lt $Cutoff }
foreach ($f in $Old) {
    Remove-Item $f.FullName
    Log "Rotado (viejo): $($f.Name)"
}

Log "Backup completado."
exit 0
