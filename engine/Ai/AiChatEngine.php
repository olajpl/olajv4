<?php
// engine/ai/AiChatEngine.php — wrapper nad AiEngine (Ollama chat + cache)
// Działa z admin/ai/ajax_chat.php, który woła saveMessage/loadHistory/sendMessage.
declare(strict_types=1);

namespace Engine\Ai;

use PDO;
use Throwable;

if (!\function_exists('logg')) {
    function logg(string $level, string $channel, string $message, array $ctx = [], array $extra = []): void {
        error_log('[logg-fallback] ' . json_encode(compact('level','channel','message','ctx','extra'), JSON_UNESCAPED_UNICODE));
    }
}

final class AiChatEngine
{
    private PDO $pdo;
    private int $ownerId;
    private int $userId;
    private string $model;

    public function __construct(PDO $pdo, int $ownerId, int $userId, ?string $model = null)
    {
        $this->pdo     = $pdo;
        $this->ownerId = $ownerId;
        $this->userId  = $userId;
        $this->model   = $model ?: (getenv('OLLAMA_MODEL') ?: 'llama3.1:8b');

        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    public static function boot(PDO $pdo, int $ownerId, int $userId, ?string $model = null): self
    {
        return new self($pdo, $ownerId, $userId, $model);
    }

    /** Zapisz wiadomość do historii. */
    public function saveMessage(string $role, string $message, ?array $context = null): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO ai_chat_history (owner_id, user_id, role, message, context_json, created_at)
            VALUES (:o, :u, :r, :m, :ctx, NOW())
        ");
        $stmt->execute([
            ':o'   => $this->ownerId,
            ':u'   => $this->userId,
            ':r'   => $role,
            ':m'   => $message,
            ':ctx' => $context ? json_encode($context, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    /** Pobierz ostatnie N wiadomości. */
    public function loadHistory(int $limit = 50): array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, role, message, context_json, created_at
            FROM ai_chat_history
            WHERE owner_id=:o AND user_id=:u
            ORDER BY id DESC
            LIMIT :lim
        ");
        $stmt->bindValue(':o',   $this->ownerId, \PDO::PARAM_INT);
        $stmt->bindValue(':u',   $this->userId,  \PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit,         \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        return array_reverse($rows);
    }

    /** Wyślij wiadomość do modelu, zapisz odpowiedź. */
    public function sendMessage(string $message, ?array $context = null): array
    {
        $messages = $this->buildMessages($message, $context);

        // Chat przez AiEngine (cache-first)
       $res = AiEngine::askChatCached(
    $this->pdo,
    $this->ownerId,
    $messages,
    $this->model,
    [
        'temperature'    => 0.3,
        'fallback_model' => 'llama3:latest',
        'timeout'        => 180,         // <— tu!
        'options'        => ['num_predict' => 128],
    ]
);


        $assistant = trim((string)($res['text'] ?? ''));
        if ($assistant === '') {
            $assistant = 'Nie udało się uzyskać odpowiedzi.';
        }

        $this->saveMessage('assistant', $assistant, [
            'backend' => 'ollama',
            'model'   => $res['model'] ?? $this->model,
        ]);

        logg('info', 'ai.chat', 'assistant_reply', [
            'owner_id' => $this->ownerId,
            'user_id'  => $this->userId,
            'model'    => $res['model'] ?? $this->model,
            'len'      => mb_strlen($assistant),
        ]);

        return [
            'role'    => 'assistant',
            'content' => $assistant,
            'backend' => 'ollama',
            'model'   => $res['model'] ?? $this->model,
        ];
    }

    /** Zbuduj system prompt + ostatnią historię + nową wiadomość. */
    private function buildMessages(string $lastMessage, ?array $context): array
    {
        $system = [
  'role'=>'system',
  'content'=>"Jesteś pomocnym, profesjonalnym, ale swobodnym asystentem w systemie Olaj.pl V4. 
Piszesz pełnymi zdaniami po polsku, używając naturalnego stylu i poprawnej gramatyki. 
Możesz podawać przykłady i rozwijać myśli, ale bez lania wody."
];


        $history = $this->loadHistory(12);
        $msgs = [$system];
        foreach ($history as $h) {
            $msgs[] = ['role' => $h['role'], 'content' => (string)$h['message']];
        }

        $user = $lastMessage;
        if (!empty($context)) {
            $user .= "\n\n[Kontekst JSON]\n" . json_encode($context, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        }
        $msgs[] = ['role' => 'user', 'content' => $user];

        return $msgs;
    }
}
