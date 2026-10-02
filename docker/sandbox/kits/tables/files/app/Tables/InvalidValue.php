<?php

namespace App\Tables;

use RuntimeException;

/**
 * A value that doesn't fit its field. The message is shown to people as is.
 */
final class InvalidValue extends RuntimeException {}
