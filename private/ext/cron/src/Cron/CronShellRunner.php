<?php

/**
 * RAVEN CMS
 * ~/private/ext/cron/src/Cron/CronShellRunner.php
 * Shell command runner for Scheduled Tasks jobs.
 * Docs: /private/ext/AGENTS.md
 */

declare(strict_types=1);

namespace Raven\Cron;

use RuntimeException;

/**
 * Runs one scheduled shell command from Raven's project root.
 */
final class CronShellRunner
{
    private string $binary;

    /**
     * @param string $binary Shell binary used to execute task commands.
     */
    public function __construct(string $binary = '/bin/bash')
    {
        $this->binary = $binary !== '' ? $binary : '/bin/bash';
    }

    /**
     * Executes one shell command and returns the captured process result.
     *
     * Commands intentionally run through `bash -lc` so administrators can use
     * the usual cron-style shell syntax, including pipes and redirects.
     *
     * @param string $command Shell command body.
     * @param string $cwd     Working directory for the command.
     * @return array{ok: bool, exit_code: int, stdout: string, stderr: string, command: string}
     */
    public function run(string $command, string $cwd): array
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open(
            [$this->binary, '-lc', $command],
            $descriptorSpec,
            $pipes,
            $cwd
        );

        if (!is_resource($process)) {
            throw new RuntimeException('Failed to start scheduled task command.');
        }

        $stdout = '';
        $stderr = '';
        try {
            if (isset($pipes[0]) && is_resource($pipes[0])) {
                fclose($pipes[0]);
            }
            if (isset($pipes[1]) && is_resource($pipes[1])) {
                $stdout = stream_get_contents($pipes[1]) ?: '';
                fclose($pipes[1]);
            }
            if (isset($pipes[2]) && is_resource($pipes[2])) {
                $stderr = stream_get_contents($pipes[2]) ?: '';
                fclose($pipes[2]);
            }
        } finally {
            $exitCode = proc_close($process);
        }

        return [
            'ok' => $exitCode === 0,
            'exit_code' => $exitCode,
            'stdout' => trim($stdout),
            'stderr' => trim($stderr),
            'command' => $command,
        ];
    }

    /**
     * Executes one shell command and throws on failure.
     *
     * @param string $command Shell command body.
     * @param string $cwd     Working directory for the command.
     * @return array{ok: bool, exit_code: int, stdout: string, stderr: string, command: string}
     */
    public function mustRun(string $command, string $cwd): array
    {
        $result = $this->run($command, $cwd);
        if ($result['ok']) {
            return $result;
        }

        $message = $result['stderr'] !== '' ? $result['stderr'] : ($result['stdout'] !== '' ? $result['stdout'] : 'Scheduled task command failed.');
        throw new RuntimeException($message);
    }
}
