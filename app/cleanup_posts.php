<?php
define('PROJECT_PATH', __DIR__); // nötig, da deine Klassen das prüfen

require_once 'config.class.php';
require_once 'db.class.php';
require_once 'user.class.php';

try {
    // Zugriffsschutz – nur eingeloggte Benutzer
    if (!User::is_logged_in()) {
        throw new Exception("Du musst eingeloggt sein, um diese Aktion auszuführen.");
    }

    // Wenn Formular abgeschickt wurde → löschen
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cleanup'])) {
        DB::get_instance()->query("DELETE FROM `posts` WHERE `status` = 5");
        $message = "✅ Alle Posts mit Status 5 wurden erfolgreich gelöscht.";
    }

} catch (Exception $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Datenbank bereinigen</title>
    <style>
        body {
            font-family: sans-serif;
            background: #f4f4f4;
            color: #333;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }
        .box {
            background: white;
            padding: 2rem;
            border-radius: 12px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            text-align: center;
            max-width: 400px;
        }
        button {
            background: #d9534f;
            color: white;
            border: none;
            padding: 0.8rem 1.2rem;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1rem;
        }
        button:hover {
            background: #c9302c;
        }
        .msg {
            margin-top: 1rem;
            color: green;
        }
        .error {
            margin-top: 1rem;
            color: red;
        }
    </style>
</head>
<body>
<div class="box">
    <h2>Beiträge bereinigen</h2>
    <p>Hier kannst du alle Posts mit <code>status = 5</code> endgültig löschen.</p>

    <form method="post">
        <button type="submit" name="cleanup" onclick="return confirm('Willst du wirklich alle gelöschten Posts entfernen?')">
            🧹 Bereinigen starten
        </button>
    </form>

    <?php if (!empty($message)) echo "<p class='msg'>$message</p>"; ?>
    <?php if (!empty($error)) echo "<p class='error'>$error</p>"; ?>
</div>
</body>
</html>
