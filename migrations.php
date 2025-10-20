<?php
// migrations.php - Script de Migração do PostgreSQL (Função Plug-and-Play)

function run_migrations($pdo) {
    try {
        // Tente verificar se uma tabela crucial (como 'usuarios') existe
        // e, se existir, pule o processo para otimizar o desempenho.
        $check_table = $pdo->query("SELECT 1 FROM pg_tables WHERE tablename = 'usuarios'");
        if ($check_table && $check_table->fetchColumn()) {
            return;
        }

        echo "Iniciando Migração Automática: Criando todas as tabelas PostgreSQL...\n";

        $pdo->beginTransaction();

        // ---------------------------------------------------------------------
        // 1. CRIAÇÃO DE FUNÇÕES E TRIGGERS
        // ---------------------------------------------------------------------

        // FUNÇÃO: Atualiza data_ultima_atualizacao na tabela suporte_tickets
        $pdo->exec("
            CREATE OR REPLACE FUNCTION public.atualizar_ultima_atualizacao_ticket()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                UPDATE suporte_tickets
                SET data_ultima_atualizacao = NOW()
                WHERE id = NEW.ticket_id;
                RETURN NEW;
            END;
            $$;
        ");

        // ---------------------------------------------------------------------
        // 2. CRIAÇÃO DAS TABELAS (NOVA TABELA CONFIGURACOES INCLUÍDA)
        // ---------------------------------------------------------------------

        $queries = [
            // ⭐ NOVO: TABELA CONFIGURACOES
            "CREATE TABLE IF NOT EXISTS configuracoes (
                id SERIAL PRIMARY KEY,
                chave VARCHAR(100) NOT NULL UNIQUE,
                valor TEXT,
                descricao VARCHAR(255)
            );",

            // TABELA 1: USUARIOS
            "CREATE TABLE IF NOT EXISTS usuarios (
                id SERIAL PRIMARY KEY,
                nome VARCHAR(255) NOT NULL,
                email VARCHAR(255) NOT NULL UNIQUE,
                senha VARCHAR(255) NOT NULL,
                nivel_acesso VARCHAR(50) NOT NULL DEFAULT 'membro',
                data_criacao TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                status VARCHAR(50) NOT NULL DEFAULT 'ativo',
                reset_token VARCHAR(64) UNIQUE,
                reset_expira_em TIMESTAMP WITHOUT TIME ZONE
            );",

            // TABELA 2: HISTORICO_SENHAS
            "CREATE TABLE IF NOT EXISTS historico_senhas (
                id SERIAL PRIMARY KEY,
                usuario_id INTEGER NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
                data_alteracao TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                metodo VARCHAR(50) NOT NULL,
                ip_origem VARCHAR(45)
            );",

            // TABELA 3: CURSOS
            "CREATE TABLE IF NOT EXISTS cursos (
                id SERIAL PRIMARY KEY,
                titulo VARCHAR(255) NOT NULL,
                descricao TEXT,
                imagem_thumbnail VARCHAR(255),
                valor NUMERIC(5,2)
            );",

            // TABELA 4: PLANOS
            "CREATE TABLE IF NOT EXISTS planos (
                id SERIAL PRIMARY KEY,
                nome VARCHAR(255) NOT NULL,
                descricao TEXT,
                valor NUMERIC(6,2) NOT NULL,
                tipo_acesso VARCHAR(50) NOT NULL
            );",

            // TABELA 5: GATEWAYS_PAGAMENTO
            "CREATE TABLE IF NOT EXISTS gateways_pagamento (
                id SERIAL PRIMARY KEY,
                nome VARCHAR(100) NOT NULL UNIQUE,
                ativo BOOLEAN DEFAULT TRUE,
                created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
            );",

            // TABELA 6: PEDIDOS
            "CREATE TABLE IF NOT EXISTS pedidos (
                id SERIAL PRIMARY KEY,
                usuario_id INTEGER NOT NULL REFERENCES usuarios(id),
                curso_id INTEGER REFERENCES cursos(id),
                plano_id INTEGER REFERENCES planos(id),
                gateway_id INTEGER NOT NULL REFERENCES gateways_pagamento(id),
                valor NUMERIC(8,2) NOT NULL,
                status VARCHAR(50) NOT NULL DEFAULT 'PENDENTE',
                gateway_transaction_id VARCHAR(255),
                pix_code TEXT,
                created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
            );",

            // TABELA 7: AULAS
            "CREATE TABLE IF NOT EXISTS aulas (
                id SERIAL PRIMARY KEY,
                curso_id INTEGER NOT NULL REFERENCES cursos(id) ON DELETE CASCADE,
                titulo VARCHAR(255) NOT NULL,
                descricao TEXT,
                url_video VARCHAR(255),
                ordem INTEGER DEFAULT 0
            );",

            // TABELA 8: ARQUIVOS
            "CREATE TABLE IF NOT EXISTS arquivos (
                id SERIAL PRIMARY KEY,
                titulo VARCHAR(255) NOT NULL,
                descricao TEXT,
                caminho_arquivo VARCHAR(255) NOT NULL
            );",

            // TABELA 9: USUARIO_CURSOS (Pivô)
            "CREATE TABLE IF NOT EXISTS usuario_cursos (
                usuario_id INTEGER NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
                curso_id INTEGER NOT NULL REFERENCES cursos(id) ON DELETE CASCADE,
                PRIMARY KEY (usuario_id, curso_id)
            );",

            // TABELA 10: AULA_ARQUIVOS (Pivô)
            "CREATE TABLE IF NOT EXISTS aula_arquivos (
                aula_id INTEGER NOT NULL REFERENCES aulas(id) ON DELETE CASCADE,
                arquivo_id INTEGER NOT NULL REFERENCES arquivos(id) ON DELETE CASCADE,
                PRIMARY KEY (aula_id, arquivo_id)
            );",

            // TABELA 11: AULA_CURTIDAS
            "CREATE TABLE IF NOT EXISTS aula_curtidas (
                id SERIAL PRIMARY KEY,
                aula_id INTEGER NOT NULL REFERENCES aulas(id) ON DELETE CASCADE,
                usuario_id INTEGER NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
                data_curtida TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (aula_id, usuario_id)
            );",

            // TABELA 12: AULA_FAVORITOS
            "CREATE TABLE IF NOT EXISTS aula_favoritos (
                id SERIAL PRIMARY KEY,
                aula_id INTEGER NOT NULL REFERENCES aulas(id) ON DELETE CASCADE,
                usuario_id INTEGER NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
                data_favorito TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (aula_id, usuario_id)
            );",

            // TABELA 13: AULA_AVALIACOES
            "CREATE TABLE IF NOT EXISTS aula_avaliacoes (
                id SERIAL PRIMARY KEY,
                aula_id INTEGER NOT NULL REFERENCES aulas(id) ON DELETE CASCADE,
                usuario_id INTEGER NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
                avaliacao INTEGER NOT NULL CHECK (avaliacao >= 1 AND avaliacao <= 5),
                data_avaliacao TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (aula_id, usuario_id)
            );",

            // TABELA 14: AULA_COMENTARIOS
            "CREATE TABLE IF NOT EXISTS aula_comentarios (
                id SERIAL PRIMARY KEY,
                aula_id INTEGER NOT NULL REFERENCES aulas(id) ON DELETE CASCADE,
                usuario_id INTEGER NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
                comentario TEXT NOT NULL,
                data_comentario TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
            );",

            // TABELA 15: SUPORTE_TICKETS
            "CREATE TABLE IF NOT EXISTS suporte_tickets (
                id SERIAL PRIMARY KEY,
                usuario_id INTEGER NOT NULL REFERENCES usuarios(id),
                assunto VARCHAR(255) NOT NULL,
                status VARCHAR(50) NOT NULL DEFAULT 'ABERTO',
                data_criacao TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                data_ultima_atualizacao TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                data_fechamento_automatico TIMESTAMP WITH TIME ZONE,
                data_fechamento TIMESTAMP WITH TIME ZONE,
                fechado_por VARCHAR(10),
                usuario_ultima_visualizacao TIMESTAMP WITH TIME ZONE,
                admin_ultima_visualizacao TIMESTAMP WITH TIME ZONE,
                avaliacao INTEGER
            );",

            // TABELA 16: SUPORTE_MENSAGENS
            "CREATE TABLE IF NOT EXISTS suporte_mensagens (
                id SERIAL PRIMARY KEY,
                ticket_id INTEGER NOT NULL REFERENCES suporte_tickets(id) ON DELETE CASCADE,
                remetente_id INTEGER NOT NULL REFERENCES usuarios(id),
                mensagem TEXT NOT NULL,
                data_envio TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                anexo_url VARCHAR(255)
            );"
        ];

        // Executa as queries de criação de tabela
        foreach ($queries as $query) {
            $pdo->exec($query);
        }

        // ---------------------------------------------------------------------
        // 3. CRIAÇÃO DE TRIGGERS E ÍNDICES
        // ---------------------------------------------------------------------

        // TRIGGER (Após criar a tabela suporte_mensagens)
        $pdo->exec("
            CREATE OR REPLACE TRIGGER trigger_atualizar_ticket_apos_mensagem
            AFTER INSERT ON public.suporte_mensagens
            FOR EACH ROW
            EXECUTE FUNCTION public.atualizar_ultima_atualizacao_ticket();
        ");

        // ÍNDICES (Opcional, mas bom para performance)
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_suporte_tickets_usuario_id ON public.suporte_tickets USING btree (usuario_id);");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_suporte_mensagens_ticket_id ON public.suporte_mensagens USING btree (ticket_id);");


        // ---------------------------------------------------------------------
        // 4. INSERIR DADOS INICIAIS (SEEDING)
        // ---------------------------------------------------------------------

        $senhaHash = password_hash('123456', PASSWORD_DEFAULT);

        // Usuários
        $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");

        $stmt->execute(['admin@email.com']);
        if ($stmt->rowCount() == 0) {
            $stmtInsert = $pdo->prepare("INSERT INTO usuarios (nome, email, senha, nivel_acesso) VALUES (?, ?, ?, ?);");
            $stmtInsert->execute(['Administrador', 'admin@email.com', $senhaHash, 'admin']);
        }

        $stmt->execute(['membro@email.com']);
        if ($stmt->rowCount() == 0) {
            $stmtInsert = $pdo->prepare("INSERT INTO usuarios (nome, email, senha, nivel_acesso) VALUES (?, ?, ?, ?);");
            $stmtInsert->execute(['Membro Teste', 'membro@email.com', $senhaHash, 'membro']);
        }

        // Gateway Padrão
        $stmt = $pdo->prepare("SELECT id FROM gateways_pagamento WHERE nome = 'PIX'");
        $stmt->execute();
        if ($stmt->rowCount() == 0) {
             $stmtInsert = $pdo->prepare("INSERT INTO gateways_pagamento (nome, ativo) VALUES ('PIX', TRUE);");
             $stmtInsert->execute();
        }

        // ⭐ NOVO: INSERÇÃO DAS CHAVES DE CONFIGURAÇÃO NA TABELA
        $config_keys = [
            'MAILGUN_API_KEY' => ['altere o valor', 'Chave de API secreta da Mailgun.'],
            'MAILGUN_DOMAIN' => ['altere o valor', 'Domínio de envio da Mailgun.'],
            'MAILGUN_FROM_EMAIL' => ['noreply@seudominio.com', 'E-mail de remetente.'],
            'MAILGUN_API_URL' => ['https://api.mailgun.net/v3', 'Endpoint da API da Mailgun (US).'],
            'PIXUP_CLIENT_ID' => ['altere o valor', 'ID do Cliente PixUp.'],
            'PIXUP_CLIENT_SECRET' => ['altere o valor', 'Chave secreta do Cliente PixUp.'],
            'PIXUP_POSTBACK_URL' => ['https://seudominio.com/paginamembros/api/webhook_pixup_cursos.php', 'URL de Webhook (Postback) do PixUp para confirmar pagamentos.'],
        ];

        foreach ($config_keys as $chave => list($valor, $descricao)) {
            $stmt = $pdo->prepare("SELECT id FROM configuracoes WHERE chave = ?");
            $stmt->execute([$chave]);
            if ($stmt->rowCount() == 0) {
                $stmtInsert = $pdo->prepare("INSERT INTO configuracoes (chave, valor, descricao) VALUES (?, ?, ?);");
                $stmtInsert->execute([$chave, $valor, $descricao]);
            }
        }
        // FIM NOVO SEEDING

        $pdo->commit();
        echo "Migração do banco de dados concluída com sucesso! (Autostart)\n";

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
             $pdo->rollBack();
        }
        error_log("ERRO FATAL NA MIGRAÇÃO: " . $e->getMessage());
    }
}