<?php

declare(strict_types=1);

arch('the package uses only PHP built-ins and itself')
    ->expect('BitFlag')
    ->toOnlyUse(['BitFlag'])
    ->ignoring('BitFlag\Tests');

arch('evaluation and protocol depend only on each other')
    ->expect(['BitFlag\\Evaluation', 'BitFlag\\Protocol'])
    ->toOnlyUse(['BitFlag\\Evaluation', 'BitFlag\\Protocol']);

arch('source files declare strict types')
    ->expect('BitFlag')
    ->toUseStrictTypes()
    ->ignoring('BitFlag\Tests');
