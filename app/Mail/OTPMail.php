<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OTPMail extends Mailable
{
    use Queueable, SerializesModels;

    public $otp;
    public $name;
    public $mailSubject;

    public function __construct($otp, $name = 'User', $mailSubject = 'Your OTP Code')
    {
        $this->otp = $otp;
        $this->name = $name;
        $this->mailSubject = $mailSubject;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->mailSubject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.otp',
            with: [
                'otp' => $this->otp,
                'name' => $this->name,
                'subject' => $this->mailSubject,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
