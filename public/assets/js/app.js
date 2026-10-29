// <form data-confirmar="Mensagem">

document.querySelectorAll('form[data-confirmar]').forEach(form => {

        form.addEventListener('submit', ev => {

            if (!confirm(form.dataset.confirmar)) {
                ev.preventDefault();
            }
        });
    });


// Leitor de código de barras: o leitor USB "digita" o código e aperta Enter.
// <input data-sem-enter> não envia o formulário com esse Enter.
// <input data-seleciona="id-do-select"> escolhe no select a opção com o mesmo data-codigo.

document.querySelectorAll('input[data-sem-enter]').forEach(input => {

    input.addEventListener('keydown', ev => {

        if (ev.key !== 'Enter') return;

        ev.preventDefault();

        const select = document.getElementById(input.dataset.seleciona || '');

        if (!select) return;


        const codigo = input.value.trim();

        const opt = [...select.options].find(
            o => codigo && o.dataset.codigo === codigo
        );


        if (!opt) {

            return alert('Nenhum produto com o código ' + codigo + '.');
        }


        select.value = opt.value;

        input.value = '';

        input.form.querySelector('[name="quantidade"]')?.focus();
    });
});


// Tela de vendas

(function () {

    const form = document.getElementById('form-venda');

    if (!form) return;


    const select = document.getElementById('v-produto');

    const qtdInput = document.getElementById('v-qtd');

    const tbody = document.querySelector('#v-tabela tbody');

    const vazio = document.getElementById('v-vazio');

    const totalEl = document.getElementById('v-total');

    const hidden = document.getElementById('venda-itens');

    const finalizar = document.getElementById('v-finalizar');


    const moeda = valor => {

        return valor.toLocaleString(
            'pt-BR',
            {
                style: 'currency',
                currency: 'BRL'
            }
        );
    };


    let itens = [];


    function renderizar() {

        tbody.innerHTML = '';

        let total = 0;


        itens.forEach((item, indice) => {

            const subtotal =
                item.preco * item.quantidade;

            total += subtotal;


            const tr = document.createElement('tr');


            const celulas = [
                item.nome,
                item.quantidade,
                moeda(item.preco),
                moeda(subtotal)
            ];


            celulas.forEach((texto, coluna) => {

                const td = document.createElement('td');

                td.textContent = texto;


                if (coluna > 0) {
                    td.className = 'num';
                }


                tr.appendChild(td);
            });


            const tdAcao = document.createElement('td');

            const btn = document.createElement('button');


            btn.type = 'button';

            btn.className =
                'btn btn-perigo btn-pequeno';

            btn.textContent = 'Remover';


            btn.addEventListener('click', () => {

                itens.splice(indice, 1);

                renderizar();
            });


            tdAcao.appendChild(btn);

            tr.appendChild(tdAcao);

            tbody.appendChild(tr);
        });


        totalEl.textContent = moeda(total);


        hidden.value = JSON.stringify(
            itens.map(
                ({ produto_id, quantidade }) => ({
                    produto_id,
                    quantidade
                })
            )
        );


        vazio.style.display =
            itens.length ? 'none' : '';


        finalizar.disabled =
            itens.length === 0;
    }


    document
        .getElementById('v-add')
        .addEventListener('click', () => {

            const opt =
                select.selectedOptions[0];

            if (!opt) return;


            const quantidade =
                parseInt(qtdInput.value, 10);

            const estoque =
                parseInt(opt.dataset.estoque, 10);

            const id = opt.value;


            const existente = itens.find(
                item => item.produto_id === id
            );


            const jaNoCarrinho =
                existente
                    ? existente.quantidade
                    : 0;


            if (!quantidade || quantidade < 1) {

                return alert(
                    'Informe uma quantidade válida.'
                );
            }


            if (jaNoCarrinho + quantidade > estoque) {

                return alert(
                    'Estoque insuficiente. Disponível: ' +
                    estoque
                );
            }


            if (existente) {

                existente.quantidade += quantidade;

            } else {

                itens.push({

                    produto_id: id,

                    nome: opt.dataset.nome,

                    preco: parseFloat(
                        opt.dataset.preco
                    ),

                    quantidade
                });
            }


            qtdInput.value = 1;

            renderizar();
        });


    renderizar();

})();

