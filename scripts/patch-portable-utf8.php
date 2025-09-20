<?php

$target = __DIR__.'/../vendor/voku/portable-utf8/src/voku/helper/UTF8.php';
if (! is_file($target)) {
    return;
}

$contents = file_get_contents($target);
if ($contents === false) {
    fwrite(STDERR, "Unable to read portable-utf8 file\n");
    exit(1);
}

$patterns = [
    '/(?<!\?)int\s+(&?\$\w+)\s*=\s*null/' => '?int $1 = null',
    '/(?<!\?)string\s+(&?\$\w+)\s*=\s*null/' => '?string $1 = null',
    '/(?<!\?)array\s+(&?\$\w+)\s*=\s*null/' => '?array $1 = null',
    '/(?<!\?)float\s+(&?\$\w+)\s*=\s*null/' => '?float $1 = null',
    '/(?<!\?)bool\s+(&?\$\w+)\s*=\s*null/' => '?bool $1 = null',
];

$updated = preg_replace(array_keys($patterns), array_values($patterns), $contents);
if ($updated === null) {
    fwrite(STDERR, "Failed to patch portable-utf8 file\n");
    exit(1);
}

if ($updated !== $contents) {
    file_put_contents($target, $updated);
}
