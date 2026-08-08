<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ReportStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReportRequest;
use App\Http\Resources\ReportResource;
use App\Models\Report;
use App\Models\User;
use App\Notifications\Reports\NewReportNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class ReportController extends Controller
{
    /**
     * File a report against a user, job post, or rating. Open to any signed-in
     * user. The reportable is resolved from its short morph alias and must
     * exist (a 404 otherwise), a reporter can't report their own content, and
     * a second complaint about the same target while an earlier one is still
     * being worked is rejected as a duplicate. On success every moderator is
     * notified so the queue never sits unseen.
     */
    public function store(StoreReportRequest $request): ReportResource
    {
        $validated = $request->validated();

        /** @var class-string<Model> $modelClass */
        $modelClass = Relation::getMorphedModel($validated['reportable_type']);
        // Scalar id (not the mixed validated value) so findOrFail resolves to a
        // single model rather than the array/Collection overload.
        $reportable = $modelClass::query()->findOrFail($request->integer('reportable_id'));

        $report = new Report([
            'reporter_id' => $request->user()->id,
            'reportable_type' => $validated['reportable_type'],
            'reportable_id' => $reportable->getKey(),
            'reason' => $validated['reason'],
        ]);
        $report->setRelation('reportable', $reportable);

        $this->guardAgainstSelfReport($report, $request->user());
        $this->guardAgainstDuplicate($report);

        $report->save();

        Notification::send(
            User::query()->where('role', 'moderator')->get(),
            new NewReportNotification($report),
        );

        $report->load('reporter');

        return new ReportResource($report);
    }

    /**
     * A user can't report their own content — the reported thing must belong
     * to someone else. Ownership is resolved through the report's subject user
     * (the reportable itself, or the employer/reviewer behind it).
     */
    protected function guardAgainstSelfReport(Report $report, User $reporter): void
    {
        if ($report->subjectUser()?->id === $reporter->id) {
            throw ValidationException::withMessages([
                'reportable_id' => 'You cannot report your own content.',
            ]);
        }
    }

    protected function guardAgainstDuplicate(Report $report): void
    {
        $hasOpenReport = Report::query()
            ->where('reporter_id', $report->reporter_id)
            ->where('reportable_type', $report->reportable_type)
            ->where('reportable_id', $report->reportable_id)
            ->whereIn('status', array_map(fn (ReportStatus $status): string => $status->value, ReportStatus::active()))
            ->exists();

        if ($hasOpenReport) {
            throw ValidationException::withMessages([
                'reportable_id' => 'You already have an open report on this.',
            ]);
        }
    }
}
