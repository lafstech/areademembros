<?php
// Requer o config.php para obter a conexão $pdo, que já é inteligente
// o suficiente para se conectar ao Heroku ou localmente.
require_once 'config.php';

// --- INÍCIO DO SCRIPT DE MIGRAÇÃO ---

// Usamos uma função para encapsular a lógica e torná-la mais limpa.
function run_migrations($pdo) {
    try {
        echo "Iniciando verificação/criação das tabelas...\n";

        // Inicia a transação para garantir que todas as queries sejam executadas ou nenhuma.
        $pdo->beginTransaction();

        // ---------------------------------------------------------------------
        // 1. CRIAÇÃO DE FUNÇÕES E TRIGGERS (Específico do PostgreSQL)
        // ---------------------------------------------------------------------

        echo "Criando Funções e Triggers de Suporte...\n";

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
        // 2. CRIAÇÃO DAS TABELAS
        // ---------------------------------------------------------------------

        // Array com todas as queries de criação de tabela
        $queries = [
            // TABELA 1: USUARIOS (Adicionadas colunas 'status' e redefinição de senha)
            "CREATE TABLE IF NOT EXISTS usuarios (
                id SERIAL PRIMARY KEY,
                nome VARCHAR(255) NOT NULL,
                email VARCHAR(255) NOT NULL UNIQUE,
                senha VARCHAR(255) NOT NULL,
                nivel_acesso VARCHAR(50) NOT NULL DEFAULT 'membro',
                data_criacao TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                status VARCHAR(50) NOT NULL DEFAULT 'ativo',
                reset_token VARCHAR(64) UNIQUE DEFAULT NULL,
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

            // TABELA 4: PLANOS (NOVO - Baseado no dump)
            "CREATE TABLE IF NOT EXISTS planos (
                id SERIAL PRIMARY KEY,
                nome VARCHAR(255) NOT NULL,
                descricao TEXT,
                valor NUMERIC(6,2) NOT NULL,
                tipo_acesso VARCHAR(50) NOT NULL
            );",

            // TABELA 5: GATEWAYS_PAGAMENTO (NOVO - Baseado no dump)
            "CREATE TABLE IF NOT EXISTS gateways_pagamento (
                id SERIAL PRIMARY KEY,
                nome VARCHAR(100) NOT NULL,
                ativo BOOLEAN DEFAULT TRUE,
                created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
            );",

            // TABELA 6: PEDIDOS (NOVO - Baseado no dump)
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

            // TABELA 9: USUARIO_CURSOS (Tabela Pivô)
            "CREATE TABLE IF NOT EXISTS usuario_cursos (
                usuario_id INTEGER NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
                curso_id INTEGER NOT NULL REFERENCES cursos(id) ON DELETE CASCADE,
                PRIMARY KEY (usuario_id, curso_id)
            );",

            // TABELA 10: AULA_ARQUIVOS (Tabela Pivô)
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

            // TABELA 15: SUPORTE_TICKETS (NOVO - Baseado no dump)
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

            // TABELA 16: SUPORTE_MENSAGENS (NOVO - Baseado no dump)
            "CREATE TABLE IF NOT EXISTS suporte_mensagens (
                id SERIAL PRIMARY KEY,
                ticket_id INTEGER NOT NULL REFERENCES suporte_tickets(id) ON DELETE CASCADE,
                remetente_id INTEGER NOT NULL REFERENCES usuarios(id),
                mensagem TEXT NOT NULL,
                data_envio TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                anexo_url VARCHAR(255)
            );"
        ];


        // Executa cada query de criação de tabela
        foreach ($queries as $query) {
            $pdo->exec($query);
        }

        echo "Estrutura de tabelas verificada com sucesso.\n";

        // ---------------------------------------------------------------------
        // 3. CRIAÇÃO DE TRIGGERS E ÍNDICES (PÓS-CRIAÇÃO DE TABELAS)
        // ---------------------------------------------------------------------

        echo "Aplicando Triggers e Índices...\n";

        // TRIGGER (Após criar a tabela suporte_mensagens)
        $pdo->exec("
            CREATE OR REPLACE TRIGGER trigger_atualizar_ticket_apos_mensagem
            AFTER INSERT ON public.suporte_mensagens
            FOR EACH ROW
            EXECUTE FUNCTION public.atualizar_ultima_atualizacao_ticket();
        ");

        // ÍNDICES
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_suporte_tickets_usuario_id ON public.suporte_tickets USING btree (usuario_id);");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_suporte_mensagens_ticket_id ON public.suporte_mensagens USING btree (ticket_id);");

        // ---------------------------------------------------------------------
        // 4. INSERIR DADOS INICIAIS (SEEDING)
        // ---------------------------------------------------------------------

        echo "Verificando usuários padrão e dados iniciais...\n";

        // Senha para ambos será "123456"
        $senhaHash = password_hash('123456', PASSWORD_DEFAULT);

        // Insere o usuário admin SE NÃO EXISTIR
        $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
        $stmt->execute(['admin@email.com']);
        if ($stmt->rowCount() == 0) {
            $stmtInsert = $pdo->prepare("INSERT INTO usuarios (nome, email, senha, nivel_acesso) VALUES (?, ?, ?, ?);");
            $stmtInsert->execute(['Administrador', 'admin@email.com', $senhaHash, 'admin']);
            echo "Usuário 'admin' criado.\n";
        } else {
            echo "Usuário 'admin' já existe.\n";
        }

        // Insere o usuário membro SE NÃO EXISTIR
        $stmt->execute(['membro@email.com']);
        if ($stmt->rowCount() == 0) {
            $stmtInsert = $pdo->prepare("INSERT INTO usuarios (nome, email, senha, nivel_acesso) VALUES (?, ?, ?, ?);");
            $stmtInsert->execute(['Membro Teste', 'membro@email.com', $senhaHash, 'membro']);
            echo "Usuário 'membro' de teste criado.\n";
        } else {
            echo "Usuário 'membro' de teste já existe.\n";
        }

        // Insere Gateway Padrão (Ex: PIX) SE NÃO EXISTIR
        $stmt = $pdo->prepare("SELECT id FROM gateways_pagamento WHERE nome = 'PIX'");
        $stmt->execute();
        if ($stmt->rowCount() == 0) {
             $stmtInsert = $pdo->prepare("INSERT INTO gateways_pagamento (nome, ativo) VALUES ('PIX', TRUE);");
             $stmtInsert->execute();
             echo "Gateway 'PIX' adicionado.\n";
        }


        $pdo->commit();
        echo "\nConfiguração do banco de dados concluída com sucesso! 🚀\n";

    } catch (PDOException $e) {
        // Se algo der errado, desfaz tudo e exibe o erro.
        if ($pdo->inTransaction()) {
             $pdo->rollBack();
        }
        die("ERRO AO CONFIGURAR O BANCO DE DADOS: " . $e->getMessage());
    }
}

// Executa a função de migração passando a conexão PDO do config.php
run_migrations($pdo);