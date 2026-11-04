param(
    [string] $SiteUrl = 'http://localhost/wordpress',
    [string] $TaskName = 'WordPress Article Automation WP-Cron'
)

$pingScript = Join-Path $PSScriptRoot 'ping-wp-cron.ps1'
if (-not (Test-Path -LiteralPath $pingScript)) {
    throw "Script de acionamento do WP-Cron não encontrado: $pingScript"
}

$powershell = Join-Path $PSHOME 'powershell.exe'
$arguments = '-NoProfile -ExecutionPolicy Bypass -File "{0}" -SiteUrl "{1}"' -f $pingScript, $SiteUrl
$taskAction = New-ScheduledTaskAction -Execute $powershell -Argument $arguments
$taskTrigger = New-ScheduledTaskTrigger `
    -Once `
    -At (Get-Date).AddMinutes(1) `
    -RepetitionInterval (New-TimeSpan -Minutes 1) `
    -RepetitionDuration (New-TimeSpan -Days 3650)
$taskSettings = New-ScheduledTaskSettingsSet -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Minutes 2)

Register-ScheduledTask `
    -TaskName $TaskName `
    -Action $taskAction `
    -Trigger $taskTrigger `
    -Settings $taskSettings `
    -Description 'Aciona o WP-Cron local para executar tarefas agendadas do WordPress.' `
    -Force | Out-Null

Write-Output "Tarefa '$TaskName' registrada. WP-Cron será consultado a cada minuto em $SiteUrl."
