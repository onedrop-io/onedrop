<?php

namespace App\Http\Controllers;

use App\Sandbox\Templates\TemplateCatalog;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

class StartController extends Controller
{
    /**
     * Starting a project from the home page (HOME-004): what they typed or picked waits on the new-project page, after
     * signing up when they haven't, like "Remix this" (SHARE-002).
     */
    public function store(Request $request, TemplateCatalog $templates): RedirectResponse
    {
        $start = $request->validate([
            'prompt' => ['required_without:template', 'nullable', 'string', 'max:5000'],
            'template' => ['nullable', 'string', 'max:200', function (string $attribute, string $value, Closure $fail) use ($templates): void {
                if ($templates->find($value) === null) {
                    $fail(__('That template isn\'t available.'));
                }
            }],
        ], [
            'prompt.required_without' => __('Describe what you want to build.'),
        ]);

        $request->session()->put('start', ['prompt' => $start['prompt'] ?? null, 'template' => $start['template'] ?? null]);

        if ($request->user()) {
            return to_route('dashboard');
        }

        redirect()->setIntendedUrl(route('dashboard'));

        return to_route(Route::has('register') ? 'register' : 'login');
    }

    /**
     * "Not now" beside the sign-up or log-in form (HOME-004): drop what they picked.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->session()->forget('start');

        return back();
    }
}
