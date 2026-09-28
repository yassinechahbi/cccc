<?php

namespace App\Notifications;

use App\Models\CircuitLeg;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Warns the next member that a cover has been mailed to them. */
class CircuitOnItsWay extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public CircuitLeg $leg) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $circuit = $this->leg->circuit;
        $sender = $this->leg->previous()?->member;

        return (new MailMessage)
            ->subject(__('A CCCC cover is on its way to you (circuit #:number)', ['number' => $circuit->number]))
            ->greeting(__('Hello :name,', ['name' => $this->leg->member->name]))
            ->line(__(':sender (:country) has just mailed circuit #:number to you.', [
                'sender' => $sender?->name,
                'country' => $sender?->country?->name,
                'number' => $circuit->number,
            ]))
            ->line(__('When it arrives, scan the QR code in your box on the circuit form, or enter your code on the website.'))
            ->action(__('Confirm a circuit'), route('track'));
    }
}
