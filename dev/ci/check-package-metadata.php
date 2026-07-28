<?php
/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

$moduleRoot = dirname(__DIR__, 2);
$composerPath = $moduleRoot . '/composer.json';
$modulePath = $moduleRoot . '/etc/module.xml';

$composerContents = file_get_contents($composerPath);
if ($composerContents === false) {
    throw new RuntimeException('Could not read composer.json.');
}

/** @var array<string, mixed> $composer */
$composer = json_decode($composerContents, true, 512, JSON_THROW_ON_ERROR);
$errors = [];

foreach (['name', 'description', 'type', 'version', 'license', 'require', 'autoload'] as $key) {
    if (!array_key_exists($key, $composer)) {
        $errors[] = sprintf('composer.json is missing "%s".', $key);
    }
}
if (($composer['type'] ?? null) !== 'magento2-module') {
    $errors[] = 'composer.json type must be magento2-module.';
}
if (($composer['name'] ?? null) !== 'bonlineco/module-sales-exchange') {
    $errors[] = 'composer.json package name does not match the public package.';
}
if (isset($composer['source']) || isset($composer['dist'])) {
    $errors[] = 'Marketplace packages cannot declare source or dist.';
}
$extra = is_array($composer['extra'] ?? null) ? $composer['extra'] : [];
if (isset($extra['map']) || isset($extra['magento-root-dir'])) {
    $errors[] = 'Marketplace-forbidden Composer extra mapping is present.';
}

$requires = is_array($composer['require'] ?? null) ? $composer['require'] : [];
foreach ($requires as $package => $constraint) {
    if (str_starts_with((string)$package, 'magento/')
        && trim((string)$constraint) === '*'
    ) {
        $errors[] = sprintf(
            'Magento dependency "%s" must use an explicit constraint.',
            $package
        );
    }
    if (preg_match('/\s+as\s+/i', (string)$constraint)) {
        $errors[] = sprintf(
            'Dependency "%s" cannot use a Composer inline alias.',
            $package
        );
    }
}
$devRequires = is_array($composer['require-dev'] ?? null)
    ? $composer['require-dev']
    : [];
foreach ($devRequires as $package => $constraint) {
    if (preg_match('/\s+as\s+/i', (string)$constraint)) {
        $errors[] = sprintf(
            'Development dependency "%s" cannot use a Composer inline alias.',
            $package
        );
    }
}
foreach ([
    'magento/magento-composer-installer',
    'magento/magento2-base',
    'magento/product-community-edition',
    'magento/magento2-ee-base',
    'magento/product-enterprise-edition',
] as $forbiddenPackage) {
    if (isset($requires[$forbiddenPackage])) {
        $errors[] = sprintf(
            'Marketplace-forbidden dependency "%s" is present.',
            $forbiddenPackage
        );
    }
}

$autoload = is_array($composer['autoload'] ?? null)
    ? $composer['autoload']
    : [];
$autoloadFiles = is_array($autoload['files'] ?? null)
    ? $autoload['files']
    : [];
$autoloadPsr4 = is_array($autoload['psr-4'] ?? null)
    ? $autoload['psr-4']
    : [];
if (!in_array('registration.php', $autoloadFiles, true)) {
    $errors[] = 'Composer autoload.files must include registration.php.';
}
if (($autoloadPsr4['Bonlineco\\SalesExchange\\'] ?? null) !== '') {
    $errors[] = 'Composer PSR-4 mapping does not match the module namespace.';
}

$archive = is_array($composer['archive'] ?? null)
    ? $composer['archive']
    : [];
$archiveExcludes = is_array($archive['exclude'] ?? null)
    ? $archive['exclude']
    : [];
if (!in_array('/Test', $archiveExcludes, true)) {
    $errors[] = 'Composer release archives must exclude the Test directory.';
}
foreach (['/CONTRIBUTING.md', '/SECURITY.md'] as $requiredDocumentation) {
    if (in_array($requiredDocumentation, $archiveExcludes, true)) {
        $errors[] = sprintf(
            'Composer release archives must retain %s because README links to it.',
            ltrim($requiredDocumentation, '/')
        );
    }
}
$gitAttributes = file_get_contents($moduleRoot . '/.gitattributes');
if ($gitAttributes === false) {
    $errors[] = 'Could not read .gitattributes.';
} elseif (preg_match(
    '/^\\s*\\/?Test\\s+export-ignore\\s*$/m',
    $gitAttributes
)) {
    $errors[] = 'Test cannot be export-ignore; mirrored CI path repositories need it.';
}

$moduleXml = new DOMDocument();
$moduleXml->preserveWhiteSpace = false;
if (!$moduleXml->load($modulePath, LIBXML_NONET)) {
    throw new RuntimeException('Could not parse etc/module.xml.');
}
$module = $moduleXml->getElementsByTagName('module')->item(0);
if (!$module instanceof DOMElement
    || $module->getAttribute('name') !== 'Bonlineco_SalesExchange'
) {
    $errors[] = 'etc/module.xml does not declare Bonlineco_SalesExchange.';
}

$sequencePackages = [];
foreach ($moduleXml->getElementsByTagName('sequence') as $sequence) {
    foreach ($sequence->childNodes as $dependency) {
        if (!$dependency instanceof DOMElement
            || $dependency->tagName !== 'module'
        ) {
            continue;
        }
        $package = moduleNameToPackage($dependency->getAttribute('name'));
        if ($package !== null) {
            $sequencePackages[$package] = true;
        }
    }
}

foreach (array_keys($sequencePackages) as $package) {
    if (!isset($requires[$package])) {
        $errors[] = sprintf(
            'Module sequence dependency "%s" is absent from Composer require.',
            $package
        );
    }
}
foreach (array_keys($requires) as $package) {
    if (str_starts_with((string)$package, 'magento/module-')
        && !isset($sequencePackages[$package])
    ) {
        $errors[] = sprintf(
            'Composer dependency "%s" is absent from module.xml sequence.',
            $package
        );
    }
}

$referencedPackages = [];
$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($moduleRoot, FilesystemIterator::SKIP_DOTS)
);
foreach ($files as $file) {
    if (!$file->isFile()
        || !in_array($file->getExtension(), ['php', 'phtml', 'xml'], true)
    ) {
        continue;
    }
    $relativePath = substr($file->getPathname(), strlen($moduleRoot) + 1);
    if (preg_match('#^(?:Test|dev|vendor|\.github)/#', $relativePath)) {
        continue;
    }
    $contents = file_get_contents($file->getPathname());
    if ($contents === false) {
        $errors[] = sprintf('Could not read "%s".', $relativePath);
        continue;
    }
    preg_match_all(
        '/\bMagento\\\\([A-Z][A-Za-z0-9]+)\\\\/',
        $contents,
        $namespaceMatches
    );
    foreach ($namespaceMatches[1] as $namespaceRoot) {
        $package = namespaceRootToPackage($namespaceRoot);
        if ($package !== null) {
            $referencedPackages[$package] = true;
        }
    }
    preg_match_all('/\bMagento_([A-Z][A-Za-z0-9]+)/', $contents, $moduleMatches);
    foreach ($moduleMatches[1] as $moduleSuffix) {
        $package = namespaceRootToPackage($moduleSuffix);
        if ($package !== null) {
            $referencedPackages[$package] = true;
        }
    }
}

foreach (array_keys($referencedPackages) as $package) {
    if (!isset($requires[$package])) {
        $errors[] = sprintf(
            'Production reference requires undeclared package "%s".',
            $package
        );
    }
}

if ($errors !== []) {
    throw new RuntimeException(implode("\n", array_unique($errors)));
}

fwrite(
    STDOUT,
    sprintf(
        "Composer, module sequence, and production Magento references align (%d packages).\n",
        count($referencedPackages)
    )
);

/**
 * Convert Magento_ModuleName to magento/module-module-name.
 */
function moduleNameToPackage(string $moduleName): ?string
{
    if (!str_starts_with($moduleName, 'Magento_')) {
        return null;
    }

    return namespaceRootToPackage(substr($moduleName, strlen('Magento_')));
}

/**
 * Convert a top-level Magento namespace to its Composer package.
 */
function namespaceRootToPackage(string $namespaceRoot): ?string
{
    if ($namespaceRoot === 'Framework') {
        return 'magento/framework';
    }
    if ($namespaceRoot === '') {
        return null;
    }
    $packageSuffix = strtolower(
        (string)preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '-', $namespaceRoot)
    );

    return 'magento/module-' . $packageSuffix;
}
