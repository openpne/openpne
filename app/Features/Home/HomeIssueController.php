<?php

declare(strict_types=1);

namespace App\Features\Home;

use App\Features\Home\Queries\AdjacentHomeIssues;
use App\Features\Home\Queries\FindHomeIssueByDate;
use App\Features\Home\Queries\ListHomeIssues;
use App\Features\Home\Queries\ShowHomeIssue;
use App\Features\Home\Serializers\HomeIssueSerializer;
use App\Http\Controllers\Controller;
use App\Models\Member;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The published issues: the run of them, and one day's.
 *
 * Modern-only — OpenPNE 3 had no such page — so both render Inertia directly rather than resolving
 * a surface. See [home-issues.md](../../../docs/internals/home-issues.md).
 */
class HomeIssueController extends Controller
{
    public function index(ListHomeIssues $issues): Response
    {
        return Inertia::render('home/issues', HomeIssueSerializer::archive($issues()));
    }

    /**
     * One day's issue, re-resolved for the member reading it.
     *
     * A day with no issue is a 404 and not an empty page: an issue is published only where there was
     * something to say, and a day that got none never had a front page to show.
     */
    public function show(
        Request $request,
        int $year,
        int $month,
        int $day,
        FindHomeIssueByDate $find,
        ShowHomeIssue $show,
        AdjacentHomeIssues $adjacent,
    ): Response {
        /** @var Member $viewer */
        $viewer = $request->user();

        $issue = $find($year, $month, $day);

        if ($issue === null) {
            throw new NotFoundHttpException;
        }

        ['previous' => $previous, 'next' => $next] = $adjacent($issue);

        return Inertia::render('home/archive', HomeIssueSerializer::page(
            $issue,
            $show($viewer, $issue),
            $viewer,
            $previous,
            $next,
            CarbonImmutable::now(),
        ));
    }
}
