<?php
/**
 * Template de visualização de fatura SAF-T
 * Espera a variável:
 *   $iv (array) — invoice_view retornado pela API
 */

if (empty($iv) || !is_array($iv)) {
    return;
}
?>

<!-- ========================================================= -->
<!-- FATURA / ATCUD (HEADER AZUL) -->
<!-- ========================================================= -->

<table style="width:100%; border-collapse:collapse; margin-bottom:14px;">
    <tr>
        <td colspan="2"
            style="
                background:#2c6fb7;
                color:#ffffff;
                font-weight:bold;
                padding:8px 10px;
                font-size:14px;
            ">
            FATURA
        </td>
    </tr>
    <tr>
        <td style="vertical-align:top; width:50%;">
            <b>N.º:</b> <?php echo dol_escape_htmltag($iv['invoice']['invoice_no'] ?? ''); ?><br>
            <b>Data:</b> <?php echo dol_escape_htmltag($iv['invoice']['invoice_date'] ?? ''); ?><br>
            <b>Tipo:</b> <?php echo dol_escape_htmltag($iv['invoice']['invoice_type'] ?? ''); ?>
            |
            <b>Período:</b> <?php echo dol_escape_htmltag($iv['invoice']['period'] ?? ''); ?>
        </td>
        <td
style="vertical-align:top; text-align:right;">
            <b>ATCUD:</b> <?php echo dol_escape_htmltag($iv['invoice']['atcud'] ?? ''); ?><br>
            <b>Moeda:</b> <?php echo dol_escape_htmltag($iv['totals']['currency'] ?? ''); ?>
        </td>
    </tr>
</table>

<!-- ========================================================= -->
<!-- EMITENTE / CLIENTE -->
<!-- ========================================================= -->

<table style="width:100%; border-collapse:collapse; margin-bottom:14px;">
    <tr>
        <td style="background:#e9eef4; font-weight:bold;">Emitente</td>
        <td style="background:#e9eef4; font-weight:bold;">Cliente</td>
    </tr>
    <tr>
        <td style="font-size:11px; vertical-align:top;">
            <b><?php echo dol_escape_htmltag($iv['supplier']['company_name'] ?? ''); ?></b><br>
            NIF: <?php echo dol_escape_htmltag($iv['supplier']['nif'] ?? ''); ?><br>
            <?php echo dol_escape_htmltag($iv['supplier']['address_detail'] ?? ''); ?><br>
            <?php echo dol_escape_htmltag($iv['supplier']['postal'] ?? ''); ?>
            <?php echo dol_escape_htmltag($iv['supplier']['city'] ?? ''); ?> –
            <?php echo dol_escape_htmltag($iv['supplier']['country'] ?? ''); ?><br>
            <?php if (!empty($iv['supplier']['email'])): ?>
                Email: <?php echo dol_escape_htmltag($iv['supplier']['email']); ?><br>
            <?php endif; ?>
            Programa certificado AT: <?php echo dol_escape_htmltag($iv['supplier']['software_cert'] ?? ''); ?><br>
            Produto:
            <?php echo dol_escape_htmltag($iv['supplier']['product_id'] ?? ''); ?>
            (<?php echo dol_escape_htmltag($iv['supplier']['product_version'] ?? ''); ?>)
        </td>

        <td style="font-size:11px; vertical-align:top;">
            <b><?php echo dol_escape_htmltag($iv['customer']['company_name'] ?? ''); ?></b><br>
            NIF: <?php echo dol_escape_htmltag($iv['customer']['nif'] ?? ''); ?><br>
            <?php echo dol_escape_htmltag($iv['customer']['addr_detail'] ?? ''); ?><br>
            <?php echo dol_escape_htmltag($iv['customer']['postal'] ?? ''); ?>
            <?php echo dol_escape_htmltag($iv['customer']['city'] ?? ''); ?> –
            <?php echo dol_escape_htmltag($iv['customer']['country'] ?? ''); ?><br>
            <?php if (!empty($iv['customer']['contact'])): ?>
                Contacto: <?php echo dol_escape_htmltag($iv['customer']['contact']); ?>
            <?php endif; ?>
        </td>
    </tr>
</table>

<!-- ========================================================= -->
<!-- SHIP FROM / SHIP TO -->
<!-- ========================================================= -->

<table style="width:100%; border-collapse:collapse; margin-bottom:14px;">
    <tr>
        <td style="background:#e9eef4; font-weight:bold;">
            Local de Expedição (Ship From)
        </td>
        <td style="background:#e9eef4; font-weight:bold;">
            Local de Destino (Ship To)
        </td>
    </tr>
    <tr>
        <td style="font-size:11px; vertical-align:top;">
            <?php echo dol_escape_htmltag($iv['shipping']['from']['addr'] ?? ''); ?><br>
            <?php echo dol_escape_htmltag($iv['shipping']['from']['postal'] ?? ''); ?>
            <?php echo dol_escape_htmltag($iv['shipping']['from']['city'] ?? ''); ?> –
            <?php echo dol_escape_htmltag($iv['shipping']['from']['country'] ?? ''); ?>
        </td>
        <td style="font-size:11px; vertical-align:top;">
            <?php echo dol_escape_htmltag($iv['shipping']['to']['addr'] ?? ''); ?><br>
            <?php echo dol_escape_htmltag($iv['shipping']['to']['postal'] ?? ''); ?>
            <?php echo dol_escape_htmltag($iv['shipping']['to']['city'] ?? ''); ?> –
            <?php echo dol_escape_htmltag($iv['shipping']['to']['country'] ?? ''); ?>
        </td>
    </tr>
</table>

<!-- ========================================================= -->
<!-- PRODUTOS / SERVIÇOS -->
<!-- ========================================================= -->

<table style="width:100%; border-collapse:collapse; margin-bottom:14px;">
    <tr style="background:#c0c0c0; font-weight:bold;">
        <td style="padding:6px;">#</td>
        <td style="padding:6px;">Produto</td>
        <td style="padding:6px;">Descrição</td>
        <td style="padding:6px; text-align:right;">Qtd</td>
        <td style="padding:6px; text-align:right;">Preço Unit.</td>
        <td style="padding:6px; text-align:right;">IVA</td>
        <td style="padding:6px; text-align:right;">Total Linha</td>
    </tr>

    <?php if (!empty($iv['lines'])): ?>
        <?php foreach ($iv['lines'] as $l): ?>
            <tr>
                <td style="padding:6px;"><?php echo dol_escape_htmltag($l['line_number'] ?? ''); ?></td>
                <td style="padding:6px;"><?php echo dol_escape_htmltag($l['product_code'] ?? ''); ?></td>
                <td style="padding:6px;">
                    <?php echo dol_escape_htmltag($l['description'] ?? ''); ?>
                    <?php if (!empty($l['exemption_reason']) && $l['exemption_reason'] !== '—'): ?>
                        <br>
                        <span style="color:#777;font-size:11px;">
                            Isenção:
                            <?php echo dol_escape_htmltag($l['exemption_reason']); ?>
                            (<?php echo dol_escape_htmltag($l['exemption_code'] ?? ''); ?>)
                        </span>
                    <?php endif; ?>
                </td>
                <td style="padding:6px; text-align:right;"><?php echo dol_escape_htmltag($l['qty'] ?? ''); ?></td>
                <td style="padding:6px; text-align:right;"><?php echo dol_escape_htmltag($l['unit_price'] ?? ''); ?></td>
                <td style="padding:6px; text-align:right;"><?php echo dol_escape_htmltag($l['tax_pct'] ?? ''); ?>%</td>
                <td style="padding:6px; text-align:right;"><?php echo dol_escape_htmltag($l['credit_amount'] ?? ''); ?></td>
            </tr>
        <?php endforeach; ?>
    <?php endif; ?>
</table>

<!-- ========================================================= -->
<!-- TOTAIS -->
<!-- ========================================================= -->

<table style="width:100%; border-collapse:collapse; margin-bottom:14px;">
    <tr>
        <td style="padding:6px; text-align:right;">Total líquido</td>
        <td style="padding:6px; text-align:right; width:180px;">
            <?php echo dol_escape_htmltag($iv['totals']['net'] ?? ''); ?>
            <?php echo dol_escape_htmltag($iv['totals']['currency'] ?? ''); ?>
        </td>
    </tr>
    <tr>
        <td style="padding:6px; text-align:right;">IVA</td>
        <td style="padding:6px; text-align:right;">
            <?php echo dol_escape_htmltag($iv['totals']['tax'] ?? ''); ?>
            <?php echo dol_escape_htmltag($iv['totals']['currency'] ?? ''); ?>
        </td>
    </tr>
    <tr>
        <td style="padding:6px; text-align:right;"><b>Total a pagar</b></td>
        <td style="padding:6px; text-align:right;"><b>
            <?php echo dol_escape_htmltag($iv['totals']['gross'] ?? ''); ?>
            <?php echo dol_escape_htmltag($iv['totals']['currency'] ?? ''); ?>
        </b></td>
    </tr>
</table>

<!-- ========================================================= -->
<!-- RODAPÉ TÉCNICO (CINZA) -->
<!-- ========================================================= -->

<table style="width:100%; border-collapse:collapse;">
    <tr>
        <td>
            <b>Hash:</b><br>
<div style="
    font-family:monospace;
    font-size:10px;
    color:#444;
    background:#c0c0c0;
    padding:6px;
    border:1px solid #ccc;
    border-radius:3px;
    word-break:break-all;
    overflow-wrap:anywhere;
    line-height:1.3;
">
<?php echo dol_escape_htmltag($iv['invoice']['hash'] ?? ''); ?>
</div>
            <b>HashControl:</b> <?php echo dol_escape_htmltag($iv['invoice']['hash_control'] ?? ''); ?> |
            <b>SourceID:</b> <?php echo dol_escape_htmltag($iv['invoice']['source_id'] ?? ''); ?> |
            <b>Data de registo:</b> <?php echo dol_escape_htmltag($iv['invoice']['system_entry_date'] ?? ''); ?>
        </td>
    </tr>
</table>
<br>