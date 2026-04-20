Param(
  [Parameter(ValueFromRemainingArguments=$true)]
  [string[]]$Args
)
. (Join-Path (Split-Path -Parent $MyInvocation.MyCommand.Path) '..\lib\powershell\Bootstrap.ps1')
exit (Invoke-TestkitMain $Args)
