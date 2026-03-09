<?php

class SaftImport
{
    public $db;
    public $error = '';

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function invoiceExistsByRefClient($socid, $refClient)
    {
        $refClient = trim((string)$refClient);
        if ($refClient === '') return false;

        $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."facture
                WHERE entity = ".((int) $GLOBALS['conf']->entity)."
                  AND fk_soc = ".((int)$socid)."
                  AND ref_client = '".$this->db->escape($refClient)."'
                LIMIT 1";
        $res = $this->db->query($sql);
        if ($res && $this->db->num_rows($res) > 0) return true;
        return false;
    }

    /**
     * Prevenção de duplicados via HASH (sem alterar BD):
     * procura o hash em note_public e note_private da fatura.
     */
    public function invoiceExistsByHash($hash)
    {
        $hash = trim((string)$hash);
        if ($hash === '') return false;

        $like = '%Hash: '.$hash.'%';

        $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."facture
                WHERE entity = ".((int) $GLOBALS['conf']->entity)."
                  AND (
                        note_public LIKE '".$this->db->escape($like)."'
                     OR note_private LIKE '".$this->db->escape($like)."'
                  )
                LIMIT 1";

        $res = $this->db->query($sql);
        if ($res && $this->db->num_rows($res) > 0) return true;
        return false;
    }

    public function findOrCreateThirdpartyFromSaft($inv, $user, &$customerStatus = null)
    {
        $customerStatus = 'unknown';
        $name = trim((string)($inv['customer_name'] ?? ''));
        $vat  = trim((string)($inv['customer_vat'] ?? ''));
        $cc   = strtoupper(trim((string)($inv['customer_country'] ?? '')));
        $address = trim((string)($inv['customer_address'] ?? ''));
        $zip = trim((string)($inv['customer_zip'] ?? ''));
        $town = trim((string)($inv['customer_city'] ?? ''));
        $state = trim((string)($inv['customer_state'] ?? ''));
        $phone = trim((string)($inv['customer_phone'] ?? ''));
        $mobile = trim((string)($inv['customer_mobile'] ?? ''));
        $fax = trim((string)($inv['customer_fax'] ?? ''));
        $email = trim((string)($inv['customer_email'] ?? ''));
        $website = trim((string)($inv['customer_website'] ?? ''));
        $contact = trim((string)($inv['customer_contact'] ?? ''));

        if ($name === '') $name = 'Cliente SAF-T';

        // 1) Tenta localizar por tva_intra (VAT)
        if ($vat !== '') {
            $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."societe
                    WHERE entity IN (0,".((int)$GLOBALS['conf']->entity).")
                      AND tva_intra = '".$this->db->escape($vat)."'
                    ORDER BY entity DESC
                    LIMIT 1";
            $res = $this->db->query($sql);
            if ($res && ($obj = $this->db->fetch_object($res))) {
                $customerStatus = 'existente';
                return (int) $obj->rowid;
            }
        }

        // 2) Se não achou, cria
        $soc = new Societe($this->db);
        $soc->name = $name;
        $soc->client = 1;
        $soc->code_client = -1;

        if ($address !== '') $soc->address = $address;
        if ($zip !== '') $soc->zip = $zip;
        if ($town !== '') $soc->town = $town;
        if ($state !== '') $soc->state = $state;
        if ($phone !== '') $soc->phone = $phone;
        if ($mobile !== '') $soc->phone_mobile = $mobile;
        if ($fax !== '') $soc->fax = $fax;
        if ($email !== '') $soc->email = $email;
        if ($website !== '') $soc->url = $website;
        if ($contact !== '') {
            $soc->note_private = trim((string) $soc->note_private);
            if ($soc->note_private !== '') $soc->note_private .= "\n";
            $soc->note_private .= 'Contacto SAF-T: '.$contact;
        }

        if ($vat !== '') $soc->tva_intra = $vat;

        // País (tenta mapear ISO2 -> rowid)
        if ($cc !== '' && strlen($cc) === 2) {
            $country_id = $this->getCountryIdByCode($cc);
            if ($country_id > 0) $soc->country_id = $country_id;
        }

        $id = $soc->create($user);
        if ($id <= 0) {
            $this->error = $soc->error;
            $customerStatus = 'erro';
            return -1;
        }

        $customerStatus = 'novo';
        return (int) $id;
    }

    public function createCustomerInvoiceDraftFromSaft($socid, $inv, $user)
    {
        $fact = new Facture($this->db);

        $fact->socid = (int) $socid;
        $fact->type  = Facture::TYPE_STANDARD;

        // ✅ Ref. cliente deve ficar em branco (pedido)
        $fact->ref_client = '';

        // Data da fatura
        $datestr = trim((string)($inv['date'] ?? ''));
        $fact->date = $this->parseDateToDolTime($datestr);
        if (empty($fact->date)) $fact->date = dol_now();

        // ✅ Notes com os campos pedidos (inclui TaxExemptionReason e TaxExemptionCode)
        $today = dol_print_date(dol_now(), '%Y-%m-%d');

        $invoiceNo         = trim((string)($inv['number'] ?? ''));
        $taxExReason       = trim((string)($inv['tax_exemption_reason'] ?? ''));
        $taxExCode         = trim((string)($inv['tax_exemption_code'] ?? ''));
        $hash              = trim((string)($inv['hash'] ?? ''));
        $hashControl       = trim((string)($inv['hash_control'] ?? ''));
        $sourceId          = trim((string)($inv['source_id'] ?? ''));
        $systemEntryDate   = trim((string)($inv['system_entry_date'] ?? ''));

        $notes = [];
        $notes[] = "Importado via SAF-T: ".$today;
        if ($invoiceNo !== '')       $notes[] = "Número da Fatura: ".$invoiceNo;
        if ($taxExReason !== '')     $notes[] = "Razão da Taxa de exceção: ".$taxExReason;
        if ($taxExCode !== '')       $notes[] = "Código da taxa de exceção: ".$taxExCode;
        if ($hash !== '')            $notes[] = "Hash: ".$hash;
        if ($hashControl !== '')     $notes[] = "HashControl: ".$hashControl;
        if ($sourceId !== '')        $notes[] = "SourceID: ".$sourceId;
        if ($systemEntryDate !== '') $notes[] = "Data da geração: ".$systemEntryDate;

        $fact->note_public = implode("\n", $notes);

        $id = $fact->create($user);
        if ($id <= 0) {
            $this->error = $fact->error;
            return -1;
        }

        // Carregar thirdparty para taxes e etc. (boa prática)
        $fact->fetch_thirdparty();

        // Linhas
        $lines = $inv['lines'] ?? [];
        if (!is_array($lines) || empty($lines)) {
            $desc = "Import SAF-T - ".$invoiceNo;
            $pu   = (float)($inv['total'] ?? 0);
            $qty  = 1;
            $vat  = 0;

            $r = $fact->addline($desc, $pu, $qty, $vat);
            if ($r <= 0) {
                $this->error = $fact->error;
                return -1;
            }
        } else {
            foreach ($lines as $ln) {
                $desc = trim((string)($ln['desc'] ?? 'Linha SAF-T'));
                $qty  = (float)($ln['qty'] ?? 1);
                if ($qty <= 0) $qty = 1;

                $pu_ht = (float)($ln['unit_price_ht'] ?? 0);
                $vat   = (float)($ln['vat_rate'] ?? 0);

                if (!is_finite($pu_ht)) $pu_ht = 0;
                if (!is_finite($vat)) $vat = 0;

                $r = $fact->addline($desc, $pu_ht, $qty, $vat);
                if ($r <= 0) {
                    $this->error = $fact->error;
                    return -1;
                }
            }
        }

        return (int) $id;
    }

    private function getCountryIdByCode($code)
    {
        $code = strtoupper(trim((string)$code));
        if ($code === '' || strlen($code) !== 2) return 0;

        $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."c_country
                WHERE code = '".$this->db->escape($code)."'
                LIMIT 1";
        $res = $this->db->query($sql);
        if ($res && ($obj = $this->db->fetch_object($res))) return (int) $obj->rowid;
        return 0;
    }

    private function parseDateToDolTime($datestr)
    {
        $datestr = trim((string)$datestr);
        if ($datestr === '') return 0;

        $parts = preg_split('/[T\s]/', $datestr);
        $d = $parts[0];

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) {
            return dol_mktime(12, 0, 0, (int)$m[2], (int)$m[3], (int)$m[1]);
        }
        return 0;
    }
}