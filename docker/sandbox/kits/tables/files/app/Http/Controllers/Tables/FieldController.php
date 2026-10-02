<?php

namespace App\Http\Controllers\Tables;

use App\Http\Controllers\Controller;
use App\Tables\Fields;
use App\Tables\Table;
use App\Tables\Tables;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FieldController extends Controller
{
    public function store(Request $request, string $table): JsonResponse
    {
        $table = $this->table($request, $table);

        (new Fields($table, $request->user()))->create($request->all());

        return $this->answer($request, $table);
    }

    public function update(Request $request, string $table, string $field): JsonResponse
    {
        $table = $this->table($request, $table);

        (new Fields($table, $request->user()))->update($field, $request->all());

        return $this->answer($request, $table);
    }

    public function destroy(Request $request, string $table, string $field): JsonResponse
    {
        $table = $this->table($request, $table);

        (new Fields($table, $request->user()))->delete($field);

        return $this->answer($request, $table);
    }

    public function duplicate(Request $request, string $table, string $field): JsonResponse
    {
        $table = $this->table($request, $table);

        (new Fields($table, $request->user()))->duplicate($field);

        return $this->answer($request, $table);
    }

    private function table(Request $request, string $key): Table
    {
        $table = Tables::findOrFail($key);
        $table->authorize($request->user(), 'view');

        return $table;
    }

    private function answer(Request $request, Table $table): JsonResponse
    {
        return response()->json(['table' => $table->refreshFields()->props($request->user())]);
    }
}
