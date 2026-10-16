<?php

function identity_upload_directory()
{
    $documentRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 3));
    if ($documentRoot === false) {
        throw new RuntimeException("Unable to resolve the web root for private identity storage.");
    }

    $configuredDirectory = getenv('IDENTITY_UPLOAD_DIR');
    $directory = $configuredDirectory !== false && $configuredDirectory !== ''
        ? $configuredDirectory
        : dirname($documentRoot) . DIRECTORY_SEPARATOR . 'private_uploads' .
            DIRECTORY_SEPARATOR . 'identity';

    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException("Unable to create the private identity upload directory.");
    }

    $resolvedDirectory = realpath($directory);
    if ($resolvedDirectory === false) {
        throw new RuntimeException("Unable to resolve the private identity upload directory.");
    }

    $webRootPrefix = rtrim($documentRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $pathToCheck = PHP_OS_FAMILY === 'Windows'
        ? strtolower($resolvedDirectory)
        : $resolvedDirectory;
    $prefixToCheck = PHP_OS_FAMILY === 'Windows'
        ? strtolower($webRootPrefix)
        : $webRootPrefix;

    if (strpos($pathToCheck . DIRECTORY_SEPARATOR, $prefixToCheck) === 0) {
        throw new RuntimeException("Identity uploads must be stored outside the public web root.");
    }

    @chmod($resolvedDirectory, 0700);
    return $resolvedDirectory;
}
