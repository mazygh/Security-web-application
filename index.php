<?php
session_start();
require 'config.php';

// Function to handle image upload
function handleImageUpload($file, $note_id) {
    $uploadDir = 'uploads/';
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
    $maxFileSize = 2 * 1024 * 1024; // 2MB

    if ($file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("Erreur lors du téléchargement de l'image : code " . $file['error']);
    }
    if (!in_array($file['type'], $allowedTypes)) {
        throw new Exception("Type de fichier non autorisé (JPEG, PNG, GIF uniquement)");
    }
    if ($file['size'] > $maxFileSize) {
        throw new Exception("L'image est trop volumineuse (max 2MB)");
    }

    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'note_' . $note_id . '_' . time() . '.' . $extension;
    $destination = $uploadDir . $filename;

    if (!is_writable($uploadDir)) {
        throw new Exception("Le dossier uploads/ n'est pas accessible en écriture");
    }
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new Exception("Impossible de sauvegarder l'image à : " . $destination);
    }

    return $filename;
}

// Handle note modification
$modification_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['modifier_note'])) {
    if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['etudiant', 'admin'])) {
        $modification_error = "Accès non autorisé pour modifier des notes";
    } elseif (!isset($_POST['csrf_token']) || !validateCsrfToken($_SESSION['user_id'], $_POST['csrf_token'], $pdo)) {
        $modification_error = "Erreur de validation CSRF";
    } else {
        $note_id = filter_input(INPUT_POST, 'note_id', FILTER_VALIDATE_INT);
        $titre = isset($_POST['titre']) ? trim($_POST['titre']) : '';
        $contenu = isset($_POST['contenu']) ? trim($_POST['contenu']) : '';

        if (!$note_id) {
            $modification_error = "ID de la note invalide";
        } else {
            try {
                $image_path = null;
                if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $stmt = $pdo->prepare("SELECT image_path FROM notes WHERE id = ? AND utilisateur_id = ?");
                    $stmt->execute([$note_id, $_SESSION['user_id']]);
                    $note = $stmt->fetch();
                    if ($note && !empty($note['image_path'])) {
                        $old_image_path = 'uploads/' . $note['image_path'];
                        if (file_exists($old_image_path)) {
                            unlink($old_image_path);
                        }
                    }
                    $image_path = handleImageUpload($_FILES['image'], $note_id);
                }

                if ($image_path) {
                    $stmt = $pdo->prepare("UPDATE notes SET titre = ?, contenu = ?, image_path = ? WHERE id = ? AND utilisateur_id = ?");
                    $stmt->execute([$titre, $contenu, $image_path, $note_id, $_SESSION['user_id']]);
                } else {
                    $stmt = $pdo->prepare("UPDATE notes SET titre = ?, contenu = ? WHERE id = ? AND utilisateur_id = ?");
                    $stmt->execute([$titre, $contenu, $note_id, $_SESSION['user_id']]);
                }

                $_SESSION['message'] = "Note modifiée avec succès";
                header("Location: index.php?page=notes");
                exit;
            } catch (Exception $e) {
                $modification_error = "Erreur lors de la modification : " . $e->getMessage();
            }
        }
    }
}

// Handle schedule modification
$schedule_modification_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['modifier_emploi'])) {
    if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['enseignant', 'admin'])) {
        $schedule_modification_error = "Accès non autorisé pour modifier des emplois du temps";
    } elseif (!isset($_POST['csrf_token']) || !validateCsrfToken($_SESSION['user_id'], $_POST['csrf_token'], $pdo)) {
        $schedule_modification_error = "Erreur de validation CSRF";
    } else {
        $emploi_id = filter_input(INPUT_POST, 'emploi_id', FILTER_VALIDATE_INT);
        $titre = isset($_POST['titre']) ? trim($_POST['titre']) : '';
        $description = isset($_POST['description']) ? trim($_POST['description']) : '';
        $jour = isset($_POST['jour']) ? trim($_POST['jour']) : '';
        $heure_debut = isset($_POST['heure_debut']) ? trim($_POST['heure_debut']) : '';
        $heure_fin = isset($_POST['heure_fin']) ? trim($_POST['heure_fin']) : '';

        if (!$emploi_id) {
            $schedule_modification_error = "ID de l'emploi du temps invalide";
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE emplois_du_temps SET titre = ?, description = ?, jour = ?, heure_debut = ?, heure_fin = ? WHERE id = ? AND utilisateur_id = ?");
                $stmt->execute([$titre, $description, $jour, $heure_debut, $heure_fin, $emploi_id, $_SESSION['user_id']]);

                $_SESSION['message'] = "Emploi du temps modifié avec succès";
                header("Location: index.php?page=notes");
                exit;
            } catch (Exception $e) {
                $schedule_modification_error = "Erreur lors de la modification : " . $e->getMessage();
            }
        }
    }
}

// Handle note deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['supprimer_note'])) {
    if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['etudiant', 'admin'])) {
        $_SESSION['erreur'] = "Accès non autorisé pour supprimer des notes";
        header("Location: index.php?page=notes");
        exit;
    }

    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_SESSION['user_id'], $_POST['csrf_token'], $pdo)) {
        $_SESSION['erreur'] = "Erreur de validation CSRF";
        header("Location: index.php?page=notes");
        exit;
    }

    $note_id = filter_input(INPUT_POST, 'note_id', FILTER_VALIDATE_INT);
    if ($note_id) {
        $stmt = $pdo->prepare("SELECT image_path FROM notes WHERE id = ? AND utilisateur_id = ?");
        $stmt->execute([$note_id, $_SESSION['user_id']]);
        $note = $stmt->fetch();
        
        if ($note) {
            if (isset($note['image_path']) && !empty($note['image_path']) && file_exists('uploads/' . $note['image_path'])) {
                unlink('uploads/' . $note['image_path']);
            }
            $stmt = $pdo->prepare("DELETE FROM notes WHERE id = ?");
            $stmt->execute([$note_id]);
            $_SESSION['message'] = "Note supprimée avec succès";
        } else {
            $_SESSION['erreur'] = "Impossible de supprimer cette note";
        }
    }
    header("Location: index.php?page=notes");
    exit;
}

// Handle schedule deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['supprimer_emploi'])) {
    if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['enseignant', 'admin'])) {
        $_SESSION['erreur'] = "Accès non autorisé pour supprimer des emplois du temps";
        header("Location: index.php?page=notes");
        exit;
    }

    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_SESSION['user_id'], $_POST['csrf_token'], $pdo)) {
        $_SESSION['erreur'] = "Erreur de validation CSRF";
        header("Location: index.php?page=notes");
        exit;
    }

    $emploi_id = filter_input(INPUT_POST, 'emploi_id', FILTER_VALIDATE_INT);
    if ($emploi_id) {
        $stmt = $pdo->prepare("SELECT id FROM emplois_du_temps WHERE id = ? AND utilisateur_id = ?");
        $stmt->execute([$emploi_id, $_SESSION['user_id']]);
        
        if ($stmt->fetch()) {
            $stmt = $pdo->prepare("DELETE FROM emplois_du_temps WHERE id = ?");
            $stmt->execute([$emploi_id]);
            $_SESSION['message'] = "Emploi du temps supprimé avec succès";
        } else {
            $_SESSION['erreur'] = "Impossible de supprimer cet emploi du temps";
        }
    }
    header("Location: index.php?page=notes");
    exit;
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php?page=login");
    exit;
}

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token_login']) || $_POST['csrf_token'] !== $_SESSION['csrf_token_login']) {
        $erreur = "Erreur de validation CSRF";
    } else {
        $email = sanitizeInput($_POST['email']);
        $mot_de_passe = $_POST['mot_de_passe'];

        if (!validateInstitutionEmail($email)) {
            $erreur = "Email institutionnel requis (@institution.edu)";
        } elseif (!validatePassword($mot_de_passe)) {
            $erreur = "Mot de passe non conforme";
        } else {
            $stmt = $pdo->prepare("SELECT * FROM utilisateurs WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($mot_de_passe, $user['mot_de_passe'])) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['role'] = $user['role'];
                unset($_SESSION['csrf_token_login']);
                header("Location: index.php?page=notes");
                exit;
            } else {
                $erreur = "Identifiants incorrects !";
            }
        }
    }
}

// Handle registration (admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register'])) {
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        $erreur = "Accès non autorisé pour ajouter des utilisateurs";
    } else {
        if (!isset($_POST['csrf_token']) || !validateCsrfToken($_SESSION['user_id'], $_POST['csrf_token'], $pdo)) {
            $erreur = "Erreur de validation CSRF";
        } else {
            $email = sanitizeInput($_POST['email']);
            $mot_de_passe = $_POST['mot_de_passe'];
            $role = sanitizeInput($_POST['role']);
            $nom = sanitizeInput($_POST['nom']);
            
            if (!validateInstitutionEmail($email)) {
                $erreur = "Email institutionnel requis (@institution.edu)";
            } elseif (!validatePassword($mot_de_passe)) {
                $erreur = "Le mot de passe doit contenir au moins 8 caractères, 1 lettre, 1 chiffre et 1 caractère spécial";
            } elseif (!in_array($role, ['admin', 'enseignant', 'etudiant'])) {
                $erreur = "Rôle invalide";
            } else {
                try {
                    $mot_de_passe_hash = password_hash($mot_de_passe, PASSWORD_BCRYPT);
                    $stmt = $pdo->prepare("INSERT INTO utilisateurs (nom, email, mot_de_passe, role) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$nom, $email, $mot_de_passe_hash, $role]);
                    $succes = "Utilisateur créé avec succès !";
                } catch (PDOException $e) {
                    $erreur = "Erreur : " . htmlspecialchars($e->getMessage());
                }
            }
        }
    }
}

// Handle note addition
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajout_note'])) {
    if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['etudiant', 'admin'])) {
        $_SESSION['erreur'] = "Accès non autorisé pour ajouter des notes";
        header("Location: index.php?page=notes");
        exit;
    }

    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_SESSION['user_id'], $_POST['csrf_token'], $pdo)) {
        $_SESSION['erreur'] = "Erreur de validation CSRF";
        header("Location: index.php?page=notes");
        exit;
    }

    $titre = sanitizeInput($_POST['titre']);
    $contenu = sanitizeInput($_POST['contenu']);
    
    if (strlen($titre) > 0 && strlen($contenu) > 0) {
        $stmt = $pdo->prepare("INSERT INTO notes (utilisateur_id, titre, contenu) VALUES (?, ?, ?)");
        $stmt->execute([$_SESSION['user_id'], $titre, $contenu]);
        $note_id = $pdo->lastInsertId();

        $image_path = null;
        if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
            try {
                $image_path = handleImageUpload($_FILES['image'], $note_id);
                if ($image_path) {
                    $stmt = $pdo->prepare("UPDATE notes SET image_path = ? WHERE id = ?");
                    $stmt->execute([$image_path, $note_id]);
                }
            } catch (Exception $e) {
                $_SESSION['erreur'] = $e->getMessage();
                header("Location: index.php?page=notes");
                exit;
            }
        }

        $_SESSION['message'] = "Note ajoutée avec succès";
    } else {
        $_SESSION['erreur'] = "Le titre et le contenu ne peuvent pas être vides";
    }
    header("Location: index.php?page=notes");
    exit;
}

// Handle schedule addition
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajout_emploi'])) {
    if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['enseignant', 'admin'])) {
        $_SESSION['erreur'] = "Accès non autorisé pour ajouter des emplois du temps";
        header("Location: index.php?page=notes");
        exit;
    }

    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_SESSION['user_id'], $_POST['csrf_token'], $pdo)) {
        $_SESSION['erreur'] = "Erreur de validation CSRF";
        header("Location: index.php?page=notes");
        exit;
    }

    $titre = sanitizeInput($_POST['titre']);
    $description = sanitizeInput($_POST['description']);
    $jour = sanitizeInput($_POST['jour']);
    $heure_debut = sanitizeInput($_POST['heure_debut']);
    $heure_fin = sanitizeInput($_POST['heure_fin']);
    
    if (strlen($titre) > 0 && strlen($description) > 0 && !empty($jour) && !empty($heure_debut) && !empty($heure_fin)) {
        $stmt = $pdo->prepare("INSERT INTO emplois_du_temps (utilisateur_id, titre, description, jour, heure_debut, heure_fin) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$_SESSION['user_id'], $titre, $description, $jour, $heure_debut, $heure_fin]);
        $_SESSION['message'] = "Emploi du temps ajouté avec succès";
    } else {
        $_SESSION['erreur'] = "Tous les champs sont requis";
    }
    header("Location: index.php?page=notes");
    exit;
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Carnet Électronique</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Inter', sans-serif;
            color: #1f2937;
            min-height: 100vh;
            display: flex;
            position: relative;
            background: none;
        }
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: url('https://images.unsplash.com/photo-1507842217343-583ffbe300b6?ixlib=rb-4.0.3&auto=format&fit=crop&w=1920&q=80');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            opacity: 0.3;
            z-index: -1;
        }
        .sidebar {
            width: 260px;
            background: linear-gradient(180deg, #ffffff 0%, #f9fafb 100%);
            border-right: 1px solid #e5e7eb;
            padding: 40px 20px;
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            transition: transform 0.3s ease;
            z-index: 1000;
            box-shadow: 2px 0 15px rgba(0, 0, 0, 0.05);
        }
        .sidebar.hidden {
            transform: translateX(-100%);
        }
        .sidebar .logo {
            font-size: 1.7rem;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 50px;
            display: flex;
            align-items: center;
        }
        .sidebar .nav-link {
            display: flex;
            align-items: center;
            padding: 12px 18px;
            color: #4b5563;
            text-decoration: none;
            border-radius: 10px;
            margin-bottom: 10px;
            transition: all 0.3s ease;
        }
        .sidebar .nav-link:hover, .sidebar .nav-link.active {
            background: #eff6ff;
            color: #3b82f6;
            transform: translateX(5px);
        }
        .sidebar .nav-link i {
            margin-right: 12px;
            font-size: 1.1rem;
        }
        .main-content {
            flex: 1;
            margin-left: 260px;
            padding: 50px 40px;
            background: rgba(255, 255, 255, 0.95);
            min-height: 100vh;
        }
        .hamburger {
            display: none;
            background: none;
            border: none;
            font-size: 1.5rem;
            color: #4b5563;
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1001;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        h1 {
            font-size: 2.8rem;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 25px;
        }
        h2 {
            font-size: 2rem;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 30px;
        }
        h3 {
            font-size: 1.5rem;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 25px;
        }
        .welcome-section {
            text-align: center;
            padding: 60px 40px;
            background: #fefefe;
            border-radius: 20px;
            box-shadow: 0 6px 25px rgba(0, 0, 0, 0.06);
            margin-bottom: 50px;
            backdrop-filter: blur(5px);
        }
        .welcome-section h1 {
            font-size: 3rem;
            margin-bottom: 20px;
        }
        .welcome-section p {
            color: #4b5563;
            font-size: 1.2rem;
            font-weight: 400;
            margin-bottom: 30px;
        }
        .login-section {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: calc(100vh - 100px);
            margin: 0 auto;
            max-width: 450px;
        }
        .login-card {
            background: rgba(255, 255, 255, 0.98);
            border-radius: 20px;
            padding: 40px;
            width: 100%;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        .login-card h2 {
            text-align: center;
            margin-bottom: 40px;
            font-size: 2rem;
            color: #1f2937;
        }
        .btn-primary {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            border: none;
            padding: 12px 28px;
            border-radius: 10px;
            font-weight: 500;
            font-size: 1rem;
            color: #ffffff;
            transition: all 0.3s ease;
        }
        .btn-primary:hover {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            transform: scale(1.05);
            box-shadow: 0 6px 15px rgba(59, 130, 246, 0.3);
        }
        .btn-secondary {
            background: #e5e7eb;
            color: #1f2937;
            border: none;
            padding: 12px 28px;
            border-radius: 10px;
            font-weight: 500;
            font-size: 1rem;
            transition: all 0.3s ease;
        }
        .btn-secondary:hover {
            background: #d1d5db;
            transform: scale(1.02);
        }
        .form-control, .form-select {
            border-radius: 10px;
            border: 1px solid #d1d5db;
            padding: 12px 15px;
            font-size: 1rem;
            transition: all 0.3s ease;
        }
        .form-control:focus, .form-select:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
            outline: none;
        }
        .form-label {
            font-weight: 500;
            color: #1f2937;
            font-size: 0.95rem;
            margin-bottom: 8px;
            display: block;
        }
        .form-group {
            position: relative;
            margin-bottom: 25px;
        }
        .form-group i {
            position: absolute;
            top: 50%;
            left: 15px;
            transform: translateY(-50%);
            color: #6b7280;
            font-size: 1rem;
        }
        .form-group .form-control {
            padding-left: 40px;
        }
        .erreur {
            color: #dc2626;
            font-weight: 500;
            background: #fef2f2;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 40px;
            text-align: center;
            font-size: 0.95rem;
        }
        .succes {
            color: #16a34a;
            font-weight: 500;
            background: #f0fdf4;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 40px;
            text-align: center;
            font-size: 0.95rem;
        }
        .notes-grid, .schedules-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
            gap: 35px;
            margin-top: 40px;
            margin-bottom: 50px;
        }
        .card {
            background: #fefefe;
            border: 1px solid #e5e7eb;
            border-radius: 20px;
            padding: 25px;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            backdrop-filter: blur(5px);
        }
        .card:hover {
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.1);
            transform: translateY(-5px);
            border: 1px solid transparent;
            background: linear-gradient(135deg, #fefefe, #fefefe) padding-box,
                        linear-gradient(135deg, #3b82f6, #93c5fd) border-box;
        }
        .card .title {
            font-size: 1.3rem;
            font-weight: 600;
            margin-bottom: 15px;
            color: #1f2937;
        }
        .card .content {
            font-size: 0.95rem;
            color: #4b5563;
            margin-bottom: 20px;
            line-height: 1.7;
        }
        .card .date {
            font-size: 0.85rem;
            color: #6b7280;
            font-weight: 400;
            margin-bottom: 15px;
        }
        .card .actions {
            margin-top: 20px;
            display: flex;
            gap: 15px;
        }
        .card .note-image {
            max-width: 100%;
            max-height: 200px;
            object-fit: cover;
            border-radius: 12px;
            margin-bottom: 20px;
            display: block;
            transition: transform 0.3s ease;
        }
        .card:hover .note-image {
            transform: scale(1.02);
        }
        .btn-edit, .btn-delete {
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        .btn-edit {
            background: #dbeafe;
            color: #3b82f6;
        }
        .btn-edit:hover {
            background: #bfdbfe;
            transform: scale(1.05);
        }
        .btn-delete {
            background: #fee2e2;
            color: #dc2626;
        }
        .btn-delete:hover {
            background: #fecaca;
            transform: scale(1.05);
        }
        .modal-content {
            border-radius: 20px;
            border: none;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.15);
            backdrop-filter: blur(10px);
            background: rgba(255, 255, 255, 0.98);
        }
        .modal-header {
            border-bottom: 1px solid #e5e7eb;
            padding: 25px 30px;
        }
        .modal-title {
            font-weight: 600;
            color: #1f2937;
            font-size: 1.4rem;
        }
        .modal-body {
            padding: 30px;
        }
        .modal-footer {
            border-top: 1px solid #e5e7eb;
            padding: 20px 30px;
        }
        .section-divider {
            border-top: 1px solid #e5e7eb;
            margin: 50px 0;
        }
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 40px;
            padding-bottom: 20px;
            border-bottom: 1px solid #e5e7eb;
        }
        .modal-body .form-control,
        .modal-body .form-control:focus,
        .modal-body textarea.form-control,
        .modal-body textarea.form-control:focus,
        .modal-body input[type="date"],
        .modal-body input[type="time"],
        .modal-body input[type="text"],
        .modal-body input[type="time"]:focus,
        .modal-body input[type="date"]:focus {
            pointer-events: auto !important;
            opacity: 1 !important;
            background-color: #fff !important;
            color: #1f2937 !important;
            cursor: auto !important;
            user-select: auto !important;
            -webkit-user-select: auto !important;
            -moz-user-select: auto !important;
            -ms-user-select: auto !important;
            border: 1px solid #d1d5db !important;
        }
        .modal-body input:not([type="hidden"]),
        .modal-body textarea {
            pointer-events: auto !important;
            opacity: 1 !important;
        }
        .modal-body textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }
        .modal-body input,
        .modal-body textarea {
            border: 1px solid #d1d5db !important;
            border-radius: 10px !important;
        }
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            .sidebar.active {
                transform: translateX(0);
            }
            .main-content {
                margin-left: 0;
                padding: 30px 20px;
            }
            .hamburger {
                display: block;
            }
            .notes-grid, .schedules-grid {
                grid-template-columns: 1fr;
                gap: 25px;
            }
            .welcome-section {
                padding: 40px 20px;
            }
            .welcome-section h1 {
                font-size: 2.2rem;
            }
            .welcome-section p {
                font-size: 1rem;
            }
            .card .note-image {
                max-height: 160px;
            }
            h1 {
                font-size: 2.2rem;
            }
            h2 {
                font-size: 1.6rem;
            }
            h3 {
                font-size: 1.3rem;
            }
            .login-card {
                padding: 30px 20px;
            }
            .login-card h2 {
                font-size: 1.8rem;
            }
        }
    </style>
</head>
<body>
    <div class="sidebar" id="sidebar">
        <div class="logo">
            <i class="fas fa-book me-2"></i>Carnet Électronique
        </div>
        <a href="index.php" class="nav-link <?php echo (!isset($_GET['page']) || $_GET['page'] === 'accueil') ? 'active' : ''; ?>">
            <i class="fas fa-home"></i>Accueil
        </a>
        <?php if (isset($_SESSION['user_id'])): ?>
            <a href="index.php?page=notes" class="nav-link <?php echo (isset($_GET['page']) && $_GET['page'] === 'notes') ? 'active' : ''; ?>">
                <i class="fas <?php echo $_SESSION['role'] === 'etudiant' ? 'fa-sticky-note' : 'fa-calendar-alt'; ?>"></i>
                <?php echo $_SESSION['role'] === 'etudiant' ? 'Mes Notes' : 'Emplois du Temps'; ?>
            </a>
            <?php if ($_SESSION['role'] === 'admin'): ?>
                <a href="index.php?page=register" class="nav-link <?php echo (isset($_GET['page']) && $_GET['page'] === 'register') ? 'active' : ''; ?>">
                    <i class="fas fa-user-plus"></i>Ajouter Utilisateur
                </a>
            <?php endif; ?>
            <a href="index.php?logout=1" class="nav-link">
                <i class="fas fa-sign-out-alt"></i>Déconnexion
            </a>
        <?php else: ?>
            <a href="index.php?page=login" class="nav-link <?php echo (isset($_GET['page']) && $_GET['page'] === 'login') ? 'active' : ''; ?>">
                <i class="fas fa-sign-in-alt"></i>Se connecter
            </a>
        <?php endif; ?>
    </div>

    <div class="main-content">
        <button class="hamburger" id="hamburger"><i class="fas fa-bars"></i></button>

        <div class="container">
            <?php
            if (isset($_SESSION['erreur'])) {
                echo "<p class='erreur'>" . htmlspecialchars($_SESSION['erreur']) . "</p>";
                unset($_SESSION['erreur']);
            }
            if (isset($_SESSION['message'])) {
                echo "<p class='succes'>" . htmlspecialchars($_SESSION['message']) . "</p>";
                unset($_SESSION['message']);
            }
            if (isset($erreur)) echo "<p class='erreur'>" . htmlspecialchars($erreur) . "</p>";
            if (isset($succes)) echo "<p class='succes'>" . htmlspecialchars($succes) . "</p>";

            $userId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
            $csrfToken = null;
            $page = $_GET['page'] ?? 'accueil';

            if ($userId && in_array($page, ['register', 'notes'])) {
                $csrfToken = generateCsrfToken($userId, $pdo);
            } elseif ($page === 'login') {
                if (!isset($_SESSION['csrf_token_login'])) {
                    $_SESSION['csrf_token_login'] = bin2hex(random_bytes(32));
                }
                $csrfToken = $_SESSION['csrf_token_login'];
            }

            switch ($page) {
                case 'accueil':
                    echo '<div class="welcome-section">';
                    echo '<h1>Bienvenue dans le Carnet Électronique</h1>';
                    echo '<p>Gérez vos notes et emplois du temps avec une interface moderne et intuitive.</p>';
                    if (!isset($_SESSION['user_id'])) {
                        echo '<a href="index.php?page=login" class="btn btn-primary mt-3"><i class="fas fa-sign-in-alt me-2"></i>Se connecter</a>';
                    } else {
                        echo '<a href="index.php?page=notes" class="btn btn-primary mt-3">';
                        echo '<i class="fas ' . ($_SESSION['role'] === 'etudiant' ? 'fa-sticky-note' : 'fa-calendar-alt') . ' me-2"></i>';
                        echo $_SESSION['role'] === 'etudiant' ? 'Accéder à mes notes' : 'Gérer mes emplois du temps';
                        echo '</a>';
                    }
                    echo '</div>';
                    break;

                case 'login':
                    echo '<div class="login-section">';
                    echo '<div class="login-card">';
                    echo '<h2>Connexion</h2>';
                    echo '<form method="POST">';
                    echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken) . '">';
                    echo '<div class="form-group">';
                    echo '<label for="email" class="form-label">Email (@institution.edu)</label>';
                    echo '<i class="fas fa-envelope"></i>';
                    echo '<input type="email" class="form-control" id="email" name="email" placeholder="Email" required>';
                    echo '</div>';
                    echo '<div class="form-group">';
                    echo '<label for="mot_de_passe" class="form-label">Mot de passe</label>';
                    echo '<i class="fas fa-lock"></i>';
                    echo '<input type="password" class="form-control" id="mot_de_passe" name="mot_de_passe" placeholder="Mot de passe" required>';
                    echo '</div>';
                    echo '<button type="submit" name="login" class="btn btn-primary"><i class="fas fa-sign-in-alt me-2"></i>Se connecter</button>';
                    echo '</form>';
                    echo '</div>';
                    echo '</div>';
                    break;

                case 'register':
                    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
                        echo '<div class="welcome-section">';
                        echo '<h1>Accès non autorisé</h1>';
                        echo '<p>Seuls les administrateurs peuvent accéder à cette page.</p>';
                        echo '<a href="index.php" class="btn btn-primary mt-3"><i class="fas fa-home me-2"></i>Retour à l\'accueil</a>';
                        echo '</div>';
                    } else {
                        echo '<div class="login-section">';
                        echo '<div class="login-card">';
                        echo '<h2>Ajouter un Utilisateur</h2>';
                        echo '<form method="POST">';
                        echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken) . '">';
                        echo '<div class="form-group">';
                        echo '<label for="nom" class="form-label">Nom</label>';
                        echo '<i class="fas fa-user"></i>';
                        echo '<input type="text" class="form-control" id="nom" name="nom" placeholder="Nom" required>';
                        echo '</div>';
                        echo '<div class="form-group">';
                        echo '<label for="email" class="form-label">Email (@institution.edu)</label>';
                        echo '<i class="fas fa-envelope"></i>';
                        echo '<input type="email" class="form-control" id="email" name="email" placeholder="Email" required>';
                        echo '</div>';
                        echo '<div class="form-group">';
                        echo '<label for="mot_de_passe" class="form-label">Mot de passe</label>';
                        echo '<i class="fas fa-lock"></i>';
                        echo '<input type="password" class="form-control" id="mot_de_passe" name="mot_de_passe" placeholder="Mot de passe" required>';
                        echo '</div>';
                        echo '<div class="form-group">';
                        echo '<label for="role" class="form-label">Rôle</label>';
                        echo '<i class="fas fa-user-tag"></i>';
                        echo '<select class="form-control" id="role" name="role" required>';
                        echo '<option value="admin">Admin</option>';
                        echo '<option value="etudiant">Étudiant</option>';
                        echo '<option value="enseignant">Enseignant</option>';
                        echo '</select>';
                        echo '</div>';
                        echo '<button type="submit" name="register" class="btn btn-primary"><i class="fas fa-user-plus me-2"></i>Créer Utilisateur</button>';
                        echo '</form>';
                        echo '</div>';
                        echo '</div>';
                    }
                    break;

                case 'notes':
                    if (!isset($_SESSION['user_id'])) {
                        header("Location: index.php?page=login");
                        exit;
                    }

                    if ($_SESSION['role'] === 'etudiant') {
                        echo '<div class="section-header">';
                        echo '<h2>Mes Notes</h2>';
                        echo '<button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addNoteModal"><i class="fas fa-plus me-2"></i>Nouvelle Note</button>';
                        echo '</div>';

                        echo '<div class="modal fade" id="addNoteModal" tabindex="-1" aria-labelledby="addNoteModalLabel" aria-hidden="true">';
                        echo '<div class="modal-dialog">';
                        echo '<div class="modal-content">';
                        echo '<div class="modal-header">';
                        echo '<h5 class="modal-title" id="addNoteModalLabel">Ajouter une Note</h5>';
                        echo '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>';
                        echo '</div>';
                        echo '<form method="POST" enctype="multipart/form-data">';
                        echo '<div class="modal-body">';
                        echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken) . '">';
                        echo '<div class="mb-3">';
                        echo '<label for="titre" class="form-label">Titre</label>';
                        echo '<input type="text" class="form-control" id="titre" name="titre" placeholder="Titre" required>';
                        echo '</div>';
                        echo '<div class="mb-3">';
                        echo '<label for="contenu" class="form-label">Contenu</label>';
                        echo '<textarea class="form-control" id="contenu" name="contenu" placeholder="Contenu" rows="4" required></textarea>';
                        echo '</div>';
                        echo '<div class="mb-3">';
                        echo '<label for="image" class="form-label">Image (facultatif, max 2MB, JPEG/PNG/GIF)</label>';
                        echo '<input type="file" class="form-control" id="image" name="image" accept="image/jpeg,image/png,image/gif">';
                        echo '</div>';
                        echo '</div>';
                        echo '<div class="modal-footer">';
                        echo '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>';
                        echo '<button type="submit" name="ajout_note" class="btn btn-primary"><i class="fas fa-save me-2"></i>Enregistrer</button>';
                        echo '</div>';
                        echo '</form>';
                        echo '</div>';
                        echo '</div>';
                        echo '</div>';

                        $stmt = $pdo->prepare("SELECT * FROM notes WHERE utilisateur_id = ? ORDER BY date_creation DESC");
                        $stmt->execute([$_SESSION['user_id']]);
                        $notes = $stmt->fetchAll();

                        if ($notes) {
                            echo '<div class="notes-grid">';
                            foreach ($notes as $note) {
                                echo '<div class="card">';
                                echo '<div class="title">' . htmlspecialchars($note['titre']) . '</div>';
                                if (isset($note['image_path']) && !empty($note['image_path'])) {
                                    echo '<img src="uploads/' . htmlspecialchars($note['image_path']) . '" alt="Note Image" class="note-image">';
                                }
                                echo '<div class="content">' . htmlspecialchars($note['contenu']) . '</div>';
                                echo '<div class="date"><i class="fas fa-clock me-1"></i>' . htmlspecialchars($note['date_creation']) . '</div>';
                                echo '<div class="actions">';
                                echo '<button class="btn-edit" data-bs-toggle="modal" data-bs-target="#editNoteModal' . htmlspecialchars($note['id']) . '"><i class="fas fa-edit me-1"></i>Modifier</button>';
                                echo '<form method="POST" class="d-inline">';
                                echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken) . '">';
                                echo '<input type="hidden" name="note_id" value="' . htmlspecialchars($note['id']) . '">';
                                echo '<button type="submit" name="supprimer_note" class="btn-delete"><i class="fas fa-trash-alt me-1"></i>Supprimer</button>';
                                echo '</form>';
                                echo '</div>';

                                echo '<div class="modal fade" id="editNoteModal' . htmlspecialchars($note['id']) . '" tabindex="-1" aria-labelledby="editNoteModalLabel' . htmlspecialchars($note['id']) . '" aria-hidden="true">';
                                echo '<div class="modal-dialog">';
                                echo '<div class="modal-content">';
                                echo '<div class="modal-header">';
                                echo '<h5 class="modal-title" id="editNoteModalLabel' . htmlspecialchars($note['id']) . '">Modifier la Note</h5>';
                                echo '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>';
                                echo '</div>';
                                echo '<form method="POST" enctype="multipart/form-data">';
                                echo '<div class="modal-body">';
                                if ($modification_error && isset($_POST['note_id']) && $_POST['note_id'] == $note['id']) {
                                    echo "<p class='erreur'>" . htmlspecialchars($modification_error) . "</p>";
                                }
                                echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken) . '">';
                                echo '<input type="hidden" name="note_id" value="' . htmlspecialchars($note['id']) . '">';
                                echo '<div class="mb-3">';
                                echo '<label for="titre' . htmlspecialchars($note['id']) . '" class="form-label">Titre</label>';
                                echo '<input type="text" class="form-control" id="titre' . htmlspecialchars($note['id']) . '" name="titre" value="' . htmlspecialchars($note['titre']) . '" required>';
                                echo '</div>';
                                echo '<div class="mb-3">';
                                echo '<label for="contenu' . htmlspecialchars($note['id']) . '" class="form-label">Contenu</label>';
                                echo '<textarea class="form-control" id="contenu' . htmlspecialchars($note['id']) . '" name="contenu" rows="4" required>' . htmlspecialchars($note['contenu']) . '</textarea>';
                                echo '</div>';
                                echo '<div class="mb-3">';
                                echo '<label for="image' . htmlspecialchars($note['id']) . '" class="form-label">Image (facultatif, max 2MB, JPEG/PNG/GIF)</label>';
                                echo '<input type="file" class="form-control" id="image' . htmlspecialchars($note['id']) . '" name="image" accept="image/jpeg,image/png,image/gif">';
                                if (isset($note['image_path']) && !empty($note['image_path'])) {
                                    echo '<p class="mt-2"><small>Image actuelle : <a href="uploads/' . htmlspecialchars($note['image_path']) . '" target="_blank">Voir l\'image</a></small></p>';
                                }
                                echo '</div>';
                                echo '</div>';
                                echo '<div class="modal-footer">';
                                echo '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>';
                                echo '<button type="submit" name="modifier_note" class="btn btn-primary"><i class="fas fa-save me-2"></i>Enregistrer</button>';
                                echo '</div>';
                                echo '</form>';
                                echo '</div>';
                                echo '</div>';
                                echo '</div>';
                                echo '</div>';
                            }
                            echo '</div>';
                        } else {
                            echo '<p class="text-center text-muted">Aucune note trouvée. Ajoutez votre première note !</p>';
                        }
                    } elseif ($_SESSION['role'] === 'enseignant') {
                        echo '<div class="section-header">';
                        echo '<h2>Mes Emplois du Temps</h2>';
                        echo '<button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addScheduleModal"><i class="fas fa-plus me-2"></i>Nouvel Emploi</button>';
                        echo '</div>';

                        echo '<div class="modal fade" id="addScheduleModal" tabindex="-1" aria-labelledby="addScheduleModalLabel" aria-hidden="true">';
                        echo '<div class="modal-dialog">';
                        echo '<div class="modal-content">';
                        echo '<div class="modal-header">';
                        echo '<h5 class="modal-title" id="addScheduleModalLabel">Ajouter un Emploi du Temps</h5>';
                        echo '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>';
                        echo '</div>';
                        echo '<form method="POST">';
                        echo '<div class="modal-body">';
                        echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken) . '">';
                        echo '<div class="mb-3">';
                        echo '<label for="titre" class="form-label">Titre</label>';
                        echo '<input type="text" class="form-control" id="titre" name="titre" placeholder="Titre" required>';
                        echo '</div>';
                        echo '<div class="mb-3">';
                        echo '<label for="description" class="form-label">Description</label>';
                        echo '<textarea class="form-control" id="description" name="description" placeholder="Description" rows="3" required></textarea>';
                        echo '</div>';
                        echo '<div class="mb-3">';
                        echo '<label for="jour" class="form-label">Jour</label>';
                        echo '<input type="date" class="form-control" id="jour" name="jour" required>';
                        echo '</div>';
                        echo '<div class="mb-3">';
                        echo '<label for="heure_debut" class="form-label">Heure de début</label>';
                        echo '<input type="time" class="form-control" id="heure_debut" name="heure_debut" required>';
                        echo '</div>';
                        echo '<div class="mb-3">';
                        echo '<label for="heure_fin" class="form-label">Heure de fin</label>';
                        echo '<input type="time" class="form-control" id="heure_fin" name="heure_fin" required>';
                        echo '</div>';
                        echo '</div>';
                        echo '<div class="modal-footer">';
                        echo '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>';
                        echo '<button type="submit" name="ajout_emploi" class="btn btn-primary"><i class="fas fa-save me-2"></i>Enregistrer</button>';
                        echo '</div>';
                        echo '</form>';
                        echo '</div>';
                        echo '</div>';
                        echo '</div>';

                        $stmt = $pdo->prepare("SELECT * FROM emplois_du_temps WHERE utilisateur_id = ? ORDER BY jour, heure_debut");
                        $stmt->execute([$_SESSION['user_id']]);
                        $emplois = $stmt->fetchAll();

                        if ($emplois) {
                            echo '<div class="schedules-grid">';
                            foreach ($emplois as $emploi) {
                                echo '<div class="card">';
                                echo '<div class="title">' . htmlspecialchars($emploi['titre']) . '</div>';
                                echo '<div class="content">' . htmlspecialchars($emploi['description']) . '</div>';
                                echo '<div class="date"><strong><i class="fas fa-calendar-day me-1"></i>Jour:</strong> ' . htmlspecialchars($emploi['jour']) . '</div>';
                                echo '<div class="date"><strong><i class="fas fa-clock me-1"></i>Heure:</strong> ' . htmlspecialchars($emploi['heure_debut']) . ' - ' . htmlspecialchars($emploi['heure_fin']) . '</div>';
                                echo '<div class="date"><i class="fas fa-clock me-1"></i>' . htmlspecialchars($emploi['date_creation']) . '</div>';
                                echo '<div class="actions">';
                                echo '<button class="btn-edit" data-bs-toggle="modal" data-bs-target="#editScheduleModal' . htmlspecialchars($emploi['id']) . '"><i class="fas fa-edit me-1"></i>Modifier</button>';
                                echo '<form method="POST" class="d-inline">';
                                echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken) . '">';
                                echo '<input type="hidden" name="emploi_id" value="' . htmlspecialchars($emploi['id']) . '">';
                                echo '<button type="submit" name="supprimer_emploi" class="btn-delete"><i class="fas fa-trash-alt me-1"></i>Supprimer</button>';
                                echo '</form>';
                                echo '</div>';

                                echo '<div class="modal fade" id="editScheduleModal' . htmlspecialchars($emploi['id']) . '" tabindex="-1" aria-labelledby="editScheduleModalLabel' . htmlspecialchars($emploi['id']) . '" aria-hidden="true">';
                                echo '<div class="modal-dialog">';
                                echo '<div class="modal-content">';
                                echo '<div class="modal-header">';
                                echo '<h5 class="modal-title" id="editScheduleModalLabel' . htmlspecialchars($emploi['id']) . '">Modifier l\'Emploi du Temps</h5>';
                                echo '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>';
                                echo '</div>';
                                echo '<form method="POST">';
                                echo '<div class="modal-body">';
                                if ($schedule_modification_error && isset($_POST['emploi_id']) && $_POST['emploi_id'] == $emploi['id']) {
                                    echo "<p class='erreur'>" . htmlspecialchars($schedule_modification_error) . "</p>";
                                }
                                echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken) . '">';
                                echo '<input type="hidden" name="emploi_id" value="' . htmlspecialchars($emploi['id']) . '">';
                                echo '<div class="mb-3">';
                                echo '<label for="titre' . htmlspecialchars($emploi['id']) . '" class="form-label">Titre</label>';
                                echo '<input type="text" class="form-control" id="titre' . htmlspecialchars($emploi['id']) . '" name="titre" value="' . htmlspecialchars($emploi['titre']) . '" required>';
                                echo '</div>';
                                echo '<div class="mb-3">';
                                echo '<label for="description' . htmlspecialchars($emploi['id']) . '" class="form-label">Description</label>';
                                echo '<textarea class="form-control" id="description' . htmlspecialchars($emploi['id']) . '" name="description" rows="3" required>' . htmlspecialchars($emploi['description']) . '</textarea>';
                                echo '</div>';
                                echo '<div class="mb-3">';
                                echo '<label for="jour' . htmlspecialchars($emploi['id']) . '" class="form-label">Jour</label>';
                                echo '<input type="date" class="form-control" id="jour' . htmlspecialchars($emploi['id']) . '" name="jour" value="' . htmlspecialchars($emploi['jour']) . '" required>';
                                echo '</div>';
                                echo '<div class="mb-3">';
                                echo '<label for="heure_debut' . htmlspecialchars($emploi['id']) . '" class="form-label">Heure de début</label>';
                                echo '<input type="time" class="form-control" id="heure_debut' . htmlspecialchars($emploi['id']) . '" name="heure_debut" value="' . htmlspecialchars($emploi['heure_debut']) . '" required>';
                                echo '</div>';
                                echo '<div class="mb-3">';
                                echo '<label for="heure_fin' . htmlspecialchars($emploi['id']) . '" class="form-label">Heure de fin</label>';
                                echo '<input type="time" class="form-control" id="heure_fin' . htmlspecialchars($emploi['id']) . '" name="heure_fin" value="' . htmlspecialchars($emploi['heure_fin']) . '" required>';
                                echo '</div>';
                                echo '</div>';
                                echo '<div class="modal-footer">';
                                echo '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>';
                                echo '<button type="submit" name="modifier_emploi" class="btn btn-primary"><i class="fas fa-save me-2"></i>Enregistrer</button>';
                                echo '</div>';
                                echo '</form>';
                                echo '</div>';
                                echo '</div>';
                                echo '</div>';
                                echo '</div>';
                            }
                            echo '</div>';
                        } else {
                            echo '<p class="text-center text-muted">Aucun emploi du temps trouvé. Ajoutez votre premier emploi !</p>';
                        }
                    } elseif ($_SESSION['role'] === 'admin') {
                        echo '<h2 class="mb-4">Gestion des Notes et Emplois du Temps</h2>';

                        echo '<div class="section-header">';
                        echo '<h3>Ajouter une Note</h3>';
                        echo '<button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addNoteModalAdmin"><i class="fas fa-plus me-2"></i>Nouvelle Note</button>';
                        echo '</div>';

                        echo '<div class="modal fade" id="addNoteModalAdmin" tabindex="-1" aria-labelledby="addNoteModalAdminLabel" aria-hidden="true">';
                        echo '<div class="modal-dialog">';
                        echo '<div class="modal-content">';
                        echo '<div class="modal-header">';
                        echo '<h5 class="modal-title" id="addNoteModalAdminLabel">Ajouter une Note</h5>';
                        echo '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>';
                        echo '</div>';
                        echo '<form method="POST" enctype="multipart/form-data">';
                        echo '<div class="modal-body">';
                        echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken) . '">';
                        echo '<div class="mb-3">';
                        echo '<label for="titre" class="form-label">Titre</label>';
                        echo '<input type="text" class="form-control" id="titre" name="titre" placeholder="Titre" required>';
                        echo '</div>';
                        echo '<div class="mb-3">';
                        echo '<label for="contenu" class="form-label">Contenu</label>';
                        echo '<textarea class="form-control" id="contenu" name="contenu" placeholder="Contenu" rows="4" required></textarea>';
                        echo '</div>';
                        echo '<div class="mb-3">';
                        echo '<label for="image" class="form-label">Image (facultatif, max 2MB, JPEG/PNG/GIF)</label>';
                        echo '<input type="file" class="form-control" id="image" name="image" accept="image/jpeg,image/png,image/gif">';
                        echo '</div>';
                        echo '</div>';
                        echo '<div class="modal-footer">';
                        echo '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>';
                        echo '<button type="submit" name="ajout_note" class="btn btn-primary"><i class="fas fa-save me-2"></i>Enregistrer</button>';
                        echo '</div>';
                        echo '</form>';
                        echo '</div>';
                        echo '</div>';
                        echo '</div>';

                        echo '<div class="section-header">';
                        echo '<h3>Ajouter un Emploi du Temps</h3>';
                        echo '<button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addScheduleModalAdmin"><i class="fas fa-plus me-2"></i>Nouvel Emploi</button>';
                        echo '</div>';

                        echo '<div class="modal fade" id="addScheduleModalAdmin" tabindex="-1" aria-labelledby="addScheduleModalAdminLabel" aria-hidden="true">';
                        echo '<div class="modal-dialog">';
                        echo '<div class="modal-content">';
                        echo '<div class="modal-header">';
                        echo '<h5 class="modal-title" id="addScheduleModalAdminLabel">Ajouter un Emploi du Temps</h5>';
                        echo '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>';
                        echo '</div>';
                        echo '<form method="POST">';
                        echo '<div class="modal-body">';
                        echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken) . '">';
                        echo '<div class="mb-3">';
                        echo '<label for="titre" class="form-label">Titre</label>';
                        echo '<input type="text" class="form-control" id="titre" name="titre" placeholder="Titre" required>';
                        echo '</div>';
                        echo '<div class="mb-3">';
                        echo '<label for="description" class="form-label">Description</label>';
                        echo '<textarea class="form-control" id="description" name="description" placeholder="Description" rows="3" required></textarea>';
                        echo '</div>';
                        echo '<div class="mb-3">';
                        echo '<label for="jour" class="form-label">Jour</label>';
                        echo '<input type="date" class="form-control" id="jour" name="jour" required>';
                        echo '</div>';
                        echo '<div class="mb-3">';
                        echo '<label for="heure_debut" class="form-label">Heure de début</label>';
                        echo '<input type="time" class="form-control" id="heure_debut" name="heure_debut" required>';
                        echo '</div>';
                        echo '<div class="mb-3">';
                        echo '<label for="heure_fin" class="form-label">Heure de fin</label>';
                        echo '<input type="time" class="form-control" id="heure_fin" name="heure_fin" required>';
                        echo '</div>';
                        echo '</div>';
                        echo '<div class="modal-footer">';
                        echo '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>';
                        echo '<button type="submit" name="ajout_emploi" class="btn btn-primary"><i class="fas fa-save me-2"></i>Enregistrer</button>';
                        echo '</div>';
                        echo '</form>';
                        echo '</div>';
                        echo '</div>';
                        echo '</div>';

                        echo '<div class="section-divider"></div>';
                        echo '<h3>Toutes les Notes</h3>';
                        $stmt = $pdo->prepare("SELECT n.*, u.nom FROM notes n JOIN utilisateurs u ON n.utilisateur_id = u.id ORDER BY n.date_creation DESC");
                        $stmt->execute();
                        $notes = $stmt->fetchAll();

                        if ($notes) {
                            echo '<div class="notes-grid">';
                            foreach ($notes as $note) {
                                echo '<div class="card">';
                                echo '<div class="title">' . htmlspecialchars($note['titre']) . ' (par ' . htmlspecialchars($note['nom']) . ')</div>';
                                if (isset($note['image_path']) && !empty($note['image_path'])) {
                                    echo '<img src="uploads/' . htmlspecialchars($note['image_path']) . '" alt="Note Image" class="note-image">';
                                }
                                echo '<div class="content">' . htmlspecialchars($note['contenu']) . '</div>';
                                echo '<div class="date"><i class="fas fa-clock me-1"></i>' . htmlspecialchars($note['date_creation']) . '</div>';
                                echo '<div class="actions">';
                                echo '<button class="btn-edit" data-bs-toggle="modal" data-bs-target="#editNoteModalAdmin' . htmlspecialchars($note['id']) . '"><i class="fas fa-edit me-1"></i>Modifier</button>';
                                echo '<form method="POST" class="d-inline">';
                                echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken) . '">';
                                echo '<input type="hidden" name="note_id" value="' . htmlspecialchars($note['id']) . '">';
                                echo '<button type="submit" name="supprimer_note" class="btn-delete"><i class="fas fa-trash-alt me-1"></i>Supprimer</button>';
                                echo '</form>';
                                echo '</div>';

                                echo '<div class="modal fade" id="editNoteModalAdmin' . htmlspecialchars($note['id']) . '" tabindex="-1" aria-labelledby="editNoteModalAdminLabel' . htmlspecialchars($note['id']) . '" aria-hidden="true">';
                                echo '<div class="modal-dialog">';
                                echo '<div class="modal-content">';
                                echo '<div class="modal-header">';
                                echo '<h5 class="modal-title" id="editNoteModalAdminLabel' . htmlspecialchars($note['id']) . '">Modifier la Note</h5>';
                                echo '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>';
                                echo '</div>';
                                echo '<form method="POST" enctype="multipart/form-data">';
                                echo '<div class="modal-body">';
                                if ($modification_error && isset($_POST['note_id']) && $_POST['note_id'] == $note['id']) {
                                    echo "<p class='erreur'>" . htmlspecialchars($modification_error) . "</p>";
                                }
                                echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken) . '">';
                                echo '<input type="hidden" name="note_id" value="' . htmlspecialchars($note['id']) . '">';
                                echo '<div class="mb-3">';
                                echo '<label for="titreAdmin' . htmlspecialchars($note['id']) . '" class="form-label">Titre</label>';
                                echo '<input type="text" class="form-control" id="titreAdmin' . htmlspecialchars($note['id']) . '" name="titre" value="' . htmlspecialchars($note['titre']) . '" required>';
                                echo '</div>';
                                echo '<div class="mb-3">';
                                echo '<label for="contenuAdmin' . htmlspecialchars($note['id']) . '" class="form-label">Contenu</label>';
                                echo '<textarea class="form-control" id="contenuAdmin' . htmlspecialchars($note['id']) . '" name="contenu" rows="4" required>' . htmlspecialchars($note['contenu']) . '</textarea>';
                                echo '</div>';
                                echo '<div class="mb-3">';
                                echo '<label for="imageAdmin' . htmlspecialchars($note['id']) . '" class="form-label">Image (facultatif, max 2MB, JPEG/PNG/GIF)</label>';
                                echo '<input type="file" class="form-control" id="imageAdmin' . htmlspecialchars($note['id']) . '" name="image" accept="image/jpeg,image/png,image/gif">';
                                if (isset($note['image_path']) && !empty($note['image_path'])) {
                                    echo '<p class="mt-2"><small>Image actuelle : <a href="uploads/' . htmlspecialchars($note['image_path']) . '" target="_blank">Voir l\'image</a></small></p>';
                                }
                                echo '</div>';
                                echo '</div>';
                                echo '<div class="modal-footer">';
                                echo '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>';
                                echo '<button type="submit" name="modifier_note" class="btn btn-primary"><i class="fas fa-save me-2"></i>Enregistrer</button>';
                                echo '</div>';
                                echo '</form>';
                                echo '</div>';
                                echo '</div>';
                                echo '</div>';
                                echo '</div>';
                            }
                            echo '</div>';
                        } else {
                            echo '<p class="text-center text-muted">Aucune note trouvée.</p>';
                        }

                        echo '<div class="section-divider"></div>';
                        echo '<h3>Tous les Emplois du Temps</h3>';
                        $stmt = $pdo->prepare("SELECT e.*, u.nom FROM emplois_du_temps e JOIN utilisateurs u ON e.utilisateur_id = u.id ORDER BY e.jour, e.heure_debut");
                        $stmt->execute();
                        $emplois = $stmt->fetchAll();

                        if ($emplois) {
                            echo '<div class="schedules-grid">';
                            foreach ($emplois as $emploi) {
                                echo '<div class="card">';
                                echo '<div class="title">' . htmlspecialchars($emploi['titre']) . ' (par ' . htmlspecialchars($emploi['nom']) . ')</div>';
                                echo '<div class="content">' . htmlspecialchars($emploi['description']) . '</div>';
                                echo '<div class="date"><strong><i class="fas fa-calendar-day me-1"></i>Jour:</strong> ' . htmlspecialchars($emploi['jour']) . '</div>';
                                echo '<div class="date"><strong><i class="fas fa-clock me-1"></i>Heure:</strong> ' . htmlspecialchars($emploi['heure_debut']) . ' - ' . htmlspecialchars($emploi['heure_fin']) . '</div>';
                                echo '<div class="date"><i class="fas fa-clock me-1"></i>' . htmlspecialchars($emploi['date_creation']) . '</div>';
                                echo '<div class="actions">';
                                echo '<button class="btn-edit" data-bs-toggle="modal" data-bs-target="#editScheduleModalAdmin' . htmlspecialchars($emploi['id']) . '"><i class="fas fa-edit me-1"></i>Modifier</button>';
                                echo '<form method="POST" class="d-inline">';
                                echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken) . '">';
                                echo '<input type="hidden" name="emploi_id" value="' . htmlspecialchars($emploi['id']) . '">';
                                echo '<button type="submit" name="supprimer_emploi" class="btn-delete"><i class="fas fa-trash-alt me-1"></i>Supprimer</button>';
                                echo '</form>';
                                echo '</div>';

                                echo '<div class="modal fade" id="editScheduleModalAdmin' . htmlspecialchars($emploi['id']) . '" tabindex="-1" aria-labelledby="editScheduleModalAdminLabel' . htmlspecialchars($emploi['id']) . '" aria-hidden="true">';
                                echo '<div class="modal-dialog">';
                                echo '<div class="modal-content">';
                                echo '<div class="modal-header">';
                                echo '<h5 class="modal-title" id="editScheduleModalAdminLabel' . htmlspecialchars($emploi['id']) . '">Modifier l\'Emploi du Temps</h5>';
                                echo '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>';
                                echo '</div>';
                                echo '<form method="POST">';
                                echo '<div class="modal-body">';
                                if ($schedule_modification_error && isset($_POST['emploi_id']) && $_POST['emploi_id'] == $emploi['id']) {
                                    echo "<p class='erreur'>" . htmlspecialchars($schedule_modification_error) . "</p>";
                                }
                                echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken) . '">';
                                echo '<input type="hidden" name="emploi_id" value="' . htmlspecialchars($emploi['id']) . '">';
                                echo '<div class="mb-3">';
                                echo '<label for="titreAdmin' . htmlspecialchars($emploi['id']) . '" class="form-label">Titre</label>';
                                echo '<input type="text" class="form-control" id="titreAdmin' . htmlspecialchars($emploi['id']) . '" name="titre" value="' . htmlspecialchars($emploi['titre']) . '" required>';
                                echo '</div>';
                                echo '<div class="mb-3">';
                                echo '<label for="descriptionAdmin' . htmlspecialchars($emploi['id']) . '" class="form-label">Description</label>';
                                echo '<textarea class="form-control" id="descriptionAdmin' . htmlspecialchars($emploi['id']) . '" name="description" rows="3" required>' . htmlspecialchars($emploi['description']) . '</textarea>';
                                echo '</div>';
                                echo '<div class="mb-3">';
                                echo '<label for="jourAdmin' . htmlspecialchars($emploi['id']) . '" class="form-label">Jour</label>';
                                echo '<input type="date" class="form-control" id="jourAdmin' . htmlspecialchars($emploi['id']) . '" name="jour" value="' . htmlspecialchars($emploi['jour']) . '" required>';
                                echo '</div>';
                                echo '<div class="mb-3">';
                                echo '<label for="heure_debutAdmin' . htmlspecialchars($emploi['id']) . '" class="form-label">Heure de début</label>';
                                echo '<input type="time" class="form-control" id="heure_debutAdmin' . htmlspecialchars($emploi['id']) . '" name="heure_debut" value="' . htmlspecialchars($emploi['heure_debut']) . '" required>';
                                echo '</div>';
                                echo '<div class="mb-3">';
                                echo '<label for="heure_finAdmin' . htmlspecialchars($emploi['id']) . '" class="form-label">Heure de fin</label>';
                                echo '<input type="time" class="form-control" id="heure_finAdmin' . htmlspecialchars($emploi['id']) . '" name="heure_fin" value="' . htmlspecialchars($emploi['heure_fin']) . '" required>';
                                echo '</div>';
                                echo '</div>';
                                echo '<div class="modal-footer">';
                                echo '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>';
                                echo '<button type="submit" name="modifier_emploi" class="btn btn-primary"><i class="fas fa-save me-2"></i>Enregistrer</button>';
                                echo '</div>';
                                echo '</form>';
                                echo '</div>';
                                echo '</div>';
                                echo '</div>';
                                echo '</div>';
                            }
                            echo '</div>';
                        } else {
                            echo '<p class="text-center text-muted">Aucun emploi du temps trouvé.</p>';
                        }
                    }
                    break;

                default:
                    echo '<div class="welcome-section">';
                    echo '<h1>Page non trouvée</h1>';
                    echo '<p>La page demandée n\'existe pas.</p>';
                    echo '<a href="index.php" class="btn btn-primary mt-3"><i class="fas fa-home me-2"></i>Retour à l\'accueil</a>';
                    echo '</div>';
                    break;
            }
            ?>
        </div>
    </div>

    <script src="js/bootstrap.bundle.min.js"></script>
    <script>
        const hamburger = document.getElementById('hamburger');
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.querySelector('.main-content');

        hamburger.addEventListener('click', () => {
            sidebar.classList.toggle('active');
            sidebar.classList.toggle('hidden');
            if (sidebar.classList.contains('active')) {
                mainContent.style.marginLeft = '260px';
            } else {
                mainContent.style.marginLeft = '0';
            }
        });

        window.addEventListener('resize', () => {
            if (window.innerWidth > 768)