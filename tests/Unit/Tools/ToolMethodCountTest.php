<?php

declare(strict_types=1);

use Spora\Plugins\Zernio\Tools\AbstractZernioTool;
use Spora\Plugins\Zernio\ZernioPlugin;

/**
 * SonarQube's php:S1448 allows a class 20 methods, and the maintainability
 * gate fails on the 21st. The rule counts the methods a class declares
 * itself, so the guard counts the same ones.
 */
it('keeps every tool class within the method count the gate allows', function (): void {
    $classes = array_merge([AbstractZernioTool::class], (new ZernioPlugin())->tools());

    $overLimit = [];

    foreach ($classes as $class) {
        $declared = array_filter(
            (new ReflectionClass($class))->getMethods(),
            fn(ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $class,
        );

        if (count($declared) > 20) {
            $overLimit[$class] = count($declared);
        }
    }

    expect($overLimit)->toBe([]);
});
