<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Envoi Email</title>
</head>
<body>
    <h1>Test d'envoi d'email</h1>
    <?php
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Récupération des données du formulaire
        $to = $_POST['to_email'];
        $subject = $_POST['subject'];
        $message = $_POST['message'];

        // En-têtes de l'email
        $headers = "From: contact@checklistud.atwebpages.com/\r\n"; // Remplacez par votre email de domaine
        $headers .= "Reply-To: contact@checklistud.atwebpages.com\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

        // Envoi de l'email
        if (mail($to, $subject, $message, $headers)) {
            echo "<p style='color:green;'>Email envoyé avec succès à $to.</p>";
        } else {
            echo "<p style='color:red;'>Échec de l'envoi de l'email. Vérifiez les paramètres de votre hébergement.</p>";
        }
    }
    ?>
    <form method="POST" action="">
        <label for="to_email">Adresse email de destination :</label><br>
        <input type="email" id="to_email" name="to_email" required><br><br>

        <label for="subject">Sujet :</label><br>
        <input type="text" id="subject" name="subject" required><br><br>

        <label for="message">Message :</label><br>
        <textarea id="message" name="message" rows="5" cols="40" required></textarea><br><br>

        <button type="submit">Envoyer l'email</button>
    </form>
</body>
</html>
