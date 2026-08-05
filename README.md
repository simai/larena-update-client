# Larena Update Client

Installed-site trust and safety boundary for consuming signed update data,
calculating plans, verifying artifacts, coordinating preflight and rollback, and
running ordered restart-aware update steps.

## Canonical ownership

`larena/update-client` owns the local update lifecycle. `larena/update` remains
the server-side catalog, signed-manifest and artifact-delivery authority.
Access, Core, Filesystem, Licensing, Backup and Audit retain their own
cross-package contracts; an application Root only composes adapters.

G4.8 adds a deterministic fail-closed reference runtime. It consumes the public
Access query-scope, Core operation, Filesystem logical-file and Licensing
contracts, plus package-owned Backup and Audit ports. The included in-memory
store and recording audit adapter are test/reference implementations, not a
production persistence selection.

The reference cycle proves entitlement and manifest denial, artifact staging
through Filesystem, explicit recovery preflight, ordered execution, confirmed
rollback, retry from a known failed step and idempotent replay. It does not
fetch from a network, extract a real package, mutate an installed site, run a
database migration or move a release pointer.

Canonical specifications are in `simai/larena-specs`.
