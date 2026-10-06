<?php

namespace App\Sandbox;

use InvalidArgumentException;

/**
 * A search inside files (FILE-008) can't run as typed, e.g. an unfinished regular expression. The message is safe to
 * show to the user.
 */
class InvalidSearchException extends InvalidArgumentException {}
