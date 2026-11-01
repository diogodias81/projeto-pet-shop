# Backup diário do PetShop: roda o backup no container e espelha storage\backups no OneDrive.
# Agendado pelo Agendador de Tarefas do Windows (ver README, seção "Backup").
$ErrorActionPreference = 'Stop'

$projeto = Split-Path -Parent $PSScriptRoot
$origem = Join-Path $projeto 'storage\backups'
$log = Join-Path $origem 'backup.log'

function Falhar([string] $motivo) {
    New-Item -ItemType Directory -Force $origem | Out-Null
    Add-Content -Encoding utf8 $log "$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss') FALHA windows: $motivo"
    exit 1
}

# O backup em si roda dentro do container (precisa do Docker ligado)
docker compose -f (Join-Path $projeto 'docker-compose.yml') exec -T -u www-data php php scripts/backup.php
if ($LASTEXITCODE -ne 0) { Falhar "backup no container não rodou (Docker ligado?), código $LASTEXITCODE" }

if (-not $env:OneDrive) { Falhar 'pasta do OneDrive não encontrada (o backup local foi feito)' }
$destino = Join-Path $env:OneDrive 'Backups PetShop'

# /MIR espelha: o que a retenção de 30 dias apagou aqui também sai do OneDrive. Códigos 8+ = erro do robocopy.
robocopy $origem $destino /MIR /R:2 /W:5 /NFL /NDL /NP /NJH /NJS | Out-Null
if ($LASTEXITCODE -ge 8) { Falhar "cópia para o OneDrive falhou, código $LASTEXITCODE" }

Write-Output "Backup copiado para $destino"
exit 0
