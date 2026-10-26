<?php

namespace Fabrement\Dev\DeadCode;

use PHPStan\Command\AnalysisResult;
use PHPStan\Command\ErrorFormatter\ErrorFormatter;
use PHPStan\Command\Output;

/** Disable the upstream mutation feature even when PHPStan is invoked directly with this config. */
final class DisabledRemovalFormatter implements ErrorFormatter {
    public function formatErrors(AnalysisResult $analysisResult, Output $output): int {
        $output->writeLineFormatted('Code removal is disabled. Use the table or json report formatter.');
        return 2;
    }
}
