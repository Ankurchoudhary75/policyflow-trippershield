# AI Usage & Verification Disclosure

*PolicyFlow: Fault-Tolerant Insurance Issuance & Reconciliation Engine*  
*Candidate: Ankur Choudhary (<https://github.com/Ankurchoudhary75>)*  
*Assessment: TripperShield Campus Engineering Challenge*

---

## 1. AI Tools Utilized
During the preparation and implementation of PolicyFlow, the following AI tools and references were utilized:
- **Claude / Gemini / Antigravity Assistant**: Used for architectural review, edge-case brainstorming, boilerplate schema drafting, and markdown formatting.
- **Official Laravel 11 & PHP 8.2 Documentation**: Referenced for Laravel 11 application lifecycle (`bootstrap/app.php` routing, Form Requests, and Eloquent attribute accessors).
- **GitHub / Docker Documentation**: Referenced for containerized multi-stage builds and isolated test execution.

---

## 2. Concrete Areas Where AI Assisted
1. **Mathematical Boundary Formulation for Pricing**:
   AI helped draft the comprehensive test parameter matrix in `tests/Unit/PremiumCalculatorTest.php`, specifically pinpointing birthday edge-cases on leap years and boundary dates (e.g. day before 18th birthday vs. exact 18th birthday on `trip_start`).
2. **Concurrency Pattern Architecture (Claim-Call-Record with Lease)**:
   AI participated in brainstorming how to prevent prolonged row-locking across network boundaries. The resulting design split submission into a short DB lease acquisition (Tx A), asynchronous network call to the insurer, and a final short DB result update (Tx B).
3. **Database Migration Skeleton & Constraints Design**:
   AI helped draft initial migration stubs ensuring composite unique keys (`agent_id`, `client_request_id`) and index placements were syntactically correct for both SQLite and MySQL engines.

---

## 3. Incorrect or Incomplete Suggestions Encountered & How Caught

### Issue 1: Floating-Point Financial Calculation and Carbon Year Diff
- **AI Suggestion**: An initial AI-generated calculator draft used native PHP floating-point multiplication (`$base * $days * $ageMult ...`) and `round($val, 2)`, accompanied by Carbon's `$birthDate->diffInYears($tripStart)`.
- **Why It Was Incomplete / Defective**:
  1. Floating-point binary arithmetic in IEEE 754 introduces precision drift (e.g., `0.1 + 0.2 !== 0.3`). In insurance policy pricing, financial calculations must be deterministic and decimal-safe.
  2. In Carbon 3 (shipped with Laravel 11), `diffInYears()` returns a float (e.g. `17.99726`), which PHP 8.2+ warns about when implicitly converted to an integer, leading to off-by-one errors on birthdays.
- **Correction Applied**:
  Replaced with `Brick\Math\BigDecimal` using exact string representations and terminal `toScale(2, RoundingMode::HALF_UP)` without intermediate rounding. Converted age evaluation to calendar day difference `$birthDate->diff($tripStart)->y`, ensuring exact calendar year boundaries.

### Issue 2: Naive `firstOrCreate` for Intake Idempotency
- **AI Suggestion**: A suggested intake pattern proposed `PolicyRequest::firstOrCreate(['agent_id' => $agentId, 'client_request_id' => $clientRequestId], [...])`.
- **Why It Was Defective**:
  `firstOrCreate` performs a `SELECT` followed by an `INSERT`. Under concurrent, near-simultaneous HTTP requests (Race Condition), two threads both observe `SELECT -> null`, and both attempt an `INSERT`. Without explicit database unique constraint error handling, this results in an unhandled 500 `QueryException` crash.
- **Correction Applied**:
  Implemented a database-first architecture: enforced `UNIQUE(agent_id, client_request_id)` at the migration level. In `PolicyRequestService`, the code catches `Illuminate\Database\QueryException` (SQLSTATE 23000 / 23505), selects the committed winner row, checks for payload fidelity, and safely returns HTTP 200 OK with `meta.idempotent_replay: true`.

### Issue 3: Open Database Transactions Across HTTP Insurer Calls
- **AI Suggestion**: An issuance draft wrapped the entire submission flow—including the external HTTP call to the insurer—in a single `DB::transaction()`.
- **Why It Was Dangerous**:
  Holding an active database connection and row-level lock (`SELECT FOR UPDATE`) while waiting for external network latency (which can take several seconds or time out) quickly exhausts the database connection pool and blocks concurrent queries.
- **Correction Applied**:
  Separated into two short transactions utilizing a 30-second in-flight lease (`submit_lease_until`). The network call runs completely outside database transaction boundaries.

---

## 4. Verification and Validation Methodology
Every line of code and test in this repository was independently reviewed, manually verified, and executed:
1. **Automated Test Suite**:
   Executed 39 automated tests (187 assertions) via Docker PHP 8.2 environment (`./test.sh`), verifying 100% green coverage across unit math, state machine transitions, intake idempotency, race conditions, timeout recovery, duplicate callbacks, and raw SQL constraints.
2. **Curl Endpoint Validation**:
   Manually executed all 7 scenario scripts in `examples/` against a live local instance to verify HTTP status codes (`201 Created`, `200 OK`, `422 Unprocessable Content`, `409 Conflict`, `404 Not Found`).
3. **Database Constraint Verification**:
   Executed `DatabaseConstraintTest` to confirm that even if application-level validation were bypassed, the database engine strictly enforces uniqueness invariants.
