<?php

namespace App\Http\Controllers\Tables;

use App\Http\Controllers\Controller;
use App\Tables\Attachments;
use App\Tables\Tables;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    /**
     * Upload a file for an attachment field (Attachment).
     */
    public function store(Request $request, string $table): JsonResponse
    {
        $table = Tables::findOrFail($table);
        $table->authorize($request->user(), 'view');
        abort_unless($table->can($request->user(), 'update') || $table->can($request->user(), 'create'), 403);

        $request->validate([
            'file' => ['required', 'file', 'max:'.(int) config('tables.max_upload_kb', 20480)],
        ]);

        return response()->json(['attachment' => Attachments::upload($table, $request->file('file'))], 201);
    }

    /**
     * Send an attachment to people who may see the table.
     */
    public function show(Request $request, string $table, string $path): StreamedResponse
    {
        $table = Tables::findOrFail($table);
        $table->authorize($request->user(), 'view');

        return Attachments::response($table, $path);
    }
}
