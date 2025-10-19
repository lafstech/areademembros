<?php
// admin/_sidebar.php

// A variável $pagina_atual deve ser definida na página que inclui este sidebar.
$pagina_atual = basename($_SERVER['PHP_SELF']);
// $nome_usuario deve ser definido na página que inclui este sidebar.
if (!isset($nome_usuario)) {
    // Fallback se $nome_usuario não estiver definido
    $nome_usuario = htmlspecialchars($_SESSION['usuario_nome'] ?? 'Admin');
}
?>

<div class="menu-toggle" id="menu-toggle">
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg>
</div>

<aside class="sidebar" id="admin-sidebar">
    <div class="logo">Admin<span>Panel</span></div>
    <nav>
        <a href="index.php" class="<?php echo ($pagina_atual === 'index.php') ? 'active' : ''; ?>">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z" /></svg>
            <span>Dashboard</span>
        </a>
        <a href="usuarios.php" class="<?php echo ($pagina_atual === 'usuarios.php') ? 'active' : ''; ?>">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-4.663M12 12.375a3.75 3.75 0 100-7.5 3.75 3.75 0 000 7.5z" /></svg>
            <span>Usuários</span>
        </a>
        <a href="cursos.php" class="<?php echo ($pagina_atual === 'cursos.php') ? 'active' : ''; ?>">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25" /></svg>
            <span>Cursos</span>
        </a>
        <a href="financascursos.php" class="<?php echo ($pagina_atual === 'financascursos.php') ? 'active' : ''; ?>">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
            <span>Finanças Cursos</span>
        </a>
        <a href="settings.php" class="<?php echo ($pagina_atual === 'settings.php') ? 'active' : ''; ?>">
             <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.75c.39.06.772.109 1.156.149a4.5 4.5 0 0 1 1.096.168 4.316 4.316 0 003.541 3.541 4.5 4.5 0 01.168 1.096c.04.384.089.766.149 1.156a4.5 4.5 0 01.452 3.197 4.316 4.316 0 003.541 3.541 4.5 4.5 0 011.096.168c.384.04.766.089 1.156.149a4.5 4.5 0 010 8.502 4.316 4.316 0 00-3.541 3.541 4.5 4.5 0 01-1.096.168c-.384.04-.766.089-1.156.149a4.5 4.5 0 01-3.197.452 4.316 4.316 0 00-3.541-3.541 4.5 4.5 0 01-1.096-.168c-.384-.04-.766-.089-1.156-.149a4.5 4.5 0 01-3.197-.452 4.316 4.316 0 00-3.541-3.541 4.5 4.5 0 01-.168-1.096c-.04-.384-.089-.766-.149-1.156a4.5 4.5 0 01-.452-3.197 4.316 4.316 0 003.541-3.541 4.5 4.5 0 01.168-1.096c.04-.384.089-.766.149-1.156a4.5 4.5 0 01.452-3.197zM12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z" /></svg>
            <span>Configurações</span>
        </a>
    </nav>
    <div class="user-profile" id="user-profile-menu">
        <div class="avatar"><?php echo strtoupper(substr($nome_usuario, 0, 2)); ?></div>
        <div class="user-info">
            <div class="user-name"><?php echo $nome_usuario; ?></div>
            <div class="user-level">Administrador</div>
        </div>
        <div class="profile-dropdown" id="profile-dropdown">
            <a href="logout.php"><span>Sair</span></a>
        </div>
    </div>
</aside>