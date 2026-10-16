<?php

require_once __DIR__ . '/../config/FraudConfig.php';

class ImageHashService
{

    public static function generateHash(string $imagePath): string
    {
        if (!file_exists($imagePath)) {
            throw new \RuntimeException("ImageHashService: image not found at {$imagePath}");
        }

        $pythonPath = self::resolvePython();

        $scriptPath = escapeshellarg(dirname(__DIR__, 2) . '/python/image_hash.py');
        $arg        = escapeshellarg($imagePath);

        // Capture both stdout and stderr so we can surface Python errors
        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $proc = proc_open("{$pythonPath} {$scriptPath} {$arg}", $descriptors, $pipes);

        if (!is_resource($proc)) {
            throw new \RuntimeException("ImageHashService: failed to start Python process");
        }

        fclose($pipes[0]);
        $stdout = trim(stream_get_contents($pipes[1]));
        $stderr = trim(stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        if ($exitCode !== 0 || $stdout === '' || $stdout === 'HASH_ERROR') {
            $detail = $stderr ?: $stdout ?: 'no output';
            throw new \RuntimeException("ImageHashService: Python error — {$detail}");
        }

        return $stdout;
    }


    private static function resolvePython(): string
    {
        $venvPython = dirname(__DIR__, 2) . '/python/.venv/' .
            (PHP_OS_FAMILY === 'Windows' ? 'Scripts/python.exe' : 'bin/python');
        if (is_file($venvPython)) {
            return escapeshellarg($venvPython);
        }

        // Check if python3 is available
        $test = shell_exec('python3 --version 2>&1');
        if ($test && stripos($test, 'python') !== false) {
            return 'python3';
        }

        $test = shell_exec('python --version 2>&1');
        if ($test && stripos($test, 'python 3') !== false) {
            return 'python';
        }

        throw new \RuntimeException("ImageHashService: Python 3 not found on this server. Install it or set the full path.");
    }

    public static function hammingDistance(string $hashA, string $hashB): ?int
    {
        if (
            $hashA === '' ||
            $hashB === '' ||
            strlen($hashA) !== strlen($hashB) ||
            !ctype_xdigit($hashA) ||
            !ctype_xdigit($hashB)
        ) {
            return null;
        }

        $distance = 0;
        for ($i = 0, $length = strlen($hashA); $i < $length; $i++) {
            $xor = hexdec($hashA[$i]) ^ hexdec($hashB[$i]);
            $distance += substr_count(decbin($xor), '1');
        }
        return $distance;
    }

    public static function areDuplicates(string $hashA, string $hashB): bool
    {
        $distance = self::hammingDistance($hashA, $hashB);
        $threshold = (int)FraudConfig::section('duplicate_image')['maximum_hamming_distance'];
        return $distance !== null && $distance <= $threshold;
    }
}
