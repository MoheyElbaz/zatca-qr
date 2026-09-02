<?php

require __DIR__ . '/bootstrap.php';

foreach (glob(__DIR__ . '/*_test.php') as $file) {
    require $file;
}

$failures = [];

foreach (TestRegistry::$tests as [$name, $body]) {
    try {
        $body();
        echo "\033[32m.\033[0m";
    } catch (Throwable $thrown) {
        echo "\033[31mF\033[0m";
        $failures[] = [$name, $thrown];
    }
}

echo "\n\n";

foreach ($failures as [$name, $thrown]) {
    printf("\033[31mFAILED\033[0m %s\n  %s\n  at %s:%d\n\n", $name, $thrown->getMessage(), $thrown->getFile(), $thrown->getLine());
}

printf(
    "%d test%s, %d assertion%s, %d failure%s\n",
    count(TestRegistry::$tests),
    count(TestRegistry::$tests) === 1 ? '' : 's',
    TestRegistry::$assertions,
    TestRegistry::$assertions === 1 ? '' : 's',
    count($failures),
    count($failures) === 1 ? '' : 's'
);

exit($failures === [] ? 0 : 1);
