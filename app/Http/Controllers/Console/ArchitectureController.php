<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Renders the interactive counterpart to docs/architecture.md §1-3.
 * The diagram data lives in resources/js/console/data/architecture.ts,
 * not here — it's static, curated content, not derived from the DB.
 */
class ArchitectureController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Architecture');
    }
}
