
<?php

declare(strict_types=1);


// Caminho principal do projeto

define(
    'BASE_PATH',
    dirname(__DIR__)
);


// Configuração de data e hora

date_default_timezone_set('America/Belem');


// Arquivos principais

require BASE_PATH . '/app/helpers.php';

require BASE_PATH . '/app/Database.php';


// Autoload dos Models

spl_autoload_register(
    function (string $classe) {

        $arquivo = BASE_PATH . '/app/Models/' . $classe . '.php';

        if (is_file($arquivo)) {

            require $arquivo;
        }
    }
);


// Inicia a sessão

session_start();


// Tratamento de erros

set_exception_handler(
    function (Throwable $e) {

        http_response_code(500);

        error_log((string) $e);

        echo '<h1>Erro interno</h1>';

        echo '<p>Algo deu errado. Tente novamente.</p>';

        if (config('debug')) {

            echo '<pre>';

            echo e((string) $e);

            echo '</pre>';
        }
    }
);


// Cria ou atualiza o banco de dados

Database::migrar();
