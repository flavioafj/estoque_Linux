<div class="container">
    <div class="row justify-content-center align-items-center" style="min-height: 80vh;">
        <div class="col-md-6 col-lg-4">
            <div class="card shadow">
                <div class="card-body">
                    <h3 class="card-title text-center mb-4">Login</h3>
                    <form action="login.php" method="POST">
                        <div class="mb-3">
                            <label for="usuario" class="form-label">Usuário ou E-mail</label>
                            <input type="text" class="form-control" id="usuario" name="usuario" required>
                        </div>
                        <div id="snh" class="mb-3 esconder">
                            <label for="senha" class="form-label">Senha</label>
                            <input type="password" class="form-control" id="senha" name="senha" value="senha" required>
                            
                        </div>
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary">Entrar</button>
                        </div>
                    </form>
                    <div class="text-center mt-3 cursor-maozinha">
                        <small class="text-muted">
                            <strong><a id="aqui"> É administrador?</a></strong>
                        </small>
                        <a id="ap" href=<?php echo "http://" . $_SERVER['SERVER_NAME'] . ":5000/abrir_estoque";?> class="btn2 btn-secondary alerts-container fundo-azul esconder">Abrir Porta</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

    <script>
        
        const inputSenha = document.getElementById('senha');

        inputSenha.addEventListener('focus', function() {
            
            this.value = '';
        });

        const link = document.getElementById('aqui');
        const snh = document.getElementById('snh');
        const ap = document.getElementById('ap');

        link.addEventListener('click', () => {
            snh.classList.toggle('esconder');
            ap.classList.toggle('esconder');
        });


       
    </script>