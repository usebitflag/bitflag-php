# PHP Rules

## Code style

- Pint for formatting; PHPStan at level max. Four spaces for indentation.
- `declare(strict_types=1);` in every file. Classes are `final` unless designed for extension.
- Keep the public API small. Anything not meant for consumers lives in an `Internal` namespace
  (`BitFlag\Evaluation\Internal`, `BitFlag\Protocol\Internal`); the application must never import those.
- 64-bit arithmetic for bucketing must be exact on 64-bit PHP; do not rely on floats.

## Dependencies

- `composer.json` requires `php` only. An architecture test asserts `BitFlag\` uses only PHP built-ins.
- Adding a runtime dependency needs an explicit decision recorded in the `bitflag` decision register.

## Testing

- Pest. Fixtures come from a pinned `bitflag-protocol` release; verify its checksum, never read a floating `main`.
- CI runs the supported PHP versions with both `--prefer-lowest` and highest dependencies.
- Add a fixture to `bitflag-protocol` first when behavior changes, then make this repository pass it.
