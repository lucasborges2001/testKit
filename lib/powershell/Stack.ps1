function Convert-TestkitStack([string]$Raw) {
  if ([string]::IsNullOrWhiteSpace($Raw)) {
    $storeDriver = if ($env:TEST_STORE_DRIVER) { $env:TEST_STORE_DRIVER.Trim().ToLowerInvariant() } else { '' }
    if ($storeDriver -eq 'none') { return '' }
    $Raw = 'mysql,redis'
  }
  $out = New-Object System.Collections.Generic.List[string]
  $seen = @{}
  foreach ($part in ($Raw -split ',')) {
    $token = $part.Trim().ToLowerInvariant()
    if ([string]::IsNullOrWhiteSpace($token)) { continue }
    switch ($token) {
      'mysql' {}
      'redis' {}
      'pg' {}
      'postgres' { $token = 'pg' }
      'postgresql' { $token = 'pg' }
      'influx' {}
      'influxdb' { $token = 'influx' }
      default { throw "TESTKIT_STACK inválido: token no reconocido '$token'." }
    }
    if (-not $seen.ContainsKey($token)) {
      $seen[$token] = $true
      $out.Add($token) | Out-Null
    }
  }
  if ($out.Count -eq 0 -and -not [string]::IsNullOrWhiteSpace(($Raw -replace '[,\s]', ''))) {
    $out.Add('mysql') | Out-Null
    $out.Add('redis') | Out-Null
  }
  return ($out -join ',')
}

function Test-TestkitStackHas([string]$Csv,[string]$Token) {
  return (",$Csv,").Contains(",$Token,")
}

function Get-TestkitComposeFiles([string]$StackCsv) {
  $files = New-Object System.Collections.Generic.List[string]
  $files.Add('-f') | Out-Null
  $files.Add((Join-Path $script:ResolvedTestkitRoot 'compose.yaml')) | Out-Null

  if (Test-TestkitStackHas $StackCsv 'mysql') {
    $files.Add('-f') | Out-Null
    $files.Add((Join-Path $script:ResolvedTestkitRoot 'compose.mysql.yaml')) | Out-Null
  }
  if (Test-TestkitStackHas $StackCsv 'redis') {
    $files.Add('-f') | Out-Null
    $files.Add((Join-Path $script:ResolvedTestkitRoot 'compose.redis.yaml')) | Out-Null
  }
  if (Test-TestkitStackHas $StackCsv 'pg') {
    $files.Add('-f') | Out-Null
    $files.Add((Join-Path $script:ResolvedTestkitRoot 'compose.pg.yaml')) | Out-Null
  }
  if (Test-TestkitStackHas $StackCsv 'influx') {
    $files.Add('-f') | Out-Null
    $files.Add((Join-Path $script:ResolvedTestkitRoot 'compose.influx.yaml')) | Out-Null
  }

  return ,$files.ToArray()
}
