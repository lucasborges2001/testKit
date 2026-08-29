# Registro contractual de testKit

> Generado desde `Testkit\Core\Config\ContractRegistry`. No editar listas manualmente.

Schema: `testkit.contract_registry@2`
Digest: `eedc46fbc21231ad36a052253e957984c0c9229364cd44194f5ed75516f9bcd3`

## Selector público

Toda corrida declara exactamente uno de `--suite`, `--group` o `--category`.
No existen targets posicionales, aliases ni extensiones `TESTKIT_TARGET_*`.

## Suites

| Nombre público | Suite ID | Lenguaje | Runner |
|---|---|---|---|
| `back-php` | `back_php` | `php` | `BackPhpSuite` |
| `back-python` | `back_python` | `python` | `BackPythonSuite` |
| `front-php` | `front_php` | `php` | `FrontPhpSuite` |
| `front-js` | `front_js` | `javascript` | `FrontJsSuite` |
| `infra-php` | `infra_php` | `php` | `InfraPhpSuite` |
| `migration-contract` | `migration_contract` | `php` | `MigrationContractSuite` |
| `reference-contract` | `reference_contract` | `php` | `ReferenceContractSuite` |
| `sql-observability` | `sql_observability` | `bash/php` | `SqlObservabilitySuite` |
| `sql-static-audit` | `sql_static_audit` | `php` | `SqlStaticAuditSuite` |

## Grupos

- `all`: `back_php`, `back_python`, `front_php`, `front_js`, `infra_php`
- `back`: `back_php`, `back_python`
- `front`: `front_php`, `front_js`
- `infra`: `infra_php`
- `php`: `back_php`, `front_php`, `infra_php`
- `js`: `front_js`

## Categorías

- `smoke`: `back_php`, `back_python`, `front_php`, `front_js`, `infra_php`
- `perf`: `back_php`, `back_python`, `front_php`, `front_js`, `infra_php`
- `stress`: `back_php`, `back_python`, `front_php`, `front_js`, `infra_php`
- `contract`: `back_php`, `back_python`, `front_php`, `front_js`, `infra_php`
- `critical`: `back_php`, `back_python`, `front_php`, `front_js`, `infra_php`
- `security`: `back_php`, `back_python`, `front_php`, `front_js`, `infra_php`
- `slow`: `back_php`, `back_python`, `front_php`, `front_js`, `infra_php`

## Ejemplos

```bash
php runTest.php --suite back-php
php runTest.php --group all --list
php runTest.php --category smoke
php runTest.php --suite back-php --test test/back/auth/login.test.php
```

## Contrato completo

```bash
php scripts/contract.php --json
php scripts/contract.php validate --json
php scripts/contract.php validate-selector suite back-php --json
php scripts/contract.php check-doc docs/CONTRACT_REGISTRY.md
php tests/framework/test_contract_registry.php
```
