<?php



return [


    'app_nome' => 'PetShop Gestão',


    'db_path' => BASE_PATH . '/storage/petshop.sqlite',

 
    // Detalhes de erro na tela só em desenvolvimento: APP_DEBUG=1
    'debug' => getenv('APP_DEBUG') === '1',


    // Cabeçalho do comprovante de venda. Troque pelos dados reais da loja.
    'loja' => [
        'nome' => 'PetShop Gestão',
        'cnpj' => '00.000.000/0001-00',
        'endereco' => 'Rua Exemplo, 123 - Centro - Belém/PA',
        'telefone' => '(91) 0000-0000',
    ],


    // Desconto máximo que o vendedor pode dar, em fração do subtotal (0.10 = 10%).
    // O administrador não tem limite.
    'desconto_max_vendedor' => 0.10,


    // Prazos dos alertas de validade, em dias antes do vencimento.
    'alertas_validade_dias' => [30, 15, 7],

];
