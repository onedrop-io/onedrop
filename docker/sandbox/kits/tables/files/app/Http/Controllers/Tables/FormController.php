<?php

namespace App\Http\Controllers\Tables;

use App\Http\Controllers\Controller;
use App\Tables\Forms;
use App\Tables\Tables;
use App\Tables\Views;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Form views (TABLE-009): their page and sending them, signed in or through their public link.
 */
class FormController extends Controller
{
    public function show(Request $request, string $table, int $view): Response
    {
        $table = Tables::findOrFail($table);
        $table->authorize($request->user(), 'view');
        $view = Forms::findOrFail($table, $request->user(), $view);

        return Inertia::render('tables/form', ['form' => Forms::page($table, $view, $request->user(), public: false)]);
    }

    public function submit(Request $request, string $table, int $view): JsonResponse
    {
        $table = Tables::findOrFail($table);
        $table->authorize($request->user(), 'view');
        $view = Forms::findOrFail($table, $request->user(), $view);

        Forms::submit($table, $view, $request->user(), $this->values($request), public: false);

        return response()->json(['ok' => true]);
    }

    public function showPublic(string $token): Response
    {
        [$table, $view] = Forms::findPublicOrFail($token);

        return Inertia::render('tables/form', ['form' => Forms::page($table, $view, null, public: true)]);
    }

    /**
     * Send a public form. A filled-in "website" (a field people don't see) means a bot: it's told the form was sent.
     */
    public function submitPublic(Request $request, string $token): JsonResponse
    {
        [$table, $view] = Forms::findPublicOrFail($token);

        if (filled($request->input('website'))) {
            return response()->json(['ok' => true]);
        }

        Forms::submit($table, $view, null, $this->values($request), public: true);

        return response()->json(['ok' => true]);
    }

    public function uploadPublic(Request $request, string $token): JsonResponse
    {
        [$table, $view] = Forms::findPublicOrFail($token);

        $request->validate([
            'file' => ['required', 'file', 'max:'.(int) config('tables.form_max_upload_kb', 10240)],
        ]);

        return response()->json(['attachment' => Forms::upload($table, $view, $request->file('file'))], 201);
    }

    /**
     * Give the form a new public link; the old one stops working.
     */
    public function replaceLink(Request $request, string $table, int $view): JsonResponse
    {
        $table = Tables::findOrFail($table);
        $table->authorize($request->user(), 'view');
        $view = Views::findOrFail($table, $request->user(), $view);

        $view = Views::replaceFormLink($table, $request->user(), $view);

        return response()->json(['view' => Views::data($table, $view, $request->user())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function values(Request $request): array
    {
        $values = $request->validate([
            'values' => ['sometimes', 'array'],
        ])['values'] ?? [];

        return $values;
    }
}
