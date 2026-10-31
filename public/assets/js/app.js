// <form data-confirmar="Mensagem">

document.querySelectorAll('form[data-confirmar]').forEach(form => {

        form.addEventListener('submit', ev => {

            if (!confirm(form.dataset.confirmar)) {
                ev.preventDefault();
            }
        });
    });


// <button data-confirmar-botao="Mensagem">: confirma só a ação deste botão

document.querySelectorAll('button[data-confirmar-botao]').forEach(btn => {

    btn.addEventListener('click', ev => {

        if (btn.form?.checkValidity() && !confirm(btn.dataset.confirmarBotao)) {
            ev.preventDefault();
        }
    });
});


// <button data-imprimir>: abre a impressão (a CSP não permite onclick no HTML)

document.querySelectorAll('[data-imprimir]').forEach(btn => {

    btn.addEventListener('click', () => window.print());
});


// Celular: cada célula recebe o nome da coluna (data-label), que o CSS mostra quando a tabela vira cartões

function rotularTabela(tabela) {

    const nomes = [...tabela.querySelectorAll('thead th')].map(th => th.textContent.trim());

    tabela.querySelectorAll('tbody tr').forEach(tr => {

        [...tr.children].forEach((td, i) => td.setAttribute('data-label', nomes[i] || ''));
    });
}

document.querySelectorAll('.table-responsive table').forEach(rotularTabela);


// Gaveta "Mais": se a tela virar tamanho de computador com ela aberta, fecha (senão o fundo escuro fica travado)

const menuMais = document.getElementById('menu-mais');

if (menuMais && window.bootstrap) {

    matchMedia('(min-width: 992px)').addEventListener('change', ev => {

        if (ev.matches) bootstrap.Offcanvas.getInstance(menuMais)?.hide();
    });
}


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
// Os valores aqui são só para mostrar na tela: o servidor refaz todas as contas ao finalizar.

(function () {

    const form = document.getElementById('form-venda');

    if (!form) return;


    const catalogo = JSON.parse(
        document.getElementById('v-catalogo').textContent
    );

    const esgotados = JSON.parse(
        document.getElementById('v-esgotados').textContent
    );

    const avisoBusca = document.getElementById('v-busca-aviso');

    const busca = document.getElementById('v-busca');

    const sugestoes = document.getElementById('v-sugestoes');

    const tbody = document.querySelector('#v-tabela tbody');

    const vazio = document.getElementById('v-vazio');

    const hidden = document.getElementById('venda-itens');

    const finalizar = document.getElementById('v-finalizar');

    const descTipo = document.getElementById('v-desc-tipo');

    const descInput = document.getElementById('v-desconto');

    const forma = document.getElementById('v-forma');

    const recebidoCampo = document.getElementById('v-recebido-campo');

    const recebido = document.getElementById('v-recebido');

    const erro = document.getElementById('v-erro');

    const descontoMax = parseFloat(form.dataset.descontoMax);

    const $ = id => document.getElementById(id);


    const moeda = valor => {

        return valor.toLocaleString(
            'pt-BR',
            {
                style: 'currency',
                currency: 'BRL'
            }
        );
    };


    // "1.234,56", "12,5" ou "12.5" → número
    const numero = texto => {

        let v = String(texto).replace(/[^\d,.]/g, '');

        if (v.includes(',')) v = v.replace(/\./g, '').replace(',', '.');

        return parseFloat(v) || 0;
    };


    const centavos = v => Math.round(v * 100) / 100;


    let itens = [];


    // ---- pesquisa rápida ----

    function adicionar(produto, quantidade = 1) {

        const existente = itens.find(i => i.produto_id === produto.id);

        const jaNoCarrinho = existente ? existente.quantidade : 0;


        if (jaNoCarrinho + quantidade > produto.estoque) {

            alert('Estoque insuficiente para ' + produto.nome + '. Disponível: ' + produto.estoque);

            return;
        }


        if (existente) {

            existente.quantidade += quantidade;

        } else {

            itens.push({ produto_id: produto.id, produto, quantidade });
        }


        busca.value = '';

        fecharSugestoes();

        renderizar();

        busca.focus();
    }


    // Minúsculas e sem acento: "racao" acha "Ração"
    const normalizar = s => s.trim().toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');

    function encontrar(texto, lista = catalogo) {

        const t = normalizar(texto);

        if (!t) return [];

        return lista
            .filter(p => p.codigo === texto.trim() || normalizar(p.nome).includes(t))
            .slice(0, 8);
    }


    function fecharSugestoes() {

        sugestoes.hidden = true;

        sugestoes.innerHTML = '';

        busca.setAttribute('aria-expanded', 'false');
    }


    function mostrarSugestoes() {

        const achados = encontrar(busca.value);

        sugestoes.innerHTML = '';


        if (!achados.length) {

            if (busca.value.trim()) {

                const li = document.createElement('li');

                li.className = 'sugestao-vazia';

                const esgotado = encontrar(busca.value, esgotados)[0];

                li.textContent = esgotado
                    ? esgotado.nome + ' está sem estoque disponível para venda.'
                    : 'Nenhum produto disponível encontrado.';

                sugestoes.appendChild(li);
            }

        } else {

            achados.forEach(p => {

                const li = document.createElement('li');

                li.role = 'option';

                li.tabIndex = -1;

                li.className = 'sugestao';


                const img = document.createElement(p.foto ? 'img' : 'span');

                img.className = 'foto' + (p.foto ? '' : ' foto-vazia');

                if (p.foto) { img.src = p.foto; img.alt = ''; }


                const nome = document.createElement('span');

                nome.className = 'sugestao-nome';

                nome.textContent = p.nome;


                const info = document.createElement('small');

                info.textContent = moeda(p.preco) + ' · estoque ' + p.estoque + (p.codigo ? ' · ' + p.codigo : '');

                nome.appendChild(info);


                li.append(img, nome);

                li.addEventListener('mousedown', ev => { ev.preventDefault(); adicionar(p); });

                li.addEventListener('keydown', ev => {

                    if (ev.key === 'Enter') { ev.preventDefault(); adicionar(p); }

                    if (ev.key === 'ArrowDown') { ev.preventDefault(); li.nextElementSibling?.focus(); }

                    if (ev.key === 'ArrowUp') { ev.preventDefault(); (li.previousElementSibling || busca).focus(); }

                    if (ev.key === 'Escape') { fecharSugestoes(); busca.focus(); }
                });

                sugestoes.appendChild(li);
            });
        }


        sugestoes.hidden = !sugestoes.children.length;

        busca.setAttribute('aria-expanded', String(!sugestoes.hidden));
    }


    busca.addEventListener('input', () => { avisoBusca.hidden = true; mostrarSugestoes(); });

    busca.addEventListener('blur', () => setTimeout(() => {

        if (!sugestoes.contains(document.activeElement)) fecharSugestoes();

    }, 100));


    busca.addEventListener('keydown', ev => {

        if (ev.key === 'ArrowDown') {

            ev.preventDefault();

            sugestoes.querySelector('.sugestao')?.focus();

            return;
        }

        if (ev.key === 'Escape') return fecharSugestoes();

        if (ev.key !== 'Enter') return;


        // Enter do leitor ou do teclado: código exato adiciona direto; senão, o primeiro da lista
        ev.preventDefault();

        const texto = busca.value.trim();

        if (!texto) return;


        const exato = catalogo.find(p => p.codigo === texto);

        const produto = exato || encontrar(texto)[0];


        if (produto) {

            adicionar(produto);

        } else {

            const esgotado = encontrar(texto, esgotados)[0];

            avisoBusca.textContent = esgotado
                ? esgotado.nome + ' está sem estoque disponível para venda.'
                : 'Nenhum produto encontrado com "' + texto + '".';

            avisoBusca.hidden = false;

            busca.select();
        }
    });


    // ---- itens e totais ----

    function calcular() {

        const subtotal = centavos(itens.reduce((s, i) => s + i.produto.preco * i.quantidade, 0));

        const v = numero(descInput.value);

        const desconto = centavos(descTipo.value === 'percentual' ? subtotal * v / 100 : v);

        const total = centavos(subtotal - desconto);

        const pago = numero(recebido.value);


        let problema = '';

        if (subtotal > 0 && desconto >= subtotal) {

            problema = 'O desconto deve ser menor que o subtotal: a venda não pode ficar em R$ 0,00.';

        } else if (desconto > centavos(subtotal * descontoMax)) {

            problema = 'Desconto acima do permitido para o seu perfil (máximo ' + Math.round(descontoMax * 100) + '%).';

        } else if (forma.value === 'dinheiro' && recebido.value.trim() && pago < total) {

            problema = 'O valor recebido é menor que o total.';
        }


        return { subtotal, desconto, total, pago, problema };
    }


    function atualizarTotais() {

        const c = calcular();

        const dinheiro = forma.value === 'dinheiro';


        $('v-subtotal').textContent = moeda(c.subtotal);

        $('v-desc-valor').textContent = c.desconto ? '− ' + moeda(c.desconto) : moeda(0);

        $('v-total').textContent = moeda(Math.max(c.total, 0));


        recebidoCampo.hidden = !dinheiro;

        const mostraTroco = dinheiro && c.pago >= c.total && recebido.value.trim() !== '';

        $('v-troco').hidden = $('v-troco-rotulo').hidden = !mostraTroco;

        $('v-troco').textContent = moeda(centavos(c.pago - c.total));


        erro.hidden = !c.problema;

        erro.textContent = c.problema;

        finalizar.disabled = itens.length === 0 || !!c.problema || !forma.value;
    }


    function renderizar() {

        tbody.innerHTML = '';


        itens.forEach((item, indice) => {

            const p = item.produto;

            const tr = document.createElement('tr');


            const tdFoto = document.createElement('td');

            const img = document.createElement(p.foto ? 'img' : 'span');

            img.className = 'foto' + (p.foto ? '' : ' foto-vazia');

            if (p.foto) { img.src = p.foto; img.alt = ''; }

            tdFoto.appendChild(img);


            const tdNome = document.createElement('td');

            tdNome.textContent = p.nome;


            const tdQtd = document.createElement('td');

            tdQtd.className = 'num';

            const qtd = document.createElement('input');

            qtd.type = 'number';

            qtd.min = 1;

            qtd.max = p.estoque;

            qtd.value = item.quantidade;

            qtd.className = 'qtd';

            qtd.setAttribute('aria-label', 'Quantidade de ' + p.nome);

            qtd.addEventListener('input', () => {

                const n = parseInt(qtd.value, 10);

                if (!n || n < 1) return;

                if (n > p.estoque) {

                    qtd.value = item.quantidade = p.estoque;

                    alert('Estoque disponível de ' + p.nome + ': ' + p.estoque);

                } else {

                    item.quantidade = n;
                }

                tdSub.textContent = moeda(p.preco * item.quantidade);

                sincronizar();
            });

            qtd.addEventListener('change', () => { qtd.value = item.quantidade; });

            tdQtd.appendChild(qtd);


            const tdUnit = document.createElement('td');

            tdUnit.className = 'num';

            tdUnit.textContent = moeda(p.preco);


            const tdSub = document.createElement('td');

            tdSub.className = 'num';

            tdSub.textContent = moeda(p.preco * item.quantidade);


            const tdAcao = document.createElement('td');

            const btn = document.createElement('button');

            btn.type = 'button';

            btn.className = 'btn btn-outline-danger btn-sm';

            btn.textContent = 'Remover';

            btn.addEventListener('click', () => {

                itens.splice(indice, 1);

                renderizar();
            });

            tdAcao.appendChild(btn);


            tr.append(tdFoto, tdNome, tdQtd, tdUnit, tdSub, tdAcao);

            tbody.appendChild(tr);
        });


        rotularTabela(tbody.closest('table'));

        vazio.style.display = itens.length ? 'none' : '';

        sincronizar();
    }


    function sincronizar() {

        hidden.value = JSON.stringify(
            itens.map(({ produto_id, quantidade }) => ({ produto_id, quantidade }))
        );

        atualizarTotais();
    }


    [descInput, recebido].forEach(el => el.addEventListener('input', atualizarTotais));

    [descTipo, forma].forEach(el => el.addEventListener('change', atualizarTotais));


    form.addEventListener('submit', ev => {

        if (finalizar.disabled) { ev.preventDefault(); return; }

        finalizar.disabled = true; // evita venda em dobro com clique duplo
    });


    renderizar();

})();
