<?php
require_once 'config.php';

if (isset($_SESSION['usuario_id'])) {
    if ($_SESSION['usuario_nivel_acesso'] == 'admin') {
        header('Location: paineladmin/index.php');
    } else {
        header('Location: paginamembros/index.php');
    }
} else {
    header('Location: paginamembros/login.php');
}
exit();
?>