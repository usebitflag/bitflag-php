<?php

declare(strict_types=1);

namespace BitFlag\Evaluation\Trace;

/**
 * One condition of a rule: where it sits, what it tested and the outcome. Never holds context values.
 */
final readonly class ConditionTrace
{
    public function __construct(
        public string $path,
        public string $operator,
        public ?string $attribute,
        public ?string $segment,
        public ConditionOutcome $outcome,
    ) {}

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $out = ['path' => $this->path, 'op' => $this->operator];
        if ($this->attribute !== null) {
            $out['attribute'] = $this->attribute;
        }
        if ($this->segment !== null) {
            $out['segment'] = $this->segment;
        }
        $out['outcome'] = $this->outcome->value;

        return $out;
    }
}
