# Smoke

Smoke evidence expected from package-local commands:

- Composer metadata validates.
- Composer autoload generation succeeds.
- Package validation accepts the batch-1 launch-context transition.
- PHP syntax lint covers `src/`, `scripts/`, `tests/` and `tools/`.
- PHPStan analyzes `src/`, `scripts/`, `tests/` and `tools/`.
- Contract tests execute through explicit runtime exceptions rather than PHP `assert()`.
- Scope check allows only launch-record files and this evidence path.
