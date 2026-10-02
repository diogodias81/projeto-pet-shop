
<?php

// ============================================================
// ROTAS DO SISTEMA
// ============================================================
//
// Para criar uma nova página:
//
// 1. Adicione uma rota neste arquivo.
// 2. Crie o arquivo correspondente em:
//    app/Pages/<nome>.php
//
// Formato:
//
// 'pagina' => [
//     'Rótulo no menu',
//     ['perfis com acesso']
// ],
//
// ============================================================

return [

    // Dashboard
    'dashboard' => [
        'Dashboard',
        ['admin', 'financeiro']
    ],

    // Vendas
    'vendas' => [
        'Vendas',
        ['admin', 'vendedor']
    ],

    // Produtos
    'produtos' => [
        'Produtos',
        ['admin', 'estoque', 'vendedor']
    ],

    // Estoque
    'estoque' => [
        'Estoque',
        ['admin', 'estoque', 'vendedor']
    ],

    // Categorias
    'categorias' => [
        'Categorias',
        ['admin', 'estoque']
    ],

    // Relatórios
    'relatorios' => [
        'Relatórios',
        ['admin', 'financeiro']
    ],

    // Usuários
    'usuarios' => [
        'Usuários',
        ['admin']
    ],

];
