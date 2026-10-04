<?php

namespace App\Sandbox\Domains;

use RuntimeException;

/**
 * A custom domain can't be connected where the project is published; the message says why, for the user.
 */
class DomainException extends RuntimeException {}
