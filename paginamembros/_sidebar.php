<?php
// Arquivo: _sidebar.php

// Certifique-se de que $_SESSION['usuario_nome'] está definido antes de usar.
// Isso já deve ser garantido pelo 'verificarAcesso()' nas páginas principais.
$nome_usuario_sidebar = htmlspecialchars($_SESSION['usuario_nome'] ?? 'Usuário');
$nivel_usuario_sidebar = htmlspecialchars($_SESSION['usuario_nivel'] ?? 'Membro'); // Adiciona o nível para o perfil

// Obtém o nome do arquivo da página atual (ex: 'index.php', 'arquivos.php', 'resetpassword.php')
$pagina_atual = basename($_SERVER['PHP_SELF']);

// --- SVG Icons ---
$svg_dashboard = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z" /></svg>';
$svg_arquivos = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>';
$svg_perfil = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6"><path stroke-linecap="round" stroke-linejoin="round" d="M17.982 18.725A7.488 7.488 0 0 0 12 15.75a7.488 7.488 0 0 0-5.982 2.975m11.963 0a9 9 0 1 0-11.963 0m11.963 0A8.966 8.966 0 0 1 12 21a8.966 8.966 0 0 1-5.982-2.275M15 9.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>';
$svg_perfil_dropdown = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="20" height="20"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" /></svg>';
$svg_alterar_senha = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="20" height="20"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z" /></svg>';
$svg_sair = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="20" height="20"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9" /></svg>';
$svg_pay = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z" /></svg>';
$svg_suporte = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 9.75a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375m-13.5 3.01c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.184-4.183a1.14 1.14 0 0 1 .778-.332 48.294 48.294 0 0 0 5.83-.498c1.585-.233 2.708-1.626 2.708-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0 0 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z" /></svg>';
?>
<aside class="sidebar">
    <div class="logo-area">
        <div class="logo-circle">
            <img src="https://ik.imagekit.io/kyjz2djk3p/logomembros?updatedAt=1760033917629" alt="Logo da Marca"> </div>
        <div class="logo-text">Área de Membros</div>
    </div>
    <div class="divider"></div>
    <nav>
        <a href="index.php" class="<?php echo ($pagina_atual === 'index.php' || $pagina_atual === 'cursos.php') ? 'active' : ''; ?>">
            <?php echo $svg_dashboard; ?>
            <span>Dashboard</span>
        </a>
        <a href="paycourse.php" class="<?php echo ($pagina_atual === 'paycourse.php' || $pagina_atual === 'checkout.php') ? 'active' : ''; ?>">
            <?php echo $svg_pay; ?>
            <span>Loja de Cursos</span>
        </a>
        <a href="arquivos.php" class="<?php echo ($pagina_atual === 'arquivos.php') ? 'active' : ''; ?>">
            <?php echo $svg_arquivos; ?>
            <span>Meus Arquivos</span>
        </a>
        <a href="perfil.php" class="<?php echo ($pagina_atual === 'perfil.php') ? 'active' : ''; ?>">
            <?php echo $svg_perfil; ?>
            <span>Meu Perfil</span>
        </a>
        <a href="resetpassword.php" class="<?php echo ($pagina_atual === 'resetpassword.php') ? 'active' : ''; ?>">
            <?php echo $svg_alterar_senha; ?>
            <span>Alterar Senha</span>
        </a>
                <a href="support.php" class="<?php echo ($pagina_atual === 'support.php') ? 'active' : ''; ?>">
            <?php echo $svg_suporte; ?>
            <span>Suporte</span>
        </a>
    </nav>

    <div class="user-profile" id="user-profile-menu">
        <div class="avatar">
            <?php echo strtoupper(substr($nome_usuario_sidebar, 0, 2)); // Pega as 2 primeiras letras do nome ?>
        </div>
        <div class="user-info">
            <div class="user-name"><?php echo $nome_usuario_sidebar; ?></div>
            <div class="user-level"><?php echo ucfirst($nivel_usuario_sidebar); ?></div>
        </div>

        <div class="profile-dropdown" id="profile-dropdown">
            <a href="perfil.php">
                <?php echo $svg_perfil_dropdown; ?>
                <span>Meu Perfil</span>
            </a>
            <a href="resetpassword.php">
                <?php echo $svg_alterar_senha; ?>
                <span>Alterar Senha</span>
            </a>
            <a href="logout.php">
                <?php echo $svg_sair; ?>
                <span>Sair</span>
            </a>

        </div>
    </div>
</aside>