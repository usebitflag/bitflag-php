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
scripts/pin-protocol.sh <version>    # pins a published release in protocol.lock.json
vendor/bin/pest                       # unit, architecture and conformance tests
composer conformance report.json      # writes what this evaluator did for every case, to compare with another one
```

Set `BITFLAG_PROTOCOL_DIR` to an extracted release to use it instead of `build/protocol/<version>`.

## Adopting a protocol release

The evaluator never reads the protocol repository's `main`; it pins a published release in `protocol.lock.json` (version, asset and SHA-256). When a new protocol release is published:

1. `GH_TOKEN=… scripts/pin-protocol.sh <version>` writes the version, asset name and checksum of that release into the lock file. The version is the release without the `v`, for example `0.1.0-alpha.2`.
2. `GH_TOKEN=… scripts/fetch-protocol-fixtures.sh` downloads and verifies it.
3. Run the tests (`vendor/bin/pest`). New fixtures that fail point at behavior to implement or fix; add the fix in the same pull request.
4. Open a pull request with the lock change and the fixes.

Nothing else records the protocol version. Until the evaluator has adopted a release, it keeps passing the release it pins; the protocol repository's `cross-language` workflow is what compares both evaluators on a newer one.

### Access in CI

The repository is private, so CI downloads the release with the `PROTOCOL_READ_TOKEN` Actions secret: a fine-grained personal access token with read-only Contents access to `usebitflag/bitflag-protocol`. Tokens expire. When the fetch step fails with `release not found` although the release exists, the token is missing, expired or lacks access; replace the secret.

## Releasing

The application installs this package from the git repository at an exact version, so a version exists once its tag does. Tag `main` after the pull requests are merged:

```bash
git checkout main && git pull
git tag -a v0.1.0-alpha.2 -m "bitflag-php 0.1.0-alpha.2"
git push origin v0.1.0-alpha.2
```

There is no release workflow; the tag is the release. The application then raises its requirement and lock (see its development guide).
