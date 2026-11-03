# Backup diário do PetShop: roda o backup no container e copia storage\backups para o OneDrive.
# Agendado pelo Agendador de Tarefas do Windows (ver README, seção "Backup").
$ErrorActionPreference = 'Stop'

$projeto = Split-Path -Parent $PSScriptRoot
$origem = Join-Path $projeto 'storage\backups'
$log = Join-Path $origem 'backup.log'

$ultimo = Join-Path $origem 'ultimo.json'

# $aviso: texto curto para o Dashboard quando o backup local foi feito e só a etapa do OneDrive falhou
function Falhar([string] $motivo, [string] $aviso = '') {
    New-Item -ItemType Directory -Force $origem | Out-Null
    $quando = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
    Add-Content -Encoding utf8 -LiteralPath $log "$quando FALHA windows: $motivo"
    # Também no ultimo.json, que é o que o Dashboard lê (sem BOM, senão o PHP não lê o JSON)
    $dados = @{ quando = $quando; ok = $false; mensagem = "windows: $motivo"; pasta = '' }
    if ($aviso) { $dados.aviso = $aviso }
    $json = $dados | ConvertTo-Json -Compress
    [IO.File]::WriteAllText((Join-Path $origem 'ultimo.json.tmp'), $json)
    Move-Item -Force -LiteralPath (Join-Path $origem 'ultimo.json.tmp') -Destination (Join-Path $origem 'ultimo.json')
    exit 1
}

try {
    # O backup em si roda dentro do container. Logo depois de ligar o computador (tarefa atrasada) o Docker Desktop
    # ainda está subindo: tenta por até 10 minutos. 'up -d' sobe o sistema se ele estiver parado.
    $compose = Join-Path $projeto 'docker-compose.yml'
    $antes = if (Test-Path -LiteralPath $ultimo) { (Get-Item -LiteralPath $ultimo).LastWriteTimeUtc }
    for ($tentativa = 1; $tentativa -le 10; $tentativa++) {
        # Aviso do docker em stderr não é erro: com 'Stop' o PS 5.1 o transforma em exceção em alguns hosts. Vale o código de saída.
        $ErrorActionPreference = 'Continue'
        docker compose -f $compose up -d 2>&1 | Out-Null
        docker compose -f $compose exec -T -u www-data php php scripts/backup.php
        $codigo = $LASTEXITCODE
        $ErrorActionPreference = 'Stop'
        if ($codigo -eq 0) { break }
        # O backup.php rodou e já gravou o motivo real no log e no ultimo.json: não cobre com "Docker ligado?"
        $depois = if (Test-Path -LiteralPath $ultimo) { (Get-Item -LiteralPath $ultimo).LastWriteTimeUtc }
        if ($depois -ne $antes) { exit 1 }
        if ($tentativa -lt 10) { Start-Sleep -Seconds 60 }
    }
    if ($codigo -ne 0) { Falhar "o Docker não respondeu em 10 minutos (Docker Desktop aberto?), código $codigo" }

    if (-not $env:OneDrive) { Falhar 'pasta do OneDrive não encontrada (o backup local foi feito)' 'a pasta do OneDrive não foi encontrada' }
    $destino = Join-Path $env:OneDrive 'Backups PetShop'

    # /E só acrescenta (sem /MIR: pasta sumida aqui não apaga nada no OneDrive); /XJ não segue junções. Códigos 8+ = erro.
    robocopy $origem $destino /E /XJ /XF *.tmp .lock /XD *.tmp /R:2 /W:5 /NFL /NDL /NP /NJH /NJS | Out-Null
    if ($LASTEXITCODE -ge 8) { Falhar "cópia para o OneDrive falhou, código $LASTEXITCODE (o backup local foi feito)" 'a cópia para o OneDrive falhou' }

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
