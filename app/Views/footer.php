    </main>

    <!-- Celular: barra inferior com os 4 primeiros itens e "Mais" com o resto -->
    <nav class="barra-inferior d-lg-none d-print-none" aria-label="Menu principal">

        <?php foreach (array_slice(array_keys($menu), 0, 4) as $chave): ?>
            <?= $item($chave, 'barra-item') ?>
        <?php endforeach; ?>

        <button
            class="barra-item"
            type="button"
            data-bs-toggle="offcanvas"
            data-bs-target="#menu-mais"
            aria-controls="menu-mais">
            <i class="bi bi-grid" aria-hidden="true"></i>
            <span>Mais</span>
        </button>

    </nav>

    <div class="offcanvas offcanvas-bottom menu-mais d-lg-none" tabindex="-1" id="menu-mais" aria-labelledby="menu-mais-titulo">

        <div class="offcanvas-header">
            <h2 class="offcanvas-title h6" id="menu-mais-titulo">Olá, <?= e(usuario()['nome']) ?></h2>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Fechar"></button>
        </div>

        <div class="offcanvas-body">

            <nav class="menu-mais-grade" aria-label="Todas as opções">
                <?php foreach (array_keys($menu) as $chave): ?>
                    <?= $item($chave, 'menu-mais-item') ?>
                <?php endforeach; ?>
            </nav>

            <?= $sair ?>

        </div>

    </div>

    <script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>

    <script src="<?= asset('assets/js/app.js') ?>"></script>

</body>

</html>
