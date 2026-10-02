<?php

namespace App\Sandbox\Agents;

use RuntimeException;

/**
 * A run can't use AI credits (CREDIT-001): they've run out, or can't be checked. The message says which.
 */
class OutOfAiCredits extends RuntimeException {}
