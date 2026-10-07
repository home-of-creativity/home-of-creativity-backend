<?php

namespace App\Contracts;

interface WhatsAppMessenger
{
    public function configured(): bool;

    public function sendText(string $to, string $text): string;

    /**
     * @param  list<array{text: string, callback_data: string}>  $buttons
     */
    public function sendReplyButtons(string $to, string $text, array $buttons): string;

    /**
     * @param  list<array{text: string, callback_data: string}>  $buttons
     */
    public function sendList(string $to, string $text, array $buttons): string;

    public function sendDocument(string $to, string $absolutePath, ?string $caption = null, ?string $filename = null): string;

    public function sendImage(string $to, string $absolutePath, ?string $caption = null): string;

    /**
     * @return array{binary: string, mime: string, filename: string}
     */
    public function downloadMedia(string $mediaId): array;

    public function markRead(string $messageId): void;
}
