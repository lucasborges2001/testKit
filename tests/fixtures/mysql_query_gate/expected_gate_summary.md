# SQL query gate

| Field | Value |
|---|---|
| Mode | `fail` |
| Decision | **BLOCKED** |
| Exit code | `5` |
| Blocking | 1 |
| Warnings | 0 |
| Observed | 0 |
| Pending stability | 0 |
| Suppressed | 0 |

## Inputs

- Profile: `c0ebf9479f01`
- Policy: `cccccccccccc`
- Baseline: `not-provided`
- Comparisons: 1

## Compatibility and stability

- Evidence runs: 1
- Confirmed: 0
- Insufficient runs: 0
- Insufficient samples: 0
- Incompatible evidence: 0

## Blocking findings

- **policy.violation** — `fingerprint:13083d1fd05a2bdaf4c2b80c2652b847ea2ff068f3f667b94c58d57d154ed138` — Policy catalog.max-calls violated max_calls.

## Warnings

- None.

## Pending stability

- None.

## Suppressions

- Used: 0
- Unused: 0
- Expired: 0

## Artifacts


## Limitations

- The gate consumes policy and comparison evidence and does not recalculate them.
