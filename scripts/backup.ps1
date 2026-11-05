# Backup diário do PetShop: roda o backup no container e manda uma cópia cifrada (7-Zip) para o OneDrive.
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

# Marca o início de cada execução: se o computador desligar ou dormir no meio, o log mostra "INICIO" sem OK/FALHA
# depois (antes não ficava rastro nenhum e a tarefa do Windows ainda dizia "sucesso").
New-Item -ItemType Directory -Force $origem | Out-Null
Add-Content -Encoding utf8 -LiteralPath $log "$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss') INICIO windows: backup começou"

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

    # No OneDrive fica só o backup cifrado: um .7z por backup, AES-256 com os nomes dos arquivos também escondidos (-mhe).
    # Quem entrar na conta do OneDrive não lê nada sem a senha BACKUP_SENHA do .env.
    $seteZip = Join-Path $env:ProgramFiles '7-Zip\7z.exe'
    if (-not (Test-Path -LiteralPath $seteZip)) { Falhar "7-Zip não encontrado em $seteZip (o backup local foi feito)" 'o 7-Zip não está instalado' }
    $senha = Get-Content -LiteralPath (Join-Path $projeto '.env') -ErrorAction SilentlyContinue |
        Where-Object { $_ -match '^\s*BACKUP_SENHA\s*=' } | ForEach-Object { ($_ -split '=', 2)[1].Trim() } | Select-Object -Last 1
    if (-not $senha -or $senha.Length -lt 12) { Falhar 'BACKUP_SENHA ausente ou com menos de 12 caracteres no .env (o backup local foi feito)' 'a senha do backup (BACKUP_SENHA no .env) não está configurada' }
    New-Item -ItemType Directory -Force -Path $destino | Out-Null

    # Nome válido = backup completo (o Backup.php só dá esse nome no fim); junção nunca é seguida
    $valido = { param($nome) $nome -match '^\d{4}-\d{2}-\d{2}_\d{4}(\d{2})?$' -and
                [datetime]::TryParseExact($nome.Substring(0, 10), 'yyyy-MM-dd', $null, 'None', [ref] [datetime]::MinValue) }
    $novos = 0
    Get-ChildItem -Directory -LiteralPath $origem |
        Where-Object { (& $valido $_.Name) -and -not ($_.Attributes -band [IO.FileAttributes]::ReparsePoint) } |
        ForEach-Object {
            $arquivo = Join-Path $destino "$($_.Name).7z"
            if (Test-Path -LiteralPath $arquivo) { return }   # já cifrado numa execução anterior
            $tmp = "$arquivo.tmp"                              # só ganha o nome .7z depois de criado e conferido
            Remove-Item -LiteralPath $tmp -Force -ErrorAction SilentlyContinue
            # shortcut: a senha vai na linha de comando do 7z (outro usuário logado no mesmo Windows poderia vê-la na lista de processos); computador de um usuário só
            $ErrorActionPreference = 'Continue'
            & $seteZip a -t7z -mhe=on "-p$senha" -bso0 -bsp0 $tmp (Join-Path $_.FullName '*') 2>&1 | Out-Null
            $codigo = $LASTEXITCODE
            if ($codigo -eq 0) { & $seteZip t "-p$senha" -bso0 -bsp0 $tmp 2>&1 | Out-Null; $codigo = $LASTEXITCODE }
            $ErrorActionPreference = 'Stop'
            if ($codigo -ne 0) {
                Remove-Item -LiteralPath $tmp -Force -ErrorAction SilentlyContinue
                Falhar "7-Zip falhou ao cifrar $($_.Name), código $codigo (o backup local foi feito)" 'a cópia cifrada para o OneDrive falhou'
            }
            Move-Item -LiteralPath $tmp -Destination $arquivo
            $novos++
        }

    # Retenção própria no OneDrive, como a do Backup.php: mais de 30 dias pelo nome, mas sempre ficam os 7 mais novos.
    # Pastas sem cifra de antes do 7-Zip entram na mesma regra e somem sozinhas.
    $limite = (Get-Date).AddDays(-30).ToString('yyyy-MM-dd')
    # -LiteralPath: OneDrive com [ ] no caminho viraria curinga (e a retenção casaria outra pasta)
    Get-ChildItem -LiteralPath $destino |
        Where-Object { -not ($_.Attributes -band [IO.FileAttributes]::ReparsePoint) } |
        ForEach-Object { $nome = if ($_.PSIsContainer) { $_.Name } elseif ($_.Name -like '*.7z') { $_.Name -replace '\.7z$', '' } else { '' }
                         if ($nome -and (& $valido $nome)) { [pscustomobject] @{ Nome = $nome; Item = $_ } } } |
        Sort-Object Nome -Descending | Select-Object -Skip 7 |
        Where-Object { $_.Nome.Substring(0, 10) -lt $limite } |
        ForEach-Object { Remove-Item -LiteralPath $_.Item.FullName -Recurse -Force }
} catch {
    Falhar "erro inesperado: $($_.Exception.Message)"
}

Write-Output "Backup cifrado no OneDrive ($novos arquivo(s) novo(s)): $destino"
exit 0
