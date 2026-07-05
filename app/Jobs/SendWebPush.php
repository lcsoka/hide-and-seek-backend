<?php

namespace App\Jobs;

use App\Models\PushSubscription;
use App\Support\PushNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Delivers one localized Web Push notification to every subscription of the given users. The text is
 * resolved per subscription so each device gets its own language. Expired/gone subscriptions are
 * pruned from their delivery report. No-ops when VAPID isn't configured.
 *
 * @param  array<int, int>  $userIds
 * @param  array<string, mixed>  $params
 */
class SendWebPush implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, int>  $userIds
     * @param  string  $key  translation key prefix, e.g. "push.question_asked"
     * @param  array<string, mixed>  $params  translation params (e.g. ['name' => 'Anna'])
     */
    public function __construct(
        public array $userIds,
        public string $key,
        public array $params,
        public string $url,
        public string $tag,
    ) {}

    public function handle(): void
    {
        if (! PushNotifier::configured() || empty($this->userIds)) {
            return;
        }

        $subscriptions = PushSubscription::whereIn('user_id', $this->userIds)->get();
        if ($subscriptions->isEmpty()) {
            return;
        }

        $webPush = new WebPush(['VAPID' => [
            'subject' => config('webpush.vapid.subject'),
            'publicKey' => config('webpush.vapid.public_key'),
            'privateKey' => config('webpush.vapid.private_key'),
        ]]);

        foreach ($subscriptions as $sub) {
            $webPush->queueNotification(
                Subscription::create([
                    'endpoint' => $sub->endpoint,
                    'publicKey' => $sub->public_key,
                    'authToken' => $sub->auth_token,
                    'contentEncoding' => config('webpush.content_encoding'),
                ]),
                // Angular's ngsw-worker reads `notification.*` and uses `data.onActionClick.default`
                // to open the URL when the notification is clicked.
                (string) json_encode([
                    'notification' => [
                        'title' => __($this->key.'.title', $this->params, $sub->locale),
                        'body' => __($this->key.'.body', $this->params, $sub->locale),
                        'icon' => '/icon-192.png',
                        'badge' => '/icon-192.png',
                        'tag' => $this->tag,
                        'data' => [
                            'onActionClick' => [
                                'default' => ['operation' => 'openWindow', 'url' => $this->url],
                            ],
                        ],
                    ],
                ]),
            );
        }

        foreach ($webPush->flush() as $report) {
            if (! $report->isSuccess() && $report->isSubscriptionExpired()) {
                PushSubscription::where('endpoint', $report->getEndpoint())->delete();
            }
        }
    }
}
