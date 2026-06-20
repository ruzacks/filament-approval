<?php

namespace Wezlo\FilamentApproval\Notifications;

use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Wezlo\FilamentApproval\FilamentApprovalPlugin;
use Wezlo\FilamentApproval\Models\ApprovalStepInstance;

class ApprovalRequestedNotification
{
    public static function send(ApprovalStepInstance $stepInstance, int $userId): void
    {
        $userModel = FilamentApprovalPlugin::resolveUserModel();
        $recipient = $userModel::find($userId);

        if (! $recipient) {
            return;
        }

        $approvable = $approval->approvable;
        $modelLabel = class_basename($approvable);

        $record = $approval->approvable;
        $resource = Filament::getModelResource(
            $record::class
        );

        $docNumber = $approvable->getKey();
        if ($modelLabel === "FundRequest") {
            $modelLabel = "Permintaan Pengeluaran Dana";
            $docNumber = $approvable->document_number;
        } else if ($modelLabel === "GeneratedDocument") {
            $modelLabel = "Permintaan Pengeluaran Dana";
            $docNumber = $approvable->title;
        }

        Notification::make()
            ->title(__('filament-approval::approval.notifications.requested_title', ['step' => $stepInstance->step->name]))
            ->body(__('filament-approval::approval.notifications.requested_body', ['model' => $modelLabel, 'id' => $docNumber]))
            ->icon(Heroicon::OutlinedClipboardDocumentCheck)
            ->actions([
                Action::make('view')
                    ->label('Lihat')
                    ->url(
                        $resource::getUrl('view', [
                            'record' => $record
                        ])
                    )
            ])
            ->warning()
            ->sendToDatabase($recipient);
    }
}
