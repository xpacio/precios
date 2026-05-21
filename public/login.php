<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - Sistema de Precios</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">
    <style>
        body { display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        article { width: 100%; max-width: 400px; }
        .error { color: #d93025; text-align: center; margin-bottom: 1rem; }
    </style>
</head>
<body>
    <main class="container">
        <article>
            <header>
                <h2 style="margin:0; text-align:center;">Precios API</h2>
            </header>

            <?php if (isset($_GET['error'])): ?>
                <p class="error">
                    <?php 
                        switch($_GET['error']) {
                            case 'invalid_credentials': echo "Usuario o contraseña incorrectos"; break;
                            case 'campos_vacios': echo "Por favor complete todos los campos"; break;
                            case 'usuario_deshabilitado': echo "Su cuenta está deshabilitada"; break;
                            default: echo "Ocurrió un error inesperado";
                        }
                    ?>
                </p>
            <?php endif; ?>

            <form action="/api/v1/auth" method="POST">
                <label for="nickname">
                    Usuario
                    <input type="text" id="nickname" name="nickname" placeholder="Nickname" required autofocus>
                </label>
                <label for="password">
                    Contraseña
                    <input type="password" id="password" name="password" placeholder="Contraseña" required>
                </label>
                <button type="submit">Entrar</button>
            </form>
        </article>
    </main>
</body>
</html>
