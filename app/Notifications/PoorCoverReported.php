<?php

namespace App\Notifications;

use App\Models\CircuitLeg;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Club rule: a cover graded POOR is reported to the Managing Director. */
class PoorCoverReported extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public CircuitLeg $leg) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $leg = $this->leg;
        $sender = $leg->previous()?->member ?? $leg->circuit->originatingMember;

        return (new MailMessage)
            ->subject(__('POOR cover reported on circuit #:number', ['number' => $leg->circuit->number]))
            ->line(__(':receiver (#:receiverNumber) graded as POOR the cover sent by :sender (#:senderNumber).', [
                'receiver' => $leg->member->name,
                'receiverNumber' => $leg->member->member_number,
                'sender' => $sender->name,
                'senderNumber' => $sender->member_number,
            ]))
            ->when($leg->comment, fn (MailMessage $m) => $m->line(__('Comment: :comment', ['comment' => $leg->comment])))
            ->action(__('See the circuit'), route('circuits.show', $leg->circuit));
    }
}
