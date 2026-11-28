<?php

namespace App\Support\Accounting;

use App\Support\Finance\Iban;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A SEPA credit transfer file (ISO 20022 pain.001.001.09) to upload in online banking: one payment information block
 * from the account of the organisation with one transfer per payee. Names and texts are reduced to the SEPA character
 * set, because banks refuse files with other characters.
 */
class SepaCreditTransfer
{
    public const NAMESPACE = 'urn:iso:std:iso:20022:tech:xsd:pain.001.001.09';

    private DOMDocument $doc;

    /**
     * @param  array{name: string, iban: string, bic: string}  $debtor
     * @param  list<array{id: string, name: string, iban: string, cents: int, text: string}>  $payments
     */
    public function render(array $debtor, array $payments, Carbon $executionDate, ?string $messageId = null): string
    {
        if ($payments === [] || ! Iban::valid($debtor['iban'])) {
            throw AccountingException::because('sepa_debtor');
        }
        $messageId ??= 'KASMIN-'.now()->format('YmdHis').'-'.Str::upper(Str::random(6));
        $total = array_sum(array_column($payments, 'cents'));
        $this->doc = $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;
        $root = $doc->createElementNS(self::NAMESPACE, 'Document');
        $doc->appendChild($root);
        $init = $this->add($root, 'CstmrCdtTrfInitn');

        $header = $this->add($init, 'GrpHdr');
        $this->add($header, 'MsgId', self::id($messageId));
        $this->add($header, 'CreDtTm', now()->format('Y-m-d\TH:i:s'));
        $this->add($header, 'NbOfTxs', (string) count($payments));
        $this->add($header, 'CtrlSum', self::amount($total));
        $this->add($this->add($header, 'InitgPty'), 'Nm', self::text($debtor['name'], 70));

        $info = $this->add($init, 'PmtInf');
        $this->add($info, 'PmtInfId', self::id($messageId.'-1'));
        $this->add($info, 'PmtMtd', 'TRF');
        $this->add($info, 'BtchBookg', 'true');
        $this->add($info, 'NbOfTxs', (string) count($payments));
        $this->add($info, 'CtrlSum', self::amount($total));
        $this->add($this->add($this->add($info, 'PmtTpInf'), 'SvcLvl'), 'Cd', 'SEPA');
        $this->add($this->add($info, 'ReqdExctnDt'), 'Dt', $executionDate->toDateString());
        $this->add($this->add($info, 'Dbtr'), 'Nm', self::text($debtor['name'], 70));
        $this->add($this->add($this->add($info, 'DbtrAcct'), 'Id'), 'IBAN', Iban::normalise($debtor['iban']));
        $agent = $this->add($this->add($info, 'DbtrAgt'), 'FinInstnId');
        $bic = strtoupper(trim($debtor['bic']));
        // Within SEPA the BIC is optional; without one the bank finds the account by the IBAN.
        $bic !== '' ? $this->add($agent, 'BICFI', $bic) : $this->add($this->add($agent, 'Othr'), 'Id', 'NOTPROVIDED');
        $this->add($info, 'ChrgBr', 'SLEV');

        foreach ($payments as $payment) {
            $this->transfer($info, $payment);
        }

        return (string) $doc->saveXML();
    }

    /** @param  array{id: string, name: string, iban: string, cents: int, text: string}  $payment */
    private function transfer(DOMElement $info, array $payment): void
    {
        $tx = $this->add($info, 'CdtTrfTxInf');
        $this->add($this->add($tx, 'PmtId'), 'EndToEndId', self::id($payment['id']));
        $amount = $this->add($this->add($tx, 'Amt'), 'InstdAmt', self::amount($payment['cents']));
        $amount->setAttribute('Ccy', 'EUR');
        $this->add($this->add($tx, 'Cdtr'), 'Nm', self::text($payment['name'], 70));
        $this->add($this->add($this->add($tx, 'CdtrAcct'), 'Id'), 'IBAN', Iban::normalise($payment['iban']));
        $this->add($this->add($tx, 'RmtInf'), 'Ustrd', self::text($payment['text'], 140));
    }

    private function add(DOMElement $parent, string $name, ?string $value = null): DOMElement
    {
        $element = $this->doc->createElementNS(self::NAMESPACE, $name);
        if ($value !== null) {
            $element->appendChild($this->doc->createTextNode($value));
        }
        $parent->appendChild($element);

        return $element;
    }

    private static function amount(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    /** The Latin character set of SEPA: umlauts are spelled out, everything else becomes a space. */
    public static function text(string $value, int $length): string
    {
        $ascii = Str::ascii(strtr($value, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss', '&' => '+']));
        $clean = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace("#[^A-Za-z0-9/\\-?:().,'+ ]#", ' ', $ascii)));

        return mb_substr($clean === '' ? '-' : $clean, 0, $length);
    }

    /** Identifiers: at most 35 characters without spaces. */
    private static function id(string $value): string
    {
        return substr((string) preg_replace('#[^A-Za-z0-9/\-?:().,\'+]#', '', $value), 0, 35);
    }
}
