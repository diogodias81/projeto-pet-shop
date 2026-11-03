# Backup diário do PetShop: roda o backup no container e copia storage\backups para o OneDrive.
# Agendado pelo Agendador de Tarefas do Windows (ver README, seção "Backup").
$ErrorActionPreference = 'Stop'

$projeto = Split-Path -Parent $PSScriptRoot
$origem = Join-Path $projeto 'storage\backups'
$log = Join-Path $origem 'backup.log'

function Falhar([string] $motivo) {
    New-Item -ItemType Directory -Force $origem | Out-Null
    $quando = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
    Add-Content -Encoding utf8 -LiteralPath $log "$quando FALHA windows: $motivo"
    # Também no ultimo.json, que é o que o Dashboard lê (sem BOM, senão o PHP não lê o JSON)
    $json = @{ quando = $quando; ok = $false; mensagem = "windows: $motivo"; pasta = '' } | ConvertTo-Json -Compress
    [IO.File]::WriteAllText((Join-Path $origem 'ultimo.json.tmp'), $json)
    Move-Item -Force -LiteralPath (Join-Path $origem 'ultimo.json.tmp') -Destination (Join-Path $origem 'ultimo.json')
    exit 1
}

try {
    # O backup em si roda dentro do container (precisa do Docker ligado)
    # Aviso do docker em stderr não é erro: com 'Stop' o PS 5.1 o transforma em exceção em alguns hosts. Vale o código de saída.
    $ErrorActionPreference = 'Continue'
    docker compose -f (Join-Path $projeto 'docker-compose.yml') exec -T -u www-data php php scripts/backup.php
    $ErrorActionPreference = 'Stop'
    if ($LASTEXITCODE -ne 0) { Falhar "backup no container não rodou (Docker ligado?), código $LASTEXITCODE" }

    if (-not $env:OneDrive) { Falhar 'pasta do OneDrive não encontrada (o backup local foi feito)' }
    $destino = Join-Path $env:OneDrive 'Backups PetShop'

    # /E só acrescenta (sem /MIR: pasta sumida aqui não apaga nada no OneDrive); /XJ não segue junções. Códigos 8+ = erro.
    robocopy $origem $destino /E /XJ /XF *.tmp .lock /XD *.tmp /R:2 /W:5 /NFL /NDL /NP /NJH /NJS | Out-Null
    if ($LASTEXITCODE -ge 8) { Falhar "cópia para o OneDrive falhou, código $LASTEXITCODE (o backup local foi feito)" }

    # Retenção própria no OneDrive, como a do Backup.php: mais de 30 dias pelo nome, mas sempre ficam as 7 mais novas
    $limite = (Get-Date).AddDays(-30).ToString('yyyy-MM-dd')
    $data = [datetime]::MinValue
    # -LiteralPath: OneDrive com [ ] no caminho viraria curinga (e a retenção casaria outra pasta)
    Get-ChildItem -Directory -LiteralPath $destino |
        Where-Object { $_.Name -match '^\d{4}-\d{2}-\d{2}_\d{4}(\d{2})?$' -and -not ($_.Attributes -band [IO.FileAttributes]::ReparsePoint) -and
                       [datetime]::TryParseExact($_.Name.Substring(0, 10), 'yyyy-MM-dd', $null, 'None', [ref] $data) } |
        Sort-Object Name -Descending | Select-Object -Skip 7 |
        Where-Object { $_.Name.Substring(0, 10) -lt $limite } |
        Remove-Item -Recurse -Force
} catch {
    Falhar "erro inesperado: $($_.Exception.Message)"
}

Write-Output "Backup copiado para $destino"
exit 0
