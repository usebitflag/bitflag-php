<?php

declare(strict_types=1);

namespace BitFlag\Protocol\Model;

enum FlagType: string
{
    case Boolean = 'boolean';
    case String = 'string';
    case Number = 'number';
    case Json = 'json';
}
