<?php
declare(strict_types=1);

namespace Engine\CentralMessaging\Channels;

use PDO;
use Engine\Log\LogEngine;

final class Messenger
{
    /**
     * $message – oczekiwany format:
     * [
     *   'owner_id' => 1,
     *   'platform_user_id' => 'PSID',
     *   'text' => 'Twoje zamówienie ...',
     *   'buttons' => [
     *       ['type'=>'web_url','title'=>'Zapłać teraz','url'=>'https://...'],
     *       ['type'=>'postback','title'=>'Pokaż status','payload'=>'STATUS:123']
     *   ],
     *   'page_token' => 'EAAG...'
     * ]
     */
    public static function send(PDO $pdo, array $message): bool
    {
        $ownerId   = (int)($message['owner_id'] ?? 0);
        $psid      = $message['platform_user_id'] ?? null;
        $text      = $message['text'] ?? '';
        $buttons   = $message['buttons'] ?? [];
        $pageToken = $message['page_token'] ?? null;

        $log = LogEngine::boot($pdo, $ownerId, [
            'context' => 'cw.messenger',
            'source'  => 'MessengerChannel'
        ]);

        if (!$psid || !$pageToken) {
            $log->error('cw.messenger','missing_params', compact('psid','pageToken'));
            return false;
        }

        try {
            $payload = [
                'recipient' => ['id' => $psid],
            ];

            if (!empty($buttons)) {
                // Button template – max 3 guziki
                $fbButtons = [];
                foreach (array_slice($buttons, 0, 3) as $btn) {
                    if ($btn['type'] === 'web_url' && !empty($btn['url'])) {
                        $fbButtons[] = [
                            'type'  => 'web_url',
                            'url'   => $btn['url'],
                            'title' => mb_substr($btn['title'] ?? 'Otwórz', 0, 20)
                        ];
                    } elseif ($btn['type'] === 'postback' && !empty($btn['payload'])) {
                        $fbButtons[] = [
                            'type'    => 'postback',
                            'payload' => $btn['payload'],
                            'title'   => mb_substr($btn['title'] ?? 'OK', 0, 20)
                        ];
                    }
                }

                $payload['message'] = [
                    'attachment' => [
                        'type' => 'template',
                        'payload' => [
                            'template_type' => 'button',
                            'text' => $text ?: ' ',
                            'buttons' => $fbButtons
                        ]
                    ]
                ];
            } else {
                // Zwykła wiadomość tekstowa
                $payload['message'] = ['text' => $text];
            }

            $ch = curl_init('https://graph.facebook.com/v18.0/me/messages?access_token=' . urlencode($pageToken));
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE)
            ]);

            $resp = curl_exec($ch);
            $err  = curl_error($ch);
            $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($err || $http >= 400) {
                $log->error('cw.messenger','send_failed', ['err'=>$err,'http'=>$http,'resp'=>$resp,'payload'=>$payload]);
                return false;
            }

            $log->info('cw.messenger','send_ok', ['http'=>$http,'resp'=>$resp,'psid'=>$psid]);
            return true;
        } catch (\Throwable $e) {
            $log->error('cw.messenger','exception', ['msg'=>$e->getMessage(),'trace'=>$e->getTraceAsString()]);
            return false;
        }
    }
}
