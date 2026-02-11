<?php
require 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nom_utilisateur = $_POST['nom_utilisateur'];
    $mot_de_passe = password_hash($_POST['mot_de_passe'], PASSWORD_DEFAULT);

    try {
        $stmt = $pdo->prepare("INSERT INTO utilisateurs (nom_utilisateur, mot_de_passe) VALUES (?, ?)");
        $stmt->execute([$nom_utilisateur, $mot_de_passe]);
        header("Location: connexion.php?inscription=success");
    } catch (PDOException $e) {
        echo "Erreur : " . $e->getMessage();
    }
}
?>

<form method="POST">
    <input type="text" name="nom_utilisateur" placeholder="Nom d'utilisateur" required>
    <input type="password" name="mot_de_passe" placeholder="Mot de passe" required>
    <button type="submit">S'inscrire</button>
</form>