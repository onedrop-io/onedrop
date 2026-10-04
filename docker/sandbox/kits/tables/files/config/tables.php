<?php

use App\Models\User;

return [

    /*
    |--------------------------------------------------------------------------
    | Tables
    |--------------------------------------------------------------------------
    |
    | The app's tables, by key. The key is used in URLs, in link fields
    | ("companies") and in the broadcast channel ("tables.companies").
    |
    */

    'tables' => [
        // 'deals' => App\Tables\DealsTable::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Endpoints
    |--------------------------------------------------------------------------
    |
    | The URL prefix of the grid's JSON endpoints, e.g. "/tables/deals".
    |
    */

    'prefix' => 'tables',

    /*
    |--------------------------------------------------------------------------
    | Public forms
    |--------------------------------------------------------------------------
    |
    | Form views shared publicly are at "/forms/{token}", for people without
    | an account. Each visitor can send a form form_submissions_per_minute
    | times a minute and upload form_uploads_per_minute files a minute, each
    | up to form_max_upload_kb kilobytes.
    |
    */

    'forms_prefix' => 'forms',

    'form_submissions_per_minute' => 10,

    'form_uploads_per_minute' => 20,

    'form_max_upload_kb' => 10240,

    /*
    |--------------------------------------------------------------------------
    | People
    |--------------------------------------------------------------------------
    */

    'user_model' => User::class,

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    |
    | Every record of a table is sent to the browser, up to max_rows.
    | Uploads are limited to max_upload_kb kilobytes.
    |
    */

    'max_rows' => 5000,

    'max_upload_kb' => 20480,

    /*
    |--------------------------------------------------------------------------
    | Attachments
    |--------------------------------------------------------------------------
    |
    | The disk attachments are stored on. When the app doesn't define it,
    | it's a private local disk in the app's storage directory.
    |
    */

    'disk' => 'table-attachments',

];
