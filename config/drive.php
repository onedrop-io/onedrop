<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Drive
    |--------------------------------------------------------------------------
    |
    | Files people keep and share in their organization (DRIVE-001), stored on
    | this disk (the app's default disk unless set). Each organization may
    | keep up to DRIVE_QUOTA_GB, Trash included; no limit when unset.
    | Deleted items stay in Trash for trash_days (DRIVE-002).
    |
    */

    'disk' => env('DRIVE_DISK'),

    'quota_gb' => env('DRIVE_QUOTA_GB') !== null && env('DRIVE_QUOTA_GB') !== '' ? (float) env('DRIVE_QUOTA_GB') : null,

    'trash_days' => 30,

    /*
    | The largest file one upload may be (S3 takes up to 5 GB in one PUT).
    */

    'max_file_mb' => (int) env('DRIVE_MAX_FILE_MB', 5120),

];
