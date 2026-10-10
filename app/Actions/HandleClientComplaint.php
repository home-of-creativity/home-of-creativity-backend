<?php

namespace App\Actions;

use App\Enums\EmployeeProfession;
use App\Models\Client;
use App\Models\Complaint;
use App\Services\GeminiService;
use App\Services\TelegramNotifier;
use App\Support\ClientUploadGuard;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Client complaint from WhatsApp or Telegram: title, description, one image, one voice note. */
class HandleClientComplaint
{
    public function __construct(
        private TelegramNotifier $telegram,
        private ClientUploadGuard $uploads,
        private GeminiService $gemini,
        private NotifyEmployees $notifyEmployees,
    ) {}

    public function active(string $key): bool
    {
        return in_array($this->state($key)['step'] ?? '', ['title', 'body', 'extra'], true);
    }

    public function wants(?string $text): bool
    {
        $text = trim((string) $text);
        if ($text === '' || $this->isEscape($text)) {
            return false;
        }

        return preg_match('/شكوى|شكاوى|أشتكي|اشتكي|complaint/ui', $text) === 1;
    }

    public function clear(string $key): void
    {
        $state = $this->state($key);
        foreach (['image_path', 'audio_path'] as $field) {
            $path = $state[$field] ?? null;
            if (is_string($path) && $path !== '') {
                Storage::disk('local')->delete($path);
            }
        }
        Cache::forget($this->cacheKey($key));
    }

    public function start(Client $client, string $chatId, string $key, string $lang): void
    {
        $this->clear($key);
        $this->put($key, ['step' => 'title']);
        $this->say($chatId, $lang, 'شو عنوان الشكوى؟', 'What is the complaint title?');
    }

    /**
     * @param  array{file_name?: string, file_base64: string, mime_type: string}|null  $image
     * @param  array{file_base64: string, mime_type: string}|null  $audio
     */
    public function turn(
        Client $client,
        string $chatId,
        string $key,
        string $lang,
        ?string $text,
        ?array $image,
        ?array $audio,
        bool $video,
    ): bool {
        $text = trim((string) $text);
        if ($this->isEscape($text)) {
            if ($this->active($key)) {
                $this->clear($key);
            }

            return false;
        }

        $state = $this->state($key);
        $step = (string) ($state['step'] ?? '');
        if ($step === '') {
            if (! $this->wants($text) || $image !== null || $audio !== null || $video) {
                return false;
            }
            $this->start($client, $chatId, $key, $lang);

            return true;
        }

        if ($video) {
            $this->say($chatId, $lang, 'الفيديو ما منقبله. ابعت صورة.', 'Video is not accepted. Send a photo.');

            return true;
        }

        if ($step === 'title') {
            if ($text === '' || $this->isBare($text)) {
                $this->say($chatId, $lang, 'شو عنوان الشكوى؟', 'What is the complaint title?');

                return true;
            }
            if (mb_strlen($text) < 2) {
                $this->say($chatId, $lang, 'اكتب عنوان أوضح.', 'Write a clearer title.');

                return true;
            }
            $state['title'] = mb_substr($text, 0, 120);
            $state['step'] = 'body';
            $this->put($key, $state);
            $this->say($chatId, $lang, 'اكتب التفاصيل، أو ابعت تسجيل صوتي.', 'Write the details, or send a voice note.');

            return true;
        }

        if ($image !== null && $step === 'body') {
            $this->say($chatId, $lang, 'اكتب التفاصيل أول، والصورة بالخطوة الجاية.', 'Write the details first. The photo comes next.');

            return true;
        }

        if ($step === 'body') {
            if ($audio !== null) {
                $stored = $this->storeAudio($audio);
                if ($stored === null) {
                    $this->say($chatId, $lang, 'التسجيل الصوتي ما انحفظ. جرب مرة ثانية أو اكتب التفاصيل.', 'The voice note was not saved. Try again or type the details.');

                    return true;
                }
                $state['audio_path'] = $stored['path'];
                $state['audio_mime'] = $stored['mime'];
                $spoken = $this->gemini->transcribeClientAudio($stored['mime'], $audio['file_base64']);
                if ($spoken !== '') {
                    $state['description'] = mb_substr($spoken, 0, 2000);
                }
            } elseif ($text !== '' && ! $this->isDone($text)) {
                $state['description'] = mb_substr($text, 0, 2000);
            } else {
                $this->say($chatId, $lang, 'اكتب التفاصيل، أو ابعت تسجيل صوتي.', 'Write the details, or send a voice note.');

                return true;
            }
            $state['step'] = 'extra';
            $this->put($key, $state);
            $this->say($chatId, $lang, 'إذا في صورة ابعتها. الفيديو ما منقبله. وإذا خلصت ابعت «تم».', 'Send a photo if you have one. Video is not accepted. Send «تم» when you are done.');

            return true;
        }

        if ($image !== null) {
            $stored = $this->storeImage($image);
            if ($stored === null) {
                $this->say($chatId, $lang, 'الصورة لازم تكون jpeg أو png أو webp. الفيديو ما منقبله.', 'The photo must be jpeg, png, or webp. Video is not accepted.');

                return true;
            }
            if (is_string($state['image_path'] ?? null) && $state['image_path'] !== '') {
                Storage::disk('local')->delete($state['image_path']);
            }
            $state['image_path'] = $stored['path'];
            $state['image_mime'] = $stored['mime'];
            $this->put($key, $state);
            $this->say($chatId, $lang, 'انحفظت الصورة. إذا في تسجيل صوتي ابعته، أو ابعت «تم».', 'The photo is saved. Send a voice note, or send «تم».');

            return true;
        }

        if ($audio !== null) {
            $stored = $this->storeAudio($audio);
            if ($stored === null) {
                $this->say($chatId, $lang, 'التسجيل الصوتي ما انحفظ. جرب مرة ثانية أو ابعت «تم».', 'The voice note was not saved. Try again or send «تم».');

                return true;
            }
            if (is_string($state['audio_path'] ?? null) && $state['audio_path'] !== '') {
                Storage::disk('local')->delete($state['audio_path']);
            }
            $state['audio_path'] = $stored['path'];
            $state['audio_mime'] = $stored['mime'];
            if (trim((string) ($state['description'] ?? '')) === '') {
                $spoken = $this->gemini->transcribeClientAudio($stored['mime'], $audio['file_base64']);
                if ($spoken !== '') {
                    $state['description'] = mb_substr($spoken, 0, 2000);
                }
            }
            $this->put($key, $state);
            $this->say($chatId, $lang, 'انحفظ التسجيل. ابعت «تم» لنسجل الشكوى.', 'The recording is saved. Send «تم» to file the complaint.');

            return true;
        }

        if ($text !== '' && ! $this->isDone($text) && mb_strlen($text) > 1) {
            $current = trim((string) ($state['description'] ?? ''));
            $state['description'] = mb_substr(trim($current."\n".$text), 0, 2000);
            $this->put($key, $state);
            $this->say($chatId, $lang, 'انضافت التفاصيل. ابعت صورة أو «تم».', 'The details were added. Send a photo or «تم».');

            return true;
        }

        if (! $this->isDone($text)) {
            $this->say($chatId, $lang, 'ابعت صورة، أو تسجيل صوتي، أو «تم».', 'Send a photo, a voice note, or «تم».');

            return true;
        }

        $description = trim((string) ($state['description'] ?? ''));
        if ($description === '' && filled($state['audio_path'] ?? null)) {
            $description = 'تسجيل صوتي';
        }
        if ($description === '' || ! filled($state['title'] ?? null)) {
            $this->say($chatId, $lang, 'اكتب التفاصيل قبل ما تنسجل الشكوى.', 'Write the details before the complaint is filed.');

            return true;
        }

        Complaint::query()->create([
            'client_id' => $client->id,
            'title' => (string) $state['title'],
            'description' => $description,
            'image_path' => $state['image_path'] ?? null,
            'image_mime' => $state['image_mime'] ?? null,
            'audio_path' => $state['audio_path'] ?? null,
            'audio_mime' => $state['audio_mime'] ?? null,
            'status' => 'open',
        ]);
        Cache::forget($this->cacheKey($key));
        $who = filled($client->company_name) ? (string) $client->company_name : (string) ($client->name ?: $client->phone);
        $this->notifyEmployees->handlePlain(
            EmployeeProfession::Sales,
            "شكوى جديدة من {$who}: {$state['title']}\n{$description}",
        );
        $this->say($chatId, $lang, 'وصلت الشكوى لفريق المبيعات، ورح يراجعوها.', 'The complaint reached the sales team, and they will review it.');

        return true;
    }

    /**
     * @param  array{file_base64: string, mime_type: string}  $image
     * @return array{path: string, mime: string}|null
     */
    private function storeImage(array $image): ?array
    {
        $binary = base64_decode((string) $image['file_base64'], true);
        if ($binary === false) {
            return null;
        }
        $mime = strtolower(trim(explode(';', (string) $image['mime_type'])[0] ?? ''));
        if (str_starts_with($mime, 'video/')) {
            return null;
        }
        try {
            $mime = $this->uploads->assertReceipt($binary, $mime);
        } catch (ValidationException) {
            return null;
        }
        if ($mime === 'application/pdf') {
            return null;
        }
        $path = 'complaints/images/'.Str::uuid().'.'.$this->uploads->extension($mime);
        Storage::disk('local')->put($path, $binary);

        return ['path' => $path, 'mime' => $mime];
    }

    /**
     * @param  array{file_base64: string, mime_type: string}  $audio
     * @return array{path: string, mime: string}|null
     */
    private function storeAudio(array $audio): ?array
    {
        $binary = base64_decode((string) $audio['file_base64'], true);
        if ($binary === false || strlen($binary) > 5 * 1024 * 1024) {
            return null;
        }
        $mime = strtolower(trim(explode(';', (string) $audio['mime_type'])[0] ?? ''));
        $allowed = ['audio/ogg', 'audio/opus', 'audio/mpeg', 'audio/mp3', 'audio/mp4', 'audio/m4a', 'audio/x-m4a', 'audio/wav', 'audio/webm', 'audio/aac'];
        if (! in_array($mime, $allowed, true)) {
            return null;
        }
        if ($mime === 'audio/mp3') {
            $mime = 'audio/mpeg';
        }
        $ext = match ($mime) {
            'audio/mpeg' => 'mp3',
            'audio/mp4', 'audio/m4a', 'audio/x-m4a', 'audio/aac' => 'm4a',
            'audio/wav' => 'wav',
            'audio/webm' => 'webm',
            default => 'ogg',
        };
        $path = 'complaints/audio/'.Str::uuid().'.'.$ext;
        Storage::disk('local')->put($path, $binary);

        return ['path' => $path, 'mime' => $mime];
    }

    /** @return array<string, mixed> */
    private function state(string $key): array
    {
        $state = Cache::get($this->cacheKey($key), []);

        return is_array($state) ? $state : [];
    }

    /** @param  array<string, mixed>  $state */
    private function put(string $key, array $state): void
    {
        Cache::put($this->cacheKey($key), $state, now()->addDay());
    }

    private function cacheKey(string $key): string
    {
        return 'hoc:complaint:'.$key;
    }

    private function say(string $chatId, string $lang, string $arabic, string $english): void
    {
        try {
            $this->telegram->send($chatId, $lang === 'en' ? $english : $arabic);
        } catch (Throwable) {
            // The complaint step stays so the client can answer again.
        }
    }

    private function isEscape(string $text): bool
    {
        $needles = [
            'طلب جديد', '🆕 طلب جديد', 'طلباتي', '📋 طلباتي', 'حجز تصوير', '📷 حجز تصوير',
            'الدعم', '💬 دعم', 'استفسار', 'بياناتي', 'تجديد الاشتراك',
            'menu:new', 'menu:mine', 'menu:photo', 'menu:help', 'menu:ask', 'menu:profile', 'menu:renew', 'menu:home',
            'new request', 'my requests', 'photo booking', 'support', 'inquiry', 'my details',
        ];

        return in_array(mb_strtolower(trim($text)), $needles, true);
    }

    private function isBare(string $text): bool
    {
        return preg_match('/^(شكوى|شكاوى|تقديم شكوى|بدي أقدم شكوى|complaint)$/ui', trim($text)) === 1;
    }

    private function isDone(string $text): bool
    {
        return preg_match('/^(تم|خلص|خلاص|بدون|بدون صورة|لا صورة|ما في صورة|no photo|done|skip)$/ui', trim($text)) === 1;
    }
}
