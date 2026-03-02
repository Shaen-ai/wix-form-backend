<?php

namespace App\Mail;

use App\Models\Form;
use App\Models\Submission;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public array $rows;
    public ?string $submitterName;
    public ?string $submitterEmail;

    public function __construct(
        public Submission $submission,
        public Form $form
    ) {
        $fields = $form->formFields()->orderBy('order_index')->get()->keyBy('id');
        $data = $submission->data_json ?? [];

        $this->rows = [];
        $this->submitterName = null;
        $this->submitterEmail = null;

        foreach ($fields as $fieldId => $field) {
            if (! array_key_exists($fieldId, $data)) {
                continue;
            }

            $value = $data[$fieldId];
            $display = is_array($value) ? ($value['value'] ?? json_encode($value)) : (string) $value;

            if ($display === '' || $display === '[]') {
                continue;
            }

            $this->rows[] = [
                'label' => $field->label,
                'value' => $display,
                'type' => $field->type,
            ];

            if ($field->type === 'email' && $this->submitterEmail === null) {
                $this->submitterEmail = $display;
            }

            if ($field->type === 'name' && $this->submitterName === null) {
                $this->submitterName = $display;
            }
        }

        if ($this->submitterName === null) {
            foreach ($this->rows as $row) {
                if (stripos($row['label'], 'name') !== false) {
                    $this->submitterName = $row['value'];
                    break;
                }
            }
        }
    }

    public function envelope(): Envelope
    {
        $subject = trim((string) $this->form->name) !== ''
            ? $this->form->name
            : 'New form submission';

        $envelope = new Envelope(
            subject: $subject,
        );

        if ($this->submitterEmail) {
            $envelope->replyTo($this->submitterEmail, $this->submitterName ?? '');
        }

        return $envelope;
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.notification'
        );
    }
}
