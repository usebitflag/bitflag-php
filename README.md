# bitflag-php

The pure PHP evaluation core (`src/Evaluation`, `src/Protocol`), later the PHP SDK client. Namespace `BitFlag\`. Uses only PHP built-ins.

Part of BitFlag. Technical documentation lives in [usebitflag/bitflag](https://github.com/usebitflag/bitflag/tree/main/docs).

## Development

Requires PHP 8.3 or newer.

```bash
composer install
vendor/bin/pint --test
vendor/bin/phpstan analyse
vendor/bin/pest
```

## Conformance

The evaluator is tested against the fixtures of a pinned `bitflag-protocol` release (`protocol.lock.json`).

```bash
scripts/fetch-protocol-fixtures.sh    # downloads, verifies the checksum and extracts to build/protocol/<version>
vendor/bin/pest                       # unit, architecture and conformance tests
composer conformance report.json      # writes what this evaluator did for every case, to compare with another one
```

Set `BITFLAG_PROTOCOL_DIR` to an extracted release to use it instead of `build/protocol/<version>`.
