# Remote target suite isolation

**Estado:** PENDIENTE

## Owner

TestKit / contrato de ejecución remota consumido por hosts integradores.

## Evidencia

En la validación remota de `SocialForge` solicitada desde `Pruebas` con:

```text
request_id: socialforge-content-engine-f1-54074ba-ubuntudev-v1
tested_sha: d4d5266779c58b975eeed62566ac4c80e7990f35
SocialForge gitlink: 54074ba19f620e0738622c3c86a642b59e24c133
profile: full
suite resuelta: all
```

el pipeline remoto pasó `Sync`, `Submodules`, `Docker`, `Preflight` y `Evidence`, pero la validación del producto terminó antes de ejecutar pruebas de SocialForge porque `all` encontró primero un fallo de baseline en `base_grupo_policy`.

Resultado observado:

```text
PIPELINE REMOTO: PASS
VALIDACIÓN DEL PRODUCTO: FAIL

bash scripts/smoke/run_testkit.sh base              PASS
bash scripts/smoke/run_testkit.sh base_grupo_policy FAIL
```

El fallo reportado pertenece a `Base`, no al SHA candidato de SocialForge.

## Problema

Una solicitud cuyo objetivo es validar un submódulo concreto puede terminar sin ejecutar ninguna prueba del owner candidato si usa el agregado global y una suite anterior falla.

Esto produce una evidencia correcta del agregado, pero insuficiente para responder la pregunta principal:

```text
¿el SHA exacto del submódulo objetivo pasa sus contratos y tests?
```

La limitación es especialmente visible cuando el host ya tiene fallos baseline conocidos en módulos no relacionados.

## Restricciones

La solución no debe:

- mover submódulos a `latest` dentro del runner;
- inferir comandos arbitrarios desde el request;
- usar `eval`;
- ocultar fallos baseline;
- convertir un fallo global en PASS;
- duplicar la lógica de negocio del consumidor dentro de TestKit;
- perder el SHA exacto, gitlinks o evidencia estructurada.

## Mejora requerida

Permitir que el control plane seleccione una **suite focalizada allowlisted** asociada al owner objetivo, manteniendo el agregado global como validación separada cuando corresponda.

Modelo esperado:

```text
SHA host exacto
+ gitlink owner exacto
+ suite focalizada allowlisted
        ↓
validación owner
        ↓
evidencia estructurada
```

Opcionalmente, una ejecución posterior puede correr `all` para integración amplia, pero un fallo previo de otro owner no debe impedir obtener la evidencia focalizada del candidato.

## Contrato deseado

Para un submódulo registrado:

```text
scope: submodule
owner: SocialForge
path: submodules/SocialForge
suite: socialforge
```

La suite debe estar declarada en el catálogo host; el request sólo selecciona su key. TestKit/runner no acepta comandos desde JSON.

El reporte debe seguir distinguiendo:

```text
PIPELINE REMOTO
VALIDACIÓN DEL PRODUCTO
```

y registrar como mínimo:

```text
request_id
tested_sha
owner_gitlink_sha
base_gitlink_sha
testkit_gitlink_sha
suite
validation_scope
commands/resultados
```

## Consideración de fail-fast

El objetivo no es eliminar fail-fast de todas las suites. Es evitar que un agregado global sea la única forma de validar un candidato focalizado.

Dentro de una suite focalizada, la política de continuación/fail-fast debe seguir siendo explícita y reproducible.

## Criterios de aceptación

- existe una forma canónica de ejecutar remotamente una suite focalizada por owner;
- la suite está allowlisted en el host y no acepta comandos arbitrarios;
- se materializan sólo los gitlinks requeridos por el scope declarado;
- el reporte prueba el SHA host exacto y el gitlink exacto del owner;
- un fallo baseline de otro submódulo no impide ejecutar la suite focalizada;
- el fallo baseline global sigue visible cuando se ejecuta posteriormente `all`;
- la evidencia estructurada conserva exit codes y comandos;
- hay smoke/contract test que demuestra aislamiento de un owner frente a un fallo anterior no relacionado.

## Validación propuesta

Caso mínimo reproducible:

1. host con dos suites `owner_a` y `owner_b`;
2. `owner_a` falla deliberadamente;
3. request remoto selecciona `owner_b`;
4. `owner_b` debe ejecutarse y publicar su resultado;
5. ejecutar después `all` debe seguir mostrando el fallo de `owner_a`.

Gates candidatos:

```text
PASS_REMOTE_TARGET_SUITE_ISOLATION
PASS_REMOTE_TARGET_EXACT_GITLINK
PASS_REMOTE_GLOBAL_BASELINE_PRESERVED
```

## No resuelto aquí

Este pendiente no implementa una suite específica de SocialForge ni modifica `Pruebas`. Documenta una capacidad reusable que debe existir en TestKit/runner para consumidores con múltiples submódulos y baseline parcialmente rojo.
