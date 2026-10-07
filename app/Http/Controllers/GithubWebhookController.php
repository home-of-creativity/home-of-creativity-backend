<?php

namespace App\Http\Controllers;

use App\Services\DevAlert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class GithubWebhookController extends Controller
{
    public function __invoke(Request $request, DevAlert $alert): JsonResponse
    {
        $secret = (string) config('services.github.webhook_secret');
        $signature = (string) $request->header('X-Hub-Signature-256', '');
        $digest = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);
        if ($secret === '' || $signature === '' || ! hash_equals($digest, $signature)) {
            abort(401, 'Invalid webhook secret.');
        }

        $delivery = (string) $request->header('X-GitHub-Delivery', '');
        if ($delivery !== '' && ! Cache::add('github.delivery.'.$delivery, true, now()->addDay())) {
            return response()->json(['data' => ['ignored' => 'duplicate'], 'message' => 'ok']);
        }

        $event = (string) $request->header('X-GitHub-Event', '');
        if ($event !== 'workflow_job') {
            return response()->json(['data' => ['ignored' => 'event'], 'message' => 'ok']);
        }

        $action = (string) $request->input('action', '');
        $job = $request->input('workflow_job');
        if (! is_array($job) || ! in_array($action, ['in_progress', 'completed'], true)) {
            return response()->json(['data' => ['ignored' => 'action'], 'message' => 'ok']);
        }

        if ($this->isSilentSchedule($job)) {
            return response()->json(['data' => ['ignored' => 'schedule'], 'message' => 'ok']);
        }

        $repo = (string) $request->input('repository.full_name', 'github');
        $name = (string) ($job['name'] ?? 'job');
        $url = (string) ($job['html_url'] ?? '');
        $text = $action === 'in_progress'
            ? "بدأ GitHub\n{$repo}\n{$name}".($url !== '' ? "\n{$url}" : '')
            : $this->completedText($repo, $name, $job, $url);

        Cache::put('dev.github.last', $text, now()->addDays(7));
        $alert->send($text);

        return response()->json(['data' => ['sent' => true], 'message' => 'ok']);
    }

    /**
     * @param  array<string, mixed>  $job
     */
    private function isSilentSchedule(array $job): bool
    {
        if (($job['conclusion'] ?? null) !== 'success') {
            return false;
        }

        $steps = is_array($job['steps'] ?? null) ? $job['steps'] : [];
        if (count($steps) < 2) {
            return false;
        }

        $ran = 0;
        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }
            if (($step['conclusion'] ?? '') !== 'skipped') {
                $ran++;
            }
        }

        return $ran <= 1;
    }

    /**
     * @param  array<string, mixed>  $job
     */
    private function completedText(string $repo, string $name, array $job, string $url): string
    {
        $conclusion = (string) ($job['conclusion'] ?? 'completed');
        $result = $conclusion === 'success' ? 'نجح' : 'فشل';
        $step = $this->failedStep($job);
        $lines = ["انتهى GitHub {$result}", $repo, $name];
        if ($step !== '') {
            $lines[] = 'الخطوة: '.$step;
        }
        if ($url !== '') {
            $lines[] = $url;
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $job
     */
    private function failedStep(array $job): string
    {
        $steps = is_array($job['steps'] ?? null) ? $job['steps'] : [];
        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }
            if (($step['conclusion'] ?? '') === 'failure') {
                return (string) ($step['name'] ?? '');
            }
        }

        return '';
    }
}
