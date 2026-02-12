<?php
require '../../main.inc.php';
require_once '../class/SaftImport.class.php';

$xml = file_get_contents($_FILES['xml']['tmp_name']);
$importer = new SaftImport($xml);

$summary = $importer->getInvoicesSummary();

foreach ($_POST['sel'] as $idx) {
    $importer->importInvoice($summary[$idx]['node'], $user);
}

setEventMessages('Faturas importadas com sucesso', null, 'mesgs');
header('Location: ../import/index.php');
exit;