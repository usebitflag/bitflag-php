<?php

declare(strict_types=1);

namespace BitFlag\Protocol;

use BitFlag\Protocol\Model\ConditionGroup;
use BitFlag\Protocol\Model\FixedServe;
use BitFlag\Protocol\Model\Flag;
use BitFlag\Protocol\Model\RolloutServe;
use BitFlag\Protocol\Model\Snapshot;

/**
 * Finds the problems a parsed snapshot can have across its own parts: references that do not resolve,
 * prerequisite and segment cycles, a prerequisite chain that is too deep, a capability list that is not the union
 * of the flags' needs, and salts shared by unrelated rollouts.
 */
final class SnapshotValidator
{
    /** The longest chain of prerequisite links the evaluator accepts. */
    public const MAX_PREREQUISITE_DEPTH = 10;

    /** @var list<ValidationIssue> */
    private array $issues = [];

    /**
     * @return list<ValidationIssue>
     */
    public static function validate(Snapshot $snapshot): array
    {
        $validator = new self();
        $validator->run($snapshot);

        return $validator->issues;
    }

    private function run(Snapshot $snapshot): void
    {
        foreach ($snapshot->flags as $key => $flag) {
            $this->flagReferences($snapshot, (string) $key, $flag);
        }
        foreach ($snapshot->segments as $key => $segment) {
            $this->group($snapshot, $segment->match, '/segments/' . $this->escape((string) $key) . '/match');
        }

        $this->prerequisiteGraph($snapshot);
        $this->segmentGraph($snapshot);
        $this->capabilities($snapshot);
        $this->salts($snapshot);
    }

    private function flagReferences(Snapshot $snapshot, string $key, Flag $flag): void
    {
        $base = '/flags/' . $this->escape($key);
        $this->variation($flag, $flag->offVariation, $base . '/offVariation');
        $this->variation($flag, $flag->defaultVariation, $base . '/defaultVariation');

        foreach ($flag->prerequisites as $index => $prerequisite) {
            $path = $base . '/prerequisites/' . $index;
            $target = $snapshot->flag($prerequisite->flagKey);
            if ($target === null) {
                $this->add(ValidationIssue::DANGLING_PREREQUISITE, $path . '/flagKey', 'The prerequisite flag "' . $prerequisite->flagKey . '" is not in the snapshot.');

                continue;
            }
            foreach ($prerequisite->variations as $i => $variation) {
                $this->variation($target, $variation, $path . '/variations/' . $i);
            }
        }
        foreach ($flag->overrides as $index => $override) {
            $this->variation($flag, $override->variation, $base . '/overrides/' . $index . '/variation');
        }
        foreach ($flag->rules as $index => $rule) {
            $path = $base . '/rules/' . $index;
            $this->group($snapshot, $rule->match, $path . '/match');
            if ($rule->serve instanceof FixedServe) {
                $this->variation($flag, $rule->serve->variation, $path . '/serve/variation');
            } elseif ($rule->serve instanceof RolloutServe) {
                foreach ($rule->serve->slots as $i => $slot) {
                    $this->variation($flag, $slot->variation, $path . '/serve/rollout/slots/' . $i . '/variation');
                }
                if ($rule->serve->remainderVariation !== null) {
                    $this->variation($flag, $rule->serve->remainderVariation, $path . '/serve/rollout/remainderVariation');
                }
            }
        }
    }

    private function variation(Flag $flag, string $variation, string $path): void
    {
        if (! array_key_exists($variation, $flag->variations)) {
            $this->add(ValidationIssue::DANGLING_VARIATION, $path, 'The flag "' . $flag->key . '" has no variation "' . $variation . '".');
        }
    }

    private function group(Snapshot $snapshot, ConditionGroup $group, string $path): void
    {
        foreach ($group->members as $index => $member) {
            $memberPath = $path . '/' . $group->kind->value . '/' . $index;
            if ($member instanceof ConditionGroup) {
                $this->group($snapshot, $member, $memberPath);
            } elseif ($member->operator->isSegment()) {
                foreach ($member->values as $i => $segment) {
                    if ($snapshot->segment((string) $segment) === null) {
                        $this->add(ValidationIssue::DANGLING_SEGMENT, $memberPath . '/values/' . $i, 'The segment "' . $segment . '" is not in the snapshot.');
                    }
                }
            }
        }
    }

    private function prerequisiteGraph(Snapshot $snapshot): void
    {
        /** @var array<string, int> $depth longest chain of links below a flag; -1 while it is being computed */
        $depth = [];
        $reported = [];

        $visit = function (string $key) use ($snapshot, &$depth, &$reported, &$visit): int {
            if (isset($depth[$key])) {
                return $depth[$key];
            }
            $flag = $snapshot->flag($key);
            if ($flag === null) {
                return 0;
            }

            $depth[$key] = -1;
            $longest = 0;
            foreach ($flag->prerequisites as $index => $prerequisite) {
                if ($snapshot->flag($prerequisite->flagKey) === null) {
                    continue;
                }
                $below = $depth[$prerequisite->flagKey] ?? null;
                if ($below === -1) {
                    $path = '/flags/' . $this->escape($key) . '/prerequisites/' . $index;
                    if (! isset($reported[$path])) {
                        $reported[$path] = true;
                        $this->add(ValidationIssue::PREREQUISITE_CYCLE, $path, 'The prerequisite "' . $prerequisite->flagKey . '" leads back to "' . $key . '".');
                    }

                    continue;
                }
                $longest = max($longest, 1 + $visit($prerequisite->flagKey));
            }

            return $depth[$key] = $longest;
        };

        foreach (array_keys($snapshot->flags) as $key) {
            $visit((string) $key);
        }
        foreach ($depth as $key => $links) {
            if ($links > self::MAX_PREREQUISITE_DEPTH) {
                $this->add(ValidationIssue::PREREQUISITE_DEPTH, '/flags/' . $this->escape((string) $key), 'A chain of ' . $links . ' prerequisite links starts here; at most ' . self::MAX_PREREQUISITE_DEPTH . ' are allowed.');
            }
        }
    }

    private function segmentGraph(Snapshot $snapshot): void
    {
        $state = [];
        $visit = function (string $key) use ($snapshot, &$state, &$visit): void {
            $state[$key] = 1;
            $segment = $snapshot->segment($key);
            foreach ($segment === null ? [] : $this->segmentReferences($segment->match) as $referenced) {
                if ($snapshot->segment($referenced) === null) {
                    continue;
                }
                if (($state[$referenced] ?? 0) === 1) {
                    $this->add(ValidationIssue::SEGMENT_CYCLE, '/segments/' . $this->escape($key) . '/match', 'The segment "' . $key . '" leads back to "' . $referenced . '".');
                } elseif (($state[$referenced] ?? 0) === 0) {
                    $visit($referenced);
                }
            }
            $state[$key] = 2;
        };

        foreach (array_keys($snapshot->segments) as $key) {
            if (($state[(string) $key] ?? 0) === 0) {
                $visit((string) $key);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function segmentReferences(ConditionGroup $group): array
    {
        $out = [];
        foreach ($group->members as $member) {
            if ($member instanceof ConditionGroup) {
                array_push($out, ...$this->segmentReferences($member));
            } elseif ($member->operator->isSegment()) {
                foreach ($member->values as $value) {
                    $out[] = (string) $value;
                }
            }
        }

        return $out;
    }

    private function capabilities(Snapshot $snapshot): void
    {
        $union = [];
        foreach ($snapshot->flags as $flag) {
            foreach ($flag->requires as $capability) {
                $union[$capability] = true;
            }
        }
        $expected = array_keys($union);
        $stated = $snapshot->requiredCapabilities;
        sort($expected);
        sort($stated);

        if ($expected !== $stated) {
            $this->add(ValidationIssue::CAPABILITY_UNION_MISMATCH, '/requiredCapabilities', 'requiredCapabilities must be the union of the flags\' requires: [' . implode(', ', $expected) . '].');
        }
    }

    /**
     * Two rollouts that share a salt assign the same subjects to the same buckets, so they must be explicitly
     * linked, which is to say share an allocationId.
     */
    private function salts(Snapshot $snapshot): void
    {
        /** @var array<string, array{path: string, allocation: string|null}> $seen */
        $seen = [];
        foreach ($snapshot->flags as $key => $flag) {
            foreach ($flag->rules as $index => $rule) {
                if (! $rule->serve instanceof RolloutServe) {
                    continue;
                }
                $path = '/flags/' . $this->escape((string) $key) . '/rules/' . $index . '/serve/rollout/salt';
                $salt = $rule->serve->salt;
                $allocation = $rule->serve->allocationId;
                if (isset($seen[$salt]) && ($allocation === null || $seen[$salt]['allocation'] !== $allocation)) {
                    $this->add(ValidationIssue::DUPLICATE_SALT, $path, 'The salt is also used at ' . $seen[$salt]['path'] . '.');

                    continue;
                }
                $seen[$salt] ??= ['path' => $path, 'allocation' => $allocation];
            }
        }
    }

    private function add(string $code, string $path, string $message): void
    {
        $this->issues[] = new ValidationIssue($code, $path, $message);
    }

    private function escape(string $name): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $name);
    }
}
