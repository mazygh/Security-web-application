<?php
session_start();
require 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nom_utilisateur = $_POST['nom_utilisateur'];
    $mot_de_passe = $_POST['mot_de_passe'];

    $stmt = $pdo->prepare("SELECT * FROM utilisateurs WHERE nom_utilisateur = ?");
    $stmt->execute([$nom_utilisateur]);
    $user = $stmt->fetch();

    if ($user && password_verify($mot_de_passe, $user['mot_de_passe'])) {
        $_SESSION['user_id'] = $user['id'];
        header("Location: notes.php");
    } else {
        echo "Identifiants incorrects !";
    }
}
?>

<form method="POST">
    <input type="text" name="nom_utilisateur" placeholder="Nom d'utilisateur" required>
    <input type="password" name="mot_de_passe" placeholder="Mot de passe" required>
    <button type="submit">Se connecter</button>
</form>