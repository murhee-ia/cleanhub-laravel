<?php

namespace App\Http\Controllers\Api\V1\Moderation;

use App\Enums\JobPostStatus;
use App\Enums\RatingStatus;
use App\Enums\ReportStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\HandleReportRequest;
use App\Http\Resources\ReportResource;
use App\Models\AuditLog;
use App\Models\CleaningJobPost;
use App\Models\Rating;
use App\Models\Report;
use App\Models\User;
use App\Notifications\Reports\ReportEscalatedNotification;
use App\Notifications\Reports\UserWarnedNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The moderator's report queue and the actions taken on a report. Every action
 * that changes state also writes an audit-log entry through AuditLog::record,
 * and each state-changing action is wrapped in a transaction so a report is
 * never left half-updated relative to the content it hides or the warning it
 * sends. The route group is already gated to moderator/admin by the `role`
 * middleware; the policy checks here are the second, model-level layer.
 */
class ReportModerationController extends Controller
{
    /**
     * The report queue, newest first, filterable by target type and status so
     * the dashboard's tabs and type filter map straight onto query params.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Report::class);

        $validated = $request->validate([
            'type' => ['sometimes', Rule::in(['user', 'job_post', 'rating'])],
            'status' => ['sometimes', Rule::enum(ReportStatus::class)],
            'per_page' => $this->perPageRule(),
        ]);

        $reports = Report::query()
            ->with(['reporter', 'reportable', 'handler'])
            ->when(
                isset($validated['type']),
                fn (Builder $query) => $query->ofType($validated['type']),
            )
            ->when(
                isset($validated['status']),
                fn (Builder $query) => $query->withStatus(ReportStatus::from($validated['status'])),
            )
            ->latest()
            ->paginate($validated['per_page'] ?? 50);

        return ReportResource::collection($reports);
    }

    public function show(Report $report): ReportResource
    {
        Gate::authorize('view', $report);

        $report->load(['reporter', 'reportable', 'handler']);

        return new ReportResource($report);
    }

    /**
     * Close a report as handled — the complaint was valid and dealt with, or
     * simply needs to leave the open queue.
     */
    public function resolve(HandleReportRequest $request, Report $report): ReportResource
    {
        return $this->finalize($request, $report, ReportStatus::Resolved, 'report.resolved');
    }

    /**
     * Dismiss a report as unfounded — no action against the target.
     */
    public function reject(HandleReportRequest $request, Report $report): ReportResource
    {
        return $this->finalize($request, $report, ReportStatus::Rejected, 'report.rejected');
    }

    /**
     * Hand the report up to the admin. The admin is notified so an escalation
     * doesn't sit unnoticed.
     */
    public function escalate(HandleReportRequest $request, Report $report): ReportResource
    {
        $resource = $this->finalize($request, $report, ReportStatus::Escalated, 'report.escalated');

        Notification::send(
            User::query()->where('role', 'admin')->get(),
            new ReportEscalatedNotification($report),
        );

        return $resource;
    }

    /**
     * Hide the reported content — a soft toggle, never a delete. A job post
     * moves to `removed`, a rating to `hidden`; both are reversible by an admin
     * later. Hiding also resolves the report. A reported *user* has no content
     * to hide (warn or resolve instead), so that case is a 422.
     */
    public function hide(HandleReportRequest $request, Report $report): ReportResource
    {
        $reportable = $report->reportable;

        return DB::transaction(function () use ($request, $report, $reportable): ReportResource {
            match (true) {
                $reportable instanceof CleaningJobPost => $reportable->update(['status' => JobPostStatus::Removed]),
                $reportable instanceof Rating => $reportable->update(['status' => RatingStatus::Hidden]),
                default => throw ValidationException::withMessages([
                    'reportable_id' => 'A reported user has no content to hide — warn or resolve instead.',
                ]),
            };

            return $this->finalize($request, $report, ReportStatus::Resolved, 'content.hidden', [
                'reportable_type' => $report->reportable_type,
                'reportable_id' => $report->reportable_id,
            ]);
        });
    }

    /**
     * Warn the user behind the reported content (the user themselves, or the
     * employer/reviewer who owns it) — fires a notification and resolves the
     * report. The moderator's note, if any, rides along in the warning.
     */
    public function warn(HandleReportRequest $request, Report $report): ReportResource
    {
        $subject = $report->subjectUser();

        if ($subject === null) {
            throw ValidationException::withMessages([
                'reportable_id' => 'The reported content no longer has an owner to warn.',
            ]);
        }

        return DB::transaction(function () use ($request, $report, $subject): ReportResource {
            $resource = $this->finalize($request, $report, ReportStatus::Resolved, 'user.warned', [
                'warned_user_id' => $subject->id,
            ]);

            // Send after finalize so the warning message can read the note that
            // finalize just persisted onto the report.
            $subject->notify(new UserWarnedNotification($report));

            return $resource;
        });
    }

    /**
     * Stamp the report with its outcome, record who did it and their note,
     * write the audit entry, and return the refreshed resource. The single
     * place report state changes, so the audit trail can't be skipped.
     *
     * @param  array<string, mixed>  $context
     */
    protected function finalize(
        HandleReportRequest $request,
        Report $report,
        ReportStatus $status,
        string $auditAction,
        array $context = [],
    ): ReportResource {
        $actor = $request->user();

        $report->update([
            'status' => $status,
            'handled_by' => $actor->id,
            'resolution_note' => $request->validated('note') ?? $report->resolution_note,
        ]);

        AuditLog::record($actor, $auditAction, $report, [
            'status' => $status->value,
            ...$context,
        ]);

        $report->load(['reporter', 'reportable', 'handler']);

        return new ReportResource($report);
    }
}
