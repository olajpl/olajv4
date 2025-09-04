<?php
// login.php — bezpieczne logowanie do panelu Olaj.pl
declare(strict_types=1);

$lifetime = 8 * 60 * 60;
session_set_cookie_params([
    'lifetime' => $lifetime,
    'path'     => '/',
    'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);
error_reporting(E_ALL);
ini_set('display_errors', '1');
session_start();

require_once($_SERVER['DOCUMENT_ROOT'] . '/../includes/db.php');

if (!isset($pdo)) {
    exit('❌ Brak $pdo — coś poszło nie tak z bazą!');
}

$errors = [];
$email = '';
$rememberMe = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $password = preg_replace('/[\x00-\x1F\x7F]/u', '', $password);
    $rememberMe = isset($_POST['remember']);

    if ($email === '' || $password === '') {
        $errors[] = 'Wpisz email i hasło.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['last_activity'] = time();
            $_SESSION['user'] = [
                'id'       => $user['id'],
                'owner_id' => $user['owner_id'],
                'role'     => $user['role'],
                'email'    => $user['email'],
            ];

            if ($rememberMe) {
                $token = bin2hex(random_bytes(32));
                setcookie('remember_me', $token, [
                    'expires'  => time() + (30 * 24 * 60 * 60),
                    'path'     => '/',
                    'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
                file_put_contents(__DIR__ . '/../includes/remember_tokens/' . sha1($token), json_encode([
                    'email' => $user['email'],
                    'ip'    => $_SERVER['REMOTE_ADDR'],
                    'ts'    => time(),
                ]));
            }

            header("Location: ../admin/index.php");
            exit;
        } else {
            usleep(250000);
            $errors[] = 'Nieprawidłowy login lub hasło.';
        }
    }
}

$welcomeMessages = [
    "Witaj w panelu admina! ☕ Kawa po lewej, śmieszki po prawej.",
    "Adminie, dziś twoja kawa ma supermoce! 🚀",
    "Cześć Szefie! Wszystkie zamówienia czekają na Twój rozkaz.",
    "Dzień dobry! System Olaj.pl w gotowości 💪",
    "Hej! Pamiętaj, że kawa nie zrobi się sama 😉",
    "Witaj w centrum dowodzenia wszechświatem Olaj.pl 🌍",
];
$randomMessage = $welcomeMessages[array_rand($welcomeMessages)];
?>
<!DOCTYPE html>
<html lang="pl">

<head>
    <meta charset="UTF-8">
    <title>Logowanie - Olaj.pl</title>
    <style>
        body {
            font-family: sans-serif;
            background: #f4f4f4;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            flex-direction: column;
        }

        form {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 0 10px #ccc;
            min-width: 300px;
            position: relative;
        }

        input {
            width: 100%;
            padding: 10px;
            margin-bottom: 1rem;
        }

        label {
            display: flex;
            align-items: center;
            font-size: 0.9rem;
            margin-bottom: 1rem;
        }

        .error {
            color: red;
            margin-bottom: 1rem;
        }

        #lottie-pinpass {
            width: 150px;
            height: 150px;
            margin-bottom: 1rem;
        }

        .welcome {
            font-size: 0.95rem;
            color: #555;
            margin-bottom: 1rem;
            text-align: center;
        }

        button {
            padding: 10px;
            width: 100%;
            background-color: #0099ff;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }
    </style>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/lottie-web/5.12.2/lottie.min.js"></script>
</head>

<body>

    <a href="../" class="text-sm text-blue-600 hover:underline flex items-center gap-1" style="margin-bottom:1rem;">
        <span class="text-lg">←</span> Wróć
    </a>

    <div id="lottie-pinpass"></div>

    <div class="welcome"><?= htmlspecialchars($randomMessage) ?></div>

    <form method="POST">
        <h2>🔐 Logowanie</h2>

        <?php foreach ($errors as $error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endforeach; ?>

        <input type="email" name="email" placeholder="Email" required autocomplete="username" value="<?= htmlspecialchars($email) ?>">
        <input type="password" name="password" placeholder="Hasło" required autocomplete="current-password">

        <label><input type="checkbox" name="remember" <?= $rememberMe ? 'checked' : '' ?>> Zapamiętaj mnie</label>

        <button type="submit">Zaloguj</button>
    </form>

    <script>
        lottie.loadAnimation({
            container: document.getElementById('lottie-pinpass'),
            renderer: 'svg',
            loop: true,
            autoplay: true,
            path: '/uploads/lottie/pinpass.json'
        });
    </script>

</body>

</html>