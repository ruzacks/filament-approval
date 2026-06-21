<?php

namespace Wezlo\FilamentApproval\Commands;

use Illuminate\Console\Command;
use Wezlo\FilamentApproval\Enums\ActionType;
use Wezlo\FilamentApproval\Enums\EscalationAction;
use Wezlo\FilamentApproval\Enums\StepInstanceStatus;
use Wezlo\FilamentApproval\Events\ApprovalEscalated;
use Wezlo\FilamentApproval\Models\ApprovalStepInstance;
use Wezlo\FilamentApproval\Notifications\ApprovalEscalatedNotification;
use Wezlo\FilamentApproval\Notifications\ApprovalRequestedNotification;
use Wezlo\FilamentApproval\Notifications\ApprovalSlaWarningNotification;
use Wezlo\FilamentApproval\Services\ApprovalEngine;

class ProcessApprovalSlaCommand extends Command
{
    protected $signature = 'approval:process-sla';

    protected $description = 'Check for SLA warnings and breaches on pending approval steps';

    public function handle(ApprovalEngine $engine): int
    {
        $warningThreshold = config('filament-approval.sla_warning_threshold', 0.75);

        $this->processWarnings($warningThreshold);
        $this->processBreaches($engine);

        return self::SUCCESS;
    }

    protected function processWarnings(float $warningThreshold): void
    {
        $candidates = ApprovalStepInstance::query()
            ->where('status', StepInstanceStatus::Waiting)
            ->whereNotNull('sla_deadline')
            ->where('sla_warning_sent', false)
            ->where('sla_breached', false)
            ->get();

        foreach ($candidates as $instance) {
            $totalDuration = $instance->activated_at->diffInSeconds($instance->sla_deadline);
            $elapsed = $instance->activated_at->diffInSeconds(now());

            if ($elapsed >= $totalDuration * $warningThreshold) {
                $instance->update(['sla_warning_sent' => true]);

                foreach ($instance->assigned_approver_ids as $userId) {
                    ApprovalSlaWarningNotification::send($instance, $userId);
                }
            }
        }
    }

    protected function processBreaches(ApprovalEngine $engine): void
    {
        $breached = ApprovalStepInstance::query()
            ->where('status', StepInstanceStatus::Waiting)
            ->whereNotNull('sla_deadline')
            ->where('sla_deadline', '<=', now())
            ->where('sla_breached', false)
            ->with(['step', 'approval'])
            ->get();

        foreach ($breached as $instance) {
            $instance->update(['sla_breached' => true]);
            $this->handleEscalation($instance, $engine);
        }
    }

    protected function handleEscalation(ApprovalStepInstance $instance, ApprovalEngine $engine): void
    {
        $step = $instance->step;
        $action = $step->escalation_action;

        if (! $action) {
            return;
        }

        $instance->approval->actions()->create([
            'approval_step_instance_id' => $instance->id,
            'type' => ActionType::Escalated,
            'metadata' => ['escalation_action' => $action->value],
        ]);

        match ($action) {
            EscalationAction::Notify => $this->sendEscalationNotification($instance),
            EscalationAction::AutoApprove => $engine->approve($instance, 0, __('filament-approval::approval.sla.auto_approved')),
            EscalationAction::Reject => $engine->reject($instance, 0, __('filament-approval::approval.sla.auto_rejected')),
            EscalationAction::Reassign => $this->reassign($instance),
        };

        event(new ApprovalEscalated($instance));

        $approvable = $instance->approval->approvable;

        if ($approvable && method_exists($approvable, 'onApprovalEscalated')) {
            $approvable->onApprovalEscalated($instance);
        }
    }

    protected function reassign(ApprovalStepInstance $instance): void
    {
        $config = $instance->step->escalation_config ?? [];
        $resolverClass = $config['reassign_to_resolver'] ?? null;
        $resolverConfig = $config['reassign_config'] ?? [];

        if (! $resolverClass || ! class_exists($resolverClass)) {
            return;
        }

        $resolver = app($resolverClass);
        $newApproverIds = $resolver->resolve($resolverConfig, $instance->approval->approvable);

        $instance->update([
            'assigned_approver_ids' => $newApproverIds,
            'sla_breached' => false,
            'sla_warning_sent' => false,
            'sla_deadline' => $instance->step->sla_hours
                ? now()->addMinutes($instance->step->sla_hours)
                : null,
        ]);

        foreach ($newApproverIds as $userId) {
            ApprovalRequestedNotification::send($instance, $userId);
        }
    }

    protected function sendEscalationNotification(ApprovalStepInstance $instance): void
    {
        foreach ($instance->assigned_approver_ids as $userId) {
            ApprovalEscalatedNotification::send($instance, $userId);
        }
    }
}
