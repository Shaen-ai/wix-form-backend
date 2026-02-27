<?php

namespace App\Services;

use App\Mail\AutoReplyMail;
use App\Mail\NotificationMail;
use App\Models\Form;
use App\Models\Submission;
use App\Models\TenantSettings;
use Illuminate\Support\Facades\Mail;

class MailService
{
    public function sendNotification(Submission $submission, Form $form, ?TenantSettings $settings = null): void
    {
        $settings ??= TenantSettings::find($submission->tenant_id);
        $formSettings = $form->settings_json ?? [];

        $email = ! empty($formSettings['adminNotificationEmail'])
            ? $formSettings['adminNotificationEmail']
            : $settings?->notification_email;

        if (empty($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        Mail::to($email)->send(new NotificationMail($submission, $form));
    }

    public function sendAutoReply(Submission $submission, Form $form, ?TenantSettings $settings = null): void
    {
        $settings ??= TenantSettings::find($submission->tenant_id);
        $formSettings = $form->settings_json ?? [];

        $enabled = isset($formSettings['userConfirmationEmail'])
            ? (bool) $formSettings['userConfirmationEmail']
            : (bool) $settings?->auto_reply_enabled;

        if (! $enabled) {
            return;
        }

        $email = $this->extractSubmitterEmail($submission, $form);
        if (empty($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        Mail::to($email)->send(new AutoReplyMail($submission, $form, $settings));
    }

    private function extractSubmitterEmail(Submission $submission, Form $form): ?string
    {
        $data = $submission->data_json ?? [];
        $fields = $form->formFields()->get()->keyBy('id');

        foreach ($data as $fieldId => $value) {
            $field = $fields->get($fieldId);
            if ($field && $field->type === 'email' && is_string($value)) {
                return $value;
            }
        }

        return null;
    }
}
