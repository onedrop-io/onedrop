<?php

namespace App\Sandbox;

use RuntimeException;

/**
 * The App Storage tool refused a request (a bad name, a bucket that already exists, a missing object).
 * The message is written for the user.
 */
class StorageException extends RuntimeException {}
