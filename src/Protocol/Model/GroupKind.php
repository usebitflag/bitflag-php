<?php

declare(strict_types=1);

namespace BitFlag\Protocol\Model;

enum GroupKind: string
{
    case All = 'all';
    case Any = 'any';
}
