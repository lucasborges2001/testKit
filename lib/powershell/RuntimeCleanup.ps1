function Convert-TestkitRuntimeAgeToSeconds([string]$Value) {
  if ($Value -notmatch '^([0-9]+)([smhd])$') {
    throw "cleanup runtime: --older-than inválido '$Value'. Use Ns, Nm, Nh o Nd."
  }
  $n = [int64]$Matches[1]
  switch ($Matches[2]) {
    's' { return $n }
    'm' { return $n * 60 }
    'h' { return $n * 3600 }
    'd' { return $n * 86400 }
  }
}

function Get-TestkitRuntimeDecision([int64]$AgeSeconds, [int]$ActiveRuns, [int64]$TtlSeconds) {
  if ($ActiveRuns -gt 0) {
    return @{ decision = 'keep'; reason = 'ACTIVE_RUN' }
  }
  if ($AgeSeconds -lt $TtlSeconds) {
    return @{ decision = 'keep'; reason = 'TTL_NOT_EXPIRED' }
  }
  return @{ decision = 'delete'; reason = 'RUNTIME_TTL_EXPIRED' }
}

function Write-TestkitRuntimeCleanupUsage {
  Write-Output @'
Usage:
  testkit.ps1 cleanup runtime [--older-than=4h] [--dry-run]
  testkit.ps1 cleanup runtime [--older-than=4h] --apply --force

Options:
  --older-than=<N>[s|m|h|d]  Minimum runtime age. Default: 4h.
  --dry-run                  Plan only. Default.
  --apply                    Delete eligible TestKit runtimes.
  --force                    Required with --apply because DB volumes are removed.
  --json                     Print the cleanup audit as JSON.
  --quiet                    Suppress normal text output; audit is still written.
'@
}

function Remove-TestkitRuntimeProject([string]$Project) {
  $ids = @(& docker ps -aq --filter "label=com.docker.compose.project=$Project") | Where-Object { -not [string]::IsNullOrWhiteSpace($_) }
  if ($ids.Count -gt 0) {
    & docker rm -f @ids | Out-Null
    if ($LASTEXITCODE -ne 0) { return $LASTEXITCODE }
  }

  $networks = @(& docker network ls -q --filter "label=com.docker.compose.project=$Project") | Where-Object { -not [string]::IsNullOrWhiteSpace($_) }
  if ($networks.Count -gt 0) {
    & docker network rm @networks 2>$null | Out-Null
  }

  $volumes = @(& docker volume ls -q --filter "label=com.docker.compose.project=$Project") | Where-Object { -not [string]::IsNullOrWhiteSpace($_) }
  if ($volumes.Count -gt 0) {
    & docker volume rm @volumes | Out-Null
    if ($LASTEXITCODE -ne 0) { return $LASTEXITCODE }
  }
  return 0
}

function Write-TestkitRuntimeAudit([string]$Mode, [string]$OlderThan, [int64]$TtlSeconds, [object[]]$Projects, [bool]$JsonStdout) {
  $root = if (-not [string]::IsNullOrWhiteSpace($script:ProjectRoot.Path)) { $script:ProjectRoot.Path } else { $env:TESTKIT_PROJECT_ROOT }
  if ([string]::IsNullOrWhiteSpace($root)) { return }

  $auditDir = Join-Path $root '.testkit\reports\cleanup'
  New-Item -ItemType Directory -Path $auditDir -Force | Out-Null
  $stamp = (Get-Date).ToUniversalTime().ToString('yyyyMMddTHHmmssZ')
  $payload = [ordered]@{
    version = 1
    timestamp = (Get-Date).ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ')
    mode = $Mode
    older_than = $OlderThan
    ttl_seconds = $TtlSeconds
    projects = @($Projects)
  }
  $json = $payload | ConvertTo-Json -Depth 6
  $auditFile = Join-Path $auditDir "runtime_cleanup_$stamp.json"
  $latestFile = Join-Path $auditDir 'runtime_cleanup_latest.json'
  Set-Content -LiteralPath $auditFile -Value $json -Encoding utf8
  Set-Content -LiteralPath $latestFile -Value $json -Encoding utf8
  if ($JsonStdout) { Write-Output $json }
}

function Invoke-TestkitRuntimeCleanup([string]$EnvFilePath, [string[]]$CleanupArgs) {
  $olderThan = if ([string]::IsNullOrWhiteSpace($env:TESTKIT_RUNTIME_MAX_AGE)) { '4h' } else { $env:TESTKIT_RUNTIME_MAX_AGE }
  $apply = $false
  $force = $false
  $json = $false
  $quiet = $false

  foreach ($arg in @($CleanupArgs)) {
    if ($arg -like '--older-than=*') {
      $olderThan = $arg.Substring('--older-than='.Length)
      continue
    }
    switch ($arg) {
      '--dry-run' { $apply = $false }
      '--apply' { $apply = $true }
      '--force' { $force = $true }
      '--json' { $json = $true }
      '--quiet' { $quiet = $true }
      '--help' { Write-TestkitRuntimeCleanupUsage; return 0 }
      '-h' { Write-TestkitRuntimeCleanupUsage; return 0 }
      default {
        Write-Error "cleanup runtime: argumento no reconocido: $arg"
        Write-TestkitRuntimeCleanupUsage
        return 2
      }
    }
  }

  try {
    $ttlSeconds = Convert-TestkitRuntimeAgeToSeconds $olderThan
  } catch {
    Write-Error $_.Exception.Message
    return 2
  }

  if ($apply -and -not $force) {
    Write-Error 'cleanup runtime: --apply requiree --force porque se eliminan volúmenes de base de datos.'
    return 2
  }

  if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    Write-Error 'cleanup runtime: Docker no disponible.'
    return 1
  }

  $now = if (-not [string]::IsNullOrWhiteSpace($env:TESTKIT_RUNTIME_CLEANUP_NOW_EPOCH)) {
    [DateTimeOffset]::FromUnixTimeSeconds([int64]$env:TESTKIT_RUNTIME_CLEANUP_NOW_EPOCH)
  } else {
    [DateTimeOffset]::UtcNow
  }

  $dbIds = @(& docker ps -aq --filter 'label=io.testkit.runtime=true' --filter 'label=io.testkit.resource=database') | Where-Object { -not [string]::IsNullOrWhiteSpace($_) }
  $projects = New-Object System.Collections.Generic.HashSet[string]
  foreach ($id in $dbIds) {
    $project = (& docker inspect -f '{{ index .Config.Labels "com.docker.compose.project" }}' $id 2>$null | Select-Object -First 1)
    if (-not [string]::IsNullOrWhiteSpace($project) -and $project -ne '<no value>') {
      [void]$projects.Add($project.Trim())
    }
  }

  $rows = New-Object System.Collections.Generic.List[object]
  foreach ($project in ($projects | Sort-Object)) {
    $projectDbIds = @(& docker ps -aq --filter "label=com.docker.compose.project=$project" --filter 'label=io.testkit.runtime=true' --filter 'label=io.testkit.resource=database') | Where-Object { -not [string]::IsNullOrWhiteSpace($_) }
    $newest = $null
    foreach ($id in $projectDbIds) {
      $createdRaw = (& docker inspect -f '{{.Created}}' $id 2>$null | Select-Object -First 1)
      if ([string]::IsNullOrWhiteSpace($createdRaw)) { continue }
      try { $created = [DateTimeOffset]::Parse($createdRaw) } catch { continue }
      if ($null -eq $newest -or $created -gt $newest) { $newest = $created }
    }
    if ($null -eq $newest) { continue }

    $age = [int64][Math]::Max(0, [Math]::Floor(($now - $newest).TotalSeconds))
    $activeIds = @(& docker ps -q --filter "label=com.docker.compose.project=$project" --filter 'label=com.docker.compose.oneoff=True') | Where-Object { -not [string]::IsNullOrWhiteSpace($_) }
    $decision = Get-TestkitRuntimeDecision $age $activeIds.Count $ttlSeconds
    $deleted = $false

    if ($decision.decision -eq 'delete' -and $apply) {
      $rc = Remove-TestkitRuntimeProject $project
      if ($rc -ne 0) { return $rc }
      $deleted = $true
    }

    $row = [ordered]@{
      project = $project
      age_seconds = $age
      active_runs = $activeIds.Count
      decision = $decision.decision
      reason = $decision.reason
      deleted = $deleted
    }
    $rows.Add($row) | Out-Null

    if (-not $json -and -not $quiet) {
      if ($decision.decision -eq 'delete' -and -not $apply) {
        Write-Output "CANDIDATE project=$project age=${age}s reason=$($decision.reason)"
      } elseif ($decision.decision -eq 'delete') {
        Write-Output "DELETE project=$project age=${age}s reason=$($decision.reason)"
      } else {
        Write-Output "KEEP project=$project age=${age}s active_runs=$($activeIds.Count) reason=$($decision.reason)"
      }
    }
  }

  $mode = if ($apply) { 'apply' } else { 'dry_run' }
  Write-TestkitRuntimeAudit $mode $olderThan $ttlSeconds $rows.ToArray() $json
  if (-not $json -and -not $quiet -and $rows.Count -eq 0) {
    Write-Output 'OK cleanup runtime: no TestKit-labeled database runtimes found.'
  }
  return 0
}

function Invoke-TestkitRuntimeAutoCleanup([string]$EnvFilePath, [string]$CommandName) {
  if ($env:TESTKIT_RUNTIME_AUTO_CLEANUP -ne '1') { return 0 }
  if ($CommandName -notin @('up','run')) { return 0 }
  $age = if ([string]::IsNullOrWhiteSpace($env:TESTKIT_RUNTIME_MAX_AGE)) { '4h' } else { $env:TESTKIT_RUNTIME_MAX_AGE }
  $runtimeStatus = Invoke-TestkitRuntimeCleanup $EnvFilePath @("--older-than=$age", '--apply', '--force', '--quiet')
  return $runtimeStatus
}
