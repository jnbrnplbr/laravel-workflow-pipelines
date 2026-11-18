<?php

declare(strict_types=1);

namespace Jnbrnplbr\WorkflowPipelines\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Jnbrnplbr\WorkflowPipelines\Models\TransactionWorkflow;

final class WorkflowStepActionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Model $transaction,
        private readonly TransactionWorkflow $workflowInstance,
        private readonly Model $actor,
        private readonly string $action,
        private readonly string $event,
        private readonly string $channel,
        private readonly ?string $subject = null,
        private readonly ?string $message = null,
        private readonly bool $administratorAction = false,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return [$this->channel];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'workflow_step_action',
            'action' => $this->action,
            'event' => $this->event,
            'transaction_type' => $this->transaction->getMorphClass(),
            'transaction_id' => $this->transaction->getKey(),
            'title' => $this->transactionTitle(),
            'url' => $this->transactionUrl(),
            'actor_id' => $this->actor->getKey(),
            'actor_name' => $this->actorName(),
            'workflow_id' => $this->workflowInstance->workflow_id,
            'workflow_status' => $this->workflowInstance->status,
            'administrator_action' => $this->administratorAction,
            'message' => $this->renderedMessage(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->subject ?? $this->defaultSubject())
            ->greeting('Workflow update')
            ->line($this->renderedMessage());

        $url = $this->transactionUrl();

        if ($url !== null) {
            $mail->action('View transaction', $url);
        }

        return $mail->line('Transaction: '.$this->transactionTitle());
    }

    private function transactionTitle(): string
    {
        return method_exists($this->transaction, 'workflowTitle')
            ? $this->transaction->workflowTitle()
            : (string) ($this->transaction->getAttribute('title') ?? $this->transaction->getKey());
    }

    private function transactionUrl(): ?string
    {
        return method_exists($this->transaction, 'workflowUrl')
            ? $this->transaction->workflowUrl()
            : null;
    }

    private function actorName(): string
    {
        return (string) ($this->actor->getAttribute('name') ?? $this->actor->getKey());
    }

    private function defaultSubject(): string
    {
        return 'Workflow '.$this->action.': '.$this->transactionTitle();
    }

    private function renderedMessage(): string
    {
        if ($this->message !== null && $this->message !== '') {
            $message = strtr($this->message, [
                '{transaction}' => $this->transactionTitle(),
                '{actor}' => $this->actorName(),
                '{action}' => $this->action,
                '{url}' => $this->transactionUrl() ?? '',
            ]);

            return $this->administratorAction ? 'Super Administrator action: '.$message : $message;
        }

        $prefix = $this->administratorAction ? 'A Super Administrator ' : '';

        return $prefix.$this->actorName().' '.$this->action.' the workflow for '.$this->transactionTitle().'.';
    }
}
