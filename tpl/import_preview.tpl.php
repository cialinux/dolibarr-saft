<form method="POST" action="process.php">
<table class="border centpercent">
<tr class="liste_titre">
    <td></td>
    <td>Nº</td>
    <td>Data</td>
    <td>Cliente</td>
    <td>Total</td>
</tr>

<?php foreach ($invoices as $k => $i): ?>
<tr class="oddeven">
    <td><input type="checkbox" name="sel[]" value="<?php echo $k; ?>" checked></td>
    <td><?php echo dol_escape_htmltag($i['invoice_no']); ?></td>
    <td><?php echo dol_escape_htmltag($i['date']); ?></td>
    <td><?php echo dol_escape_htmltag($i['customer']); ?></td>
    <td class="right"><?php echo price($i['total']); ?></td>
</tr>
<?php endforeach; ?>
</table>

<input type="submit" class="button" value="Importar selecionadas">
</form>