<?php

namespace App\Enums;

enum SandboxStatus: string
{
    case Creating = 'creating';
    case Running = 'running';
    case Paused = 'paused';
    case Failed = 'failed';
}
