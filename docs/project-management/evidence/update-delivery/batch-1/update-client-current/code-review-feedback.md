# Code Review Feedback

Accepted clarifications:

- Contract tests use explicit runtime exceptions rather than PHP `assert()`.
- `UpdatePlan::isValid()` rejects first-batch site mutation.
- `UpdateAdminDiagnostics::isSafeForDisplay()` rejects secret-bearing diagnostics and install authorization.

Deferred to implementation planning:

- Concrete signed manifest schema and signature verification adapter.
- Artifact download, local staging, extraction and cleanup strategy.
- Backup package integration for rollback snapshots.
- Installer orchestration state machine and approval gates.

No canonical graph/spec promotion is required from this batch.
