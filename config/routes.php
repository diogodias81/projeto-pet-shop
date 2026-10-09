<?php
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
