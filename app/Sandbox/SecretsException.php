<?php

namespace App\Sandbox;

use RuntimeException;

/**
 * The secrets tool refused a request (a bad name, a secret that already exists).
 * The message is written for the user.
 */
class SecretsException extends RuntimeException {}
