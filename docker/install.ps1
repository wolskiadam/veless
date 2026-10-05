# Instalacja lokalna CRM w Dockerze (Windows, Docker Desktop).
# Uruchom w PowerShell z katalogu CRM:  powershell -ExecutionPolicy Bypass -File docker\install.ps1
$ErrorActionPreference = 'Stop'
Set-Location (Join-Path $PSScriptRoot '..')

docker compose version *> $null
if ($LASTEXITCODE -ne 0) {
    Write-Host 'Brak Dockera. Zainstaluj Docker Desktop: https://www.docker.com/products/docker-desktop/'
    exit 1
}

function Rnd([int]$n) { -join ((48..57) + (65..90) + (97..122) | Get-Random -Count $n | ForEach-Object { [char]$_ }) }

$port = if ($env:CRM_PORT) { $env:CRM_PORT } else { '8081' }
if (-not (Test-Path .env)) {
    $adminPass = Rnd 16
    $map = [ordered]@{
        'DB_HOST' = 'db'; 'DB_NAME' = 'crm'; 'DB_USER' = 'crm'; 'DB_PASS' = (Rnd 32)
        'WORKER_HTTP_SECRET' = (Rnd 48); 'ADMIN_DEFAULT_PASSWORD' = $adminPass
        'APP_MODE' = 'local'; 'APP_URL' = "http://localhost:$port"
        'ALLEGRO_REDIRECT_URI' = "http://localhost:$port/auth_allegro_callback.php"
    }
    $lines = Get-Content .env.example | ForEach-Object {
        $line = $_
        foreach ($k in $map.Keys) { if ($line -match "^$k=") { $line = "$k=$($map[$k])" } }
        $line
    }
    $lines += '', "CRM_PORT=$port"
    # Bez BOM - docker compose i parser CRM czytają czysty UTF-8.
    [IO.File]::WriteAllLines((Join-Path (Get-Location) '.env'), $lines)
    Write-Host 'Utworzono .env (hasła wygenerowane losowo).'
} else {
    Write-Host 'Plik .env już istnieje - zostawiam go bez zmian.'
    $adminPass = ((Select-String -Path .env -Pattern '^ADMIN_DEFAULT_PASSWORD=(.*)$').Matches.Groups[1].Value)
}

New-Item -ItemType Directory -Force -Path dane\mysql, storage | Out-Null
docker compose up -d --build
if ($LASTEXITCODE -ne 0) { exit 1 }

Write-Host -NoNewline 'Czekam na panel'
for ($i = 0; $i -lt 90; $i++) {
    try { Invoke-WebRequest -UseBasicParsing "http://localhost:$port/admin/login.php" -TimeoutSec 3 | Out-Null; break }
    catch { Write-Host -NoNewline '.'; Start-Sleep 2 }
}
Write-Host ''
Write-Host "Gotowe. Panel: http://localhost:$port/admin/"
Write-Host "Login: admin   Hasło: $adminPass"
Write-Host 'Zmień hasło po pierwszym logowaniu. Dane leżą w tym katalogu (dane\ i storage\) - rób kopie.'
