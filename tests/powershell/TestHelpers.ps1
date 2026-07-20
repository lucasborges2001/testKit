$script:TestkitAssertionFailures = [System.Collections.Generic.List[string]]::new()

function Assert-True([bool]$Condition, [string]$Message) {
  if (-not $Condition) {
    $script:TestkitAssertionFailures.Add($Message)
  }
}

function Assert-Equal($Actual, $Expected, [string]$Message) {
  if ($Actual -ne $Expected) {
    $script:TestkitAssertionFailures.Add("$Message (expected: '$Expected', actual: '$Actual')")
  }
}

function Complete-TestkitAssertions {
  if ($script:TestkitAssertionFailures.Count -gt 0) {
    foreach ($failure in $script:TestkitAssertionFailures) {
      Write-Host "FAIL: $failure"
    }
    exit 1
  }
  exit 0
}
