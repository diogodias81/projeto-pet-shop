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


    // Largura do papel da impressora de cupom (térmica), em mm: 80 ou 58
    'cupom_largura_mm' => 80,

    // Desconto máximo que o vendedor pode dar, em fração do subtotal (0.10 = 10%).
    // O administrador não tem limite.
    'desconto_max_vendedor' => 0.10,

    // Soma máxima das sangrias de um mesmo vendedor no dia (todos os caixas do dia: fechar e reabrir não zera), em R$.
    // Ele não vê o esperado, então a sangria dele não é comparada com a gaveta; acima disso, só o administrador.
    // O administrador não tem este limite.
    'sangria_max_vendedor' => 500.00,


    // Minutos sem usar o sistema até a sessão expirar e pedir login de novo (caixa esquecido aberto).
    'sessao_inatividade_min' => 30,


    // Banho e tosa: quantos pets podem ser atendidos ao mesmo tempo (1 tosador = 1) e o horário da agenda
    'banho_tosa_simultaneos' => 1,
    'agenda_abre' => '08:00',
    'agenda_fecha' => '19:00',
    // Dashboard avisa as próximas doses (vacina, vermífugo, antipulgas) que vencem nestes dias
    'doses_aviso_dias' => 7,


    // Prazos dos alertas de validade, em dias antes do vencimento.
    'alertas_validade_dias' => [30, 15, 7],

];
