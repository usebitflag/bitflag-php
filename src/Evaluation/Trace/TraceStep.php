<?php

declare(strict_types=1);

namespace BitFlag\Evaluation\Trace;

/**
 * One step of an evaluation: capability, off, prerequisite, override, rule or default.
 */
final readonly class TraceStep
{
    /**
     * @param  list<string>|null  $prerequisitePath
     * @param  list<ConditionTrace>|null  $conditions
     * @param  array{variation: string, start: int, end: int}|null  $slot
     */
    public function __construct(
        public string $step,
        public string $outcome,
        public ?string $ruleId = null,
        public ?array $prerequisitePath = null,
        public ?array $conditions = null,
        public ?int $bucket = null,
        public ?array $slot = null,
        public ?string $overrideExpiresAt = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = ['step' => $this->step, 'outcome' => $this->outcome];
        if ($this->ruleId !== null) {
            $out['ruleId'] = $this->ruleId;
        }
        if ($this->prerequisitePath !== null) {
            $out['prerequisitePath'] = $this->prerequisitePath;
        }
        if ($this->conditions !== null) {
            $out['conditions'] = array_map(static fn(ConditionTrace $c): array => $c->toArray(), $this->conditions);
        }
        if ($this->bucket !== null) {
            $out['bucket'] = $this->bucket;
        }
        if ($this->slot !== null) {
            $out['slot'] = $this->slot;
        }
        if ($this->overrideExpiresAt !== null) {
            $out['overrideExpiresAt'] = $this->overrideExpiresAt;
        }

        return $out;
    }
}
