function Invoke-TestkitDoctorBaseChecks {
  param(
    [Parameter(Mandatory=$true)]$Context,
    [Parameter(Mandatory=$false)]$EnvFile,
    [Parameter(Mandatory=$true)][AllowEmptyString()][string]$StackCsv,
    [Parameter(Mandatory=$true)][ref]$Ok
  )

  if ($EnvFile) {
    Add-TestkitDoctorCheck 'base' 'PASS' 'ENV_FILE_PRESENT' "env detectado: $($EnvFile.Path)"
  } else {
    Add-TestkitDoctorCheck 'base' 'FAIL' 'ENV_FILE_MISSING' 'falta env de tests: <project>/test/.env.test o <project>/.env.test' 'Creá el env dentro del repo del proyecto.'
    $Ok.Value = $false
  }

  if (Test-Path $script:ResolvedTestkitRoot) {
    if (Test-Path (Join-Path $script:ResolvedTestkitRoot 'runTest.php')) {
      Add-TestkitDoctorCheck 'base' 'PASS' 'TESTKIT_ROOT_READY' "TESTKIT_ROOT parece repo completo: $($script:ResolvedTestkitRoot.Path)"
    } else {
      Add-TestkitDoctorCheck 'base' 'FAIL' 'TESTKIT_ROOT_INCOMPLETE' 'TESTKIT_ROOT no parece repo completo: falta runTest.php' 'Apuntá TESTKIT_ROOT al repo real de testkit.'
      $Ok.Value = $false
    }
  } else {
    Add-TestkitDoctorCheck 'base' 'FAIL' 'TESTKIT_ROOT_MISSING' "TESTKIT_ROOT no existe: $($script:ResolvedTestkitRoot)" 'Corregí TESTKIT_ROOT o ejecutá desde el repo correcto.'
    $Ok.Value = $false
  }

  if (Test-Path $script:ProjectRoot) {
    Add-TestkitDoctorCheck 'base' 'PASS' 'PROJECT_ROOT_READY' "TESTKIT_PROJECT_ROOT existe: $($script:ProjectRoot.Path)"
  } else {
    Add-TestkitDoctorCheck 'base' 'FAIL' 'PROJECT_ROOT_MISSING' "TESTKIT_PROJECT_ROOT no existe: $($script:ProjectRoot)" 'Definí TESTKIT_PROJECT_ROOT contra el repo bajo prueba.'
    $Ok.Value = $false
  }

  if ($EnvFile) {
    if (Test-TestkitPathUnderRoot $script:ProjectRoot $EnvFile.Path) {
      Add-TestkitDoctorCheck 'base' 'PASS' 'ENV_UNDER_PROJECT' 'el env de tests vive dentro del repo montado'
    } else {
      Add-TestkitDoctorCheck 'base' 'FAIL' 'ENV_OUTSIDE_PROJECT' "el env de tests quedó fuera del repo montado: $($EnvFile.Path)" 'Mové el env a <project>/test/.env.test o <project>/.env.test.'
      $Ok.Value = $false
    }
  }

  $testDir = Join-Path $script:ProjectRoot 'test'
  if ($Context.ReadOnly) {
    Add-TestkitDoctorCheck 'base' 'UNKNOWN' 'TEST_DIR_WRITE_NOT_PROBED' "$testDir`: escritura no verificada en modo --readonly" 'Corré doctor sin --readonly para crear/verificar el directorio y confirmar que es escribible.'
  } else {
    if (-not (Test-Path $testDir)) {
      New-Item -ItemType Directory -Path $testDir -Force | Out-Null
    }

    try {
      $probe = Join-Path $testDir '.doctor_write_probe'
      Set-Content -Path $probe -Value 'ok' -NoNewline
      Remove-Item $probe -Force -ErrorAction SilentlyContinue
      Add-TestkitDoctorCheck 'base' 'PASS' 'TEST_DIR_WRITABLE' "$testDir es escribible"
    } catch {
      Add-TestkitDoctorCheck 'base' 'FAIL' 'TEST_DIR_NOT_WRITABLE' "$testDir no es escribible" 'Corregí permisos del repo o del volumen montado.'
      $Ok.Value = $false
    }
  }

  if (Get-Command docker -ErrorAction SilentlyContinue) {
    Add-TestkitDoctorCheck 'base' 'PASS' 'DOCKER_FOUND' 'docker está disponible en PATH'
  } else {
    Add-TestkitDoctorCheck 'base' 'FAIL' 'DOCKER_MISSING' 'docker no está disponible en PATH' 'Instalá Docker o corregí PATH.'
    $Ok.Value = $false
  }

  Add-TestkitDoctorCheck 'base' 'PASS' 'STACK_RESOLVED' "TESTKIT_STACK efectivo: $StackCsv"

  $provisionMode = Normalize-TestkitDoctorToken $env:TEST_STORE_PROVISION
  if ([string]::IsNullOrWhiteSpace($provisionMode)) { $provisionMode = 'managed' }

  if ($provisionMode -notin @('managed','external')) {
    Add-TestkitDoctorCheck 'base' 'FAIL' 'INVALID_STORE_PROVISION' "TEST_STORE_PROVISION=$($env:TEST_STORE_PROVISION) no es válido" 'Usá managed o external.'
    $Ok.Value = $false
    $provisionMode = 'managed'
  } else {
    Add-TestkitDoctorCheck 'base' 'PASS' 'STORE_PROVISION_DECLARED' "TEST_STORE_PROVISION=$provisionMode"
  }

  $storeDriver = $env:TEST_STORE_DRIVER

  if ($storeDriver -ceq 'none') {
    Add-TestkitDoctorCheck 'base' 'PASS' 'STORE_DRIVER_NONE' 'proyecto sin store runtime: TEST_STORE_DRIVER=none'
  } elseif ($storeDriver -ceq 'mysql') {
    if (Test-TestkitDoctorEnvPresentAny @('DB_HOST','TEST_MYSQL_HOST','MYSQL_HOST')) {
      Add-TestkitDoctorCheck 'base' 'PASS' 'MYSQL_HOST_PRESENT' 'host MySQL visible'
    } else {
      Add-TestkitDoctorCheck 'base' 'FAIL' 'MYSQL_HOST_MISSING' 'falta host MySQL (DB_HOST / TEST_MYSQL_HOST / MYSQL_HOST)' 'Declará el host runtime del store.'
      $Ok.Value = $false
    }

    if (Test-TestkitDoctorEnvPresentAny @('DB_PORT','TEST_MYSQL_PORT','MYSQL_PORT')) {
      Add-TestkitDoctorCheck 'base' 'PASS' 'MYSQL_PORT_PRESENT' 'puerto MySQL visible'
    } else {
      Add-TestkitDoctorCheck 'base' 'FAIL' 'MYSQL_PORT_MISSING' 'falta puerto MySQL (DB_PORT / TEST_MYSQL_PORT / MYSQL_PORT)' 'Declará el puerto runtime del store.'
      $Ok.Value = $false
    }

    if (Test-TestkitDoctorEnvPresentAny @('DB_NAME','TEST_MYSQL_DB','MYSQL_DATABASE')) {
      Add-TestkitDoctorCheck 'base' 'PASS' 'MYSQL_DB_PRESENT' 'nombre de DB MySQL visible'
    } else {
      Add-TestkitDoctorCheck 'base' 'FAIL' 'MYSQL_DB_MISSING' 'falta nombre de DB MySQL (DB_NAME / TEST_MYSQL_DB / MYSQL_DATABASE)' 'Declará la DB runtime del store.'
      $Ok.Value = $false
    }

    if (Test-TestkitDoctorEnvPresentAny @('DB_USER','TEST_MYSQL_USER','MYSQL_USER')) {
      Add-TestkitDoctorCheck 'base' 'PASS' 'MYSQL_USER_PRESENT' 'usuario runtime MySQL visible'
    } else {
      Add-TestkitDoctorCheck 'base' 'FAIL' 'MYSQL_USER_MISSING' 'falta usuario runtime MySQL (DB_USER / TEST_MYSQL_USER / MYSQL_USER)' 'Declará el usuario runtime del store.'
      $Ok.Value = $false
    }

    if (Test-TestkitDoctorEnvPresentAny @('DB_PASS','TEST_MYSQL_PASSWORD','MYSQL_PASSWORD')) {
      Add-TestkitDoctorCheck 'base' 'PASS' 'MYSQL_PASSWORD_PRESENT' 'password runtime MySQL visible'
    } else {
      Add-TestkitDoctorCheck 'base' 'FAIL' 'MYSQL_PASSWORD_MISSING' 'falta password runtime MySQL (DB_PASS / TEST_MYSQL_PASSWORD / MYSQL_PASSWORD)' 'Declará la credencial runtime del store.'
      $Ok.Value = $false
    }

    if ($provisionMode -eq 'managed') {
      if (Test-TestkitDoctorEnvPresentAny @('TEST_MYSQL_ADMIN_USER','MYSQL_ROOT_USER')) {
        Add-TestkitDoctorCheck 'base' 'PASS' 'MYSQL_ADMIN_USER_PRESENT' 'usuario admin MySQL visible (managed)'
      } else {
        Add-TestkitDoctorCheck 'base' 'FAIL' 'MYSQL_ADMIN_USER_MISSING' 'falta usuario admin MySQL (TEST_MYSQL_ADMIN_USER / MYSQL_ROOT_USER)' 'Declará la credencial admin para provision managed.'
        $Ok.Value = $false
      }

      if (Test-TestkitDoctorEnvPresentAny @('TEST_MYSQL_ROOT_PASSWORD','MYSQL_ROOT_PASSWORD')) {
        Add-TestkitDoctorCheck 'base' 'PASS' 'MYSQL_ADMIN_PASSWORD_PRESENT' 'password admin MySQL visible (managed)'
      } else {
        Add-TestkitDoctorCheck 'base' 'FAIL' 'MYSQL_ADMIN_PASSWORD_MISSING' 'falta password admin MySQL (TEST_MYSQL_ROOT_PASSWORD / MYSQL_ROOT_PASSWORD)' 'Declará la credencial admin para provision managed.'
        $Ok.Value = $false
      }
    } else {
      Add-TestkitDoctorCheck 'base' 'PASS' 'EXTERNAL_PROVISION_PATH' 'TEST_STORE_PROVISION=external: no se exigen credenciales admin MySQL'
    }
  } elseif ($storeDriver -ceq 'pgsql') {
    Add-TestkitDoctorCheck 'base' 'WARN' 'NON_MYSQL_BASE_CHECKS_PARTIAL' 'doctor base no cierra validación de credenciales específicas para driver=pgsql' 'Completá checks específicos del motor si querés endurecer este path.'
  } elseif ([string]::IsNullOrEmpty($storeDriver)) {
    Add-TestkitDoctorCheck 'base' 'FAIL' 'STORE_DRIVER_REQUIRED' 'TEST_STORE_DRIVER es obligatorio' 'Usá exactamente mysql, pgsql o none.'
    $Ok.Value = $false
  } else {
    Add-TestkitDoctorCheck 'base' 'FAIL' 'INVALID_STORE_DRIVER' "TEST_STORE_DRIVER=$storeDriver no pertenece al contrato" 'Usá exactamente mysql, pgsql o none.'
    $Ok.Value = $false
  }
}
