<?php

namespace App\Http\Controllers\Settings;

use App\Enums\BuildMode;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BuildModeController extends Controller
{
    /**
     * Choose Simple or Advanced mode, from the new-project page or the account menu (PRJ-013).
     */
    public function __invoke(Request $request): RedirectResponse
    {
        $mode = $request->validate(['mode' => ['required', Rule::enum(BuildMode::class)]])['mode'];

        $request->user()->forceFill(['build_mode' => $mode])->save();

        return back();
    }
}
