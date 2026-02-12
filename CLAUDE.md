# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

PHPFinder is a PHP library that locates the PHP CLI binary from a web execution context (mod_php, CGI, FastCGI). It supports diverse hosting environments: cPanel/EasyPHP, CloudLinux, Plesk, XAMPP, MAMP, HomeBrew, WAMPServer, and generic Linux/BSD/Windows/macOS servers.

## Development Setup

- **No build system, no tests, no CI/CD** — this is a focused 2-file library
- **PHP requirement:** >=7.2.0 <=8.4 — do not use features unavailable in PHP 7.2 (no typed properties, no union types, no named arguments, no match expressions, no arrow functions, no null-safe operator)
- **Dependencies:** None beyond PHP itself
- **Autoloading:** PSR-4 under `Akeeba\PHPFinder` namespace → `src/`
- Install with `composer install` (only sets up autoloading)

## Architecture

Two `final` classes in `src/`:

- **`PHPFinder`** (`src/PHPFinder.php`) — Main discovery engine. Factory method `PHPFinder::make(?Configuration $config)`. Three public methods:
  - `getBestPath(?string $version)` — returns first validated PHP path or NULL
  - `getBestPathMeta(?string $version)` — returns path with version/CLI metadata
  - `getPossiblePaths(?string $version)` — returns all candidate paths (unvalidated)
  - Internally organized as ~20 private `paths*()` methods by search strategy (constants, OS commands, hosting-specific paths), merged in priority order

- **`Configuration`** (`src/Configuration.php`) — Fluent builder via `Configuration::make()`. 11 boolean options controlling which search strategies are enabled. Uses magic methods (`__get`, `__set`, `__call`) for fluent `->setSomething(value)` chaining.

## Code Conventions

- **Indentation:** Tabs
- **Both classes are `final`** — never remove this
- **Error suppression:** `@` operator is used intentionally on file/exec operations — follow this pattern
- **Return NULL for not-found** — not exceptions
- **Cross-platform paths:** Always use `DIRECTORY_SEPARATOR` and `PATH_SEPARATOR` constants
- **PHPDoc:** All public members have `@since` tags; use `@since 1.0.0` for existing API surface
- **Version format:** major.minor (e.g., "8.3"), not just major
