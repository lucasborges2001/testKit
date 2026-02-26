# bin/

Esta carpeta contiene los "helpers" que facilitan el uso de Docker
para el entorno de pruebas. Hay dos versiones de la misma herramienta:
una en Bash para sistemas Unix y otra en PowerShell para Windows. El
propósito principal es ofrecer una interfaz consistente a `docker
compose` y encapsular lógica común como la carga del archivo de entorno
de tests y la detección de Postgres.

## testkit (Unix shell)

El ejecutable `bin/testkit` es un script POSIX que se invoca desde la
raíz del repositorio. Sobre el nivel más básico actúa como un
proxy/atajo para `docker compose`, seleccionando automáticamente los
archivos de composición (`compose.yaml` / `compose.pg.yaml`) y
propagando el fichero `.env.test`.

### Uso básico

```bash
./bin/testkit doctor           # chequea prerequisitos
./bin/testkit up -d            # levanta contenedores
./bin/testkit pg up -d         # igual, con Postgres forzado
./bin/testkit run --rm testkit php runTest.php back
```

### Características importantes

- Comando `doctor`: valida existencia de directorios esperados,
  disponibilidad de puertos, presencia de Docker, etc.
- Soporta atajos `pg` / `nopg` para incluir o excluir el servicio de
  Postgres.
- Modo auto: si un contenedor `postgres_test` ya está corriendo el
  script incluye `compose.pg.yaml` a menos que `TESTKIT_DISABLE_AUTO_PG`
  esté en `true`.
- Define la variable de entorno `TESTKIT_DB_ENV_PATH` apuntando al
  archivo de entorno montado dentro del contenedor (el valor es
  `"/app/test/.env.test"` o similar).

### Variables de entorno relevantes

- `TESTKIT_ENV_FILE` – ruta absoluta a `.env.test` si no está donde se
  espera.
- `TESTKIT_DEFAULT_PG` – si se evalúa como verdadero, el modo con Postgres
  se activa por defecto.
- `TESTKIT_DISABLE_AUTO_PG` – deshabilita la detección automática de
  contenedor Postgres en ejecución.
- `TESTKIT_PROJECT` – anula el nombre del proyecto de compose.

El script está documentado internamente y puede leerse para ver los
detalles de implementación.

## testkit.ps1 (Windows PowerShell)

Versión en PowerShell del mismo helper. Su comportamiento es análogo al
script de Bash, utiliza funciones equivalentes (`Pick-EnvFile`,
`Port-InUse`, etc.) y maneja las rutas de Windows. Se ejecuta así:

```powershell
.\bin\testkit.ps1 doctor
.\bin\testkit.ps1 pg up -d
```

El conjunto de variables de entorno y atajos coincide con la versión
Unix; los comentarios dentro del fichero explican las diferencias
específicas de Windows.

## Notas generales

- Ambos scripts esperan que el directorio `test/` esté ubicado junto a
  los archivos `compose.yaml` y, opcionalmente, `compose.pg.yaml` en la
  raíz del repositorio.
- Se usan desde los Makefiles o directamente en la línea de comandos
  para inicializar la infraestructura necesaria para ejecutar los
  tests (`mysql`, `php`, `nginx`, etc.).
- Aunque los comandos de `bin/` son independientes de la plataforma, los
  artefactos concretos que invocan (por ejemplo `test/scripts/unix/test.sh`
  o `test/scripts/win/test.ps1`) residen en `test/scripts/`.

---

*Documento generado para clarificar el contenido de `bin/` y su rol en
el proyecto.*