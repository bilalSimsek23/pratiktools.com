<?php

namespace App\Connectors\Iys;

use LogicException;

/**
 * Raised before any network call when code asks the İYS connector for an
 * operation that could change consent state. In shadow mode the existing
 * integrator is the single writer.
 */
final class ReadOnlyModeViolation extends LogicException {}
