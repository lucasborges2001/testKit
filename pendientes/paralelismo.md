Estoy trabajando sobre mi repo actual de `testkit` y quiero mejorar el soporte de ejecución en paralelo de forma segura, sin vender humo y sin romper el flujo actual.

Contexto técnico ya validado:
- `testkit` ya tiene base de paralelismo: `RunnerConfig` expone `TEST_JOBS`, `SuiteExecutor` ya ejecuta tests en paralelo cuando `jobs > 1`, `auto_prepend.php` ya contempla `TEST_DB_STRATEGY=per_worker` + `TEST_WORKER_ID`, y `scripts/seed.ps1` ya contempla bootstrap por worker.
- El problema real no es “si existe `TEST_JOBS`”, sino que hoy correr varios runners top-level o varios procesos sobre el mismo store/report root genera ruido, seed/bootstrap cruzado y potenciales carreras.
- No quiero una solución cosmética tipo “subir jobs y esperar lo mejor”.
- No quiero romper el modo actual no paralelo.
- Quiero que el cambio sea real, seguro y documentado.

Objetivo:
Implementar un modelo de paralelismo seguro en `testkit`, priorizando ejecución paralela dentro de una suite (`back-php`, etc.) y endureciendo el contrato para que no haya falsos positivos por store compartido o reportes pisados.

Quiero que cuestiones el diseño y no me valides por defecto.

Qué necesito que resuelvas:
1. Revisá el soporte actual de paralelismo del repo.
2. Detectá qué partes ya sirven y cuáles son peligrosas/incompletas.
3. Implementá una estrategia segura para correr tests en paralelo.
4. Actualizá la documentación para que un humano entienda:
   - cuándo usar paralelismo
   - cuándo NO usarlo
   - cómo configurar `TEST_JOBS`
   - cómo configurar `TEST_DB_STRATEGY`
   - qué hacer con MySQL/store/reportes
   - qué limitaciones siguen existiendo

Prioridades obligatorias:
- priorizar paralelismo intra-suite sobre runners top-level paralelos
- no permitir configuraciones peligrosas silenciosas
- evitar que varios workers se pisen en DB si están corriendo integration tests
- evitar colisiones/overwrite de reportes cuando hay paralelismo real
- mantener compatibilidad con el flujo actual no paralelo
- no romper Linux ni Windows
- cambios pequeños y de alto impacto

Lo que quiero que evalúes y, si corresponde, implementes:
- guardrails explícitos si `TEST_JOBS > 1` y `TEST_DB_STRATEGY=shared` en suites con DB/integration
- mejorar `per_worker` para que sea la ruta segura cuando hay paralelismo real
- evitar carreras en bootstrap/seed del store
- aislar `report_root` o los writes de reportes cuando haya corridas top-level concurrentes
- marcar o documentar suites/tests que no son `parallel-safe`
- endurecer mensajes de error/warning para que el usuario no descubra el problema por ruido de bootstrap

Importante:
- NO quiero una reescritura completa del runner.
- NO quiero paralelizar “todo” por defecto.
- NO quiero una solución que funcione solo en CI ideal; tiene que ser razonable también para uso humano local.
- NO quiero que el meta-runner quede más frágil.
- NO quiero un cambio que solo mejore throughput teórico pero empeore reproducibilidad.

Criterios de aceptación:
- una suite como `back-php` puede correrse con `TEST_JOBS>1` sin compartir peligrosamente la misma DB entre workers cuando corresponde
- si el usuario intenta una configuración insegura, `testkit` avisa o falla explícitamente
- los reportes no quedan corruptos o pisados por paralelismo top-level
- el modo no paralelo actual sigue funcionando como antes
- la documentación queda clara y accionable
- el cambio es defendible técnicamente, no cosmético

Forma de trabajo:
1. Hacé un diagnóstico corto y crítico del estado actual del paralelismo en el repo.
2. Proponé la estrategia correcta y por qué.
3. Implementala en el repo actual.
4. Actualizá la documentación relevante.
5. Devolveme solo:
   - diagnóstico breve
   - decisión de diseño
   - archivos nuevos/modificados
   - contenido completo de cada archivo nuevo/modificado
   - ejemplos de uso
   - warnings/limitaciones que siguen vigentes

Muy importante:
- distinguí claramente entre:
  a) paralelismo dentro de una suite
  b) runners top-level paralelos
- si uno de esos dos modelos no es seguro hoy, decilo sin maquillaje
- si hay partes del repo donde conviene documentar “no parallel-safe” en vez de automatizar magia, hacelo
- actualizá también la documentación para reflejar la política elegida