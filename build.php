#!/bin/php
<?php
declare(strict_types=1);
// Only allow run from cli.
if (php_sapi_name() !== 'cli') {
    exit(0);
}

// Any command needed to run and build plugin assets when newly cheched out of repo.
$buildCommands = [
    'npm ci --no-progress',
    'npm run build',
    'composer install --prefer-dist --no-progress --no-dev'
];

// Remove colocated tests before generating the production classmap.
if (in_array('--cleanup', $argv, true)) {
    $buildCommands[] = "find source/php -type f \\( -name '*Test.php' -o -name '*.test.php' \\) -delete";
    // Service archives include tests; *Test.php also names real service contracts.
    $buildCommands[] = "find vendor -type f -name '*.test.php' -delete";
    $buildCommands[] = 'find vendor -mindepth 3 -maxdepth 3 -type d -name tests -exec rm -rf -- {} +';
}
$buildCommands[] = 'composer dump-autoload --no-dev --classmap-authoritative';

// Files and directories not suitable for prod to be removed.
$removables = [
    '.git',
    '.gitignore',
    '.github',
    '.devcontainer',
    '.playwright-cli',
    '.npmrc',
    'build.php',
    'composer.json',
    'composer.lock',
    'node_modules',
    'package-lock.json',
    'package.json',
    'patchwork.json',
    'phpunit.xml',
    'phpunit-integration.xml',
    'mago.toml',
    'source/tests'
];

$dirName = basename(dirname(__FILE__));

// Run all build commands.
$output = '';
$exitCode = 0;
foreach ($buildCommands as $buildCommand) {
    print "---- Running build command '$buildCommand' for $dirName. ----\n";
    $exitCode = executeCommand($buildCommand);
    print "---- Done build command '$buildCommand' for $dirName. ----\n";
    if ($exitCode > 0) {
        exit($exitCode);
    }
}

// Remove files and directories if '--cleanup' argument is supplied to save local developers from disasters.
if (in_array('--cleanup', $argv, true)) {
    // Inspect dependency archives after installation, including on a clean build.
    $removables = array_merge($removables, glob('vendor/*/*/.devcontainer'), glob('vendor/*/*/.github'), glob('vendor/*/*/phpunit*.xml*'));
    foreach ($removables as $removable) {
        if (file_exists($removable)) {
            print "Removing $removable from $dirName\n";
            $exitCode = executeCommand('rm -rf -- ' . escapeshellarg($removable));
            if ($exitCode !== 0) {
                exit($exitCode);
            }
        }
    }
}

/**
 * Better shell script execution with live output to STDOUT and status code return.
 * @param  string $command Command to execute in shell.
 * @return int             Exit code.
 */
function executeCommand($command)
{
    $proc = popen("$command 2>&1 ; echo Exit status : $?", 'r');

    $liveOutput     = '';
    $completeOutput = '';

    while (!feof($proc)) {
        $liveOutput     = fread($proc, 4096);
        $completeOutput = $completeOutput . $liveOutput;
        print $liveOutput;
        flush();
    }

    pclose($proc);

    // Get exit status.
    preg_match('/[0-9]+$/', $completeOutput, $matches);

    // Return exit status.
    return intval($matches[0]);
}
