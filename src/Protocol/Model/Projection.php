<?php

declare(strict_types=1);

namespace BitFlag\Protocol\Model;

enum Projection: string
{
    case Server = 'server';
    case Public = 'public';
}
