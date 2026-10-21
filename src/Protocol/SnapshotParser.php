<?php

declare(strict_types=1);

namespace BitFlag\Protocol;

use BitFlag\Evaluation\ErrorKind;
use BitFlag\Evaluation\Internal\Values\Instant;
use BitFlag\Evaluation\Internal\Values\SemVer;
use BitFlag\Protocol\Json\CanonicalJson;
use BitFlag\Protocol\Json\JsonDecoder;
use BitFlag\Protocol\Model\Condition;
use BitFlag\Protocol\Model\ConditionGroup;
use BitFlag\Protocol\Model\EnvironmentRef;
use BitFlag\Protocol\Model\FixedServe;
use BitFlag\Protocol\Model\Flag;
use BitFlag\Protocol\Model\FlagType;
use BitFlag\Protocol\Model\GroupKind;
use BitFlag\Protocol\Model\Operator;
use BitFlag\Protocol\Model\Override;
use BitFlag\Protocol\Model\Prerequisite;
use BitFlag\Protocol\Model\Projection;
use BitFlag\Protocol\Model\RolloutServe;
use BitFlag\Protocol\Model\Rule;
use BitFlag\Protocol\Model\Segment;
use BitFlag\Protocol\Model\Serve;
use BitFlag\Protocol\Model\Slot;
use BitFlag\Protocol\Model\Snapshot;
use BitFlag\Protocol\Model\SubjectRef;
use stdClass;

/**
 * Turns snapshot JSON into a Snapshot, or rejects it. Checks everything the schema says and the rules JSON
 * Schema cannot express, except references between flags, segments and variations (see SnapshotValidator).
 */
final class SnapshotParser
{
    private const MAX_RULES = 200;

    private const MAX_OVERRIDES = 500;

    private const MAX_PREREQUISITES = 20;

    private const MAX_MEMBERS = 50;

    private const MAX_VALUES = 1000;

    private const MAX_SEGMENT_KEYS = 50;

    private const MAX_GROUP_DEPTH = 5;

    private const MAX_JSON_VALUE_BYTES = 32 * 1024;

    private const FLAG_KEY = '/^[a-z][a-z0-9_-]{0,99}$/D';

    private const VARIATION_KEY = '/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/D';

    private const CONTEXT_KIND = '/^[a-z][a-z0-9_-]{0,63}$/D';

    private const CAPABILITY = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*\.v[1-9][0-9]*$/D';

    private const ENV_ID = '/^env_[A-Za-z0-9]{1,60}$/D';

    private const ENV_KEY = '/^[a-z][a-z0-9_-]{0,63}$/D';

    private const CHECKSUM = '/^sha256:[0-9a-f]{64}$/D';

    /**
     * @throws MalformedSnapshot
     */
    public static function parse(string $json, bool $verifyChecksum = true): Snapshot
    {
        return self::fromDecoded(JsonDecoder::decode($json), $verifyChecksum);
    }

    /**
     * @param  mixed  $decoded  the result of JsonDecoder::decode
     *
     * @throws MalformedSnapshot
     */
    public static function fromDecoded(mixed $decoded, bool $verifyChecksum = true): Snapshot
    {
        $parser = new self();

        return $parser->snapshot($decoded, $verifyChecksum);
    }

    private function snapshot(mixed $root, bool $verifyChecksum): Snapshot
    {
        if (! $root instanceof stdClass) {
            throw $this->fail('', Problem::INVALID_TYPE);
        }

        $this->checkVersions($root);
        $this->rejectUnsafeNumbers($root, '');

        $m = $this->members($root, '', ['protocolVersion', 'algorithmVersion', 'environment', 'revision', 'generation', 'projection', 'publishedAt', 'requiredCapabilities', 'flags', 'segments', 'checksum']);

        $environment = $this->environment($m['environment']);
        $revision = $this->integer($m['revision'], '/revision', 0);
        $generation = $this->integer($m['generation'], '/generation', 1);
        $projectionName = $this->string($m['projection'], '/projection');
        $projection = Projection::tryFrom($projectionName) ?? throw $this->fail('/projection', Problem::INVALID_VALUE);
        $publishedAt = $this->string($m['publishedAt'], '/publishedAt');
        if (Instant::parse($publishedAt, true) === null) {
            throw $this->fail('/publishedAt', Problem::INVALID_VALUE, 'not an RFC 3339 instant in UTC');
        }
        $required = $this->capabilities($m['requiredCapabilities'], '/requiredCapabilities');
        $checksum = $this->string($m['checksum'], '/checksum');
        if (preg_match(self::CHECKSUM, $checksum) !== 1) {
            throw $this->fail('/checksum', Problem::INVALID_VALUE);
        }

        $flagsNode = $this->object($m['flags'], '/flags');
        if ($revision === 0 && get_object_vars($flagsNode) !== []) {
            throw $this->fail('/flags', Problem::EMPTY_SNAPSHOT_REQUIRED);
        }
        $flags = [];
        foreach (get_object_vars($flagsNode) as $name => $node) {
            $key = (string) $name;
            if (preg_match(self::FLAG_KEY, $key) !== 1) {
                throw $this->fail('/flags/' . $this->escape($key), Problem::INVALID_VALUE, 'invalid flag key');
            }
            $flags[$key] = $this->flag($key, $node, '/flags/' . $this->escape($key), $projection);
        }

        $segmentsNode = $this->object($m['segments'], '/segments');
        $segments = [];
        foreach (get_object_vars($segmentsNode) as $name => $node) {
            $key = (string) $name;
            if (preg_match(self::FLAG_KEY, $key) !== 1) {
                throw $this->fail('/segments/' . $this->escape($key), Problem::INVALID_VALUE, 'invalid segment key');
            }
            $segments[$key] = $this->segment($node, '/segments/' . $this->escape($key), $projection);
        }

        if ($verifyChecksum) {
            Checksum::verify($root);
        }

        return new Snapshot($environment, $revision, $generation, $projection, $publishedAt, $required, $flags, $segments, $checksum);
    }

    private function checkVersions(stdClass $root): void
    {
        if (property_exists($root, 'protocolVersion')) {
            $version = $root->protocolVersion;
            if (! (is_int($version) || is_float($version))) {
                throw $this->fail('/protocolVersion', Problem::INVALID_TYPE);
            }
            if ($version !== 1 && $version !== 1.0) {
                throw $this->fail('/protocolVersion', Problem::UNSUPPORTED_VERSION);
            }
        }
        if (property_exists($root, 'algorithmVersion')) {
            $algorithm = $root->algorithmVersion;
            if (! is_string($algorithm)) {
                throw $this->fail('/algorithmVersion', Problem::INVALID_TYPE);
            }
            if ($algorithm !== 'bucket-sha256-v1') {
                throw new MalformedSnapshot(ErrorKind::UnsupportedCapability, '/algorithmVersion', Problem::UNSUPPORTED_ALGORITHM);
            }
        }
    }

    private function rejectUnsafeNumbers(mixed $node, string $path): void
    {
        if (is_int($node) || is_float($node)) {
            if (JsonDecoder::isUnsafeNumber($node)) {
                throw $this->fail($path, Problem::UNSAFE_NUMBER);
            }

            return;
        }
        if ($node instanceof stdClass) {
            foreach (get_object_vars($node) as $name => $child) {
                $this->rejectUnsafeNumbers($child, $path . '/' . $this->escape((string) $name));
            }
        } elseif (is_array($node)) {
            foreach ($node as $index => $child) {
                $this->rejectUnsafeNumbers($child, $path . '/' . $index);
            }
        }
    }

    private function environment(mixed $node): EnvironmentRef
    {
        $m = $this->members($this->object($node, '/environment'), '/environment', ['id', 'key']);
        $id = $this->string($m['id'], '/environment/id');
        $key = $this->string($m['key'], '/environment/key');
        if (preg_match(self::ENV_ID, $id) !== 1) {
            throw $this->fail('/environment/id', Problem::INVALID_VALUE);
        }
        if (preg_match(self::ENV_KEY, $key) !== 1) {
            throw $this->fail('/environment/key', Problem::INVALID_VALUE);
        }

        return new EnvironmentRef($id, $key);
    }

    /**
     * @return list<string>
     */
    private function capabilities(mixed $node, string $path): array
    {
        $items = $this->list($node, $path);
        $out = [];
        foreach ($items as $index => $item) {
            $name = $this->string($item, $path . '/' . $index);
            if (preg_match(self::CAPABILITY, $name) !== 1 || in_array($name, $out, true)) {
                throw $this->fail($path . '/' . $index, Problem::INVALID_VALUE, 'invalid or repeated capability');
            }
            $out[] = $name;
        }

        return $out;
    }

    private function flag(string $key, mixed $node, string $path, Projection $projection): Flag
    {
        $m = $this->members(
            $this->object($node, $path),
            $path,
            ['type', 'enabled', 'variations', 'offVariation', 'defaultVariation', 'prerequisites', 'overrides', 'rules', 'requires'],
            optional: ['requires'],
        );

        $typeName = $this->string($m['type'], $path . '/type');
        $type = FlagType::tryFrom($typeName) ?? throw $this->fail($path . '/type', Problem::INVALID_VALUE);
        $enabled = $this->boolean($m['enabled'], $path . '/enabled');
        $variations = $this->variations($m['variations'], $path . '/variations', $type);
        $off = $this->variationKey($m['offVariation'], $path . '/offVariation');
        $default = $this->variationKey($m['defaultVariation'], $path . '/defaultVariation');
        $requires = array_key_exists('requires', $m) ? $this->capabilities($m['requires'], $path . '/requires') : [];

        $prerequisites = [];
        $prerequisiteNodes = $this->list($m['prerequisites'], $path . '/prerequisites');
        if (count($prerequisiteNodes) > self::MAX_PREREQUISITES) {
            throw $this->fail($path . '/prerequisites', Problem::LIMIT_EXCEEDED);
        }
        foreach ($prerequisiteNodes as $index => $item) {
            $prerequisites[] = $this->prerequisite($item, $path . '/prerequisites/' . $index);
        }

        $overrides = [];
        $overrideNodes = $this->list($m['overrides'], $path . '/overrides');
        if (count($overrideNodes) > self::MAX_OVERRIDES) {
            throw $this->fail($path . '/overrides', Problem::LIMIT_EXCEEDED);
        }
        if ($projection === Projection::Public && $overrideNodes !== []) {
            throw $this->fail($path . '/overrides', Problem::PROJECTION_VIOLATION);
        }
        $seen = [];
        foreach ($overrideNodes as $index => $item) {
            $override = $this->override($item, $path . '/overrides/' . $index);
            $identity = strlen($override->kind) . ':' . $override->kind . $override->key;
            if (isset($seen[$identity])) {
                throw $this->fail($path . '/overrides/' . $index, Problem::DUPLICATE_OVERRIDE);
            }
            $seen[$identity] = true;
            $overrides[] = $override;
        }

        $rules = [];
        $ruleNodes = $this->list($m['rules'], $path . '/rules');
        if (count($ruleNodes) > self::MAX_RULES) {
            throw $this->fail($path . '/rules', Problem::LIMIT_EXCEEDED);
        }
        foreach ($ruleNodes as $index => $item) {
            $rules[] = $this->rule($item, $path . '/rules/' . $index);
        }

        return new Flag($key, $type, $enabled, $variations, $off, $default, $requires, $prerequisites, $overrides, $rules);
    }

    /**
     * @return array<string, mixed>
     */
    private function variations(mixed $node, string $path, FlagType $type): array
    {
        $object = $this->object($node, $path);
        $members = get_object_vars($object);
        if ($members === []) {
            throw $this->fail($path, Problem::INVALID_VALUE, 'a flag needs at least one variation');
        }

        $out = [];
        foreach ($members as $name => $value) {
            $key = (string) $name;
            $valuePath = $path . '/' . $this->escape($key);
            if (preg_match(self::VARIATION_KEY, $key) !== 1) {
                throw $this->fail($valuePath, Problem::INVALID_VALUE, 'invalid variation key');
            }
            $valid = match ($type) {
                FlagType::Boolean => is_bool($value),
                FlagType::String => is_string($value),
                FlagType::Number => is_int($value) || is_float($value),
                FlagType::Json => true,
            };
            if (! $valid) {
                throw $this->fail($valuePath, Problem::INVALID_TYPE, 'the value does not match the flag type');
            }
            if ($type === FlagType::Json && strlen(CanonicalJson::encode($value)) > self::MAX_JSON_VALUE_BYTES) {
                throw $this->fail($valuePath, Problem::LIMIT_EXCEEDED, 'a json value is at most 32 KB');
            }
            $out[$key] = $value;
        }

        return $out;
    }

    private function prerequisite(mixed $node, string $path): Prerequisite
    {
        $m = $this->members($this->object($node, $path), $path, ['flagKey', 'variations']);
        $flagKey = $this->string($m['flagKey'], $path . '/flagKey');
        if (preg_match(self::FLAG_KEY, $flagKey) !== 1) {
            throw $this->fail($path . '/flagKey', Problem::INVALID_VALUE);
        }
        $items = $this->list($m['variations'], $path . '/variations');
        if ($items === []) {
            throw $this->fail($path . '/variations', Problem::INVALID_VALUE);
        }
        $variations = [];
        foreach ($items as $index => $item) {
            $variation = $this->variationKey($item, $path . '/variations/' . $index);
            if (in_array($variation, $variations, true)) {
                throw $this->fail($path . '/variations/' . $index, Problem::INVALID_VALUE, 'repeated variation');
            }
            $variations[] = $variation;
        }

        return new Prerequisite($flagKey, $variations);
    }

    private function override(mixed $node, string $path): Override
    {
        $m = $this->members($this->object($node, $path), $path, ['kind', 'key', 'variation', 'expiresAt']);
        $kind = $this->string($m['kind'], $path . '/kind');
        if (preg_match(self::CONTEXT_KIND, $kind) !== 1) {
            throw $this->fail($path . '/kind', Problem::INVALID_VALUE);
        }
        $key = $this->string($m['key'], $path . '/key');
        if (strlen($key) < 1 || strlen($key) > 256) {
            throw $this->fail($path . '/key', Problem::INVALID_VALUE, 'a key is 1 to 256 bytes');
        }
        $variation = $this->variationKey($m['variation'], $path . '/variation');
        $expiresAt = null;
        $expiresAtMs = null;
        if ($m['expiresAt'] !== null) {
            $expiresAt = $this->string($m['expiresAt'], $path . '/expiresAt');
            $expiresAtMs = Instant::parse($expiresAt) ?? throw $this->fail($path . '/expiresAt', Problem::INVALID_VALUE, 'not an RFC 3339 instant');
        }

        return new Override($kind, $key, $variation, $expiresAtMs, $expiresAt);
    }

    private function rule(mixed $node, string $path): Rule
    {
        $m = $this->members($this->object($node, $path), $path, ['id', 'enabled', 'match', 'serve'], optional: ['enabled']);
        $id = $this->string($m['id'], $path . '/id');
        $length = preg_match_all('/./su', $id);
        if ($length < 1 || $length > 64) {
            throw $this->fail($path . '/id', Problem::INVALID_VALUE, 'a rule ID is 1 to 64 characters');
        }
        $enabled = array_key_exists('enabled', $m) ? $this->boolean($m['enabled'], $path . '/enabled') : true;

        return new Rule($id, $enabled, $this->group($m['match'], $path . '/match', 1), $this->serve($m['serve'], $path . '/serve'));
    }

    private function group(mixed $node, string $path, int $depth): ConditionGroup
    {
        $object = $this->object($node, $path);
        if ($depth > self::MAX_GROUP_DEPTH) {
            throw $this->fail($path, Problem::LIMIT_EXCEEDED, 'groups nest at most 5 levels');
        }

        $members = get_object_vars($object);
        $kinds = [];
        foreach (array_keys($members) as $name) {
            $name = (string) $name;
            if (! in_array($name, ['all', 'any'], true)) {
                throw $this->fail($path . '/' . $this->escape($name), Problem::UNKNOWN_FIELD);
            }
            $kinds[] = $name;
        }
        if ($kinds === []) {
            throw $this->fail($path, Problem::MISSING_FIELD, 'a group has all or any');
        }
        if (count($kinds) > 1) {
            throw $this->fail($path, Problem::INVALID_VALUE, 'a group has all or any, not both');
        }

        $name = $kinds[0];
        $items = $this->list($members[$name], $path . '/' . $name);
        if (count($items) > self::MAX_MEMBERS) {
            throw $this->fail($path . '/' . $name, Problem::LIMIT_EXCEEDED, 'a group has at most 50 members');
        }

        $out = [];
        foreach ($items as $index => $item) {
            $itemPath = $path . '/' . $name . '/' . $index;
            $itemObject = $this->object($item, $itemPath);
            $out[] = (property_exists($itemObject, 'all') || property_exists($itemObject, 'any'))
                ? $this->group($itemObject, $itemPath, $depth + 1)
                : $this->condition($itemObject, $itemPath);
        }

        return new ConditionGroup(GroupKind::from($name), $out);
    }

    private function condition(stdClass $node, string $path): Condition
    {
        $m = $this->members($node, $path, ['attribute', 'op', 'values', 'ignoreCase'], optional: ['attribute', 'values', 'ignoreCase']);
        if (! array_key_exists('op', $m)) {
            throw $this->fail($path, Problem::MISSING_FIELD, 'a condition has an operator');
        }
        $operator = Operator::tryFrom($this->string($m['op'], $path . '/op')) ?? throw $this->fail($path . '/op', Problem::INVALID_VALUE, 'unknown operator');

        $attribute = null;
        if ($operator->isSegment()) {
            if (array_key_exists('attribute', $m)) {
                throw $this->fail($path . '/attribute', Problem::INVALID_VALUE, 'a segment condition has no attribute');
            }
        } else {
            if (! array_key_exists('attribute', $m)) {
                throw $this->fail($path, Problem::MISSING_FIELD, 'a condition names an attribute');
            }
            $attribute = $this->string($m['attribute'], $path . '/attribute');
            if (strlen($attribute) < 1 || strlen($attribute) > 128) {
                throw $this->fail($path . '/attribute', Problem::INVALID_VALUE, 'an attribute name is 1 to 128 bytes');
            }
        }
        $ignoreCase = array_key_exists('ignoreCase', $m) ? $this->boolean($m['ignoreCase'], $path . '/ignoreCase') : false;

        if ($operator->isExistence()) {
            if (array_key_exists('values', $m)) {
                throw $this->fail($path . '/values', Problem::INVALID_VALUE, 'exists and not_exists take no values');
            }

            return new Condition($operator, $attribute, [], $ignoreCase);
        }
        if (! array_key_exists('values', $m)) {
            throw $this->fail($path, Problem::MISSING_FIELD, 'a condition lists values');
        }

        return new Condition($operator, $attribute, $this->conditionValues($operator, $m['values'], $path . '/values'), $ignoreCase);
    }

    /**
     * @return list<string|int|float|bool>
     */
    private function conditionValues(Operator $operator, mixed $node, string $path): array
    {
        $items = $this->list($node, $path);
        $limit = $operator->isSegment() ? self::MAX_SEGMENT_KEYS : self::MAX_VALUES;
        if (count($items) > $limit) {
            throw $this->fail($path, Problem::LIMIT_EXCEEDED);
        }
        if ($items === []) {
            throw $this->fail($path, Problem::INVALID_VALUE, 'a condition lists at least one value');
        }
        $single = in_array($operator, [Operator::Eq, Operator::Neq], true) || $operator->isNumeric() || $operator->isSemver() || $operator->isDate();
        if ($single && count($items) !== 1) {
            throw $this->fail($path, Problem::INVALID_VALUE, 'this operator takes exactly one value');
        }

        $out = [];
        $family = null;
        foreach ($items as $index => $item) {
            $itemPath = $path . '/' . $index;
            if (! (is_string($item) || is_int($item) || is_float($item) || is_bool($item))) {
                throw $this->fail($itemPath, Problem::INVALID_TYPE, 'a value is a string, number or boolean');
            }
            if ($operator->isNumeric()) {
                if (! (is_int($item) || is_float($item))) {
                    throw $this->fail($itemPath, Problem::INVALID_TYPE);
                }
            } elseif ($operator->isStringMatch() || $operator->isSemver() || $operator->isDate() || $operator->isSegment()) {
                if (! is_string($item)) {
                    throw $this->fail($itemPath, Problem::INVALID_TYPE);
                }
                if ($operator->isSemver() && SemVer::parse($item) === null) {
                    throw $this->fail($itemPath, Problem::INVALID_VALUE, 'not a valid SemVer');
                }
                if ($operator->isDate() && Instant::parse($item) === null) {
                    throw $this->fail($itemPath, Problem::INVALID_VALUE, 'not an RFC 3339 instant');
                }
                if ($operator->isSegment() && preg_match(self::FLAG_KEY, $item) !== 1) {
                    throw $this->fail($itemPath, Problem::INVALID_VALUE, 'not a segment key');
                }
            } elseif ($operator === Operator::In || $operator === Operator::NotIn) {
                if (is_bool($item)) {
                    throw $this->fail($itemPath, Problem::INVALID_TYPE);
                }
                $itemFamily = is_string($item) ? 'string' : 'number';
                if ($family !== null && $family !== $itemFamily) {
                    throw $this->fail($itemPath, Problem::INVALID_TYPE, 'the values of in and not_in have one type');
                }
                $family = $itemFamily;
            }
            $out[] = $item;
        }

        return $out;
    }

    private function serve(mixed $node, string $path): Serve
    {
        $object = $this->object($node, $path);
        $members = [];
        foreach (get_object_vars($object) as $name => $value) {
            $name = (string) $name;
            if (! in_array($name, ['variation', 'rollout'], true)) {
                throw $this->fail($path . '/' . $this->escape($name), Problem::UNKNOWN_FIELD);
            }
            $members[$name] = $value;
        }
        if (count($members) === 0) {
            throw $this->fail($path, Problem::MISSING_FIELD, 'serve has variation or rollout');
        }
        if (count($members) > 1) {
            throw $this->fail($path, Problem::INVALID_VALUE, 'serve has variation or rollout, not both');
        }
        if (array_key_exists('variation', $members)) {
            return new FixedServe($this->variationKey($members['variation'], $path . '/variation'));
        }

        return $this->rollout($members['rollout'], $path . '/rollout');
    }

    private function rollout(mixed $node, string $path): RolloutServe
    {
        $m = $this->members(
            $this->object($node, $path),
            $path,
            ['bucketBy', 'salt', 'slots', 'remainderVariation', 'allocationId', 'experimentId'],
            optional: ['remainderVariation', 'allocationId', 'experimentId'],
        );

        $bucketBy = $this->string($m['bucketBy'], $path . '/bucketBy');
        if (strlen($bucketBy) < 1 || strlen($bucketBy) > 128) {
            throw $this->fail($path . '/bucketBy', Problem::INVALID_VALUE);
        }
        $salt = $this->string($m['salt'], $path . '/salt');
        if (strlen($salt) < 1 || strlen($salt) > 128) {
            throw $this->fail($path . '/salt', Problem::INVALID_VALUE, 'a salt is 1 to 128 bytes');
        }

        $slotNodes = $this->list($m['slots'], $path . '/slots');
        if ($slotNodes === []) {
            throw $this->fail($path . '/slots', Problem::INVALID_VALUE, 'a rollout has at least one slot');
        }
        $slots = [];
        $total = 0;
        foreach ($slotNodes as $index => $slotNode) {
            $slotPath = $path . '/slots/' . $index;
            $sm = $this->members($this->object($slotNode, $slotPath), $slotPath, ['variation', 'weight']);
            $weight = $this->integer($sm['weight'], $slotPath . '/weight', 0, 100000);
            $slots[] = new Slot($this->variationKey($sm['variation'], $slotPath . '/variation'), $weight);
            $total += $weight;
        }

        $remainder = array_key_exists('remainderVariation', $m) ? $this->variationKey($m['remainderVariation'], $path . '/remainderVariation') : null;
        $allocationId = array_key_exists('allocationId', $m) ? $this->identifier($m['allocationId'], $path . '/allocationId') : null;
        $experimentId = array_key_exists('experimentId', $m) ? $this->identifier($m['experimentId'], $path . '/experimentId') : null;
        if (($allocationId === null) !== ($experimentId === null)) {
            throw $this->fail($path, Problem::MISSING_FIELD, 'allocationId and experimentId come together');
        }
        if ($total > 100000) {
            throw $this->fail($path . '/slots', Problem::WEIGHT_TOTAL_EXCEEDED);
        }
        if ($total < 100000 && $remainder === null) {
            throw $this->fail($path, Problem::MISSING_REMAINDER);
        }

        return new RolloutServe($bucketBy, $salt, $slots, $remainder, $allocationId, $experimentId);
    }

    private function segment(mixed $node, string $path, Projection $projection): Segment
    {
        $m = $this->members($this->object($node, $path), $path, ['version', 'match', 'include', 'exclude'], optional: ['include', 'exclude']);
        $lists = ['include' => [], 'exclude' => []];
        foreach (['include', 'exclude'] as $name) {
            if (! array_key_exists($name, $m)) {
                continue;
            }
            if ($projection === Projection::Public) {
                throw $this->fail($path . '/' . $name, Problem::PROJECTION_VIOLATION);
            }
            foreach ($this->list($m[$name], $path . '/' . $name) as $index => $item) {
                $lists[$name][] = $this->subject($item, $path . '/' . $name . '/' . $index);
            }
        }

        return new Segment($this->integer($m['version'], $path . '/version', 1), $this->group($m['match'], $path . '/match', 1), $lists['include'], $lists['exclude']);
    }

    private function subject(mixed $node, string $path): SubjectRef
    {
        $m = $this->members($this->object($node, $path), $path, ['kind', 'key']);
        $kind = $this->string($m['kind'], $path . '/kind');
        if (preg_match(self::CONTEXT_KIND, $kind) !== 1) {
            throw $this->fail($path . '/kind', Problem::INVALID_VALUE);
        }
        $key = $this->string($m['key'], $path . '/key');
        if (strlen($key) < 1 || strlen($key) > 256) {
            throw $this->fail($path . '/key', Problem::INVALID_VALUE);
        }

        return new SubjectRef($kind, $key);
    }

    // Primitive readers

    /**
     * The members of an object, checked against the allowed names and the required ones.
     *
     * @param  list<string>  $allowed
     * @param  list<string>  $optional
     * @return array<string, mixed>
     */
    private function members(stdClass $object, string $path, array $allowed, array $optional = []): array
    {
        $members = [];
        foreach (get_object_vars($object) as $name => $value) {
            $key = (string) $name;
            if (! in_array($key, $allowed, true)) {
                throw $this->fail($path . '/' . $this->escape($key), Problem::UNKNOWN_FIELD);
            }
            $members[$key] = $value;
        }
        foreach ($allowed as $name) {
            if (! array_key_exists($name, $members) && ! in_array($name, $optional, true)) {
                throw $this->fail($path, Problem::MISSING_FIELD, 'missing "' . $name . '"');
            }
        }

        return $members;
    }

    private function object(mixed $node, string $path): stdClass
    {
        return $node instanceof stdClass ? $node : throw $this->fail($path, Problem::INVALID_TYPE, 'expected an object');
    }

    /**
     * @return list<mixed>
     */
    private function list(mixed $node, string $path): array
    {
        return is_array($node) ? array_values($node) : throw $this->fail($path, Problem::INVALID_TYPE, 'expected an array');
    }

    private function string(mixed $node, string $path): string
    {
        return is_string($node) ? $node : throw $this->fail($path, Problem::INVALID_TYPE, 'expected a string');
    }

    private function boolean(mixed $node, string $path): bool
    {
        return is_bool($node) ? $node : throw $this->fail($path, Problem::INVALID_TYPE, 'expected a boolean');
    }

    private function integer(mixed $node, string $path, int $min, ?int $max = null): int
    {
        if (is_float($node) && is_finite($node) && floor($node) === $node && abs($node) <= JsonDecoder::MAX_SAFE_INTEGER) {
            $node = (int) $node;
        }
        if (! is_int($node)) {
            throw $this->fail($path, Problem::INVALID_TYPE, 'expected an integer');
        }
        if ($node < $min || ($max !== null && $node > $max)) {
            throw $this->fail($path, Problem::INVALID_VALUE, 'out of range');
        }

        return $node;
    }

    private function variationKey(mixed $node, string $path): string
    {
        $key = $this->string($node, $path);

        return preg_match(self::VARIATION_KEY, $key) === 1 ? $key : throw $this->fail($path, Problem::INVALID_VALUE, 'invalid variation key');
    }

    private function identifier(mixed $node, string $path): string
    {
        $value = $this->string($node, $path);
        $length = preg_match_all('/./su', $value);

        return $length >= 1 && $length <= 64 ? $value : throw $this->fail($path, Problem::INVALID_VALUE, 'an ID is 1 to 64 characters');
    }

    private function escape(string $name): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $name);
    }

    private function fail(string $path, string $problem, string $detail = ''): MalformedSnapshot
    {
        return MalformedSnapshot::malformed($path, $problem, $detail);
    }
}
