<?php
http_response_code(404);
?>
<!doctype html>
<html lang="pl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Checkout — nie znaleziono</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css">
</head>

<body class="bg-gray-50 min-h-screen flex items-center">
    <div class="max-w-md mx-auto p-6 bg-white rounded-xl shadow">
        <h1 class="text-xl font-bold mb-2">😕 Nie znaleziono</h1>
        <p class="text-gray-600 mb-4">
            Brakuje informacji do wyświetlenia checkoutu albo token jest niepoprawny.
        </p>
        <div class="flex gap-2">
            <a href="/cart/index.php" class="px-4 py-2 rounded bg-pink-600 text-white">Wróć do koszyka</a>
            <a href="/" class="px-4 py-2 rounded border">Strona główna</a>
        </div>
    </div>
</body>

</html>