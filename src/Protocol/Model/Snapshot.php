<?php

declare(strict_types=1);

namespace BitFlag\Protocol\Model;

final readonly class Snapshot
{
    /**
     * @param  list<string>  $requiredCapabilities
     * @param  array<string, Flag>  $flags
     * @param  array<string, Segment>  $segments
     */
    public function __construct(
        public EnvironmentRef $environment,
        public int $revision,
        public int $generation,
        public Projection $projection,
        public string $publishedAt,
        public array $requiredCapabilities,
        public array $flags,
        public array $segments,
        public string $checksum,
    ) {}

    public function flag(string $key): ?Flag
    {
        return $this->flags[$key] ?? null;
    }

    public function segment(string $key): ?Segment
    {
        return $this->segments[$key] ?? null;
    }
}
