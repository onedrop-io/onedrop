<?php

namespace App\Http\Controllers\Tables;

use App\Http\Controllers\Controller;
use App\Tables\Tables;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TableController extends Controller
{
    /**
     * The table's data (TableData), to load it again.
     */
    public function show(Request $request, string $table): JsonResponse
    {
        $table = Tables::findOrFail($table);
        $table->authorize($request->user(), 'view');

        return response()->json($table->props($request->user()));
    }
}
