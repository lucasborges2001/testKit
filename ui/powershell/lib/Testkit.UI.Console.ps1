Set-StrictMode -Version 2.0

function Write-UiBanner {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Title,

        [string[]]$Lines = @()
    )

    Write-Host ''
    Write-Host ('=' * 78) -ForegroundColor DarkCyan
    Write-Host $Title -ForegroundColor Cyan
    Write-Host ('=' * 78) -ForegroundColor DarkCyan

    foreach ($line in $Lines) {
        Write-Host $line -ForegroundColor Gray
    }

    Write-Host ''
}

function Write-UiSection {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Title
    )

    Write-Host ''
    Write-Host ('-' * 78) -ForegroundColor DarkGray
    Write-Host $Title -ForegroundColor Yellow
    Write-Host ('-' * 78) -ForegroundColor DarkGray
}

function Write-UiInfo {
    param([Parameter(Mandatory = $true)][string]$Message)
    Write-Host $Message -ForegroundColor Gray
}

function Write-UiSuccess {
    param([Parameter(Mandatory = $true)][string]$Message)
    Write-Host $Message -ForegroundColor Green
}

function Write-UiWarning {
    param([Parameter(Mandatory = $true)][string]$Message)
    Write-Host $Message -ForegroundColor Yellow
}

function Write-UiErrorLine {
    param([Parameter(Mandatory = $true)][string]$Message)
    Write-Host $Message -ForegroundColor Red
}

function Write-UiKeyValueTable {
    param(
        [Parameter(Mandatory = $true)]
        [System.Collections.IEnumerable]$Rows
    )

    $preparedRows = @($Rows)
    if ($preparedRows.Count -eq 0) {
        return
    }

    $maxLabel = 0
    foreach ($row in $preparedRows) {
        if ($null -ne $row -and $row.Label.Length -gt $maxLabel) {
            $maxLabel = $row.Label.Length
        }
    }

    foreach ($row in $preparedRows) {
        $label = $row.Label.PadRight($maxLabel)
        Write-Host ("{0} : {1}" -f $label, $row.Value)
    }
}

function Write-UiIndentedBlock {
    param(
        [Parameter(Mandatory = $true)]
        [string[]]$Lines,

        [string]$Prefix = '    '
    )

    foreach ($line in $Lines) {
        Write-Host ($Prefix + $line)
    }
}

function Select-UiMenuOption {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Title,

        [Parameter(Mandatory = $true)]
        [System.Collections.IEnumerable]$Options,

        [int]$DefaultIndex = 0,

        [switch]$AllowSkip,

        [string]$SkipLabel = 'Omitir / dejar vacio'
    )

    $optionsList = @($Options)
    if ($optionsList.Count -eq 0) {
        throw 'Select-UiMenuOption requiere al menos una opcion.'
    }

    while ($true) {
        Write-UiSection -Title $Title

        for ($i = 0; $i -lt $optionsList.Count; $i++) {
            $mark = ' '
            if ($i -eq $DefaultIndex) {
                $mark = '*'
            }

            $option = $optionsList[$i]
            Write-Host ('[{0}] {1} {2}' -f ($i + 1), $mark, $option.Label)
        }

        if ($AllowSkip) {
            Write-Host ('[0]   {0}' -f $SkipLabel)
        }

        Write-Host '[Q]   Cancelar'
        $prompt = 'Elegi una opcion'
        if ($DefaultIndex -ge 0 -and $DefaultIndex -lt $optionsList.Count) {
            $prompt += ' [' + ($DefaultIndex + 1) + ']'
        }

        $raw = Read-Host $prompt
        if ([string]::IsNullOrWhiteSpace($raw)) {
            return $optionsList[$DefaultIndex]
        }

        if ($raw -match '^[Qq]$') {
            return $null
        }

        if ($AllowSkip -and $raw -eq '0') {
            return $null
        }

        $index = -1
        if ([int]::TryParse($raw, [ref]$index)) {
            $index = $index - 1
            if ($index -ge 0 -and $index -lt $optionsList.Count) {
                return $optionsList[$index]
            }
        }

        Write-UiWarning -Message 'Entrada invalida. Probá de nuevo.'
    }
}

function Read-UiText {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Prompt,

        [string]$Default = '',

        [switch]$AllowEmpty = $true
    )

    while ($true) {
        $suffix = ''
        if (-not [string]::IsNullOrWhiteSpace($Default)) {
            $suffix = ' [' + $Default + ']'
        }

        $value = Read-Host ($Prompt + $suffix)
        if ([string]::IsNullOrWhiteSpace($value)) {
            $value = $Default
        }

        if ($AllowEmpty -or -not [string]::IsNullOrWhiteSpace($value)) {
            return $value
        }

        Write-UiWarning -Message 'Este valor no puede quedar vacio.'
    }
}

function Read-UiYesNo {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Prompt,

        [bool]$Default = $false
    )

    $defaultToken = 'n'
    if ($Default) {
        $defaultToken = 's'
    }

    while ($true) {
        $raw = Read-Host ($Prompt + ' [s/n] [' + $defaultToken + ']')
        if ([string]::IsNullOrWhiteSpace($raw)) {
            return $Default
        }

        switch -Regex ($raw.Trim()) {
            '^(s|si|sí|y|yes)$' { return $true }
            '^(n|no)$' { return $false }
            default { Write-UiWarning -Message 'Responde s o n.' }
        }
    }
}

function Read-UiPositiveInt {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Prompt,

        [string]$Default = '',

        [switch]$AllowEmpty = $true
    )

    while ($true) {
        $value = Read-UiText -Prompt $Prompt -Default $Default -AllowEmpty:$AllowEmpty
        if ([string]::IsNullOrWhiteSpace($value)) {
            return ''
        }

        $parsed = 0
        if ([int]::TryParse($value, [ref]$parsed) -and $parsed -gt 0) {
            return [string]$parsed
        }

        Write-UiWarning -Message 'Ingresá un entero positivo.'
    }
}

function Format-UiCommandLine {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Command,

        [string[]]$Arguments = @()
    )

    $tokens = New-Object System.Collections.Generic.List[string]
    $tokens.Add((Format-UiCommandToken -Value $Command))

    foreach ($argument in $Arguments) {
        $tokens.Add((Format-UiCommandToken -Value $argument))
    }

    return ($tokens -join ' ')
}

function Format-UiCommandToken {
    param([Parameter(Mandatory = $true)][string]$Value)

    if ($Value -match '^[A-Za-z0-9_\-\./,:=]+$') {
        return $Value
    }

    return ("'" + ($Value -replace "'", "''") + "'")
}