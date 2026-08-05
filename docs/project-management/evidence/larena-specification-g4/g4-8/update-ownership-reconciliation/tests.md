# Test Contract

Required package scenarios:

- trusted entitlement, manifest, artifact, staging and operation success;
- access denial before planning;
- capability denial before staging;
- invalid manifest and invalid artifact denial;
- logical filesystem staging denial;
- absent recovery boundary denial;
- injected operation failure stops later operations;
- confirmed rollback creates a recoverable state;
- retry resumes without repeating completed operations;
- unknown-cycle retry denial;
- idempotent replay of an existing cycle.
- stable generated plan identity when the underlying hash starts with a digit.

Exact commands, counts, revisions and hashes remain pending the sealed package
revision and will be retained by the canonical G4.8 evidence carrier.
