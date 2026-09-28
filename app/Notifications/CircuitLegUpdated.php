<?php

namespace App\Notifications;

use App\Models\CircuitLeg;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells the Originating Member that one of their circuits moved. */
class CircuitLegUpdated extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  'received'|'mailed'  $event */
    public function __construct(public CircuitLeg $leg, public string $event) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $leg = $this->leg;
        $circuit = $leg->circuit;
        $member = $leg->member;
        $who = $member->name.' ('.$member->country?->name.')';

        $message = (new MailMessage)->subject(__('Circuit #:number: step :position :event', [
            'number' => $circuit->number,
            'position' => $leg->position,
            'event' => $this->event === 'received' ? __('received') : __('mailed on'),
        ]));

        if ($this->event === 'received') {
            $message->line(__(':who received circuit #:number on :date.', ['who' => $who, 'number' => $circuit->number, 'date' => $leg->received_at->format('Y-m-d')]));

            if ($leg->quality) {
                $message->line(__('Cover quality: :quality.', ['quality' => $leg->quality->label()]));
            }
        } else {
            $message->line(__(':who mailed circuit #:number to the next member on :date.', ['who' => $who, 'number' => $circuit->number, 'date' => $leg->mailed_at->format('Y-m-d')]));
        }

        return $message->action(__('Follow the circuit'), route('circuits.show', $circuit));
    }
}
