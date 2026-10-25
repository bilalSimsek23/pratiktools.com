<?php

namespace App\Automation;

use RuntimeException;

/**
 * Another worker took over the run (lease expired): nothing is written.
 */
final class LeaseLost extends RuntimeException {}
