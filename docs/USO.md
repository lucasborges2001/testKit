# Uso operativo de testkit

## 4) Comandos base

| Necesidad | Comando | Lectura correcta |
|---|---|---|
| ver configuración soportada | `inspect config-schema` | catálogo ejecutable de env vars, defaults y valores válidos |
| listar selección efectiva de una suite | `php runTest.php back-php --list` | lista la selección y fuerza `TEST_LIST=1` para esa corrida |
| ayuda del runner | `php runTest.php --help` | muestra target, `--list` y guía hacia `inspect config-schema` |

## 5) Cómo leer `doctor`

- opciones `--...` desconocidas deben tratarse como error de uso, no como hint ignorado
- el target posicional de `doctor` debe pertenecer al set soportado
