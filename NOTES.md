Parche cross-platform para Windows y Ubuntu.

Qué cambia:
- compose.yaml usa bind mounts explícitos con sintaxis larga.
- bin/testkit y bin/testkit.ps1 exportan TESTKIT_ROOT host absoluto.
- Los wrappers reescriben runTest.php y scripts/* a /workspace/testkit/*.
- El doctor valida que TESTKIT_ROOT sea un repo completo.

Qué esperar:
- Funciona si Docker Desktop/Engine puede montar el path host real.
- En Windows, evita depender de '.' y de rutas relativas ambiguas.
- Si el drive E: no está compartido con Docker Desktop, el mount seguirá fallando aunque la lógica sea correcta.

Uso recomendado:
- Windows:
  $env:TESTKIT_PROJECT_ROOT = 'E:/Kiara'
  $env:TESTKIT_ROOT = 'E:/Kiara/testkit'
- Ubuntu:
  export TESTKIT_PROJECT_ROOT=/ruta/al/proyecto
  export TESTKIT_ROOT=/ruta/al/proyecto/testkit

Smoke test:
  ./bin/testkit doctor --dump
  ./bin/testkit run --rm testkit sh -lc "ls -la /workspace/testkit; ls -la /workspace/project"
