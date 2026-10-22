<?php

declare(strict_types=1);

namespace BitFlag\Evaluation;

use stdClass;

/**
 * The subject an evaluation is about: a kind, an optional key and attributes.
 *
 * A Context is never rejected on construction. {@see validate()} lists what is wrong, and the evaluator turns an
 * invalid context into an INVALID_CONTEXT result. Values are kept as given; the evaluator reads them as they are.
 */
final class Context
{
    private const KIND = '/^[a-z][a-z0-9_-]{0,63}$/D';

    /** @var list<string>|null */
    private ?array $issues = null;

    /**
     * @param  array<array-key, mixed>  $attributes
     * @param  list<string>  $knownIssues  problems found while reading raw input
     */
    public function __construct(
        public readonly string $kind,
        public readonly ?string $key = null,
        public readonly array $attributes = [],
        private readonly array $knownIssues = [],
    ) {}

    /**
     * From a PHP array: ['kind' => 'user', 'key' => 'u_1', 'attributes' => ['plan' => 'pro']]. A nested array with
     * string keys is an object and is not a valid attribute value.
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return self::fromInput($data, jsonArrays: false);
    }

    /**
     * From decoded JSON: objects are stdClass, arrays are lists. Anything that is not a well-formed context
     * gives an invalid Context.
     */
    public static function fromDecoded(mixed $data): self
    {
        return self::fromInput($data, jsonArrays: true);
    }

    /**
     * @return list<string> what is wrong with the context; empty when it is valid
     */
    public function validate(): array
    {
        if ($this->issues !== null) {
            return $this->issues;
        }

        $issues = $this->knownIssues;
        if (preg_match(self::KIND, $this->kind) !== 1) {
            $issues[] = 'kind must match ' . self::KIND;
        }
        if ($this->key !== null && (strlen($this->key) < 1 || strlen($this->key) > 256)) {
            $issues[] = 'key must be 1 to 256 bytes';
        }
        if (count($this->attributes) > 100) {
            $issues[] = 'at most 100 attributes';
        }
        foreach ($this->attributes as $name => $value) {
            $name = (string) $name;
            if (strlen($name) < 1 || strlen($name) > 128 || $name === 'kind' || $name === 'key') {
                $issues[] = 'invalid attribute name "' . $name . '"';
            }
            if (! self::validValue($value)) {
                $issues[] = 'invalid value for attribute "' . $name . '"';
            }
        }

        return $this->issues = $issues;
    }

    public function isValid(): bool
    {
        return $this->validate() === [];
    }

    private static function fromInput(mixed $data, bool $jsonArrays): self
    {
        if ($data instanceof stdClass) {
            $members = get_object_vars($data);
        } elseif (is_array($data) && ($data === [] ? ! $jsonArrays : ! array_is_list($data))) {
            $members = $data;
        } else {
            return self::invalid('a context is an object');
        }

        $issues = [];
        $kind = '';
        $key = null;
        $attributes = [];
        foreach ($members as $name => $value) {
            switch ((string) $name) {
                case 'kind':
                    if (is_string($value)) {
                        $kind = $value;
                    } else {
                        $issues[] = 'kind must be a string';
                    }
                    break;
                case 'key':
                    if (is_string($value)) {
                        $key = $value;
                    } else {
                        $issues[] = 'key must be a string';
                    }
                    break;
                case 'attributes':
                    if ($value instanceof stdClass) {
                        $attributes = get_object_vars($value);
                    } elseif (is_array($value) && ($value === [] ? ! $jsonArrays : ! array_is_list($value))) {
                        $attributes = $value;
                    } else {
                        $issues[] = 'attributes must be an object';
                    }
                    break;
                default:
                    $issues[] = 'unknown member "' . $name . '"';
            }
        }
        if (! array_key_exists('kind', $members)) {
            $issues[] = 'kind is required';
        }

        return new self($kind, $key, $attributes, $issues);
    }

    private static function invalid(string $issue): self
    {
        return new self('', null, [], [$issue]);
    }

    private static function validValue(mixed $value): bool
    {
        if ($value === null || is_bool($value)) {
            return true;
        }
        if (is_string($value)) {
            return strlen($value) <= 1024;
        }
        if (is_int($value)) {
            return true;
        }
        if (is_float($value)) {
            return is_finite($value);
        }
        if (! is_array($value) || ! array_is_list($value) || count($value) > 100) {
            return false;
        }

        $family = null;
        foreach ($value as $item) {
            $itemFamily = match (true) {
                is_string($item) => 'string',
                is_int($item), is_float($item) => 'number',
                is_bool($item) => 'boolean',
                default => null,
            };
            if ($itemFamily === null || ($family !== null && $family !== $itemFamily) || ! self::validValue($item)) {
                return false;
            }
            $family = $itemFamily;
        }

        return true;
    }
}
