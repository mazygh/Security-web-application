<?php
require 'config.php';

// Check if an admin already exists
$stmt = $pdo->prepare("SELECT COUNT(*) FROM utilisateurs WHERE role = 'admin'");
$stmt->execute();
$adminCount = $stmt->fetchColumn();

// If an admin already exists, redirect to login
if ($adminCount > 0) {
    header("Location: index.php?page=login");
    exit;
}

// Generate CSRF token for the setup form (unauthenticated user)
$csrfToken = generateCsrfToken(0, $pdo);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['setup_admin'])) {
    if (!isset($_POST['csrf_token']) || !validateCsrfToken(0, $_POST['csrf_token'], $pdo)) {
        $erreur = "Erreur de validation CSRF";
    } else {
        $nom = sanitizeInput($_POST['nom']);
        $email = sanitizeInput($_POST['email']);
        $mot_de_passe = $_POST['mot_de_passe'];

        // Validate input
        if (empty($nom) || empty($email) || empty($mot_de_passe)) {
            $erreur = "Tous les champs sont requis.";
        } elseif (!validateInstitutionEmail($email)) {
            $erreur = "Email institutionnel requis (@institution.edu).";
        } elseif (!validatePassword($mot_de_passe)) {
            $erreur = "Le mot de passe doit contenir au moins 8 caractères, 1 lettre, 1 chiffre et 1 caractère spécial.";
        } else {
            try {
                $mot_de_passe_hash = password_hash($mot_de_passe, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("INSERT INTO utilisateurs (nom, email, mot_de_passe, role) VALUES (?, ?, ?, ?)");
                $stmt->execute([$nom, $email, $mot_de_passe_hash, 'admin']);
                header("Location: index.php?page=login&setup=success");
                exit;
            } catch (PDOException $e) {
                $erreur = "Erreur : " . htmlspecialchars($e->getMessage());
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuration Initiale - Premier Admin</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            background-image: url('image.jpg');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            min-height: 100vh;
        }
        .contenu {
            background-color: rgba(255, 255, 255, 0.8);
            padding: 20px;
            border-radius: 10px;
            max-width: 500px;
            margin: 50px auto;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }
        .erreur { color: red; }
        input, button {
            margin: 10px 0;
            padding: 8px;
            width: 100%;
            box-sizing: border-box;
        }
        button {
            background: #4CAF50;
            color: white;
            border: none;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <div class="contenu">
        <h2>Configuration Initiale - Créer le Premier Admin</h2>
        <p>Ce formulaire permet de créer le premier compte admin. Une fois créé, cette page sera désactivée.</p>

        <?php if (isset($erreur)) echo "<p class='erreur'>" . htmlspecialchars($erreur) . "</p>"; ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="text" name="nom" placeholder="Nom" required>
            <input type="email" name="email" placeholder="Email (@institution.edu)" required>
            <input type="password" name="mot_de_passe" placeholder="Mot de passe" required>
            <button type="submit" name="setup_admin">Créer Admin</button>
        </form>
    </div>
</body>
</html>