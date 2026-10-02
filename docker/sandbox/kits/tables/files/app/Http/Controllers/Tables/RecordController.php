<?php

namespace App\Http\Controllers\Tables;

use App\Http\Controllers\Controller;
use App\Tables\Computation;
use App\Tables\RecordChanges;
use App\Tables\Tables;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RecordController extends Controller
{
    /**
     * Records by id, worked out again: ?ids=1,2,3.
     */
    public function index(Request $request, string $table): JsonResponse
    {
        $table = Tables::findOrFail($table);
        $table->authorize($request->user(), 'view');

        $ids = array_values(array_filter(array_map('intval', explode(',', (string) $request->query('ids', '')))));
        $computation = new Computation($request->user());
        $computation->register($table);

        $records = $ids === [] ? collect() : $table->query($request->user())->with($table->eagerLoads())->whereKey($ids)->orderBy($table->newModel()->getQualifiedKeyName())->get();
        $computation->seed($table, $records);

        return response()->json([
            'records' => $records->map(fn (Model $record) => $computation->record($table, $record))->values(),
        ]);
    }

    /**
     * Create, update and delete records in one go (RecordChanges), answered with RecordChangesResult.
     */
    public function store(Request $request, string $table): JsonResponse
    {
        $table = Tables::findOrFail($table);
        $table->authorize($request->user(), 'view');

        $changes = $request->validate([
            'creates' => ['sometimes', 'array'],
            'creates.*' => ['array'],
            'creates.*.values' => ['sometimes', 'array'],
            'updates' => ['sometimes', 'array'],
            'updates.*.id' => ['required', 'integer'],
            'updates.*.values' => ['sometimes', 'array'],
            'deletes' => ['sometimes', 'array'],
            'deletes.*' => ['integer'],
        ]);

        return response()->json((new RecordChanges($table, $request->user()))->apply($changes));
    }
}
