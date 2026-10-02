<?php

namespace App\Tables\Formula;

use RuntimeException;

/**
 * A formula couldn't be compiled or evaluated. The message is shown to end users.
 */
final class FormulaError extends RuntimeException {}
