<?php

namespace Printed\PdfTools\Utils;

use Symfony\Component\Process\Process;

class SymfonyProcessRunner
{
    /**
     * You _must_ prepend your commands with "exec" command. See https://bugs.php.net/bug.php?id=39992 for details.
     *
     * @param Process[] $processes
     * @param int $timeoutSeconds Max time for this group of processes to execute
     */
    public static function runSymfonyProcessesWithTimeout(array $processes, $timeoutSeconds)
    {
        $finishTimestamp = time() + $timeoutSeconds;

        foreach ($processes as $process) {
            $processTimeoutSeconds = $finishTimestamp - time();

            /*
             * Minimum sanity check I care to do. Let it timeout after 1s (or get lucky and finish before that)
             */
            if ($processTimeoutSeconds < 1) {
                $processTimeoutSeconds = 1;
            }

            $process->setTimeout($processTimeoutSeconds);
            $process->mustRun();
        }
    }
}