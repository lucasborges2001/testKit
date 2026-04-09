# 0101 - testkit UI PowerShell v2 (cerrar pendientes críticos sin inventar contrato)

Estoy trabajando sobre mi repo real de `testkit`.

Ya hubo una primera iteración de una UI interactiva PowerShell como fachada humana del CLI.  
Ahora quiero una **segunda pasada crítica**, enfocada en **cerrar pendientes reales** y **eliminar supuestos no verificados**.

## Objetivo de esta iteración

No quiero “más UI” por estética.  
Quiero que revises el repo real y resuelvas **solo lo que falta para que la UI sea honesta, reproducible y de bajo riesgo**.

Prioridad estricta:

1. validar el **contrato real de stack**
2. validar la **semántica real** de env vars/filtros
3. validar el flujo real en **Windows PowerShell 5.1**
4. endurecer el wrapper sin romper el modo no interactivo
5. recién después, si ya quedó sólido, proponer mejoras opcionales

---

## Contexto que NO debes violar

- Hoy `testkit` ya funciona por CLI y ese modo es la **fuente de verdad**.
- No quiero un sistema paralelo.
- No quiero refactor grande del runner.
- No quiero romper CI, agentes, wrappers ni scripts existentes.
- La UI interactiva debe seguir siendo una **fachada** del flujo real.
- Antes de ejecutar debe mostrar:
  - resumen de selección
  - comando final real
  - bloque PowerShell reproducible
- Debe pedir confirmación antes de ejecutar.
- Cancelar no debe ejecutar nada.

---

## Lo que debes hacer primero

### 1) Inspección crítica del repo real

No asumas nada por el prompt.  
Inspecciona el repo real y responde estas preguntas con evidencia de código:

- ¿Cómo se expresa realmente el **stack** hoy?
  - flags
  - env vars
  - wrappers
  - scripts auxiliares
  - perfiles docker/compose
- ¿`mysql`, `mysql,redis`, `pg` tienen hoy una traducción técnica real o solo una expectativa operativa?
- ¿Qué valores espera realmente el runner para:
  - `TEST_FAIL_FAST`
  - `TEST_COVERAGE`
  - `TEST_LIST`
  - `TEST_SCOPE`
  - `TEST_CATEGORY`
  - `TEST_MATCH`
  - `TEST_JOBS`
- ¿Qué wrappers reales existen y son canónicos?
  - `bin/testkit.ps1`
  - `scripts/seed.ps1`
  - otros
- ¿Existen helpers reutilizables de terminal/UI que valga la pena usar **sin mezclar lógica de ejecución**?

### 2) Detectar supuestos falsos

Si la primera iteración de la UI:
- inventó contrato,
- asumió booleanos incorrectos,
- hardcodeó opciones que hoy no salen del repo,
- o dejó `stack` solo como dato visual sin efecto real,

debes decirlo con claridad y corregirlo.

No quiero validación complaciente.  
Quiero diagnóstico técnico honesto.

---

## Pendientes críticos a resolver

### A. `stack`
Quiero que verifiques si `stack`:
- ya tiene contrato real utilizable
- o no lo tiene

#### Si sí lo tiene:
cabléalo correctamente en la UI usando el flujo real existente.

#### Si no lo tiene:
NO lo inventes.  
En ese caso:
- deja la UI honesta
- explica la limitación
- encapsula el punto de integración en un único lugar mantenible
- no simules soporte efectivo si no existe

### B. booleanos / semántica real
Quiero que verifiques con código real si estos flags deben emitirse como:
- `1`
- `true`
- `yes`
- presencia/ausencia
- otro formato real

No adivines.

### C. validación real del wrapper
Quiero pruebas reales del flujo al menos para:

- `doctor`
- `up`
- `seed`
- `run tests` sin filtros
- `run tests` con filtros
- `report`
- `down`
- cancelación sin ejecución
- restauración correcta del entorno/env vars tras ejecutar

Si no puedes ejecutar algo en el entorno actual, no inventes resultados:
- deja claro qué validaste
- qué no pudiste validar
- qué evidencia de código usaste en reemplazo

### D. endurecer la UI sin tocar el runner más de lo necesario
Me interesa especialmente:

- validación previa de rutas reales
- detección clara de prerequisitos
- errores distinguibles:
  - error del wrapper
  - error del script llamado
  - cancelación
  - contrato faltante
- evitar catálogos falsos o desalineados con el repo
- extraer configuración a un lugar más mantenible **solo si aporta seguridad real**

---

## Restricciones duras

1. El CLI actual sigue siendo la fuente de verdad.
2. La UI interactiva debe seguir siendo fachada, no sistema paralelo.
3. No romper agentes, CI, scripts ni wrappers existentes.
4. No cambiar incompatiblemente flags, contratos ni semántica actual.
5. No inventar contratos nuevos si ya existen flags/env vars reales.
6. No introducir dependencia obligatoria en `pwsh`.
7. Mantener compatibilidad con Windows PowerShell 5.1.
8. No mezclar lógica del runner con la UI.
9. `bin/testkit.ps1` no debe tocarse salvo que sea estrictamente inevitable.
10. Si tocas `bin/testkit.ps1`, debes justificar por qué era inevitable y por qué no había una opción más segura.

---

## Preferencias de diseño

Si ya existe una primera versión de la UI, reutilízala y corrígela.  
No la reescribas entera porque sí.

Estructura preferida si sigue siendo válida:

- `bin/testkit-ui.ps1`
- `ui/powershell/Testkit.UI.ps1`
- `ui/powershell/lib/...`
- `docs/UI.md`

Pero si encuentras una estructura mejor y más segura, úsala y justifícala.

---

## Qué NO quiero

- No quiero mockups.
- No quiero una demo que “parece linda” pero no respeta el contrato real.
- No quiero que me digas que algo “seguramente” funciona.
- No quiero invención de env vars como `TEST_PATH` o `TEST_MODULE` si el repo no las soporta.
- No quiero soporte ficticio de `stack`.
- No quiero que cambies semánticas del runner para adaptarlas a la UI.
- No quiero documentación inflada.

---

## Mejoras opcionales, solo si lo crítico ya quedó sólido

Estas solo van **después** de resolver lo importante:

- presets operativos útiles
- recordar última selección
- volver atrás / editar una parte sin recomenzar todo
- copiar bloque reproducible al portapapeles
- tests del wrapper/UI
- catálogo desacoplado a config
- dejar mejor preparado el camino futuro a Bash/Linux

No metas estas mejoras si todavía hay dudas de contrato.

---

## Forma de trabajo esperada

1. Haz un diagnóstico breve y crítico del diseño actual de la UI si ya existe.
2. Inspecciona el repo real para validar contratos verdaderos.
3. Corrige supuestos incorrectos.
4. Elige la solución más segura y de menor riesgo.
5. Implementa solo los cambios necesarios.
6. Devuélveme un ZIP con **solo** archivos nuevos/modificados, listo para descomprimir dentro del repo.

---

## Criterios de aceptación

- El modo no interactivo actual sigue funcionando exactamente como antes.
- La UI sigue siendo una fachada del flujo real.
- El comando final mostrado es reproducible fuera de la UI.
- `stack` queda:
  - o bien realmente soportado con contrato real,
  - o bien explícitamente limitado sin engañar.
- Los env vars/flags emitidos coinciden con la semántica real del repo.
- La solución funciona o queda razonablemente validada para Windows PowerShell 5.1.
- No hay duplicación peligrosa de lógica del runner.
- La estructura queda limpia y mantenible.

---

## Entregable exacto

Devuélveme **solo** esto:

1. diagnóstico breve
2. contratos reales detectados
3. decisión de diseño
4. lista de archivos nuevos/modificados
5. contenido completo de cada archivo nuevo/modificado
6. cómo invocarlo
7. qué validaste realmente y qué no
8. riesgos y límites
9. ZIP final

### Importante
- El ZIP debe contener únicamente los archivos nuevos/modificados.
- Debe preservar paths relativos del repo.
- No quiero PR.
- No quiero branch.
- No quiero commits.
- Solo ZIP.

---

## Regla final

Si el repo real contradice este prompt, manda el repo real.  
No me complacas: corrige el prompt en tu diagnóstico y sigue el contrato verdadero.
