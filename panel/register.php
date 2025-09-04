<?php
session_start();
require_once 'includes/db.php';

// Rejestracja tylko dla pierwszego superadmina lub zablokowana domyślnie
$stmt = $pdo->query("SELECT COUNT(*) FROM users");
if ($stmt->fetchColumn() > 0) {
    die('Rejestracja jest zablokowana. Konto już istnieje.');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $company = trim($_POST['company'] ?? '');

    if (empty($email) || empty($password) || empty($company)) {
        $errors[] = 'Wypełnij wszystkie pola';
    } else {
        // utwórz firmę (owner)
        $stmt = $pdo->prepare("INSERT INTO owners (name, email) VALUES (?, ?)");
        $stmt->execute([$company, $email]);
        $owner_id = $pdo->lastInsertId();

        // utwórz użytkownika
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (owner_id, email, password_hash, role) VALUES (?, ?, ?, 'superadmin')");
        $stmt->execute([$owner_id, $email, $hash]);

        $_SESSION['user'] = [
            'id' => $pdo->lastInsertId(),
            'owner_id' => $owner_id,
            'role' => 'superadmin',
            'email' => $email,
        ];
        header("Location: ../admin/index.php");
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Rejestracja - Olaj.pl</title>
    <style>
        body { font-family: sans-serif; background: #f4f4f4; display: flex; justify-content: center; align-items: center; height: 100vh; }
        form { background: white; padding: 2rem; border-radius: 10px; box-shadow: 0 0 10px #ccc; min-width: 300px; }
        input { width: 100%; padding: 10px; margin-bottom: 1rem; }
        .error { color: red; margin-bottom: 1rem; }
    </style>
</head>
<body>
    <form method="POST">
        <h2>🆕 Rejestracja superadmina</h2>

        <?php foreach ($errors as $error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endforeach; ?>

        <input type="text" name="company" placeholder="Nazwa firmy" required value="<?= htmlspecialchars($_POST['company'] ?? '') ?>">
        <input type="email" name="email" placeholder="Email" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
        <input type="password" name="password" placeholder="Hasło" required>
        <button type="submit">Zarejestruj</button>
    </form>
</body>
</html>
