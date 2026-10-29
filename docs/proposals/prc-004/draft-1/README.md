# PRC-004 draft-1 approval delta

Status: **PROPOSED / NON-NORMATIVE / NOT ACTIVE**  
Base: active contract `v3.21.0`  
Target contract version: **OPEN**

This package records the user's 2026-10-25 selections without activating them. It does not alter `contracts/v3.21`, `registry/v3.21`, application routes, persistence, API behavior, UI, AC coverage, or TS coverage.

`contract-delta.yaml` is the decision map and activation checklist. Existing proposal artifacts under `docs/proposals/prc-004/v3.20` remain non-normative inputs. Where those older files call `ADR-009` PROPOSED, that statement is obsolete: `registry/v3.3/decisions.yaml` and `contracts/v3.3/VERSIONING.md` establish **ADR-009 ACCEPTED / MySQL 8**. This correction does not resolve `OD-013` or approve any PRC record.

No independently contract-approved PRC-004 runtime behavior exists yet. In particular, a permanent `CONFIGURATION_UNRESOLVED` endpoint would invent an inactive operation and response schema, so no backend/UI implementation is included.
