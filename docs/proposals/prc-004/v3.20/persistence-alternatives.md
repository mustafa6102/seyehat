# Persistence alternatives and ADR-009 impact

No alternative is selected by this proposal.

## A — ADR-002 OLTP table, engine selected by ADR-009

Matches accepted ADR-002: immutable price snapshots in a separate, time-partitioned OLTP table. ADR-009 is now ACCEPTED as MySQL 8; partition DDL, indexes, JSON representation and retention implementation still depend on an approved snapshot schema and OD-013. This is the contract-compatible destination after those approvals.

## B — Existing transient offer/cache records

No schema change, but entries expire and are not an append-only historical source. It cannot satisfy `FR-PRC-004-01`, `AC-PRC-004-02` or `NFR-AUD-001`; therefore it is not a compliant persistence solution.

## C — Separate time-series store

ADR-002 explicitly treats this as premature before M2 volume evidence. Selecting it requires revisiting ADR-002 and a new architecture decision.

## Future logical table shape

If A is approved, later manually executable MySQL/MariaDB SQL needs one append-only logical snapshot table containing only schema-backed values:

- opaque snapshot id;
- product type and opaque source offer id;
- serialized `DerivedMoney` price;
- serialized supported observed dimensions;
- provider id and provider offer id;
- `fetched_at`, nullable `verified_at`, `captured_at`;
- snapshot/schema version;
- created timestamp.

The following remain deliberately unspecified: physical SQL types, primary/unique key strategy, partition key/range, indexes, foreign keys, retention/deletion procedure and canonical cohort hash. No SQL may be generated until the snapshot schema, OD-013 treatment and canonical cohort mappings are approved.
