<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

$testRoot = $argv[1] ?? dirname(__DIR__, 2) . '/Test';

if (!is_dir($testRoot)) {
    throw new RuntimeException(
        sprintf('Test directory does not exist: %s', $testRoot)
    );
}

$errors = [];
$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($testRoot, FilesystemIterator::SKIP_DOTS)
);

foreach ($files as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }

    $source = file_get_contents($file->getPathname());
    if ($source === false) {
        $errors[] = sprintf('%s: could not read file', $file->getPathname());
        continue;
    }

    foreach ([
        '/->addMethods\s*\(/' =>
            'uses MockBuilder::addMethods(), which was removed in PHPUnit 12',
        '#PHPUnit\\\\Framework\\\\MockObject\\\\Generator\\\\Generator#' =>
            'uses PHPUnit private mock-generator internals',
        '/\b(?:self|static)::(?:isArray|isCallable|isType)\s*\(/' =>
            'uses a PHPUnit-version-specific convenience constraint',
    ] as $pattern => $message) {
        if (preg_match($pattern, $source)) {
            $errors[] = sprintf('%s: %s', $file->getPathname(), $message);
        }
    }

    preg_match_all(
        '/@dataProvider\s+([A-Za-z_][A-Za-z0-9_]*)/',
        $source,
        $annotations,
        PREG_OFFSET_CAPTURE
    );
    foreach ($annotations[1] as [$provider, $offset]) {
        $functionOffset = strpos($source, 'public function', $offset);
        if ($functionOffset === false) {
            $errors[] = sprintf(
                '%s: @dataProvider %s is not attached to a public test method',
                $file->getPathname(),
                $provider
            );
            continue;
        }

        $metadata = substr($source, $offset, $functionOffset - $offset);
        $attribute = sprintf(
            '/#\[\s*DataProvider\(\s*([\\' . "'" . '\"])%s\1\s*\)\s*\]/',
            preg_quote($provider, '/')
        );
        if (!preg_match($attribute, $metadata)) {
            $errors[] = sprintf(
                '%s: @dataProvider %s is missing #[DataProvider(%s)]',
                $file->getPathname(),
                $provider,
                var_export($provider, true)
            );
        }
    }

    preg_match_all(
        '/#\[\s*DataProvider\(\s*([\\' . "'" . '\"])([A-Za-z_][A-Za-z0-9_]*)\1\s*\)\s*\]/',
        $source,
        $attributes,
        PREG_OFFSET_CAPTURE
    );
    foreach ($attributes[2] as [$provider, $offset]) {
        $prefix = substr($source, 0, $offset);
        $docOffset = strrpos($prefix, '/**');
        $functionOffset = strpos($source, 'public function', $offset);
        if ($docOffset === false || $functionOffset === false) {
            $errors[] = sprintf(
                '%s: #[DataProvider(%s)] is not attached to a documented public test method',
                $file->getPathname(),
                var_export($provider, true)
            );
            continue;
        }

        $metadata = substr($source, $docOffset, $functionOffset - $docOffset);
        if (!preg_match(
            '/@dataProvider\s+' . preg_quote($provider, '/') . '\b/',
            $metadata
        )) {
            $errors[] = sprintf(
                '%s: #[DataProvider(%s)] is missing the PHPUnit 9 annotation',
                $file->getPathname(),
                var_export($provider, true)
            );
        }
    }
}

if ($errors !== []) {
    throw new RuntimeException(implode("\n", $errors));
}

fwrite(
    STDOUT,
    "Test metadata and public mock APIs support PHPUnit 9, 10, and 12.\n"
);
