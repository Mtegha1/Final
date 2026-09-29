<?php

class PythonBridge
{
    public static function run($scriptName, $args = [])
    {
        $scriptPath = dirname(__DIR__, 2) . '/python/' . $scriptName;

        if (!file_exists($scriptPath)) {
            return [
                "error" => "Python script not found: {$scriptPath}"
            ];
        }

        $escapedArgs = array_map('escapeshellarg', $args);

        $command =
            escapeshellarg(self::resolvePython()) . " " .
            escapeshellarg($scriptPath) . " " .
            implode(" ", $escapedArgs);

        $output = [];
        $exitCode = 0;

        exec($command . " 2>&1", $output, $exitCode);

        $rawOutput = implode("\n", $output);

        // Log everything for debugging
        file_put_contents(
            __DIR__ . '/python_debug.log',
            "\n====================\n" .
                "COMMAND:\n{$command}\n\n" .
                "EXIT CODE:\n{$exitCode}\n\n" .
                "OUTPUT:\n{$rawOutput}\n",
            FILE_APPEND
        );

        // Find JSON in output
        preg_match('/\{.*\}/s', $rawOutput, $matches);

        if (!$matches) {
            return [
                "error" => "No valid JSON returned from Python",
                "raw_output" => $rawOutput,
                "exit_code" => $exitCode
            ];
        }

        $decoded = json_decode($matches[0], true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                "error" => "Invalid JSON from Python",
                "json_error" => json_last_error_msg(),
                "raw_output" => $rawOutput
            ];
        }

        return $decoded;
    }

    private static function resolvePython()
    {
        $venvPython = dirname(__DIR__, 2) . '/python/.venv/bin/python';
        if (is_file($venvPython)) {
            return $venvPython;
        }

        return PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3';
    }
}
