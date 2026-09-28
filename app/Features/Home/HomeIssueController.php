<?php

declare(strict_types=1);

namespace App\Features\Home;

use App\Features\Home\Data\HomeIssueMonth;
use App\Features\Home\Queries\AdjacentHomeIssueMonths;
use App\Features\Home\Queries\AdjacentHomeIssues;
use App\Features\Home\Queries\FindHomeIssueByDate;
use App\Features\Home\Queries\ListHomeIssueMonths;
use App\Features\Home\Queries\ListHomeIssuesInMonth;
use App\Features\Home\Queries\ShowHomeIssue;
use App\Features\Home\Queries\SummarizeHomeIssues;
use App\Features\Home\Serializers\HomeIssueSerializer;
use App\Http\Controllers\Controller;
use App\Models\HomeIssue;
use App\Models\Member;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The published issues: a month of them, and one day's. Modern-only — OpenPNE 3 had no such page —
 * so they render Inertia directly rather than resolving a surface (docs/internals/home-issues.md,
 * "Routes").
 */
class HomeIssueController extends Controller
{
    public function __construct(
        private readonly ListHomeIssuesInMonth $inMonth,
        private readonly AdjacentHomeIssueMonths $adjacentMonths,
        private readonly SummarizeHomeIssues $summarize,
        private readonly ListHomeIssueMonths $months,
    ) {}

    public function index(Request $request): Response
    {
        $latest = HomeIssue::query()->orderByDesc('issue_date')->first();

        return $latest === null
            ? Inertia::render('home/issues', HomeIssueSerializer::month(null, collect(), [], null, null, []))
            : $this->render($request, HomeIssueMonth::of($latest->issue_date));
    }

    public function month(Request $request, int $year, int $month): Response
    {
        return $this->render($request, new HomeIssueMonth($year, $month));
    }

    /**
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
            $previous,
            $next,
            CarbonImmutable::now(),
        ));
    }

    private function render(Request $request, HomeIssueMonth $month): Response
    {
        /** @var Member $viewer */
        $viewer = $request->user();

        $issues = ($this->inMonth)($month);
        ['previous' => $previous, 'next' => $next] = ($this->adjacentMonths)($month);

        // An empty month is a page only between two months that are not.
        if ($issues->isEmpty() && ($previous === null || $next === null)) {
            throw new NotFoundHttpException;
        }

        return Inertia::render('home/issues', HomeIssueSerializer::month(
            $month,
            $issues,
            ($this->summarize)($viewer, $issues),
            $previous,
            $next,
            ($this->months)(),
        ));
    }
}
