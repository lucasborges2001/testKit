# testkit runTest entrypoint fix

Qué corrige:
- Cuando el usuario ejecuta:
  .\bin\testkit.ps1 run --rm testkit php runTest.php back
  o:
  ./bin/testkit run --rm testkit php runTest.php back

el wrapper reescribe `runTest.php` a `/workspace/testkit/runTest.php`.

Por qué:
- El contenedor `testkit` corre con working_dir=/workspace/project
- `runTest.php` vive en el repo de testkit, montado en /workspace/testkit

Comando esperado después del parche:
  .\bin\testkit.ps1 run --rm testkit php runTest.php back
o
  ./bin/testkit run --rm testkit php runTest.php back

También sigue funcionando si llamás directamente:
  php /workspace/testkit/runTest.php back
