# CLAUDE.md

## Development Setup

- **No build system, no tests, no CI/CD** — this is a focused 2-file library
- **PHP requirement:** >=7.2.0 <=8.4 — do not use features unavailable in PHP 7.2 (no typed properties, no union types, no named arguments, no match expressions, no arrow functions, no null-safe operator)

## Code Conventions

- **Indentation:** Tabs
- **Both classes are `final`** — never remove this
- **Error suppression:** `@` operator is used intentionally on file/exec operations — follow this pattern
- **Return NULL for not-found** — not exceptions
- **Cross-platform paths:** Always use `DIRECTORY_SEPARATOR` and `PATH_SEPARATOR` constants
- **PHPDoc:** All public members have `@since` tags; use `@since 1.0.0` for existing API surface
- **Version format:** major.minor (e.g., "8.3"), not just major
