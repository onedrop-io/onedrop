<?php

namespace App\Sandbox;

use RuntimeException;

/**
 * A skill couldn't be added or changed (a bad name, a link without a skill, too many files).
 * The message is written for the user.
 */
class SkillException extends RuntimeException {}
