<?php

declare(strict_types=1);

use App\Modernizers\V2\Rector\NormalizeSlothRegistrationPropertiesRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withRules([
        NormalizeSlothRegistrationPropertiesRector::class,
    ]);
