# Flight Passenger Composition Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add optional search-only passenger composition and safely activate supported Duffel adult searches.

**Architecture:** Version the SSOT first, then carry a nullable composition through TripRequest persistence and FlightSearchCriteria. Map only all-adult explicit compositions; reject under-18 compositions before provider HTTP and preserve the explicit legacy ALL_ADULT path.

**Tech Stack:** Laravel 12, PHP 8.2, JSON Schema, Blade, PHPUnit, Laravel HTTP fake.

**Spec:** `docs/superpowers/specs/2026-09-29-flight-passenger-composition-design.md`

## Global Constraints

- OD-020 and OD-021 remain OPEN.
- Previous contract versions are immutable.
- No Traveler records or identity/document data are involved.
- No invented age or DOB values.
- All user-visible validation and provider errors are Turkish.
- No new dependencies and no booking/order/payment calls.

## Review Focus

- Legacy count-only searches require the explicit ALL_ADULT policy.
- New composition never inherits the legacy policy.
- Child/infant criteria produce zero provider HTTP calls.
- Mismatched and zero compositions fail before persistence/provider execution.
- Tokens and raw provider payloads never reach canonical errors.

---

### Task 1: v3.12 SSOT and contracts

**Files:** Create `registry/v3.12/*`, `contracts/v3.12/*`; preserve all earlier versions.

**Interfaces:** Produces `SCH-PassengerComposition` and nullable `passenger_composition` on TripRequest and FlightSearchCriteria.

- [ ] Add schema/traceability validation tests or executable JSON checks and observe failure before files exist.
- [ ] Add registry-first v3.12 decision notes, contract index, schemas, changelog, versioning, traceability and OD impact.
- [ ] Run JSON/YAML and reference consistency checks.

### Task 2: TripRequest persistence and UI

**Files:** Modify TripRequest domain/view/repository/controller/Blade and tests; create nullable JSON migration.

**Interfaces:** Consumes contract composition; produces normalized `array{adults:int,children:int,infants:int}|null` without Traveler side effects.

- [ ] Add failing domain, web, UI and persistence tests for valid, zero, negative, mismatch and legacy-null cases.
- [ ] Implement validation, persistence, public representation and three numeric controls.
- [ ] Run focused TripRequest/UI/database tests.

### Task 3: Flight criteria and Duffel mapping

**Files:** Modify FlightSearchCriteria, Duffel adapter/configuration comments and provider tests.

**Interfaces:** Consumes optional composition; produces adult Duffel passengers or a pre-provider unsupported-criteria failure.

- [ ] Add failing tests for one/multiple adults, child/infant rejection, mismatch, capability disabled, response mapping, failures and secret redaction.
- [ ] Implement criteria propagation, adult mapping, under-18 preflight rejection and legacy policy compatibility.
- [ ] Run focused mocked provider and resilience tests.

### Task 4: Regression and smoke verification

**Files:** No additional production files expected.

**Interfaces:** Verifies the complete flow and environment-gated test-mode smoke.

- [ ] Run UI JavaScript tests, full PHPUnit suite, Pint, PHP syntax and contract validation.
- [ ] If `DUFFEL_ACCESS_TOKEN` exists, run one TEST-mode search only; otherwise record NOT RUN.
- [ ] Review the complete diff against the spec and report remaining OD blockers.
