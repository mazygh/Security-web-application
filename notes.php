<?php
session_start();
require 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: connexion.php");
    exit;
}

// Ajouter une note
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['titre'])) {
    $titre = $_POST['titre'];
    $contenu = $_POST['contenu'];
    $stmt = $pdo->prepare("INSERT INTO notes (utilisateur_id, titre, contenu) VALUES (?, ?, ?)");
    $stmt->execute([$_SESSION['user_id'], $titre, $contenu]);
}

// Récupérer les notes de l'utilisateur
$stmt = $pdo->prepare("SELECT * FROM notes WHERE utilisateur_id = ? ORDER BY date_creation DESC");
$stmt->execute([$_SESSION['user_id']]);
$notes = $stmt->fetchAll();
?>

<h1>Mes Notes</h1>
<form method="POST">
    <input type="text" name="titre" placeholder="Titre" required>
    <textarea name="contenu" placeholder="Contenu"></textarea>
    <button type="submit">Ajouter</button>
</form>

<ul>
    <?php foreach ($notes as $note): ?>
        <li>
            <h3><?= htmlspecialchars($note['titre']) ?></h3>
            <p><?= htmlspecialchars($note['contenu']) ?></p>
            <small><?= $note['date_creation'] ?></small>
        </li>
    <?php endforeach; ?>
</ul>