# Tarifa contract support

TestKit exposes neutral helpers for Tarifa contract validation only. They cover
deterministic `PricingSnapshot` fixtures, minor-unit money validation, canonical
scopes, tenant checks, immutable snapshot/no-repricing assertions, idempotent
replay/conflict checks, process-based concurrency evidence, and JSON evidence.

They do not implement Cobros, Contaduria, Wallet, SesionCarga, or host adapters.
