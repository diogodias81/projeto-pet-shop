
<?php

ob_start();

// Front controller: toda requisição passa por aqui.
require __DIR__ . '/../app/bootstrap.php';

$rotas = require BASE_PATH . '/config/routes.php';

$pagina = $_GET['page'] ?? 'inicio';


// Login / logout

if ($pagina === 'logout') {

    session_destroy();

    header('Location: index.php?page=login');

    exit;
}


if ($pagina === 'login') {

    if (usuario()) {
        redirect('inicio');
    }

    require BASE_PATH . '/app/Pages/login.php';

    exit;
}


if (!usuario()) {
    redirect('login');
}


// Página inicial depende do perfil

if ($pagina === 'inicio') {

    foreach ($rotas as $nome => [, $perfis]) {

        if (temPerfil($perfis)) {
            redirect($nome);
        }
    }

    exit('Seu perfil não tem acesso a nenhuma página.');
}


// Verifica se a página existe

if (!isset($rotas[$pagina])) {

    http_response_code(404);

    exit('Página não encontrada.');
}


// Verifica o perfil

exigirPerfil($rotas[$pagina][1]);


// Valida CSRF em requisições POST

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_validar();
}


// Executa a página antes do layout

ob_start();

require BASE_PATH . '/app/Pages/' . $pagina . '.php';

$conteudo = ob_get_clean();


// Monta o layout

require BASE_PATH . '/app/Views/header.php';

echo $conteudo;

require BASE_PATH . '/app/Views/footer.php';


ob_end_flush();
