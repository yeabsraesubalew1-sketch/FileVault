---

name: implement-module
description: Implements an approved module specification as a complete, coherent module within the existing project architecture. Use when a Module Owner has provided an implementation-ready module specification or implementation brief.
--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

# Implement Module

You are executing the governed module implementation workflow.

The workspace governance rule is authoritative. This skill defines the execution procedure; it does not replace or override the governance rule.

Your objective is to produce a complete, maintainable, reviewable implementation of the approved module and finish with an accurate implementation report.

---

## Phase 0 — Establish the Implementation Target

Identify:

1. The module being implemented.
2. Its Module Owner, if specified.
3. The approved module specification or implementation brief.
4. Any referenced architecture or governance documents.
5. Explicit exclusions and non-goals.
6. Any acceptance criteria.

If the implementation target is not identifiable, stop and request the missing specification.

Do not infer an entirely new module from a vague feature request.

---

## Phase 1 — Read the Specification

Read the complete approved module specification before making implementation changes.

Extract and understand:

* Purpose.
* Responsibilities.
* Public interfaces.
* Inputs and outputs.
* Data models.
* Validation requirements.
* Dependencies.
* Integration boundaries.
* Expected behavior.
* Edge cases.
* Security requirements.
* Reliability requirements.
* Acceptance criteria.
* Explicit exclusions and non-goals.

Follow referenced project documents when they are necessary to understand the specification.

Do not rely on summaries when the referenced source is available.

---

## Phase 2 — Inspect the Repository

Before modifying code, inspect the existing implementation context.

At minimum, inspect:

* Project structure.
* Relevant source directories.
* Related modules.
* Public interfaces.
* Models and schemas.
* Shared utilities.
* Configuration.
* Dependency files.
* Existing tests.
* Existing implementation patterns.
* Relevant documentation.

Search the repository rather than assuming that required files, APIs, utilities, dependencies, or conventions exist.

Determine:

* Where the module belongs.
* What existing code it should integrate with.
* Which interfaces it should consume.
* Which interfaces it must expose.
* Which files will likely need creation or modification.
* Whether the existing architecture supports the approved specification.

Do not modify files during this phase unless inspection itself requires a harmless tool-generated change.

---

## Phase 3 — Specification Readiness Check

Before implementation, classify each requirement as:

* Clear.
* Minor ambiguity.
* Material ambiguity.
* Blocked by a missing prerequisite.
* In conflict with existing code or architecture.

Apply the workspace governance rules.

### Minor ambiguity

Use the lowest-risk interpretation when it is clearly supported by existing project conventions.

Record the assumption.

### Material ambiguity

Do not guess.

Material ambiguity includes uncertainty affecting:

* Public behavior.
* Security.
* Data integrity.
* Architecture.
* Scope.
* Module boundaries.
* External contracts.

Stop the affected decision and request clarification.

### Missing prerequisite

Do not fabricate:

* Files.
* APIs.
* Credentials.
* Database records.
* External service behavior.
* Configuration.
* Requirements.

Continue with unaffected work only when doing so cannot lock in the unresolved decision.

### Existing-code conflict

Do not silently overwrite the conflict.

Record:

1. Specification requirement.
2. Existing behavior.
3. Why they conflict.
4. Available implementation options.
5. Decision requiring review.

If a material blocker remains, stop and report it.

---

## Phase 4 — Build the Implementation Plan

Create a concise internal plan before changing code.

The plan must identify:

* Files to create.
* Files to modify.
* Files that must not be touched.
* Main implementation components.
* Public interfaces.
* Integration points.
* Data or persistence changes.
* Tests to create or modify.
* Validation commands.
* Relevant risks.
* Minor assumptions.

The plan is an execution aid, not a new architectural specification.

Do not redesign the module while planning.

Do not ask for approval for routine implementation steps already covered by the approved specification.

---

## Phase 5 — Implement the Module

Implement the approved module as one coherent unit.

Complete all necessary implementation work within the approved scope, including where applicable:

* Production source files.
* Interfaces.
* Classes, functions, and types.
* Adapters.
* Models.
* Persistence changes.
* Validation.
* Error handling.
* Configuration integration.
* Tests.
* Required documentation.
* Necessary integration changes.

Use existing project conventions and abstractions where appropriate.

Prefer the simplest implementation that satisfies the contract.

Keep implementation decisions within the authority granted by the workspace governance rule.

Do not:

* Add unrelated features.
* Expand the module's responsibilities.
* Redesign the project.
* Rewrite unrelated code.
* Introduce speculative abstractions.
* Replace approved architecture with personal preferences.
* Add major dependencies without authorization.
* Change public contracts silently.
* Delete existing functionality without authorization.

If an approval-required change becomes necessary, isolate the affected decision and report it rather than silently proceeding.

---

## Phase 6 — Self-Review

Before running final validation, inspect the implementation as if reviewing another developer's pull request.

Check for:

### Requirements

* Is every approved requirement implemented?
* Are explicit exclusions respected?
* Are acceptance criteria satisfied?

### Architecture

* Does the module remain inside its intended boundary?
* Were responsibilities accidentally moved?
* Were existing abstractions bypassed?
* Were unrelated modules modified?

### Contracts

* Are public interfaces correct?
* Are inputs and outputs correct?
* Are data models and schemas consistent?

### Security

* Are authentication and authorization requirements preserved?
* Is untrusted input validated?
* Are secrets protected?
* Is sensitive information excluded from logs?
* Were security controls left intact?

### Reliability

* Are expected failures handled?
* Are important edge cases covered?
* Could invalid state or partial failure corrupt data?

### Scope

* Did implementation introduce anything not requested?
* Did opportunistic refactoring expand the change?

### Code quality

* Is the implementation understandable?
* Is complexity justified?
* Are there unnecessary abstractions?
* Are existing project conventions followed?

---

## Phase 7 — Test and Validate

Run the relevant project validation.

At minimum, when applicable:

1. Existing tests.
2. New or modified module tests.
3. Linting.
4. Formatting checks.
5. Type checking.
6. Build checks.
7. Relevant integration tests.

Also manually inspect important:

* Success paths.
* Failure paths.
* Edge cases.
* Module integration points.

Do not claim a check passed unless it was actually executed.

Do not delete or weaken tests to obtain a passing result.

Do not bypass validation without reporting the bypass.

If a validation command cannot be run, record why.

If failures are caused by the implementation and are within scope, fix them and rerun validation.

If failures are unrelated or require an approval-level decision, isolate them and report them.

---

## Phase 8 — Final Diff Review

Inspect the final change set.

Verify:

* Every changed file is relevant.
* Every created file is necessary.
* No unrelated file was modified.
* No accidental debug code remains.
* No secrets or credentials were introduced.
* No temporary files or generated artifacts were unintentionally added.
* No required implementation was omitted.

Compare the final implementation against the original module specification, not merely against the implementation plan.

The specification is the source of truth.

---

## Phase 9 — Requirements Coverage

Create a requirement-by-requirement coverage assessment.

For each approved requirement, determine:

* Implemented.
* Partially implemented.
* Not implemented.
* Blocked.

For anything other than "Implemented", explain why.

Do not mark a requirement complete merely because related code exists.

---

## Phase 10 — Produce the Implementation Report

End the task with the implementation report required by the workspace governance rule.

Use this structure:

### Status

Choose the most accurate status:

* **Ready for review**
* **Incomplete**
* **Blocked pending clarification**
* Another accurate status when necessary.

### Summary

Briefly describe what was implemented.

### Files Changed

List every created, modified, or deleted file and its purpose.

### Requirements Coverage

Map the implementation to the approved module requirements.

Identify incomplete requirements.

### Architectural Decisions

Describe meaningful implementation decisions and how they fit the existing architecture.

Do not present ordinary low-level coding choices as architectural decisions.

### Deviations and Assumptions

List:

* Deviations from the specification.
* Minor assumptions.
* Unresolved decisions.
* Items requiring review or approval.

If there are none, explicitly state that.

### Testing and Validation

Report:

* Tests run.
* Commands used.
* Results.
* Relevant failures.
* Validation not performed and why.

### Security Considerations

Describe relevant security behavior and unresolved concerns.

### Dependencies and Configuration

List:

* New dependencies.
* Configuration changes.
* Environment requirements.
* External prerequisites.

### Limitations and Follow-Up Work

List known limitations and intentionally deferred work.

Do not silently implement follow-up work merely because it is useful.

### Handoff

State exactly what the Module Owner or reviewer should inspect before acceptance.

---

## Phase 11 — Acceptance Boundary

The implementation is now ready for review only when the Definition of Done in the workspace governance rule is satisfied.

Do not claim:

* Architectural acceptance.
* Module acceptance.
* Production readiness.
* Reviewer approval.

unless the appropriate authority has explicitly provided it.

The correct final state after successful implementation is:

**Ready for review.**

The Module Owner or designated reviewer decides whether the implementation is accepted.

---

# Operating Principle

Implement the entire approved module when its specification is ready.

Do not turn a coherent module into arbitrary micro-tasks.

Do not turn implementation autonomy into architectural authority.

Do not confuse "the code works" with "the module has been accepted."

**Build the approved thing completely. Verify it honestly. Report what actually happened. Hand it back for review.**
