<?php
// llama_test.php – test połączenia z Ollama na localhost

$url = 'http://localhost:11434/api/generate';
$prompt = "Zamówienie: daj 1120x2";

$data = [
    'model' => 'llama3',
    'prompt' => "Zinterpretuj wiadomość klienta: \"$prompt\". Odpowiedz JSON-em w formacie: {\"code\":1234, \"qty\":2}",
    'stream' => false
];

$options = [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode($data),
];

$ch = curl_init();
curl_setopt_array($ch, $options);

$response = curl_exec($ch);
curl_close($ch);

echo "🔍 Odpowiedź LLaMY:\n";
echo $response;
