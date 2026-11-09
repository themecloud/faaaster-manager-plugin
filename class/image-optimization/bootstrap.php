<?php

// Keep the bootstrap parseable on older site runtimes; never load the vendor classes there.
require_once __DIR__ . '/module.php';

function faaaster_image_optimization_boot()
{
    FaaasterImageOptimization::boot();
}

function faaaster_image_optimization_state()
{
    return FaaasterImageOptimization::state();
}
