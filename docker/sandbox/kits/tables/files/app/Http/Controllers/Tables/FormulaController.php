<?php

namespace App\Http\Controllers\Tables;

use App\Http\Controllers\Controller;
use App\Tables\FormulaPreview;
use App\Tables\Tables;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FormulaController extends Controller
{
    /**
     * Check a formula and work it out for the first records (FormulaPreview).
     */
    public function preview(Request $request, string $table): JsonResponse
    {
        $table = Tables::findOrFail($table);
        $table->authorize($request->user(), 'view');
        $table->authorize($request->user(), 'manageFields');

        $input = $request->validate([
            'formula' => ['required', 'string', 'max:10000'],
            'format' => ['sometimes', 'nullable', 'string'],
        ]);

        return response()->json(FormulaPreview::make($table, $request->user(), $input['formula'], $input['format'] ?? 'auto'));
    }
}
