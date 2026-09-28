<?php

namespace App\Sandbox;

use RuntimeException;

/**
 * The app's database refused a request (bad SQL, a constraint, no connection).
 * The message comes from the database and is safe to show to the user.
 */
class DatabaseException extends RuntimeException {}
