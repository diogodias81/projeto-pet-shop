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

        // Devolução: sem nenhuma quantidade marcada não há o que confirmar
        const qtds = btn.dataset.exigeQuantidade ? [...btn.form.querySelectorAll(btn.dataset.exigeQuantidade)] : [];

        if (qtds.length && !qtds.some(i => parseInt(i.value, 10) > 0)) {
            qtds[0].setCustomValidity('Informe a quantidade de pelo menos um item para devolver.');
            qtds[0].reportValidity();
            qtds.forEach(i => i.addEventListener('input', () => qtds[0].setCustomValidity(''), { once: true }));
            ev.preventDefault();
            return;
        }

        if (btn.form?.checkValidity() && !confirm(btn.dataset.confirmarBotao)) {
            ev.preventDefault();
        }
    });
});


// <input type="file" data-max-bytes="...">: avisa antes de enviar, sem perder o que foi digitado

document.querySelectorAll('input[type="file"][data-max-bytes]').forEach(input => {

    input.addEventListener('change', async () => {

        // Foto de celular (3 a 5 MB, 4032 px) passa do limite: reduz aqui para 1600 px em JPEG antes de enviar
        const f = input.files[0];
        if (f && window.DataTransfer && window.createImageBitmap) {
            input.setCustomValidity('Aguarde: preparando a foto.'); // segura o envio enquanto reduz
            try {
                const img = await createImageBitmap(f, { imageOrientation: 'from-image' }); // foto de pé continua de pé
                const escala = Math.min(1, 1600 / Math.max(img.width, img.height));
                if (escala < 1 || f.size > Number(input.dataset.maxBytes)) {
                    const quadro = document.createElement('canvas');
                    quadro.width = Math.round(img.width * escala);
                    quadro.height = Math.round(img.height * escala);
                    const ctx = quadro.getContext('2d');
                    ctx.fillStyle = '#fff'; // PNG transparente: fundo branco em vez de preto no JPEG
                    ctx.fillRect(0, 0, quadro.width, quadro.height);
                    ctx.drawImage(img, 0, 0, quadro.width, quadro.height);
                    const menor = await new Promise(ok => quadro.toBlob(ok, 'image/jpeg', 0.85));
                    if (menor) {
                        const dt = new DataTransfer();
                        dt.items.add(new File([menor], f.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }));
                        input.files = dt.files;
                    }
                }
            } catch { /* não deu para reduzir: segue o arquivo original e o aviso de tamanho abaixo */ }
            input.setCustomValidity('');
        }

        const grande = [...input.files].some(f => f.size > Number(input.dataset.maxBytes));

        input.setCustomValidity(grande ? 'A foto deve ter no máximo ' + Math.round(input.dataset.maxBytes / 1048576) + ' MB.' : '');

        if (grande) input.reportValidity();
    });
});


// <section data-recolher>: no celular as tabelas longas abrem recolhidas, com "Mostrar" no título

if (matchMedia('(max-width: 640px)').matches) {

    document.querySelectorAll('section[data-recolher]').forEach(secao => {

        const titulo = secao.querySelector('h2');

        if (!titulo) return;

        const botao = document.createElement('button');

        botao.type = 'button';

        botao.className = 'btn btn-light btn-sm recolher';

        const alternar = aberta => {
            secao.classList.toggle('recolhida', !aberta);
            botao.textContent = aberta ? 'Ocultar' : 'Mostrar';
            botao.setAttribute('aria-expanded', String(aberta));
        };

        botao.addEventListener('click', () => alternar(secao.classList.contains('recolhida')));

        titulo.append(botao);

        alternar(false);
    });

    // Atalho (#seção) para uma seção recolhida: abre antes de rolar até ela
    document.querySelectorAll('a[href^="#"]').forEach(link => {

        link.addEventListener('click', () => {

            const alvo = document.getElementById(link.getAttribute('href').slice(1));

            if (alvo && alvo.classList.contains('recolhida')) alvo.querySelector('.recolher')?.click();
        });
    });
}


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


// Notificações de sucesso: somem sozinhas em 5 s (pausa com o mouse em cima) ou no X

document.querySelectorAll('.toast-aviso').forEach(toast => {

    const fechar = () => {
        toast.classList.add('saindo');
        setTimeout(() => toast.remove(), 250);
    };

    let tempo = setTimeout(fechar, 5000);

    toast.addEventListener('mouseenter', () => clearTimeout(tempo));
    toast.addEventListener('mouseleave', () => { tempo = setTimeout(fechar, 2500); });
    toast.querySelector('.toast-fechar').addEventListener('click', fechar);
});


// <input data-revelar>: botão de olho para mostrar/ocultar a senha

document.querySelectorAll('input[data-revelar]').forEach(input => {

    const caixa = document.createElement('span');
    caixa.className = 'campo-senha';
    input.replaceWith(caixa);
    caixa.appendChild(input);

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'revelar';
    btn.setAttribute('aria-label', 'Mostrar senha');
    btn.innerHTML = '<i class="bi bi-eye" aria-hidden="true"></i>';
    caixa.appendChild(btn);

    btn.addEventListener('click', () => {
        const mostrar = input.type === 'password';
        input.type = mostrar ? 'text' : 'password';
        btn.setAttribute('aria-label', mostrar ? 'Ocultar senha' : 'Mostrar senha');
        btn.innerHTML = '<i class="bi bi-eye' + (mostrar ? '-slash' : '') + '" aria-hidden="true"></i>';
    });

    // Aviso de Caps Lock ligado: senha errada por letra maiúscula conta tentativa no bloqueio do login
    const caps = document.createElement('small');
    caps.className = 'aviso-caps';
    caps.id = (input.id || input.name) + '-caps-' + Math.random().toString(36).slice(2, 7);
    caps.setAttribute('aria-live', 'polite');
    (input.closest('label') || caixa).after(caps);
    input.setAttribute('aria-describedby', ((input.getAttribute('aria-describedby') || '') + ' ' + caps.id).trim());

    const conferirCaps = ev => {
        const ligado = ev.getModifierState && ev.getModifierState('CapsLock');
        caps.textContent = ligado ? 'Caps Lock está ligado' : '';
    };
    input.addEventListener('keydown', conferirCaps);
    input.addEventListener('keyup', conferirCaps);
    input.addEventListener('blur', () => { caps.textContent = ''; });
});


// Menu "⋯" (details.menu-acoes): só um aberto por vez e fecha ao clicar fora ou com Esc

document.addEventListener('click', ev => {

    document.querySelectorAll('details.menu-acoes[open]').forEach(d => {
        if (!d.contains(ev.target)) d.open = false;
    });
});

document.addEventListener('keydown', ev => {

    if (ev.key !== 'Escape') return;

    document.querySelectorAll('details.menu-acoes[open]').forEach(d => { d.open = false; d.querySelector('summary').focus(); });
});


// <details data-aberto-desktop>: no computador os filtros ficam sempre abertos; no celular, recolhidos

if (matchMedia('(min-width: 641px)').matches) {

    document.querySelectorAll('details[data-aberto-desktop]').forEach(d => { d.open = true; });
}


// Gaveta com formulário (ex.: produto): abre já carregada quando está editando ou voltou com erro
document.querySelectorAll('.offcanvas[data-abrir]').forEach(el => {

    // O erro do servidor fica dentro da gaveta: na página ele ficava atrás do fundo escuro
    const corpo = el.querySelector('.offcanvas-body');

    document.querySelectorAll('#conteudo > .alert-danger, #conteudo > .alert-warning').forEach(a => corpo?.prepend(a));

    if (window.bootstrap) bootstrap.Offcanvas.getOrCreateInstance(el).show();
});

document.querySelectorAll('.offcanvas.gaveta').forEach(el => {

    // Ao abrir, o cursor vai para o primeiro campo (não para a caixa da gaveta)
    el.addEventListener('shown.bs.offcanvas', () => {

        el.querySelector('.offcanvas-body input:not([type="hidden"]):not([type="file"]):not([type="radio"]), .offcanvas-body select')?.focus();
    });

    // Fechou sem salvar: tira ?editar= e &novo= do endereço, senão recarregar a página abria a gaveta de novo
    el.addEventListener('hidden.bs.offcanvas', () => {

        const url = new URL(location.href);

        if (url.searchParams.has('editar') || url.searchParams.has('novo')) {

            url.searchParams.delete('editar');
            url.searchParams.delete('novo');

            history.replaceState(null, '', url);
        }
    });
});


// Abas de seção (estoque): destaca a seção que está na tela

document.querySelectorAll('.abas-secao').forEach(nav => {

    const links = [...nav.querySelectorAll('a[href^="#"]')];

    const secoes = links.map(a => document.getElementById(a.getAttribute('href').slice(1))).filter(Boolean);

    if (!secoes.length || !('IntersectionObserver' in window)) return;

    const visiveis = new Set();

    const marcar = () => {

        const atual = secoes.find(s => visiveis.has(s));

        links.forEach(a => a.classList.toggle('active', !!atual && a.getAttribute('href') === '#' + atual.id));
    };

    const obs = new IntersectionObserver(entradas => {

        entradas.forEach(e => e.isIntersecting ? visiveis.add(e.target) : visiveis.delete(e.target));

        marcar();

    }, { rootMargin: '-140px 0px -55% 0px' });

    secoes.forEach(s => obs.observe(s));
});


// Bipe de leitura (sem arquivo de som): agudo = achou, grave = não achou. No celular também vibra.
let audioBipe = null;

function bipe(ok = true) {

    try {

        audioBipe ??= new (window.AudioContext || window.webkitAudioContext)();

        const osc = audioBipe.createOscillator(), vol = audioBipe.createGain();

        osc.frequency.value = ok ? 1250 : 330;
        vol.gain.value = 0.08;

        osc.connect(vol).connect(audioBipe.destination);
        osc.start();
        osc.stop(audioBipe.currentTime + (ok ? 0.09 : 0.25));

    } catch (e) { /* navegador sem áudio: segue sem som */ }

    try { navigator.vibrate?.(ok ? 40 : [60, 60, 60]); } catch (e) { }
}


// Leitor pela CÂMERA (<input data-camera>): botão ao lado do campo, lê o código e age como a pistola
// (preenche e "aperta Enter"). Usa o leitor nativo do navegador (Chrome/Edge no Android); onde não existe
// (iPhone/Safari, Firefox) usa a biblioteca ZXing local (assets/vendor/zxing), carregada só ao abrir a câmera.
// A câmera só abre em HTTPS ou neste computador (localhost).
const formatosCamera = ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39', 'itf', 'codabar'];

async function leitorNativo() {

    if (!('BarcodeDetector' in window)) return null;

    try {
        const formatos = (await BarcodeDetector.getSupportedFormats()).filter(f => formatosCamera.includes(f));
        return formatos.length ? new BarcodeDetector({ formats: formatos }) : null;
    } catch (e) { return null; }
}

async function cameraDisponivel() {

    if (!navigator.mediaDevices?.getUserMedia || !window.isSecureContext) return false;

    if (await leitorNativo()) return true;

    // sem leitor nativo: ZXing, só em aparelho de toque (no computador vale a pistola)
    return matchMedia('(pointer: coarse)').matches;
}

// ZXing (362 KB) só baixa na primeira vez que alguém abre a câmera num aparelho sem leitor nativo
let carregandoZxing = null;

function carregarZxing() {

    carregandoZxing ??= new Promise((ok, erro) => {

        if (window.ZXing) return ok();

        const s = document.createElement('script');
        s.src = document.querySelector('script[data-zxing]').dataset.zxing; // footer.php: endereço com a versão do arquivo
        s.onload = () => (window.ZXing ? ok() : erro(new Error('ZXing')));
        s.onerror = () => { carregandoZxing = null; erro(new Error('ZXing')); };
        document.head.appendChild(s);
    });

    return carregandoZxing;
}

// Mesma "cara" do BarcodeDetector (detect(video) → [{ rawValue }]): o resto da câmera não muda
async function criarLeitor() {

    const nativo = await leitorNativo();

    if (nativo) return nativo;

    await carregarZxing();

    const Z = window.ZXing;
    const dicas = new Map();
    dicas.set(Z.DecodeHintType.POSSIBLE_FORMATS, [Z.BarcodeFormat.EAN_13, Z.BarcodeFormat.EAN_8, Z.BarcodeFormat.UPC_A, Z.BarcodeFormat.UPC_E,
        Z.BarcodeFormat.CODE_128, Z.BarcodeFormat.CODE_39, Z.BarcodeFormat.ITF, Z.BarcodeFormat.CODABAR]);
    dicas.set(Z.DecodeHintType.TRY_HARDER, true);

    const leitor = new Z.MultiFormatReader();
    leitor.setHints(dicas);

    const quadro = document.createElement('canvas');
    const ctx = quadro.getContext('2d', { willReadFrequently: true });

    return {
        async detect(video) {

            if (!video.videoWidth) return [];

            // reduz para no máximo 800 px de largura: mais rápido e lê igual
            const escala = Math.min(1, 800 / video.videoWidth);
            quadro.width = Math.round(video.videoWidth * escala);
            quadro.height = Math.round(video.videoHeight * escala);
            ctx.drawImage(video, 0, 0, quadro.width, quadro.height);

            try {
                const lido = leitor.decode(new Z.BinaryBitmap(new Z.HybridBinarizer(new Z.HTMLCanvasElementLuminanceSource(quadro))));
                return [{ rawValue: lido.getText() }];
            } catch (e) {
                return []; // nenhum código neste quadro
            } finally {
                leitor.reset();
            }
        },
    };
}

function abrirCamera(input) {

    const tela = document.createElement('div');
    tela.className = 'camera-leitor';
    tela.setAttribute('role', 'dialog');
    tela.setAttribute('aria-modal', 'true');
    tela.setAttribute('aria-label', 'Ler código de barras pela câmera');

    const video = document.createElement('video');
    video.muted = true;
    video.playsInline = true;
    video.setAttribute('playsinline', '');

    const mira = document.createElement('div');
    mira.className = 'camera-mira';

    const texto = document.createElement('p');
    texto.className = 'camera-texto';
    texto.textContent = 'Aponte para o código de barras';

    const fechar = document.createElement('button');
    fechar.type = 'button';
    fechar.className = 'camera-fechar';
    fechar.setAttribute('aria-label', 'Fechar câmera');
    fechar.innerHTML = '<i class="bi bi-x-lg" aria-hidden="true"></i>';

    tela.append(video, mira, texto, fechar);

    // Dentro da gaveta aberta (ex.: cadastro de produto), a tela fica DENTRO dela: a gaveta prende o foco
    // nela mesma e fecha com Esc; fora dela, o foco pulava para o X da gaveta e o Esc fechava as duas.
    // A gaveta aberta não tem transform, então o position: fixed continua cobrindo a tela inteira.
    (input.closest('.offcanvas.show') || document.body).appendChild(tela);
    fechar.focus();

    let fluxo = null, ativo = true;

    const gaveta = input.closest('.offcanvas.show');

    const encerrar = () => {

        ativo = false;
        fluxo?.getTracks().forEach(t => t.stop());
        tela.remove();
        document.removeEventListener('keydown', teclas, true);
        gaveta?.removeEventListener('hide.bs.offcanvas', encerrar);
    };

    // Em captura no document: pega o Esc e o Tab onde quer que o foco esteja (ex.: depois de tocar no vídeo
    // o foco ia para a gaveta) e não deixa o Esc chegar na gaveta
    const teclas = ev => {

        if (ev.key === 'Escape') { ev.preventDefault(); ev.stopPropagation(); encerrar(); input.focus(); }

        // aria-modal: o Tab não sai da tela da câmera (o único controle é o Fechar)
        if (ev.key === 'Tab') { ev.preventDefault(); ev.stopPropagation(); fechar.focus(); }
    };

    document.addEventListener('keydown', teclas, true);

    // tocar no vídeo não tira o foco da câmera
    tela.addEventListener('pointerdown', ev => { if (ev.target !== fechar && !fechar.contains(ev.target)) { ev.preventDefault(); fechar.focus(); } });

    // se a gaveta fechar por qualquer motivo, a câmera desliga junto (não fica ligada escondida)
    gaveta?.addEventListener('hide.bs.offcanvas', encerrar);

    fechar.addEventListener('click', () => { encerrar(); input.focus(); });

    (async () => {

        try {

            fluxo = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false });

            if (!ativo) { fluxo.getTracks().forEach(t => t.stop()); return; }

            video.srcObject = fluxo;
            await video.play();

            texto.textContent = 'Preparando o leitor…';

            const leitor = await criarLeitor();

            texto.textContent = 'Aponte para o código de barras';

            // ~6 leituras por segundo: rápido o bastante e não esquenta o celular
            while (ativo) {

                const achados = video.readyState >= 2 ? await leitor.detect(video).catch(() => []) : [];
                const codigo = achados.map(a => a.rawValue.trim()).find(v => /^\d{4,14}$/.test(v));

                if (codigo) {

                    encerrar();
                    input.value = codigo;
                    input.focus();
                    // "input" antes do Enter, como na digitação: o Enter é o último (no Caixa, depois do input
                    // a lista "Nenhum produto disponível" ficava aberta por cima do carrinho)
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                    // o mesmo caminho da pistola: cada tela já sabe o que fazer com o Enter (e bipa achou/não achou)
                    const enter = new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true });
                    input.dispatchEvent(enter);
                    if (!enter.bipou) bipe(true); // campo sem busca (ex.: cadastro de produto): bipe de "lido"
                    return;
                }

                await new Promise(r => setTimeout(r, 160));
            }

        } catch (e) {

            if (!ativo) return;

            texto.textContent = e && e.name === 'NotAllowedError'
                ? 'O navegador não liberou a câmera. Toque no cadeado da barra de endereço e permita a câmera.'
                : e && e.message === 'ZXing'
                    ? 'Não foi possível carregar o leitor de código. Confira a conexão com o sistema e tente de novo.'
                    : 'Não foi possível abrir a câmera neste aparelho.';

            tela.classList.add('camera-erro');
        }
    })();
}

cameraDisponivel().then(ok => {

    if (!ok) return;

    document.querySelectorAll('input[data-camera]').forEach(input => {

        const caixa = document.createElement('span');
        caixa.className = 'campo-camera';
        input.replaceWith(caixa);
        caixa.appendChild(input);

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn-camera';
        btn.title = 'Ler pela câmera';
        btn.setAttribute('aria-label', 'Ler código de barras pela câmera');
        btn.innerHTML = '<i class="bi bi-camera" aria-hidden="true"></i>';
        btn.addEventListener('click', () => abrirCamera(input));

        caixa.appendChild(btn);
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


        // Aviso na tela (não alert): e o código desconhecido desmarca o produto escolhido antes,
        // para o estoquista não lançar a entrada no produto errado
        // o aviso vai depois do campo inteiro (com o botão de câmera, se houver), não dentro dele:
        // dentro, empurrava o botão para baixo da borda
        const campo = input.closest('.campo-camera') || input;

        let aviso = campo.parentElement.querySelector('.busca-aviso');

        if (!aviso) {
            aviso = document.createElement('small');
            aviso.className = 'busca-aviso';
            aviso.setAttribute('role', 'alert');
            campo.after(aviso);
        }

        ev.bipou = true;

        if (!opt) {

            bipe(false);

            select.value = '';

            aviso.textContent = codigo ? 'Nenhum produto com o código ' + codigo + '.' : 'Leia ou digite o código.';

            aviso.hidden = false;

            input.select();

            return;
        }


        bipe(true);

        aviso.hidden = true;

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

    const motivo = document.getElementById('v-motivo');

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


    // "1.234,56", "12,5" ou "12.5" → número; vazio → 0; outro texto → NaN (mesmos formatos de numero() em helpers.php)
    const numero = texto => {

        // "R$" e espaços (comum, tab, NBSP) só nas pontas, igual ao servidor: "12 15" e "1R$2" são recusados
        const v = /^[ \t\u00A0]*(?:R\$)?[ \t\u00A0]*([\s\S]*?)[ \t\u00A0]*$/i.exec(String(texto))[1];

        if (v === '') return 0;

        // "1.250" sem vírgula é milhar
        let x = NaN;
        if (/^-?([1-9]\d{0,2}(\.\d{3})+|\d+)(,\d*)?$/.test(v)) x = parseFloat(v.replace(/\./g, '').replace(',', '.'));
        else if (/^-?\d+(\.\d+)?$/.test(v)) x = parseFloat(v);

        // Dinheiro tem centavos: "0,001" e "50,001" são recusados ("0.500" = 0,50 passa), igual ao servidor
        return Math.round(x * 100) / 100 === x ? x : NaN;
    };


    const centavos = v => Math.round(v * 100) / 100;


    let itens = [];


    // ---- pesquisa rápida ----

    function adicionar(produto, quantidade = 1) {

        const existente = itens.find(i => i.produto_id === produto.id);

        const jaNoCarrinho = existente ? existente.quantidade : 0;


        if (jaNoCarrinho + quantidade > produto.estoque) {

            avisoBusca.textContent = 'Estoque insuficiente para ' + produto.nome + ': ' + produto.estoque + (produto.estoque === 1 ? ' unidade disponível' : ' unidades disponíveis') + (jaNoCarrinho ? ', todas já no carrinho.' : '.');

            avisoBusca.hidden = false;

            fecharSugestoes();

            busca.select();

            bipe(false);

            return;
        }


        bipe(true);

        avisoBusca.hidden = true; // tira o aviso de uma leitura anterior que não achou

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


    // Pistola lendo com o cursor FORA da busca: na quantidade, no desconto, no valor recebido, numa sugestão,
    // num botão ou em lugar nenhum. A pistola "digita" muito rápido (menos de 80 ms por tecla) e termina com Enter.
    // Uma rajada assim é tratada como leitura: o que ela digitou no campo é desfeito e o código vai para a busca.
    // Antes, ler com o cursor na quantidade virava "quantidade = estoque inteiro" e o Enter finalizava a venda.
    // Fase de captura: roda antes do Enter da sugestão e do botão, que também agiriam.
    let rajada = { alvo: null, antes: null, digitos: '', valida: true, ultima: 0 };

    document.addEventListener('keydown', ev => {

        const alvo = ev.target;

        if (alvo === busca || alvo.closest?.('.camera-leitor, .modal, .offcanvas') || ev.ctrlKey || ev.altKey || ev.metaKey) return;

        if (ev.key === 'Shift') return;

        const agora = Date.now();

        // pausa de gente digitando ou troca de campo: começa outra rajada (o valor do campo ANTES dela fica guardado)
        if (agora - rajada.ultima > 80 || rajada.alvo !== alvo) {

            // quantidade do carrinho: volta à última quantidade válida (com o campo vazio, voltar a "" deixava o estoque inteiro)
            const antes = alvo.dataset?.confirmada ?? ('value' in alvo && alvo.matches?.('input, textarea') ? alvo.value : null);

            rajada = { alvo, antes, digitos: '', valida: true, ultima: agora };
        }

        rajada.ultima = agora;

        if (/^\d$/.test(ev.key)) { rajada.digitos += ev.key; return; }

        if (ev.key !== 'Enter') {

            if (ev.key.length === 1) rajada.valida = false; // letra no meio ("LOTE789..."): não é código de produto
            return;
        }

        // No desconto e no valor recebido só código de barras de produto (EAN-8/EAN-13, 8+ dígitos): "1000" digitado
        // bem rápido continua sendo valor. Na quantidade (ninguém digita 4 números em menos de 80 ms) e fora dos campos, 4+.
        const minimo = rajada.antes !== null && !alvo.matches?.('input.qtd') ? 8 : 4;

        if (!rajada.valida || rajada.digitos.length < minimo || !/^\d{4,14}$/.test(rajada.digitos)) return;

        ev.preventDefault();
        ev.stopImmediatePropagation();

        // desfaz no campo o que a pistola digitou (ex.: a quantidade volta ao que era)
        if (rajada.antes !== null) {

            alvo.value = rajada.antes;
            alvo.dispatchEvent(new Event('input', { bubbles: true }));
        }

        const codigo = rajada.digitos;

        rajada = { alvo: null, antes: null, digitos: '', valida: true, ultima: 0 };

        busca.value = codigo;
        busca.focus();
        busca.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }));

    }, true);


    // Enter dentro dos campos do caixa (quantidade, desconto, recebido) NUNCA finaliza a venda:
    // finalizar é só no botão. Antes, mudar a quantidade e apertar Enter já gravava a venda.
    form.addEventListener('keydown', ev => {

        if (ev.key === 'Enter' && ev.target !== busca && ev.target.matches?.('input, select')) ev.preventDefault();
    });


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


        // Enter do leitor ou do teclado: adiciona direto só o código exato, o nome exato ou um resultado único
        ev.preventDefault();

        const texto = busca.value.trim();

        if (!texto) return;


        const achados = encontrar(texto);

        const produto = catalogo.find(p => p.codigo === texto)
            || achados.find(p => normalizar(p.nome) === normalizar(texto))
            || (achados.length === 1 ? achados[0] : null);

        ev.bipou = true; // adicionar() e o "não achou" abaixo já bipam (ver leitor pela câmera)

        if (produto) {

            adicionar(produto);

        } else if (achados.length > 1) {

            bipe(false);

            // "ra" acha "Ração" e "Bolinha de Borracha": quem escolhe é o caixa
            mostrarSugestoes();

            avisoBusca.textContent = achados.length + ' produtos com "' + texto + '": escolha na lista (seta ↓ e Enter).';

            avisoBusca.hidden = false;

        } else {

            fecharSugestoes(); // a lista também dizia "nenhum produto": a mensagem aparecia duas vezes

            bipe(false);

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

        // Texto inválido (NaN) conta como 0 nos totais e vira a mensagem de problema abaixo
        const descLido = numero(descInput.value);
        const v = descLido || 0;

        // Negativo vira o aviso abaixo; nos totais conta 0 (antes a prévia mostrava "− -R$ 5,00" e o total subia)
        const desconto = v < 0 ? 0 : centavos(descTipo.value === 'percentual' ? subtotal * v / 100 : v);

        const total = centavos(subtotal - desconto);

        const pagoLido = numero(recebido.value);
        const pago = pagoLido || 0;


        let problema = '';

        if (Number.isNaN(descLido)) {

            problema = 'Desconto inválido: digite só números, ex.: 5,00.';

        } else if (v < 0) {

            problema = 'O desconto não pode ser negativo.';

        } else if (forma.value === 'dinheiro' && Number.isNaN(pagoLido)) {

            problema = 'Valor recebido inválido: digite só números, ex.: 50,00.';

        } else if (subtotal > 0 && desconto >= subtotal) {

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

        // Barra do celular com o total
        const nItens = itens.reduce((soma, it) => soma + Number(it.quantidade), 0);
        $('v-barra').hidden = nItens === 0;
        $('v-barra-total').textContent = $('v-total').textContent;
        $('v-barra-itens').textContent = nItens + (nItens === 1 ? ' item' : ' itens');


        recebidoCampo.hidden = !dinheiro;

        const mostraTroco = dinheiro && c.pago >= c.total && recebido.value.trim() !== '';

        $('v-troco').hidden = $('v-troco-rotulo').hidden = !mostraTroco;

        $('v-troco').textContent = moeda(centavos(c.pago - c.total));


        erro.hidden = !c.problema;

        erro.textContent = c.problema;

        finalizar.disabled = itens.length === 0 || !!c.problema || !forma.value;

        // Diz por que o botão está desabilitado (o problema, se houver, já aparece em vermelho)
        motivo.textContent = c.problema ? '' : itens.length === 0 ? 'Adicione um produto para finalizar.' : !forma.value ? 'Escolha a forma de pagamento para finalizar.' : '';

        motivo.hidden = !motivo.textContent;
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

            // última quantidade válida: a leitura da pistola neste campo volta a ela (o campo pode estar vazio)
            qtd.dataset.confirmada = item.quantidade;

            qtd.className = 'qtd';

            qtd.setAttribute('aria-label', 'Quantidade de ' + p.nome);

            qtd.addEventListener('input', () => {

                const n = parseInt(qtd.value, 10);

                if (!n || n < 1) return;

                if (n > p.estoque) {

                    qtd.value = item.quantidade = p.estoque;

                    avisoBusca.textContent = 'Estoque disponível de ' + p.nome + ': ' + p.estoque + (p.estoque === 1 ? ' unidade.' : ' unidades.');

                    avisoBusca.hidden = false;

                } else {

                    item.quantidade = n;
                }

                tdSub.textContent = moeda(p.preco * item.quantidade);

                qtd.dataset.confirmada = item.quantidade;

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

            // Só ícone (como nas outras tabelas): o texto "Remover" não cabia na linha do carrinho no celular
            btn.className = 'btn btn-outline-danger btn-sm btn-quadrado';

            btn.title = 'Remover';

            btn.setAttribute('aria-label', 'Remover ' + p.nome);

            const lixeira = document.createElement('i');

            lixeira.className = 'bi bi-trash3';

            lixeira.setAttribute('aria-hidden', 'true');

            btn.appendChild(lixeira);

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


    // ---- carrinho guardado no navegador ----
    // Recarregar, sair da tela ou a sessão expirar (30 min) não pode fazer o caixa ler tudo de novo
    // com o cliente esperando. Fica por usuário neste navegador; o comprovante da venda limpa (data-limpar-carrinho).

    const chave = 'pdv-carrinho:' + (form.dataset.usuario || '');

    const guardar = () => {

        try {

            if (!itens.length) { localStorage.removeItem(chave); return; }

            localStorage.setItem(chave, JSON.stringify({
                itens: JSON.parse(hidden.value || '[]'),
                desconto_tipo: descTipo.value, desconto: descInput.value, forma: forma.value, recebido: recebido.value,
                em: Date.now(),
            }));

        } catch (e) { /* navegador sem armazenamento (modo privado): segue sem guardar */ }
    };

    [descInput, recebido].forEach(el => el.addEventListener('input', guardar));

    [descTipo, forma].forEach(el => el.addEventListener('change', guardar));

    const sincronizarSemGuardar = sincronizar;

    sincronizar = () => { sincronizarSemGuardar(); guardar(); };

    let recuperado = false;

    // Só quando o servidor não devolveu um carrinho (venda recusada tem prioridade)
    if (!hidden.value) {

        try {

            const salvo = JSON.parse(localStorage.getItem(chave) || 'null');

            // carrinho de mais de 12 h é de outro dia: descarta
            if (salvo && Array.isArray(salvo.itens) && salvo.itens.length && Date.now() - salvo.em < 12 * 3600 * 1000) {

                hidden.value = JSON.stringify(salvo.itens);

                descTipo.value = salvo.desconto_tipo === 'percentual' ? 'percentual' : 'valor';
                descInput.value = String(salvo.desconto || '');
                if ([...forma.options].some(o => o.value === salvo.forma)) forma.value = salvo.forma;
                recebido.value = String(salvo.recebido || '');

                recuperado = true;
            }

        } catch (e) { /* armazenamento indisponível ou corrompido: começa vazio */ }
    }


    form.addEventListener('submit', ev => {

        if (finalizar.disabled) { ev.preventDefault(); return; }

        finalizar.disabled = true; // evita venda em dobro com clique duplo
    });


    // Barra do celular: rola até o pagamento sem pôr #pdv-pagamento no endereço
    // (o fragmento ia junto no redirecionamento para o comprovante)
    $('v-barra').addEventListener('click', ev => {

        ev.preventDefault();

        $('pdv-pagamento').scrollIntoView({ behavior: 'smooth', block: 'start' });

        forma.focus({ preventScroll: true });
    });


    // Venda recusada pelo servidor (ou carrinho recuperado): volta limitado ao estoque de agora
    let ajustados = [];

    try {

        JSON.parse(hidden.value || '[]').forEach(({ produto_id, quantidade }) => {

            const produto = catalogo.find(p => p.id === produto_id);

            const qtd = Math.min(parseInt(quantidade, 10) || 0, produto ? produto.estoque : 0);

            if (qtd > 0) itens.push({ produto_id, produto, quantidade: qtd });

            if (qtd < quantidade) ajustados.push(produto ? produto.nome : 'um produto sem estoque');
        });

    } catch (e) { itens = []; }

    if (ajustados.length) {

        avisoBusca.textContent = 'Ajustado ao estoque disponível: ' + ajustados.join(', ') + '.';

        avisoBusca.hidden = false;

    } else if (recuperado && itens.length) {

        avisoBusca.textContent = 'Carrinho recuperado (' + itens.length + (itens.length === 1 ? ' produto' : ' produtos') + '). Confira antes de finalizar.';

        avisoBusca.hidden = false;
    }

    renderizar();

})();


// Sair: apaga os carrinhos guardados neste navegador (a sessão expirada não passa por aqui, e aí o carrinho fica para o novo login)
document.querySelectorAll('form[action="index.php?page=logout"]').forEach(f => f.addEventListener('submit', () => {

    try { Object.keys(localStorage).filter(k => k.startsWith('pdv-carrinho:')).forEach(k => localStorage.removeItem(k)); } catch (e) { }
}));


// Comprovante da venda que o caixa acabou de fazer: o carrinho guardado já virou venda
document.querySelectorAll('[data-limpar-carrinho]').forEach(el => {

    try { localStorage.removeItem('pdv-carrinho:' + el.dataset.limparCarrinho); } catch (e) { }
});
