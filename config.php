<?php
// Database configuration
$host = 'localhost';
$dbname = 'mon_carnet_notes';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET CHARACTER SET utf8mb4");
} catch (PDOException $e) {
    die("Erreur de connexion à la base de données : " . $e->getMessage());
}

// Function to sanitize input
function sanitizeInput($data) {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

// Function to validate institutional email
function validateInstitutionEmail($email) {
    return preg_match('/^[a-zA-Z0-9._%+-]+@institution\.edu$/', $email);
}

// Function to validate password (at least 8 characters, 1 letter, 1 number, 1 special character)
function validatePassword($password) {
    return preg_match('/^(?=.*[A-Za-z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/', $password);
}

// Function to generate CSRF token
function generateCsrfToken($userId, $pdo) {
    $token = bin2hex(random_bytes(32));
    $stmt = $pdo->prepare("INSERT INTO csrf_tokens (user_id, token, created_at) VALUES (?, ?, NOW())");
    $stmt->execute([$userId, $token]);
    return $token;
}

// Function to validate CSRF token
function validateCsrfToken($userId, $token, $pdo) {
    $stmt = $pdo->prepare("SELECT * FROM csrf_tokens WHERE user_id = ? AND token = ? AND created_at > NOW() - INTERVAL 1 HOUR");
    $stmt->execute([$userId, $token]);
    $result = $stmt->fetch();
    
    if ($result) {
        $stmt = $pdo->prepare("DELETE FROM csrf_tokens WHERE user_id = ? AND token = ?");
        $stmt->execute([$userId, $token]);
        return true;
    }
    return false;
}

// Create necessary tables if they don't exist
try {
    // Users table
    $pdo->exec("CREATE TABLE IF NOT EXISTS utilisateurs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nom VARCHAR(255) NOT NULL,
        email VARCHAR(255) NOT NULL UNIQUE,
        mot_de_passe VARCHAR(255) NOT NULL,
        role ENUM('admin', 'enseignant', 'etudiant') NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Notes table
    $pdo->exec("CREATE TABLE IF NOT EXISTS notes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        utilisateur_id INT NOT NULL,
        titre VARCHAR(255) NOT NULL,
        contenu TEXT NOT NULL,
        image_path VARCHAR(255),
        date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs(id) ON DELETE CASCADE
    )");

    // Schedules table
    $pdo->exec("CREATE TABLE IF NOT EXISTS emplois_du_temps (
        id INT AUTO_INCREMENT PRIMARY KEY,
        utilisateur_id INT NOT NULL,
        titre VARCHAR(255) NOT NULL,
        description TEXT NOT NULL,
        jour DATE NOT NULL,
        heure_debut TIME NOT NULL,
        heure_fin TIME NOT NULL,
        date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs(id) ON DELETE CASCADE
    )");

    // CSRF tokens table
    $pdo->exec("CREATE TABLE IF NOT EXISTS csrf_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        token VARCHAR(64) NOT NULL,
        created_at TIMESTAMP NOT NULL
    )");

    // Subjects table
    $pdo->exec("CREATE TABLE IF NOT EXISTS matieres (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nom VARCHAR(255) NOT NULL UNIQUE
    )");

    // Grades table (for student performance)
    $pdo->exec("CREATE TABLE IF NOT EXISTS notes_matieres (
        id INT AUTO_INCREMENT PRIMARY KEY,
        utilisateur_id INT NOT NULL,
        matiere_id INT NOT NULL,
        note DECIMAL(4,2) NOT NULL,
        date_ajout TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs(id) ON DELETE CASCADE,
        FOREIGN KEY (matiere_id) REFERENCES matieres(id) ON DELETE CASCADE
    )");
} catch (PDOException $e) {
    die("Erreur lors de la création des tables : " . $e->getMessage());
}

// Create uploads directory if it doesn't exist
if (!file_exists('uploads')) {
    mkdir('uploads', 0777, true);
}

// Function to calculate average grade per subject
function getAverageGrades($userId, $pdo) {
    $stmt = $pdo->prepare("
        SELECT m.nom AS matiere, AVG(nm.note) AS moyenne
        FROM notes_matieres nm
        JOIN matieres m ON nm.matiere_id = m.id
        WHERE nm.utilisateur_id = ?
        GROUP BY m.id, m.nom
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Function to get grade history for a student
function getGradeHistory($userId, $pdo) {
    $stmt = $pdo->prepare("
        SELECT m.nom AS matiere, nm.note, nm.date_ajout
        FROM notes_matieres nm
        JOIN matieres m ON nm.matiere_id = m.id
        WHERE nm.utilisateur_id = ?
        ORDER BY nm.date_ajout
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>