<?php

namespace App\Http\Controllers;

use App\Sandbox\Templates\AppScreenshots;
use App\Sandbox\Templates\TemplateCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TemplateScreenshotController extends Controller
{
    /**
     * A free app's pictures for its details (PRJ-012), asked for when they open.
     */
    public function __invoke(Request $request, TemplateCatalog $templates, AppScreenshots $screenshots): JsonResponse
    {
        $value = $request->validate(['template' => ['required', 'string', 'max:200']])['template'];
        $template = $templates->registryFor($value) ? $templates->find($value) : null;

        abort_if($template === null, 404);

        return response()->json(['screenshots' => $screenshots->for($template)]);
    }
}
