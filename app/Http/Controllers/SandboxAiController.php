<?php

namespace App\Http\Controllers;

use App\Models\Sandbox;
use App\Sandbox\Agents\ChatGptSignInFailed;
use App\Sandbox\Agents\OneOffPrompt;
use App\Sandbox\SandboxException;
use Illuminate\Http\JsonResponse;

/**
 * Called by `ask` in a sandbox's shell (SBX-012), on the signed address the sandbox was created with.
 */
class SandboxAiController extends Controller
{
    /**
     * How to ask the project's AI: the same AI and model as its one-off questions, with the credentials handed over
     * for this one question, so `ask` keeps none in the sandbox and always uses the AI the project uses now.
     */
    public function show(Sandbox $sandbox, OneOffPrompt $prompt): JsonResponse
    {
        try {
            $setup = $prompt->setup($sandbox->project);
        } catch (SandboxException|ChatGptSignInFailed $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Asking counts as use, so the sandbox isn't suspended under the person asking (SBX-007).
        $sandbox->markActive();

        return response()->json([
            'harness' => $setup['harness']->value,
            'model' => $setup['model'],
            'env' => (object) $setup['env'],
        ]);
    }
}
