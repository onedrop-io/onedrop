<?php

namespace App\Http\Controllers\Tables;

use App\Events\TableChanged;
use App\Http\Controllers\Controller;
use App\Tables\Table;
use App\Tables\Tables;
use App\Tables\Views;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class ViewController extends Controller
{
    public function store(Request $request, string $table): JsonResponse
    {
        $table = $this->table($request, $table);

        $input = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required_without:duplicate', Rule::in(Views::TYPES)],
            'personal' => ['sometimes', 'boolean'],
            'config' => ['sometimes', 'nullable', 'array'],
            'duplicate' => ['sometimes', 'nullable', 'integer'],
        ]);

        $view = Views::create($table, $request->user(), array_filter($input, fn (mixed $value) => $value !== null));
        $this->changed($view->isPersonal(), $table);

        return response()->json(['view' => Views::data($table, $view, $request->user())], 201);
    }

    public function update(Request $request, string $table, int $view): JsonResponse
    {
        $table = $this->table($request, $table);
        $view = Views::findOrFail($table, $request->user(), $view);

        $input = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'config' => ['sometimes', 'array'],
        ]);

        $view = Views::update($table, $request->user(), $view, $input);
        $this->changed($view->isPersonal(), $table);

        return response()->json(['view' => Views::data($table, $view, $request->user())]);
    }

    public function destroy(Request $request, string $table, int $view): Response
    {
        $table = $this->table($request, $table);
        $view = Views::findOrFail($table, $request->user(), $view);

        Views::delete($table, $request->user(), $view);
        $this->changed($view->isPersonal(), $table);

        return response()->noContent();
    }

    public function order(Request $request, string $table): Response
    {
        $table = $this->table($request, $table);

        $input = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        Views::order($table, $request->user(), $input['ids']);
        $this->changed(false, $table);

        return response()->noContent();
    }

    private function table(Request $request, string $key): Table
    {
        $table = Tables::findOrFail($key);
        $table->authorize($request->user(), 'view');

        return $table;
    }

    /**
     * Shared views changed: tell the others to reload.
     */
    private function changed(bool $personal, Table $table): void
    {
        if (! $personal) {
            TableChanged::send($table->key(), reload: true);
        }
    }
}
