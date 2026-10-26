<?php

/** Fixed, read-only PHPStan invocation. No arbitrary CLI passthrough or code-removal formatter. */
if (PHP_VERSION_ID < 80200) {
    fwrite(STDERR, "This development tool requires PHP 8.2+. The plugin still supports PHP 8.0.\n");
    exit(2);
}
$options = array_slice($argv, 1);
if (array_diff($options, ['--json', '--self-test', '--help'])) {
    fwrite(STDERR, "Allowed options: --json, --self-test, --help. This command only reports findings.\n");
    exit(2);
}
if (in_array('--help', $options, true)) {
    echo "php tools/dead-code/check.php [--json | --self-test]\n";
    exit(0);
}
if (!is_file(__DIR__ . '/vendor/autoload.php')) {
    fwrite(STDERR, "Install the checker first: composer --working-dir=tools/dead-code install --no-plugins --no-scripts\n");
    exit(2);
}
$selfTest = in_array('--self-test', $options, true);
$command = [PHP_BINARY, '-d', 'memory_limit=2G', __DIR__ . '/vendor/phpstan/phpstan/phpstan',
    'analyse', '--configuration=' . __DIR__ . ($selfTest ? '/tests/phpstan.neon' : '/phpstan.neon'),
    '--autoload-file=' . __DIR__ . '/vendor/autoload.php', '--no-progress',
    '--error-format=json'];
$descriptors = [0 => STDIN, 1 => ['pipe', 'w'], 2 => STDERR];
// Isolate Composer discovery: never load the plugin's executable WordPress autoloader.
$process = proc_open($command, $descriptors, $pipes, __DIR__, null, ['bypass_shell' => true]);
if (!is_resource($process)) {
    fwrite(STDERR, "Could not start PHPStan.\n");
    exit(2);
}
$output = stream_get_contents($pipes[1]);
fclose($pipes[1]);
$status = proc_close($process);
$report = json_decode($output, true);
if (!is_array($report) || !isset($report['totals'], $report['files'], $report['errors']) || !in_array($status, [0, 1], true)) {
    fwrite(STDERR, "Analysis did not produce a complete report (process exit: $status).\n" . $output . "\n");
    exit(2);
}
if (!$selfTest) {
    $incomplete = !empty($report['errors']);
    foreach ($report['files'] as $file) {
        foreach ($file['messages'] as $message) {
            if (!in_array($message['identifier'] ?? '', ['shipmonk.deadMethod', 'shipmonk.deadConstant', 'shipmonk.deadEnumCase'], true)) {
                $incomplete = true;
            }
        }
    }
    if (in_array('--json', $options, true)) {
        echo $output;
    } else {
        foreach ($report['files'] as $path => $file) {
            foreach ($file['messages'] as $message) {
                echo $path . ':' . $message['line'] . ' ' . $message['message'] . "\n";
                if (!empty($message['tip'])) {
                    echo '  ' . $message['tip'] . "\n";
                }
            }
        }
        foreach ($report['errors'] as $error) {
            fwrite(STDERR, $error . "\n");
        }
        echo $incomplete ? "Analysis incomplete.\n" : $report['totals']['file_errors'] . " candidate(s); review before any changes.\n";
    }
    exit($incomplete ? 2 : $status);
}
$messages = [];
foreach ($report['files'] ?? [] as $file) {
    foreach ($file['messages'] as $message) {
        $messages[] = $message['message'];
    }
}
$expected = [
    'Unused Fabrement\\Api\\Fields\\Probe::deadPrivate',
    'Unused Fabrement\\Api\\Fields\\Probe::internalOnly',
    'Unused Fabrement\\Sdk\\ProbePage::generatedSubclassOnly',
    'Unused Fabrement\\Sdk\\ProbePage::deadPrivate',
    'Unused Fabrement\\Route\\InternalPostsRestController::deadPrivate',
    'Unused DeadCodeFixture\\Worker::unused',
];
sort($expected);
sort($messages);
if ($status !== 1 || !is_array($report) || !empty($report['errors']) || $messages !== $expected) {
    fwrite(STDERR, "Checker self-test failed:\n" . $output . "\n");
    exit(2);
}
echo "PASS: public PHP API, MCP contract, WordPress hooks and REST callbacks kept; removed SDK and dead private/internal methods detected.\n";
