<?php

declare(strict_types=1);

namespace Jnbrnplbr\WorkflowPipelines\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Jnbrnplbr\WorkflowPipelines\Contracts\Approvable;
use Jnbrnplbr\WorkflowPipelines\Events\WorkflowEvent;
use Jnbrnplbr\WorkflowPipelines\Models\TransactionWorkflow;
use Jnbrnplbr\WorkflowPipelines\Models\WorkflowStep;
use Jnbrnplbr\WorkflowPipelines\Models\WorkflowStepNotification;
use Jnbrnplbr\WorkflowPipelines\Notifications\WorkflowStepActionNotification;
use Throwable;

final class WorkflowNotifier
{
    public function notify(
        WorkflowEvent $event,
        string $notificationEvent,
        int $cycle,
        ?WorkflowStep $settingsStep = null,
    ): void {
        if (! config('workflow-pipelines.notifications.enabled', true)) {
            return;
        }

        $transaction = $event->transaction;
        $instance = $event->workflowInstance;
        $instance->loadMissing(['workflow', 'currentStep']);
        $settingsStep ??= $event->step;
        $allRecords = $settingsStep->notifications()->get();
        $records = $allRecords->where('is_active', true)->values();

        if ($allRecords->isEmpty()) {
            $records = collect([new WorkflowStepNotification([
                'channel' => 'database',
                'events' => ['approved', 'reverted', 'rejected'],
                'recipients' => ['next_handlers', 'creator'],
                'is_active' => true,
            ])]);
        }

        foreach ($records as $record) {
            if (! $this->notificationApplies($record, $notificationEvent, $transaction)) {
                continue;
            }

            $channel = (string) $record->channel;

            if (! in_array($channel, ['database', 'mail'], true)) {
                continue;
            }

            $recipients = $this->recipients($record, $transaction, $instance, $cycle);
            $notification = new WorkflowStepActionNotification(
                $transaction,
                $instance,
                $event->actor,
                $event->action,
                $notificationEvent,
                $channel,
                $record->subject,
                $record->message,
                in_array($event->action, ['reopened', 'moved', 'rerouted'], true),
            );

            foreach ($recipients['users'] as $recipient) {
                $this->deliver($recipient, $notification);
            }

            if ($channel === 'mail') {
                foreach ($recipients['emails'] as $email) {
                    $this->deliverEmail($email, $notification);
                }
            }
        }
    }

    /** @return array{users: list<Model>, emails: list<string>} */
    private function recipients(
        WorkflowStepNotification $record,
        Model $transaction,
        TransactionWorkflow $instance,
        int $cycle,
    ): array {
        $users = [];
        $emails = [];

        foreach ($record->recipients ?? [] as $recipientType) {
            if ($recipientType === 'next_handlers') {
                array_push($users, ...$this->nextHandlers($instance));
            } elseif ($recipientType === 'creator' && $transaction instanceof Approvable) {
                $creatorId = $transaction->workflowCreatorId();
                $creator = $creatorId === null ? null : $this->findUser($creatorId);

                if ($creator !== null) {
                    $users[] = $creator;
                }
            } elseif ($recipientType === 'participants') {
                array_push($users, ...$this->participants($transaction, $cycle));
            }
        }

        if ($record->channel === 'mail') {
            $emails = array_values(array_unique(array_filter($record->emails ?? [], 'is_string')));
        }

        $uniqueUsers = [];

        foreach ($users as $user) {
            if ($user instanceof Model) {
                $uniqueUsers[(string) $user->getKey()] = $user;
            }
        }

        return ['users' => array_values($uniqueUsers), 'emails' => $emails];
    }

    /** @return list<Model> */
    private function nextHandlers(TransactionWorkflow $instance): array
    {
        if (! in_array($instance->status, ['draft', 'pending'], true) || $instance->currentStep === null) {
            return [];
        }

        $step = $instance->currentStep;
        $approvable = $instance->approvable;

        if (! $approvable instanceof Model || ! method_exists($approvable, 'stepApprovers')) {
            return [];
        }

        $assignment = $approvable->stepApprovers()
            ->where('workflow_step_id', $step->getKey())
            ->where('cycle', $instance->cycle)
            ->where('status', 'pending')
            ->first();

        if ($assignment === null) {
            return [];
        }

        if ($step->assignable) {
            $user = $assignment?->approver;

            return $user instanceof Model ? [$user] : [];
        }

        $unit = $instance->approvable;
        $unitId = $unit instanceof Approvable ? $unit->workflowUnitId() : null;

        if ($unitId === null) {
            return [];
        }

        /** @var class-string<Model> $userClass */
        $userClass = (string) config('workflow-pipelines.models.user');
        $membershipRelation = (string) config('workflow-pipelines.organization.membership_relation', 'organizations');

        if (! method_exists($userClass, 'roles') || ! method_exists($userClass, $membershipRelation)) {
            return [];
        }

        return $userClass::query()
            ->whereHas('roles', static fn ($query) => $query->whereKey($step->role_id))
            ->whereHas($membershipRelation, static fn ($query) => $query->whereKey($unitId))
            ->get()
            ->all();
    }

    /** @return list<Model> */
    private function participants(Model $transaction, int $cycle): array
    {
        if (! method_exists($transaction, 'workflowLogs')) {
            return [];
        }

        return $transaction->workflowLogs()
            ->where('cycle', $cycle)
            ->with('actor')
            ->get()
            ->pluck('actor')
            ->filter(static fn ($actor): bool => $actor instanceof Model)
            ->unique(static fn (Model $actor): string => (string) $actor->getKey())
            ->values()
            ->all();
    }

    private function findUser(int $id): ?Model
    {
        /** @var class-string<Model> $userClass */
        $userClass = (string) config('workflow-pipelines.models.user');

        return $userClass::query()->find($id);
    }

    private function notificationApplies(WorkflowStepNotification $record, string $event, Model $transaction): bool
    {
        if (! in_array($event, $record->events ?? [], true)) {
            return false;
        }

        $modelTypes = $record->model_types;

        return $modelTypes === null || $modelTypes === [] || in_array($transaction->getMorphClass(), $modelTypes, true);
    }

    private function deliver(Model $recipient, WorkflowStepActionNotification $notification): void
    {
        if (! method_exists($recipient, 'notify') || ! method_exists($recipient, 'routeNotificationFor')) {
            return;
        }

        try {
            if ($this->shouldQueue()) {
                Notification::send([$recipient], $notification);
            } else {
                Notification::sendNow([$recipient], $notification);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function deliverEmail(string $email, WorkflowStepActionNotification $notification): void
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            report(new \InvalidArgumentException("Invalid workflow notification email address [$email]."));

            return;
        }

        try {
            $notifiable = new AnonymousNotifiable;
            $notifiable->route('mail', $email);

            if ($this->shouldQueue()) {
                Notification::send([$notifiable], $notification);
            } else {
                Notification::sendNow([$notifiable], $notification);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function shouldQueue(): bool
    {
        return (bool) config('workflow-pipelines.notifications.queue', false);
    }
}
