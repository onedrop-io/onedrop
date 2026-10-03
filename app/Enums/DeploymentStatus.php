<?php

namespace App\Enums;

/**
 * Where a deployment to hosting is (HOST-001).
 */
enum DeploymentStatus: string
{
    case Running = 'running';
    case Live = 'live';
    case Failed = 'failed';
}
