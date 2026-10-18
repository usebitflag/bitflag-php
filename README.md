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
