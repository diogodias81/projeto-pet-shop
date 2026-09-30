<?php
// Para criar uma nova página: adicione uma linha aqui e um arquivo em app/Pages/<nome>.php
// 'pagina' => [rótulo no menu, perfis com acesso]
return [
    'dashboard'  => ['Dashboard',  ['admin', 'financeiro']],
    'vendas'     => ['Vendas',     ['admin', 'vendedor']],
    'produtos'   => ['Produtos',   ['admin', 'estoque', 'vendedor']],
    'estoque'    => ['Estoque',    ['admin', 'estoque', 'vendedor']],
    'categorias' => ['Categorias', ['admin', 'estoque']],
    'relatorios' => ['Relatórios', ['admin', 'financeiro']],
    'usuarios'   => ['Usuários',   ['admin']],
];
