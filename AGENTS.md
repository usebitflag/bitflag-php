# AGENTS.md

This file provides guidance for coding agents working in `bitflag-php`.

## Project Overview

`bitflag-php` is the pure PHP evaluation core (`BitFlag\` namespace) and, later, the PHP SDK client. The `bitflag`
application uses it for simulation and publication checks. It must work without Laravel or any other framework.

The contract it implements is owned by `bitflag-protocol`. It is written independently of `bitflag-js`; both must pass
the same fixtures.

Design context lives in the `bitflag` repository (`docs/evaluation-and-sdk-protocol.md`).

## Project Structure

```
bitflag-php/
├── src/
│   ├── Evaluation/   # evaluation order, operators, bucketing, typed results
│   ├── Protocol/     # snapshot parsing and validation
│   └── Client/ Transport/ Cache/ Telemetry/   # SDK client modules
├── tests/            # Pest: Unit and Arch
├── composer.json     # require: php only
├── phpstan.neon
└── pint.json
```

## Core Rules

- `src/Evaluation` and `src/Protocol` use only PHP built-ins. No network, no framework, no Composer runtime dependency.
- Evaluation never imports transport, cache, telemetry or framework classes.
- Results are typed. A caller-visible failure is a result with a reason, not an exception, unless the spec says otherwise.
- Every change goes through a pull request. See `.claude/rules/git-workflow.md`.

Detailed rules: `.claude/rules/php.md` and `.claude/rules/git-workflow.md`.

## Commands

PHP 8.3 or newer:

- `composer install`
- `composer validate --strict`
- `vendor/bin/pint --test` (use `vendor/bin/pint` to fix)
- `vendor/bin/phpstan analyse` (level max, `src` only)
- `vendor/bin/pest` (add `--group=arch` for architecture tests)
