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

    // Histórico de vendas (vendedor vê só as próprias)
    'historico' => [
        'Histórico',
        ['admin', 'vendedor', 'financeiro']
    ],

    // Comprovante de venda: rótulo null = não aparece no menu
    'comprovante' => [
        null,
        ['admin', 'vendedor', 'financeiro']
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

    // Despesas da loja
    'despesas' => [
        'Despesas',
        ['admin', 'financeiro']
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

    // Registro de operações importantes
    'auditoria' => [
        'Auditoria',
        ['admin']
    ],

    // Cada um troca a própria senha (link junto do "Sair", fora da lista do menu)
    'minha_senha' => [
        null,
        ['admin', 'vendedor', 'estoque', 'financeiro']
    ],

];
