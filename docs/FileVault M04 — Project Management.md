M04 — Project Management

Final Architecture & Module Specification

Status: READY FOR IMPLEMENTATION

This specification is the implementation authority for M04. Anything not explicitly defined here should not be invented during implementation.

1. Module Purpose

M04 owns the project aggregate and project-level resource policy for FileVault.

It is responsible for:

project identity

project name

project status

project quotas

project usage accounting

project lifecycle rules

project persistence contracts

project application operations

M04 does not own:

Concern

Owner

Application bootstrap/configuration

M01

Database/PDO infrastructure

M05

File metadata persistence

M05

Physical file storage

M06

File lifecycle

M07

API keys

M03

HTTP/API routing

M02

This boundary is mandatory.

2. Project Identity

The database primary key is also the project's public identifier.

Project #17

Therefore:

id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY

There is no separate public identifier column.

There is:

no slug

no UUID project identifier

no mutable identifier

no project URL alias

API routes can consequently use:

/projects/17/...

The numeric project ID is immutable for the lifetime of the project.

3. Project Aggregate

The aggregate root is:

Project

Conceptually:

Project
├── id
├── name
├── status
├── quota
├── usage
├── createdAt
└── updatedAt

The aggregate owns its own invariants.

External callers must not directly mutate:

status
currentFileCount
currentStorageBytes

Instead, state changes happen through domain operations.

4. Project Name

Project names are:

required

maximum 120 characters

trimmed at both ends

original casing preserved

case-insensitively unique

immutable in v1

Example:

"My Website"

is stored with that casing.

But:

"My Website"
"my website"
"MY WEBSITE"

must be considered the same name for uniqueness purposes.

There is no rename operation in v1.

Important

Do not solve case-insensitive uniqueness by silently lowercasing the stored name.

The stored representation should preserve the user's chosen casing.

The database uniqueness mechanism must enforce the case-insensitive constraint.

5. Project Status

Exactly three states exist:

active
restricted
disabled

No additional states should be introduced.

active

Normal project operation.

Allowed:

API-key authenticated operations

file creation

file mutation

public file delivery

restricted

Normal project activity is stopped.

Existing public active files remain publicly accessible.

Therefore:

API operations      ❌
new file creation   ❌
file mutation       ❌
public delivery     ✅

disabled

The project is completely inactive.

API operations      ❌
new file creation   ❌
file mutation       ❌
public delivery     ❌

Existing API keys are not revoked or deleted merely because the project is disabled.

They simply cannot successfully operate while the project is disabled.

If the project becomes active again, non-revoked keys become usable again.

6. Status Transitions

Valid transitions:

active
 ├──→ restricted
 └──→ disabled

restricted
 ├──→ active
 └──→ disabled

disabled
 └──→ active

Invalid:

disabled → restricted

Same-state transitions should be idempotent/no-op.

For example:

active → active

does not constitute an error.

The aggregate should own these rules.

7. Quotas

M04 has exactly three quota dimensions:

maxFileSize
maxFileCount
maxStorageBytes

All are:

BIGINT UNSIGNED

0 means unlimited.

There are no additional quota dimensions in v1.

No:

bandwidth quota

daily upload quota

download quota

API request quota

per-user quota

MIME-type quota

Keep that shit out of M04. 😂

8. Usage Accounting

Project usage contains:

currentFileCount
currentStorageBytes

Both are:

BIGINT UNSIGNED

Usage is authoritative database state.

The filesystem is not scanned to determine quota usage.

9. Usage Rules

Lifecycle operation

File count

Storage

New file

+1

+size

Trash

0

0

Restore

0

0

Replace

+1

+new size

Purge

-1

-old size

The crucial rule:

Trashed files continue counting toward both file-count and storage quotas.

Only permanent purge releases quota.

Therefore:

upload 10 MB
→ usage +10 MB

trash it
→ usage still +10 MB

restore it
→ usage still +10 MB

purge it
→ usage -10 MB

10. Replacement Semantics

Replacement is deliberately accounted as a new stored file.

If:

old = 10 MB
new = 15 MB

then replacement produces:

file count: +1
storage:    +15 MB

The old file continues contributing to usage until it is purged.

So temporarily:

10 MB old
+
15 MB new
=
25 MB

This means replacement can legitimately fail quota checks even when the final desired state would fit.

That's intentional.

It prevents quota enforcement from pretending the old resource disappeared before it actually did.

11. UsageDelta

M04 should represent usage changes explicitly.

Conceptually:

UsageDelta
├── fileCountDelta
└── storageBytesDelta

Examples:

new file     → (+1, +size)
trash        → ( 0,    0)
restore      → ( 0,    0)
replacement  → (+1, +newSize)
purge        → (-1, -oldSize)

The Project aggregate applies these deltas.

It must never permit usage to become negative.

12. ProjectUsage

ProjectUsage encapsulates:

currentFileCount
currentStorageBytes

Its invariant:

file count >= 0
storage bytes >= 0

It should expose controlled operations rather than public mutable properties.

The aggregate is responsible for applying usage changes.

13. ProjectQuota

ProjectQuota encapsulates:

maxFileSize
maxFileCount
maxStorageBytes

Its responsibility is determining whether a proposed operation fits within configured limits.

0 means unlimited.

For example:

maxFileSize = 0

means no file-size restriction.

14. Quota Checking

A quota check must consider:

individual file size

resulting file count

resulting storage usage

A single operation can violate multiple limits.

Therefore the result should be able to represent multiple violations, rather than failing on the first one.

Conceptually:

QuotaCheckResult
├── allowed
└── violations[]

Potential violation types:

file_size
file_count
storage

The actual exception thrown to callers should be a dedicated quota exception carrying the violations.

15. Important Concurrency Rule

Quota checks cannot rely solely on the project object loaded normally.

Consider:

quota = 100 MB
current = 90 MB

Two simultaneous uploads:

A = 8 MB
B = 8 MB

Both initially see:

90 + 8 <= 100

Both appear valid.

Without locking:

90 + 8 + 8 = 106 MB

Quota is exceeded.

Therefore authoritative quota enforcement must happen after locking the project row.

M05 repository contract:

findByIdForUpdate(projectId)

must be used inside a database transaction.

The flow is:

BEGIN
    ↓
SELECT project ... FOR UPDATE
    ↓
check status
    ↓
check quota
    ↓
modify file metadata
    ↓
apply usage delta
    ↓
save project
    ↓
COMMIT

This is a critical invariant.

16. Early Quota Checks

An early quota check before expensive physical storage is allowed as an optimization.

For example:

check project
check obvious quota failure
store physical object
BEGIN
lock project
check again authoritatively

The early check is never authoritative.

The locked transaction check remains mandatory.

This avoids unnecessary storage work while preserving correctness under concurrency.

17. Project Policy

M04 provides a small ProjectPolicy boundary for use-case enforcement.

It should not become a giant permissions interface.

Good:

requireOperational(project)
requirePublicDeliveryAllowed(project)

Bad:

canUpload()
canDelete()
canReplace()
canRestore()
canTrash()
canPurge()
canDownload()
canRename()
...

The project's intrinsic state semantics remain on Project.

For example:

Project::isOperational()
Project::allowsPublicDelivery()

Policy translates those state semantics into use-case enforcement.

18. Public Delivery Rules

M04 determines whether the project permits public delivery.

active     → yes
restricted → yes
disabled   → no

This does not mean a file is automatically public.

Actual public delivery requires both:

Project allows public delivery
AND
File is public
AND
File is active/deliverable

M04 owns only the project half.

19. Project Repository Contract

M04 defines the repository contract.

M05 supplies its persistence implementation.

Conceptually:

ProjectRepository

findById(id)
findByIdForUpdate(id)
findByName(name)
save(project)

findByIdForUpdate() exists specifically for authoritative transactional operations involving project usage/quota concurrency.

It is not simply an alternate spelling of findById().

20. Repository Responsibilities

The repository handles persistence of the aggregate.

It does not:

enforce HTTP behavior

perform filesystem operations

calculate physical storage usage

create API keys

enforce API authentication

decide file visibility

silently modify domain state

save(project) persists the aggregate's current state.

We do not want a repository API like:

incrementUsage()
decrementUsage()
setStatus()
setQuota()

Those methods would bypass the aggregate.

21. ProjectService

M04's application-facing service provides operations such as:

create()
get()
activate()
restrict()
disable()
updateQuota()

It must not:

create API keys

create file records

touch M06 storage

manipulate HTTP responses

expose arbitrary usage setters

In particular, there must be no:

setCurrentStorage(...)
setCurrentFileCount(...)

Usage changes happen through domain operations such as:

project.applyUsageDelta(...)

22. Project Creation

Creating a project produces:

status = active
currentFileCount = 0
currentStorageBytes = 0

Quota values omitted by the caller become:

0 = unlimited

Project creation itself does not create an API key inside M04.

Later, a higher-level use case can coordinate:

BEGIN
    ↓
M04 create project
    ↓
M03 create initial API key
    ↓
COMMIT

If API-key creation fails, the project creation rolls back.

M04 does not depend on M03 to accomplish this.

23. Transaction Ownership

There is no Unit of Work abstraction.

We pressure-tested it.

It isn't justified by FileVault's architecture.

Instead:

The outer application use case owns the transaction boundary.

Repositories participate in transactions.

Repositories do not begin/commit their own transactions.

M04 services must not create nested transaction boundaries when participating in a larger operation.

M05 remains responsible for the actual database transaction mechanism.

24. File Lifecycle Integration

M07 remains the coordinator for operations involving both project accounting and files.

For upload:

M07
 │
 ├── M06 stores physical object
 │
 └── M05 transaction
      ├── lock M04 project
      ├── verify project operational
      ├── authoritative quota check
      ├── create M05 file record
      ├── apply M04 usage delta
      └── commit

If the database transaction fails:

M07 → M06 compensation

This preserves the previously established M06/M07 consistency strategy.

25. Purge Integration

Purge remains intentionally asymmetric.

M07
 ↓
M06 physically deletes object
 ↓
M05 transaction
 ├── lock project
 ├── remove file metadata
 └── decrement project usage

If physical deletion fails:

DB metadata remains
usage remains unchanged

If physical deletion succeeds but the DB transaction fails:

metadata remains
usage remains unchanged

The operation can then be retried/reconciled.

M04 does not own this orchestration.

26. Project Status + File Operations

For normal authenticated file operations:

active → allowed
restricted → rejected
disabled → rejected

For public delivery:

active → allowed by project
restricted → allowed by project
disabled → rejected

This distinction is essential.

restricted is not equivalent to disabled.

27. Database Schema

The conceptual table is:

projects

id                     BIGINT UNSIGNED PK AUTO_INCREMENT
name                   VARCHAR(120) NOT NULL UNIQUE
status                 ...
max_file_size          BIGINT UNSIGNED NOT NULL
max_file_count         BIGINT UNSIGNED NOT NULL
max_storage_bytes      BIGINT UNSIGNED NOT NULL
current_file_count     BIGINT UNSIGNED NOT NULL
current_storage_bytes  BIGINT UNSIGNED NOT NULL
created_at             ...
updated_at             ...

The exact SQL type used for status should follow the existing M05 schema conventions rather than introducing unnecessary DB-specific cleverness.

The name uniqueness constraint must be case-insensitive.

All timestamps follow the existing FileVault/M05 timestamp conventions.

28. Database Invariants

The database must enforce what it reasonably can:

id > 0
quota values >= 0
usage values >= 0
name NOT NULL
status NOT NULL

Application/domain logic enforces:

valid status
valid transitions
valid name semantics
quota rules
usage transitions

The database is not the only enforcement layer.

29. No Project Deletion

There is no:

deleteProject()

in M04 v1.

Projects remain permanently represented.

This avoids the entire cascade/ownership problem across:

projects
api_keys
files
storage objects

We'll solve project deletion only if/when it becomes an actual requirement.

30. Exception Boundaries

M04 should have dedicated exceptions for meaningful domain/application failures, such as:

InvalidProjectNameException
DuplicateProjectNameException
InvalidProjectStatusTransitionException
InvalidProjectQuotaException
QuotaExceededException
InvalidUsageDeltaException
ProjectNotFoundException

Exact hierarchy should remain consistent with the exception conventions already established in M01/M05/M07.

Exceptions should describe why the operation failed, rather than leaking SQL/PDO concerns into M04.

31. What M04 Must NOT Do

This deserves its own red flag list because Antigravity loves "helping." 😭

Do not add:

slug generation

UUID project IDs

project deletion

API-key creation

API-key hashing

HTTP controllers

routes

JSON response formatting

filesystem access

storage backend selection

ORM

query builders

quota scanning of the filesystem

background quota reconciliation

Redis/cache

event bus

Unit of Work

generic repository base classes

speculative interfaces

project hierarchy

users/teams/organizations

billing

bandwidth quotas

Unless an existing accepted module contract absolutely requires something, it does not belong in M04.

32. Testing Requirements

M04 implementation is not accepted merely because the happy path works.

Tests need to cover at minimum:

Project

creation

default values

name trimming

casing preservation

invalid empty/oversized names

status transitions

invalid status transitions

same-state transitions

Quotas

unlimited quota (0)

max file size

max file count

max storage

multiple simultaneous violations

exact-boundary success

one-over-limit failure

Usage

positive deltas

negative deltas

zero deltas

prevention of negative usage

replacement accounting

purge accounting

Persistence

save/load round trip

case-insensitive duplicate name rejection

status persistence

quota persistence

usage persistence

findByIdForUpdate() behavior inside transactions

Integration/concurrency

At least one meaningful test must demonstrate that the project-row locking strategy prevents concurrent quota oversubscription.

That last one is particularly important.

33. Acceptance Criteria

M04 is accepted only when:

The implementation matches this specification.

All M04 tests pass.

Existing M01/M05/M06/M07 tests still pass.

Composer validation remains valid.

No ORM is introduced.

No new architectural dependency violates module ownership.

M04 does not depend on M03.

M04 does not touch M06.

Usage cannot be mutated except through the defined aggregate/domain mechanism.

Quota enforcement uses authoritative project-row locking.

Trashed files remain counted.

Replacement accounting follows the defined (+1, +newSize) rule.

No project deletion exists.

No Unit of Work abstraction is introduced.

No undocumented public API is invented.

34. Implementation Order

I would now give Antigravity one implementation prompt for the whole M04 module, but explicitly tell it to implement in controlled phases internally:

Phase 1
Schema + persistence records

Phase 2
Project + quota + usage domain

Phase 3
Repository contract/implementation

Phase 4
Project service/policy

Phase 5
Exceptions

Phase 6
Tests

Phase 7
Integration/regression verification

It can create the required files, but it must not move on to the next phase while discovering that the previous one requires an architectural change.

If it discovers a genuine conflict with M01/M05/M06/M07, stop and report it instead of inventing a solution.

