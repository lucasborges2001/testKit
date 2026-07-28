<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$coreRoot = $root . '/core/php';
$errors = [];

$assert = static function (bool $condition, string $message) use (&$errors): void {
    if (!$condition) {
        $errors[] = $message;
    }
};

$allowedDirectories = [
    'cleanup',
    'common',
    'config',
    'coverage',
    'dbprofiling',
    'discovery',
    'execution',
    'influxprofiling',
    'references',
    'reporting',
    'seeding',
    'store',
    'suites',
];
sort($allowedDirectories);

$actualDirectories = [];
foreach (new DirectoryIterator($coreRoot) as $entry) {
    if ($entry->isDot() || !$entry->isDir()) {
        continue;
    }
    $actualDirectories[] = $entry->getFilename();
}
sort($actualDirectories);

$unexpectedDirectories = array_values(array_diff($actualDirectories, $allowedDirectories));
$assert(
    $unexpectedDirectories === [],
    'core/php contains non-platform top-level directories: ' . implode(', ', $unexpectedDirectories)
);

$allowedNamespaceSegments = [
    'Cleanup',
    'Common',
    'Config',
    'Coverage',
    'DbProfiling',
    'Discovery',
    'Execution',
    'InfluxProfiling',
    'References',
    'Reporting',
    'Seeding',
    'Store',
    'Suites',
];

$knownDomainRegressionMarkers = [
    'Testkit\\Core\\Tarifa',
    "'/tarifa/",
    'tarifa-contract',
    'tarifa_evidence',
    'TarifaContractSupport',
];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($coreRoot, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
        continue;
    }

    $path = $file->getPathname();
    $relative = str_replace('\\', '/', substr($path, strlen($root) + 1));
    $contents = file_get_contents($path);
    $assert(is_string($contents), 'unable to read ' . $relative);
    if (!is_string($contents)) {
        continue;
    }

    foreach ($knownDomainRegressionMarkers as $marker) {
        $assert(
            !str_contains($contents, $marker),
            $relative . ' contains domain-specific marker ' . $marker
        );
    }

    if (preg_match('/^namespace\s+Testkit\\\\Core\\\\([^;\\\\]+)(?:\\\\|;)/m', $contents, $match) === 1) {
        $segment = (string)$match[1];
        $assert(
            in_array($segment, $allowedNamespaceSegments, true),
            $relative . ' declares non-platform namespace segment ' . $segment
        );
    }
}

$targetResolver = $coreRoot . '/suites/TargetResolver.php';
$targetResolverContents = file_get_contents($targetResolver);
$assert(is_string($targetResolverContents), 'unable to read TargetResolver.php');
if (is_string($targetResolverContents)) {
    $assert(
        !str_contains($targetResolverContents, 'putenv('),
        'TargetResolver must not mutate process environment for a target-specific filter'
    );
}

if ($errors !== []) {
    fwrite(STDERR, "Core domain boundary tests failed:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

echo "OK core domain boundary\n";
