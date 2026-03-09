<?php

class SaftParser
{
    /**
     * Lê SAF-T e devolve FATURAS DE CLIENTE (Sales Invoices)
     * Compatível com vários layouts e namespaces
     */
    public static function loadCustomerInvoices($file)
    {
        if (!is_readable($file)) {
            throw new Exception("Ficheiro não encontrado: ".$file);
        }

        libxml_use_internal_errors(true);

        // Remove namespace para facilitar XPath
        $xmlStr = file_get_contents($file);
        $xmlStr = preg_replace('/xmlns[^=]*="[^"]*"/i', '', $xmlStr);
        $xml = simplexml_load_string($xmlStr, 'SimpleXMLElement', LIBXML_NOCDATA);
        if (!$xml) {
            throw new Exception("XML inválido");
        }

        /* ======================================================
         * 1) Carregar clientes
         * ====================================================== */
        $customers = [];

        foreach ($xml->xpath('//MasterFiles/Customer') as $c) {
            $cid = trim((string)$c->CustomerID);
            if (!$cid) continue;

            $name = (string)($c->CompanyName ?: $c->CustomerName ?: $cid);
            $tax  = (string)$c->CustomerTaxID;
            $addrNode = isset($c->BillingAddress) ? $c->BillingAddress : null;

            $streetName   = $addrNode ? trim((string)($addrNode->StreetName ?? '')) : '';
            $buildingNo   = $addrNode ? trim((string)($addrNode->BuildingNumber ?? '')) : '';
            $addressLine1 = $addrNode ? trim((string)($addrNode->AddressDetail ?? '')) : '';
            $city         = $addrNode ? trim((string)($addrNode->City ?? '')) : '';
            $postalCode   = $addrNode ? trim((string)($addrNode->PostalCode ?? '')) : '';
            $region       = $addrNode ? trim((string)($addrNode->Region ?? '')) : '';
            $cc           = $addrNode ? (string)($addrNode->Country ?: '') : '';
            $phone        = self::firstNonEmpty(array(
                $c->Telephone ?? null,
                $c->Phone ?? null,
                $c->CustomerTelephone ?? null,
            ));
            $mobile       = self::firstNonEmpty(array(
                $c->MobilePhone ?? null,
                $c->CellPhone ?? null,
                $c->CustomerMobile ?? null,
            ));
            $fax          = self::firstNonEmpty(array(
                $c->Fax ?? null,
                $c->CustomerFax ?? null,
            ));
            $email        = self::firstNonEmpty(array(
                $c->Email ?? null,
                $c->CustomerEmail ?? null,
            ));
            $website      = self::firstNonEmpty(array(
                $c->Website ?? null,
                $c->WebSite ?? null,
            ));
            $contact      = self::firstNonEmpty(array(
                $c->Contact ?? null,
                $c->ContactPerson ?? null,
            ));

            if ($addressLine1 === '' && ($streetName !== '' || $buildingNo !== '')) {
                $addressLine1 = trim($streetName.' '.$buildingNo);
            }

            $customers[$cid] = [
                'id'      => $cid,
                'name'    => trim($name),
                'taxid'   => preg_replace('/\s+/', '', $tax),
                'country' => strtoupper(trim($cc)),
                'address' => $addressLine1,
                'city'    => $city,
                'zip'     => $postalCode,
                'state'   => $region,
                'phone'   => $phone,
                'mobile'  => $mobile,
                'fax'     => $fax,
                'email'   => $email,
                'website' => $website,
                'contact' => $contact,
            ];
        }

        /* ======================================================
         * 2) Encontrar faturas (vários layouts possíveis)
         * ====================================================== */
        $invoiceNodes = $xml->xpath('//SourceDocuments/SalesInvoices/Invoice');

        if (empty($invoiceNodes)) {
            $invoiceNodes = $xml->xpath('//SourceDocuments/SalesInvoices/SalesInvoice');
        }

        if (empty($invoiceNodes)) {
            return [];
        }

        $invoices = [];

        foreach ($invoiceNodes as $inv) {
            $invNo = trim((string)$inv->InvoiceNo);
            if (!$invNo) continue;

            $date   = (string)$inv->InvoiceDate;
            $custId = (string)$inv->CustomerID;
            $total  = (float)($inv->DocumentTotals->GrossTotal ?: 0);

            $cust = $customers[$custId] ?? [
                'name' => $custId,
                'taxid' => '',
                'country' => '',
                'address' => '',
                'city' => '',
                'zip' => '',
                'state' => '',
                'phone' => '',
                'mobile' => '',
                'fax' => '',
                'email' => '',
                'website' => '',
                'contact' => '',
            ];

            // Campos adicionais (nível da fatura - quando existirem)
            $hash            = trim((string)($inv->Hash ?? ''));
            $hashControl     = trim((string)($inv->HashControl ?? ''));
            $sourceId        = trim((string)($inv->SourceID ?? ''));
            $systemEntryDate = trim((string)($inv->SystemEntryDate ?? ''));

            // Tax Exemption (pode vir no header ou nas linhas)
            $taxExReason = trim((string)($inv->TaxExemptionReason ?? ''));
            $taxExCode   = trim((string)($inv->TaxExemptionCode ?? ''));

            /* =======================
             * Linhas
             * ======================= */
            $lines = [];
            foreach ($inv->xpath('.//Line') as $ln) {
                $desc = (string)(
                    $ln->Description ?:
                    $ln->ProductDescription ?:
                    $ln->ProductCode ?:
                    'Linha SAF-T'
                );

                $qty = (float)($ln->Quantity ?: 1);
                if ($qty <= 0) $qty = 1;

                $unit = (float)(
                    $ln->UnitPrice ?:
                    ($ln->CreditAmount && $qty ? $ln->CreditAmount / $qty : 0)
                );

                $vat = 0;
                if (isset($ln->Tax->TaxPercentage)) {
                    $vat = (float)$ln->Tax->TaxPercentage;
                }

                // Captura TaxExemption* das linhas (primeiro valor não vazio)
                if ($taxExReason === '') {
                    $taxExReason = trim((string)($ln->TaxExemptionReason ?? ''));
                }
                if ($taxExCode === '') {
                    $taxExCode = trim((string)($ln->TaxExemptionCode ?? ''));
                }
                if ($taxExReason === '' && isset($ln->Tax->TaxExemptionReason)) {
                    $taxExReason = trim((string)$ln->Tax->TaxExemptionReason);
                }
                if ($taxExCode === '' && isset($ln->Tax->TaxExemptionCode)) {
                    $taxExCode = trim((string)$ln->Tax->TaxExemptionCode);
                }

                $lines[] = [
                    'desc' => trim($desc),
                    'qty'  => $qty,
                    'unit_price_ht' => $unit,
                    'vat_rate' => $vat,
                ];
            }

            $invoices[] = [
                'number' => $invNo,
                'date'   => $date,
                'total'  => $total,

                'customer_name'    => $cust['name'],
                'customer_taxid'   => $cust['taxid'],
                'customer_country' => $cust['country'],
                'customer_vat'     => self::buildVat($cust['country'], $cust['taxid']),
                'customer_address' => $cust['address'],
                'customer_city'    => $cust['city'],
                'customer_zip'     => $cust['zip'],
                'customer_state'   => $cust['state'],
                'customer_phone'   => $cust['phone'],
                'customer_mobile'  => $cust['mobile'],
                'customer_fax'     => $cust['fax'],
                'customer_email'   => $cust['email'],
                'customer_website' => $cust['website'],
                'customer_contact' => $cust['contact'],

                // Campos adicionais do XML
                'tax_exemption_reason' => $taxExReason,
                'tax_exemption_code'   => $taxExCode,
                'hash'                 => $hash,
                'hash_control'         => $hashControl,
                'source_id'            => $sourceId,
                'system_entry_date'    => $systemEntryDate,

                'lines' => $lines,
            ];
        }

        return $invoices;
    }

    public static function buildVat($country, $taxid)
    {
        $country = strtoupper(trim((string)$country));
        $taxid   = preg_replace('/[^0-9A-Za-z]/', '', (string)$taxid);

        if ($country && $taxid) return $country.$taxid;
        return $taxid;
    }

    private static function firstNonEmpty($candidates)
    {
        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '') return $value;
        }
        return '';
    }
}