#!/usr/bin/env php
<?php
declare(strict_types=1);

/** Lists the Canadian models your Hopscotch key can call. */

require_once __DIR__ . '/../src/hopscotch.php';

try {
    $models = hopscotch_canadian_models();
} catch (HopscotchError $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

if (!$models) {
    echo "This key cannot call any model from a Canadian lab (" . implode(', ', array_keys(CANADIAN_MAKERS)) . ").\n";
    exit(1);
}
$configured = env('HOPSCOTCH_MODEL', DEFAULT_MODEL);
foreach ($models as $model) {
    echo ($model === $configured ? '* ' : '  ') . $model . "\n";
}
if (!in_array($configured, $models, true)) {
    echo "\nHOPSCOTCH_MODEL is \"$configured\", which is not in this list. Set it to one of the ids above.\n";
}
