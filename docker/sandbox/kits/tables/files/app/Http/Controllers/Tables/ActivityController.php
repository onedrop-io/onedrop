<?php

namespace App\Http\Controllers\Tables;

use App\Http\Controllers\Controller;
use App\Models\TableComment;
use App\Tables\Activity;
use App\Tables\Tables;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ActivityController extends Controller
{
    /**
     * A record's comments and history (ActivityItem[]), oldest first.
     */
    public function index(Request $request, string $table, int $record): JsonResponse
    {
        $table = Tables::findOrFail($table);
        $table->authorize($request->user(), 'view');
        $record = $table->findRecordOrFail($request->user(), $record);

        return response()->json(['items' => Activity::forRecord($table, $record)]);
    }

    public function comment(Request $request, string $table, int $record): JsonResponse
    {
        $table = Tables::findOrFail($table);
        $table->authorize($request->user(), 'view');
        $record = $table->findRecordOrFail($request->user(), $record);
        $table->authorize($request->user(), 'comment', $record);

        $input = $request->validate([
            'body' => ['required', 'string', 'max:10000'],
        ], [
            'body.required' => 'Write a comment.',
            'body.max' => 'Comments can be up to 10,000 characters.',
        ]);

        $comment = TableComment::query()->create([
            'table' => $table->key(),
            'record_id' => $record->getKey(),
            'user_id' => $request->user()?->getKey(),
            'body' => $input['body'],
        ]);

        return response()->json(['item' => Activity::comment($comment->load('user'))], 201);
    }

    /**
     * Delete one of your own comments.
     */
    public function destroyComment(Request $request, string $table, int $comment): Response
    {
        $table = Tables::findOrFail($table);
        $table->authorize($request->user(), 'view');

        $comment = TableComment::query()->where('table', $table->key())->findOrFail($comment);

        abort_unless($request->user() !== null && (int) $comment->user_id === (int) $request->user()->getKey(), 403);

        $comment->delete();

        return response()->noContent();
    }
}
