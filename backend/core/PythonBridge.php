<?php

require_once __DIR__ . '/../config/FraudConfig.php';

class PythonBridge
{
    public static function run($scriptName, $args = [])
    {
        $timeouts = FraudConfig::section('timeouts');
        $timeoutSeconds = (int)$timeouts['verification_seconds'];
        $requestBufferSeconds = (int)$timeouts['php_request_buffer_seconds'];
        if ($timeoutSeconds <= 0 || $requestBufferSeconds < 0) {
            return ["status" => "error", "reason" => "Local verification timeout settings are invalid."];
        }
        set_time_limit($timeoutSeconds + $requestBufferSeconds);
        if (basename($scriptName) !== $scriptName) {
            return ["status" => "error", "reason" => "Invalid Python script name."];
        }

        $scriptPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'python' . DIRECTORY_SEPARATOR . $scriptName;

        if (!file_exists($scriptPath)) {
            return ["status" => "error", "reason" => "The local verification script is not installed."];
        }

        $escapedArgs = array_map('escapeshellarg', $args);
        $command = escapeshellarg(self::resolvePython()) . " " .
            escapeshellarg($scriptPath) . " " . implode(" ", $escapedArgs);
        $pipes = [];
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, dirname($scriptPath));

        if (!is_resource($process)) {
            return ["status" => "error", "reason" => "The local verification process could not be started."];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $timedOut = false;
        $startedAt = microtime(true);
        $processStatus = proc_get_status($process);

        while ($processStatus['running']) {
            $stdout .= stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            if (microtime(true) - $startedAt > $timeoutSeconds) {
                $timedOut = true;
                proc_terminate($process);
                break;
            }
            usleep(100000);
            $processStatus = proc_get_status($process);
        }

        $stdout .= stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closedExitCode = proc_close($process);

        if ($timedOut) {
            error_log("Local Python verification timed out.");
            return ["status" => "error", "reason" => "Verification took too long. Please retry or request admin assistance."];
        }

        $decoded = json_decode(trim($stdout), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("Local Python verification returned malformed JSON.");
            return ["status" => "error", "reason" => "The local verification service returned an invalid response. Please retry."];
        }

        if (!is_array($decoded)) {
            return ["status" => "error", "reason" => "The local verification service returned an invalid response. Please retry."];
        }

        $exitCode = $processStatus['exitcode'] >= 0
            ? $processStatus['exitcode']
            : $closedExitCode;
        if ($exitCode !== 0 && ($decoded['status'] ?? null) !== 'error') {
            error_log("Local Python verification failed with exit code " . (int)$exitCode . ".");
            return ["status" => "error", "reason" => "The local verification service failed. Please retry or request admin assistance."];
        }
        return $decoded;
    }

    private static function resolvePython()
    {
        $venvPython = dirname(__DIR__, 2) . '/python/.venv/' .
            (PHP_OS_FAMILY === 'Windows' ? 'Scripts/python.exe' : 'bin/python');
        if (is_file($venvPython)) {
            return $venvPython;
        }

        return PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3';
    }
}
