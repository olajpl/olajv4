<?php
// test_webhook.php – formularz testowy do wysyłki danych do webhooka płatności
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <title>Test Webhook Płatności</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen py-12 px-4">
  <div class="max-w-md mx-auto bg-white p-6 rounded-xl shadow">
    <h1 class="text-xl font-bold mb-4">🔁 Test Webhooka Płatności</h1>
    <form action="/api/payment_webhook.php" method="POST" class="space-y-4">
      <div>
        <label class="block text-sm font-medium text-gray-700">Kod referencyjny (reference_code)</label>
        <input type="text" name="reference_code" required class="mt-1 block w-full border px-3 py-2 rounded-lg" placeholder="OLAJ-20250731-00042">
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700">Status</label>
        <select name="status" class="mt-1 block w-full border px-3 py-2 rounded-lg">
          <option value="opłacone">opłacone</option>
          <option value="oczekujące">oczekujące</option>
          <option value="anulowane">anulowane</option>
        </select>
      </div>

      <button type="submit" class="w-full bg-blue-600 text-white py-2 px-4 rounded hover:bg-blue-700">
        Wyślij webhook
      </button>
    </form>
  </div>
</body>
</html>
