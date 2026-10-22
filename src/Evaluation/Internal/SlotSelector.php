<?php

declare(strict_types=1);

namespace BitFlag\Evaluation\Internal;

use BitFlag\Protocol\Model\Slot;

/**
 * Finds the variation whose bucket interval contains a bucket. Slot i covers [sum of earlier weights, sum
 * including its own weight); a bucket beyond the total weight gets the remainder variation.
 *
 * @internal
 */
final class SlotSelector
{
    /**
     * @param  list<Slot>  $slots
     * @return array{variation: string|null, start: int, end: int} the variation (null for the remainder) and the interval
     */
    public static function select(array $slots, int $bucket): array
    {
        $start = 0;
        foreach ($slots as $slot) {
            $end = $start + $slot->weight;
            if ($bucket >= $start && $bucket < $end) {
                return ['variation' => $slot->variation, 'start' => $start, 'end' => $end];
            }
            $start = $end;
        }

        return ['variation' => null, 'start' => $start, 'end' => 100000];
    }
}
