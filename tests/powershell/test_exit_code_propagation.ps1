#Requires -Version 7.0
# The wrapper (bin/testkit.ps1, Doctor.ps1) relies on the pattern
# `& <process>; return $LASTEXITCODE` to forward exit codes from docker/compose.
# This test only exercises that runtime assumption on this PowerShell version;
# it does not touch testkit-specific code.
$ErrorActionPreference = 'Stop'

. (Join-Path $PSScriptRoot 'TestHelpers.ps1')

& cmd.exe /c 'exit 7' | Out-Null
Assert-Equal $LASTEXITCODE 7 'a non-zero external process exit code must propagate through $LASTEXITCODE'

& cmd.exe /c 'exit 0' | Out-Null
Assert-Equal $LASTEXITCODE 0 'a zero external process exit code must propagate through $LASTEXITCODE'

Complete-TestkitAssertions
