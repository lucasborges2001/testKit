# Registro contractual de testKit

> Generado desde `Testkit\Core\Config\ContractRegistry`. No editar listas manualmente.

Schema: `testkit.contract_registry@1`  
Digest: `900feb8987848ff42e89a61855a92f23a3bc2361fc05639e54cb2684d892baac`

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

## Grupos

- `all`: `back_php`, `back_python`, `front_php`, `front_js`, `infra_php`
- `back`: `back_php`, `back_python`
- `front`: `front_php`, `front_js`
- `infra`: `infra_php`
- `public_html`: `front_php`, `front_js`
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

## Aliases heredados

Se eliminan en Fase 3; no son nombres canónicos.

- `back-py` → `back-python`
- `python` → `back-python`
- `py` → `back-python`
- `http` → `infra-php`
- `migration` → `migration-contract`
- `migrations` → `migration-contract`
- `references` → `reference-contract`
- `php-references` → `reference-contract`

## Contrato completo

Capacidades, restricciones, target definitions y support matrix se serializan con:

```bash
php scripts/contract.php --json
php scripts/contract.php validate --json
php scripts/contract.php check-doc docs/CONTRACT_REGISTRY.md
php tests/framework/test_contract_registry.php
```
