<?php
/*
 * QontoSync dolibarr module
 * @file       class/qontoublprotocol.class.php
 * @author     Gaëtan COURTOIS
 * @license    GPLv3
 */

if (!defined('DOL_DOCUMENT_ROOT')) {
    http_response_code(403);
    exit('Access Forbidden');
}

dol_include_once('/einvoicing/class/protocols/CIIProtocol.class.php');

/**
 * Class QontoUBLProtocol
 *
 * Parses XML documents in UBL 2.1 format (Invoice-2 and CreditNote-2) into the normalized
 * EN 16931 structure expected by CIIProtocol::doCreateSupplierInvoiceFromSource(), delegating 100%
 * of Dolibarr business object creation (third party, supplier invoice, lines, allowances/charges,
 * deposits, VAT alignment, and GED attachments) to the community einvoicing module.
 */
class QontoUBLProtocol extends CIIProtocol
{
	/** @const string Namespace UBL CommonBasicComponents-2 */
	const NS_CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

	/** @const string Namespace UBL CommonAggregateComponents-2 */
	const NS_CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

	/**
	 * Initializes DOMDocument and DOMXPath with UBL 2.1 namespaces (cbc, cac).
	 *
	 * @param  string $xml Raw XML content
	 * @return array{0:\DOMDocument, 1:\DOMXPath, 2:\DOMElement|null}
	 * @throws \InvalidArgumentException If XML is invalid
	 */
	protected function initUblXPath(string $xml): array
	{
		$doc = new \DOMDocument();
		$previous = libxml_use_internal_errors(true);
		if (!$doc->loadXML(self::stripStrayXmlDeclarations($xml))) {
			$errors = libxml_get_errors();
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
			$msg = $errors ? trim($errors[0]->message) : 'unknown XML error';
			throw new \InvalidArgumentException("Invalid UBL XML provided: {$msg}");
		}
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		$xpath = new \DOMXPath($doc);
		$xpath->registerNamespace('cbc', self::NS_CBC);
		$xpath->registerNamespace('cac', self::NS_CAC);

		return array($doc, $xpath, $doc->documentElement);
	}

	/**
	 * Evaluates a scalar XPath expression (element text or attribute value) and returns
	 * the first non-empty value found, or null.
	 *
	 * @param  \DOMXPath     $xpath       XPath instance
	 * @param  string        $expr        Relative or absolute XPath expression
	 * @param  \DOMNode|null $contextNode Context node
	 * @return string|null
	 */
	protected function getUblValue(\DOMXPath $xpath, string $expr, ?\DOMNode $contextNode = null): ?string
	{
		if ($expr === '' || $expr === 'NA') {
			return null;
		}

		$nodes = $xpath->query($expr, $contextNode);
		if (!$nodes || $nodes->length === 0) {
			return null;
		}

		foreach ($nodes as $node) {
			$value = trim((string) $node->nodeValue);
			if ($value !== '') {
				return $value;
			}
		}

		return null;
	}

	/**
	 * Extracts key => value pairs from repeating elements having an attribute (e.g. schemeID).
	 *
	 * @param  \DOMXPath     $xpath       XPath instance
	 * @param  string        $expr        XPath expression pointing to elements
	 * @param  string        $attrName    Attribute name used as key (defaults to 'schemeID')
	 * @param  \DOMNode|null $contextNode Context node
	 * @return array<string|int,string>
	 */
	protected function getUblAttrPairs(\DOMXPath $xpath, string $expr, string $attrName = 'schemeID', ?\DOMNode $contextNode = null): array
	{
		if ($expr === '' || $expr === 'NA') {
			return array();
		}

		$nodes = $xpath->query($expr, $contextNode);
		$result = array();
		if ($nodes) {
			foreach ($nodes as $node) {
				if (!($node instanceof \DOMElement)) {
					continue;
				}
				$key = trim($node->getAttribute($attrName));
				$value = trim((string) $node->nodeValue);
				if ($value !== '') {
					$result[$key !== '' ? $key : count($result)] = $value;
				}
			}
		}

		return $result;
	}

	/**
	 * Normalizes an UBL date string (YYYY-MM-DD or YYYYMMDD) to YYYY-MM-DD format.
	 *
	 * @param  string|null $raw Raw date string
	 * @return string|null
	 */
	protected function normUblDate(?string $raw): ?string
	{
		if ($raw === null || trim($raw) === '') {
			return null;
		}
		$raw = trim($raw);

		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $raw, $m)) {
			return $m[1] . '-' . $m[2] . '-' . $m[3];
		}

		if (preg_match('/^(\d{4})(\d{2})(\d{2})/', $raw, $m)) {
			return $m[1] . '-' . $m[2] . '-' . $m[3];
		}

		return $raw;
	}

	/**
	 * Converts numeric string to float, or null if empty / non-numeric.
	 *
	 * @param  string|float|int|null $v Raw value
	 * @return float|null
	 */
	protected function toUblFloat($v): ?float
	{
		if ($v === null || $v === '') {
			return null;
		}
		if (is_float($v) || is_int($v)) {
			return (float) $v;
		}
		$v = str_replace(',', '.', trim((string) $v));
		return is_numeric($v) ? (float) $v : null;
	}

	/**
	 * Overrides depositAnnouncedByDocument() to also include billing frameworks
	 * B7, S7, and M7 (e-reporting / VAT already collected and invoice settled at cashier, e.g. Leroy Merlin)
	 * in addition to B2, S2, and M2.
	 *
	 * @param  array<string,mixed> $parsedHeader Parsed header of the received document
	 * @return float                             Prepaid amount to be reconciled, 0 if none
	 */
	protected function depositAnnouncedByDocument(array $parsedHeader)
	{
		$announced = (float) ($parsedHeader['totalPrepaidAmount'] ?? 0);
		if ($announced < 0.005 || in_array((string) ($parsedHeader['businessProcessId'] ?? ''), array('B2', 'S2', 'M2', 'B7', 'S7', 'M7'), true)) {
			return 0.0;
		}

		return $announced;
	}

	/**
	 * Counts tolerance items entitling one cent rounding tolerance on EXTENDED profiles
	 * (BR-FREXT-CO-15 / BR-FXEXT-S-08), overall and grouped by VAT rate.
	 *
	 * @param  \DOMXPath        $xpath XPath instance
	 * @param  \DOMElement|null $root  UBL root element
	 * @return array{0:int, 1:array<string,int>}
	 */
	protected function countUblToleranceItems(\DOMXPath $xpath, ?\DOMElement $root): array
	{
		if (!$root) {
			return array(0, array());
		}

		$total = 0;
		$byRate = array();

		$queries = array(
			'cac:InvoiceLine | cac:CreditNoteLine' => 'cac:Item/cac:ClassifiedTaxCategory/cbc:Percent',
			'cac:AllowanceCharge' => 'cac:TaxCategory/cbc:Percent',
		);

		foreach ($queries as $itemExpr => $rateExpr) {
			$nodes = $xpath->query($itemExpr, $root);
			if ($nodes === false) {
				continue;
			}
			foreach ($nodes as $node) {
				$total++;
				$rateStr = $this->getUblValue($xpath, $rateExpr, $node);
				if ($rateStr === null && ($node->localName === 'InvoiceLine' || $node->localName === 'CreditNoteLine')) {
					$rateStr = $this->getUblValue($xpath, 'cac:TaxTotal/cac:TaxSubtotal/cac:TaxCategory/cbc:Percent', $node);
				}
				$rate = (string) price2num((float) $this->toUblFloat($rateStr));
				$byRate[$rate] = ($byRate[$rate] ?? 0) + 1;
			}
		}

		return array($total, $byRate);
	}

	/**
	 * Extracts party details for a UBL Party (Seller or Buyer).
	 *
	 * @param  \DOMXPath        $xpath     XPath instance
	 * @param  \DOMNode|null    $partyNode cac:Party node
	 * @param  string           $prefix    'seller' or 'buyer'
	 * @return array<string,mixed>
	 */
	protected function parseUblParty(\DOMXPath $xpath, ?\DOMNode $partyNode, string $prefix): array
	{
		$res = array(
			$prefix . 'name' => null,
			$prefix . 'ids' => null,
			$prefix . 'lineone' => null,
			$prefix . 'linetwo' => null,
			$prefix . 'linethree' => null,
			$prefix . 'postcode' => null,
			$prefix . 'city' => null,
			$prefix . 'country' => null,
			$prefix . 'subdivision' => null,
			$prefix . 'contactpersonname' => null,
			$prefix . 'contactemailaddr' => null,
			$prefix . 'contactphoneno' => null,
			$prefix . 'GlobalIds' => array(),
			$prefix . 'vatnumber' => null,
			$prefix . 'LegalOrgId' => null,
			$prefix . 'LegalOrgScheme' => null,
			$prefix . 'TradingName' => null,
		);

		if ($prefix === 'seller') {
			$res['sellercontactdepartmentname'] = null;
			$res['sellerCommunicationUriScheme'] = null;
			$res['sellerCommunicationUri'] = null;
			$res['sellerTaxRegistations'] = array();
		}

		if (!$partyNode) {
			return $res;
		}

		// Legal registration name (BT-27 / BT-44) and trading name (BT-28 / BT-45)
		$regName = $this->getUblValue($xpath, 'cac:PartyLegalEntity/cbc:RegistrationName', $partyNode);
		$tradeName = $this->getUblValue($xpath, 'cac:PartyName/cbc:Name', $partyNode);
		$res[$prefix . 'name'] = $regName ?? $tradeName;
		$res[$prefix . 'TradingName'] = $tradeName ?? $regName;

		// Private identifier / without schemeID (BT-29 / BT-46)
		$res[$prefix . 'ids'] = $this->getUblValue($xpath, 'cac:PartyIdentification/cbc:ID[not(@schemeID)]', $partyNode)
			?? $this->getUblValue($xpath, 'cac:PartyIdentification/cbc:ID', $partyNode);

		// Postal address (BG-8 / BG-13)
		$streetName = $this->getUblValue($xpath, 'cac:PostalAddress/cbc:StreetName', $partyNode);
		$addStreetName = $this->getUblValue($xpath, 'cac:PostalAddress/cbc:AdditionalStreetName', $partyNode);
		$addrLine1 = $this->getUblValue($xpath, 'cac:PostalAddress/cac:AddressLine[1]/cbc:Line', $partyNode);
		$addrLine2 = $this->getUblValue($xpath, 'cac:PostalAddress/cac:AddressLine[2]/cbc:Line', $partyNode);
		$addrLine3 = $this->getUblValue($xpath, 'cac:PostalAddress/cac:AddressLine[3]/cbc:Line', $partyNode);

		if ($streetName !== null) {
			$res[$prefix . 'lineone'] = $streetName;
			$res[$prefix . 'linetwo'] = $addStreetName ?? $addrLine1;
			$res[$prefix . 'linethree'] = ($addStreetName !== null) ? $addrLine1 : $addrLine2;
		} else {
			$res[$prefix . 'lineone'] = $addrLine1;
			$res[$prefix . 'linetwo'] = $addStreetName ?? $addrLine2;
			$res[$prefix . 'linethree'] = $addrLine3;
		}

		$res[$prefix . 'postcode'] = $this->getUblValue($xpath, 'cac:PostalAddress/cbc:PostalZone', $partyNode);
		$res[$prefix . 'city'] = $this->getUblValue($xpath, 'cac:PostalAddress/cbc:CityName', $partyNode);
		$res[$prefix . 'country'] = $this->getUblValue($xpath, 'cac:PostalAddress/cac:Country/cbc:IdentificationCode', $partyNode);
		$res[$prefix . 'subdivision'] = $this->getUblValue($xpath, 'cac:PostalAddress/cbc:CountrySubentity', $partyNode);

		// Contact details (BG-6 / BG-9)
		$res[$prefix . 'contactpersonname'] = $this->getUblValue($xpath, 'cac:Contact/cbc:Name', $partyNode);
		$res[$prefix . 'contactphoneno'] = $this->getUblValue($xpath, 'cac:Contact/cbc:Telephone', $partyNode);
		$res[$prefix . 'contactemailaddr'] = $this->getUblValue($xpath, 'cac:Contact/cbc:ElectronicMail', $partyNode);

		// Electronic address (BT-34 / BT-49)
		$endpointUri = $this->getUblValue($xpath, 'cbc:EndpointID', $partyNode);
		$endpointScheme = $this->getUblValue($xpath, 'cbc:EndpointID/@schemeID', $partyNode);
		if ($prefix === 'seller') {
			$res['sellerCommunicationUri'] = $endpointUri;
			$res['sellerCommunicationUriScheme'] = $endpointScheme;
		}

		// Global identifiers (BT-29 / BT-46 with schemeID)
		$globalIds = array();
		$idNodes = $xpath->query('cac:PartyIdentification/cbc:ID', $partyNode);
		if ($idNodes) {
			foreach ($idNodes as $idNode) {
				if (!($idNode instanceof \DOMElement)) {
					continue;
				}
				$val = trim((string) $idNode->nodeValue);
				if ($val === '') {
					continue;
				}
				$scheme = trim($idNode->getAttribute('schemeID'));
				if ($scheme === '' && strtoupper((string) $res[$prefix . 'country']) === 'FR') {
					$digits = preg_replace('/\D/', '', $val);
					if ($digits === $val && strlen($digits) === 9) {
						$scheme = '0002';
					} elseif ($digits === $val && strlen($digits) === 14) {
						$scheme = '0009';
					}
				}
				if ($scheme !== '') {
					$globalIds[$scheme] = $val;
				}
			}
		}

		// Legal entity identifier (BT-30 / BT-47: SIREN / SIRET)
		$legalOrgId = $this->getUblValue($xpath, 'cac:PartyLegalEntity/cbc:CompanyID', $partyNode);
		$legalOrgScheme = $this->getUblValue($xpath, 'cac:PartyLegalEntity/cbc:CompanyID/@schemeID', $partyNode);
		if ($legalOrgId !== null && ($legalOrgScheme === null || $legalOrgScheme === '') && strtoupper((string) $res[$prefix . 'country']) === 'FR') {
			$digits = preg_replace('/\D/', '', $legalOrgId);
			if ($digits === $legalOrgId && strlen($digits) === 9) {
				$legalOrgScheme = '0002';
			} elseif ($digits === $legalOrgId && strlen($digits) === 14) {
				$legalOrgScheme = '0009';
			}
		}
		if ($legalOrgId !== null && $legalOrgScheme !== null && !isset($globalIds[$legalOrgScheme])) {
			$globalIds[$legalOrgScheme] = $legalOrgId;
		}

		$res[$prefix . 'GlobalIds'] = $globalIds;
		$res[$prefix . 'LegalOrgId'] = $legalOrgId;
		$res[$prefix . 'LegalOrgScheme'] = $legalOrgScheme;

		// Tax registrations / VAT identification (BT-31 / BT-32 / BT-48)
		$vatNumber = $this->getUblValue(
			$xpath,
			'cac:PartyTaxScheme[cac:TaxScheme/cbc:ID="VAT" or cac:TaxScheme/cbc:ID="VA"]/cbc:CompanyID',
			$partyNode
		);
		if ($vatNumber === null) {
			$candidateVat = $this->getUblValue($xpath, 'cac:PartyTaxScheme[1]/cbc:CompanyID', $partyNode);
			if ($candidateVat !== null && preg_match('/^[A-Z]{2}[A-Z0-9]+$/i', preg_replace('/\s+/', '', $candidateVat))) {
				$vatNumber = $candidateVat;
			}
		}
		$res[$prefix . 'vatnumber'] = $vatNumber;

		if ($prefix === 'seller') {
			$taxRegistrations = array();
			if ($vatNumber !== null && $vatNumber !== '') {
				// CommonProtocol expects the 'VA' key in sellerTaxRegistations
				$taxRegistrations['VA'] = $vatNumber;
			}
			$taxSchemeNodes = $xpath->query('cac:PartyTaxScheme', $partyNode);
			if ($taxSchemeNodes) {
				foreach ($taxSchemeNodes as $tsNode) {
					$compId = $this->getUblValue($xpath, 'cbc:CompanyID', $tsNode);
					$schemeId = strtoupper((string) $this->getUblValue($xpath, 'cac:TaxScheme/cbc:ID', $tsNode));
					if ($compId === null || $compId === '') {
						continue;
					}
					if ($schemeId === 'VAT' || $schemeId === 'VA') {
						$taxRegistrations['VA'] = $compId;
					} elseif ($schemeId !== '') {
						$taxRegistrations[$schemeId] = $compId;
					}
				}
			}
			$res['sellerTaxRegistations'] = $taxRegistrations;
		}

		return $res;
	}

	/**
	 * Parses header information of an UBL 2.1 invoice or credit note.
	 *
	 * @param  string $rawContent Raw UBL XML content
	 * @return array<string,mixed>
	 */
	public function parseInvoiceHeader(string $rawContent)
	{
		list(, $xpath, $root) = $this->initUblXPath($rawContent);

		$isCreditNote = ($root && $root->localName === 'CreditNote');

		$documentNo = $this->getUblValue($xpath, 'cbc:ID', $root);
		$documentTypeCode = $this->getUblValue($xpath, 'cbc:InvoiceTypeCode', $root)
			?? $this->getUblValue($xpath, 'cbc:CreditNoteTypeCode', $root)
			?? ($isCreditNote ? '381' : '380');

		// Notes (BG-1): supports format "#CODE#Text" (XP Z12-012 / FNFE-MPE) as well as free notes
		$documentNotes = array();
		$notePublic = null;
		$notePMT = null;
		$notePMD = null;
		$noteAAB = null;

		$noteNodes = $xpath->query('cbc:Note', $root);
		if ($noteNodes) {
			foreach ($noteNodes as $noteNode) {
				$rawNote = trim((string) $noteNode->nodeValue);
				if ($rawNote === '') {
					continue;
				}
				$subjectCode = '';
				$content = $rawNote;
				if (preg_match('/^#([A-Z0-9]{3})#\s*(.*)$/s', $rawNote, $m)) {
					$subjectCode = strtoupper($m[1]);
					$content = trim($m[2]);
				}
				$documentNotes[] = array(
					'content' => $content,
					'subjectCode' => $subjectCode,
				);
				if ($notePublic === null) {
					$notePublic = $content;
				}
				if ($subjectCode === 'PMT' && $notePMT === null) {
					$notePMT = $content;
				} elseif ($subjectCode === 'PMD' && $notePMD === null) {
					$notePMD = $content;
				} elseif ($subjectCode === 'AAB' && $noteAAB === null) {
					$noteAAB = $content;
				}
			}
		}

		// Seller (BG-4) and Buyer (BG-7) parties
		$sellerNodes = $xpath->query('cac:AccountingSupplierParty/cac:Party', $root);
		$sellerNode = ($sellerNodes && $sellerNodes->length > 0) ? $sellerNodes->item(0) : null;
		$sellerData = $this->parseUblParty($xpath, $sellerNode, 'seller');

		$buyerNodes = $xpath->query('cac:AccountingCustomerParty/cac:Party', $root);
		$buyerNode = ($buyerNodes && $buyerNodes->length > 0) ? $buyerNodes->item(0) : null;
		$buyerData = $this->parseUblParty($xpath, $buyerNode, 'buyer');

		// Document level allowances and charges (BG-20 / BG-21)
		$headerAllowancesCharges = array();
		$acNodes = $xpath->query('cac:AllowanceCharge', $root);
		if ($acNodes) {
			foreach ($acNodes as $acNode) {
				$indicatorRaw = strtolower((string) $this->getUblValue($xpath, 'cbc:ChargeIndicator', $acNode));
				$headerAllowancesCharges[] = array(
					'indicator' => ($indicatorRaw === 'true' || $indicatorRaw === '1') ? 'true' : 'false',
					'reasonCode' => $this->getUblValue($xpath, 'cbc:AllowanceChargeReasonCode', $acNode),
					'reason' => $this->getUblValue($xpath, 'cbc:AllowanceChargeReason', $acNode),
					'calculationPercent' => $this->toUblFloat($this->getUblValue($xpath, 'cbc:MultiplierFactorNumeric', $acNode)),
					'basisAmount' => $this->toUblFloat($this->getUblValue($xpath, 'cbc:BaseAmount', $acNode)),
					'actualAmount' => $this->toUblFloat($this->getUblValue($xpath, 'cbc:Amount', $acNode)),
					'categoryCode' => $this->getUblValue($xpath, 'cac:TaxCategory/cbc:ID', $acNode),
					'rateApplicablePercent' => $this->toUblFloat($this->getUblValue($xpath, 'cac:TaxCategory/cbc:Percent', $acNode)),
				);
			}
		}

		// Referenced documents (BG-3: predecessor invoices / prepayments)
		$invoiceRefDocs = array();
		$refDocNodes = $xpath->query('cac:BillingReference/cac:InvoiceDocumentReference', $root);
		if ($refDocNodes) {
			foreach ($refDocNodes as $refDocNode) {
				$invoiceRefDocs[] = array(
					'IssuerAssignedID' => $this->getUblValue($xpath, 'cbc:ID', $refDocNode),
					'issueDate' => $this->normUblDate($this->getUblValue($xpath, 'cbc:IssueDate', $refDocNode)),
					'TypeCode' => $this->getUblValue($xpath, 'cbc:DocumentTypeCode', $refDocNode),
				);
			}
		}

		// VAT breakdown (BG-23)
		$taxBreakdown = array();
		$taxSubNodes = $xpath->query('cac:TaxTotal/cac:TaxSubtotal', $root);
		if ($taxSubNodes) {
			foreach ($taxSubNodes as $taxSubNode) {
				$rawTypeCode = strtoupper((string) ($this->getUblValue($xpath, 'cac:TaxCategory/cac:TaxScheme/cbc:ID', $taxSubNode) ?? 'VAT'));
				$typeCode = ($rawTypeCode === 'VA' || $rawTypeCode === '') ? 'VAT' : $rawTypeCode;
				$rateVal = $this->getUblValue($xpath, 'cac:TaxCategory/cbc:Percent', $taxSubNode)
					?? $this->getUblValue($xpath, 'cbc:Percent', $taxSubNode);

				$taxBreakdown[] = array(
					'typeCode' => $typeCode,
					'categoryCode' => $this->getUblValue($xpath, 'cac:TaxCategory/cbc:ID', $taxSubNode),
					'rateApplicablePercent' => $this->toUblFloat($rateVal),
					'calculatedAmount' => $this->toUblFloat($this->getUblValue($xpath, 'cbc:TaxAmount', $taxSubNode)),
					'basisAmount' => $this->toUblFloat($this->getUblValue($xpath, 'cbc:TaxableAmount', $taxSubNode)),
					'ExemptionReason' => $this->getUblValue($xpath, 'cac:TaxCategory/cbc:TaxExemptionReason', $taxSubNode),
					'ExemptionReasonCode' => $this->getUblValue($xpath, 'cac:TaxCategory/cbc:TaxExemptionReasonCode', $taxSubNode),
				);
			}
		}

		// Purchase order reference (BT-13)
		$orderReference = $this->getUblValue($xpath, 'cac:OrderReference/cbc:ID', $root)
			?? $this->getUblValue($xpath, 'cac:AdditionalDocumentReference[cbc:DocumentDescription="BON_COMMANDE" or cbc:DocumentTypeCode="130"]/cbc:ID', $root);

		// Payment due date (BT-9)
		$paymentDueDate = $this->getUblValue($xpath, 'cbc:DueDate', $root)
			?? $this->getUblValue($xpath, 'cac:PaymentMeans/cbc:PaymentDueDate', $root)
			?? $this->getUblValue($xpath, 'cac:PaymentTerms/cbc:SettlementPeriod/cbc:EndDate', $root);

		// Payment means (BT-81 / BT-82)
		$paymentMeansCode = $this->getUblValue($xpath, 'cac:PaymentMeans/cbc:PaymentMeansCode', $root);
		$paymentMeansText = $this->getUblValue($xpath, 'cac:PaymentMeans/cbc:InstructionNote', $root)
			?? $this->getUblValue($xpath, 'cac:PaymentMeans/cbc:PaymentMeansCode/@name', $root)
			?? $this->getUblValue($xpath, 'cac:PaymentMeans/cbc:PaymentID', $root);

		// Total VAT amount (BT-110) in invoice currency
		$invoiceCurrency = $this->getUblValue($xpath, 'cbc:DocumentCurrencyCode', $root);
		$taxTotalAmountRaw = null;
		if ($invoiceCurrency !== null) {
			$taxTotalAmountRaw = $this->getUblValue($xpath, 'cac:TaxTotal[cbc:TaxAmount/@currencyID="' . $invoiceCurrency . '"]/cbc:TaxAmount', $root);
		}
		if ($taxTotalAmountRaw === null) {
			$taxTotalAmountRaw = $this->getUblValue($xpath, 'cac:TaxTotal[1]/cbc:TaxAmount', $root);
		}

		$data = array_merge(
			array(
				// Document
				'documentno' => $documentNo,
				'documenttypecode' => $documentTypeCode,
				'documentdate' => $this->normUblDate($this->getUblValue($xpath, 'cbc:IssueDate', $root)),
				'invoiceCurrency' => $invoiceCurrency,
				'taxCurrency' => $this->getUblValue($xpath, 'cbc:TaxCurrencyCode', $root),
				'documentname' => null,
				'documentlanguage' => $this->getUblValue($xpath, 'cbc:LanguageID', $root),
				'effectiveSpecifiedPeriod' => null,
				'documentDeliveryDate' => $this->normUblDate($this->getUblValue($xpath, 'cac:Delivery/cbc:ActualDeliveryDate', $root)),
				'invoicingPeriodStart' => $this->normUblDate($this->getUblValue($xpath, 'cac:InvoicePeriod/cbc:StartDate', $root)),
				'invoicingPeriodEnd' => $this->normUblDate($this->getUblValue($xpath, 'cac:InvoicePeriod/cbc:EndDate', $root)),
				'businessProcessId' => $this->getUblValue($xpath, 'cbc:ProfileID', $root),
				'guidelineId' => $this->getUblValue($xpath, 'cbc:CustomizationID', $root),
				'isTestDocument' => null,

				// Notes
				'documentNotePublic' => $notePublic,
				'documentNotePMT' => $notePMT,
				'documentNotePMD' => $notePMD,
				'documentNoteAAB' => $noteAAB,
				'documentNotes' => $documentNotes,
			),
			$sellerData,
			$buyerData,
			array(
				'buyerReference' => $this->getUblValue($xpath, 'cbc:BuyerReference', $root),

				// Totals (BG-22)
				'grandTotalAmount' => $this->toUblFloat($this->getUblValue($xpath, 'cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount', $root)),
				'duePayableAmount' => $this->toUblFloat($this->getUblValue($xpath, 'cac:LegalMonetaryTotal/cbc:PayableAmount', $root)),
				'lineTotalAmount' => $this->toUblFloat($this->getUblValue($xpath, 'cac:LegalMonetaryTotal/cbc:LineExtensionAmount', $root)),
				'chargeTotalAmount' => $this->toUblFloat($this->getUblValue($xpath, 'cac:LegalMonetaryTotal/cbc:ChargeTotalAmount', $root)),
				'allowanceTotalAmount' => $this->toUblFloat($this->getUblValue($xpath, 'cac:LegalMonetaryTotal/cbc:AllowanceTotalAmount', $root)),
				'taxBasisTotalAmount' => $this->toUblFloat($this->getUblValue($xpath, 'cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount', $root)),
				'taxTotalAmount' => $this->toUblFloat($taxTotalAmountRaw),
				'roundingAmount' => $this->toUblFloat($this->getUblValue($xpath, 'cac:LegalMonetaryTotal/cbc:PayableRoundingAmount', $root)),
				'totalPrepaidAmount' => $this->toUblFloat($this->getUblValue($xpath, 'cac:LegalMonetaryTotal/cbc:PrepaidAmount', $root)),

				// Payment details (BG-16 / BG-17 / BT-20)
				'paymentMeansCode' => $paymentMeansCode,
				'paymentMeansText' => $paymentMeansText,
				'iban' => $this->getUblValue($xpath, 'cac:PaymentMeans/cac:PayeeFinancialAccount/cbc:ID', $root),
				'bic' => $this->getUblValue($xpath, 'cac:PaymentMeans/cac:PayeeFinancialAccount/cac:FinancialInstitutionBranch/cbc:ID', $root),
				'accountName' => $this->getUblValue($xpath, 'cac:PaymentMeans/cac:PayeeFinancialAccount/cbc:Name', $root),
				'paymentDueDate' => $this->normUblDate($paymentDueDate),
				'paymentTermsText' => $this->getUblValue($xpath, 'cac:PaymentTerms/cbc:Note', $root),

				// Header allowances & charges
				'headerAllowancesCharges' => $headerAllowancesCharges,

				// Document references
				'invoiceRefDocs' => $invoiceRefDocs,
				'orderReference' => $orderReference,
				'contractReference' => $this->getUblValue($xpath, 'cac:ContractDocumentReference/cbc:ID', $root),
				'despatchAdviceRef' => $this->getUblValue($xpath, 'cac:DespatchDocumentReference/cbc:ID', $root),

				// VAT breakdown
				'taxBreakdown' => $taxBreakdown,
			)
		);

		list($data['toleranceItemCount'], $data['toleranceItemCountByRate']) = $this->countUblToleranceItems($xpath, $root);

		return $data;
	}

	/**
	 * Parses all line items of an UBL 2.1 invoice or credit note.
	 *
	 * @param  string $rawContent Raw UBL XML content
	 * @return array<int,array<string,mixed>>
	 */
	public function parseInvoiceLines(string $rawContent)
	{
		list(, $xpath, $root) = $this->initUblXPath($rawContent);
		if (!$root) {
			return array();
		}

		$parentDocNo = $this->getUblValue($xpath, 'cbc:ID', $root);
		$nodes = $xpath->query('cac:InvoiceLine | cac:CreditNoteLine', $root);
		if (!$nodes || $nodes->length === 0) {
			return array();
		}

		$lines = array();

		foreach ($nodes as $node) {
			// Invoiced or credited quantity (BT-129 / BT-130)
			$billedQtyRaw = $this->getUblValue($xpath, 'cbc:InvoicedQuantity', $node)
				?? $this->getUblValue($xpath, 'cbc:CreditedQuantity', $node);
			$billedQtyUnit = $this->getUblValue($xpath, 'cbc:InvoicedQuantity/@unitCode', $node)
				?? $this->getUblValue($xpath, 'cbc:CreditedQuantity/@unitCode', $node);

			// Net and gross unit price (BG-29)
			$netPriceRaw = $this->getUblValue($xpath, 'cac:Price/cbc:PriceAmount', $node);
			$baseQtyRaw = $this->getUblValue($xpath, 'cac:Price/cbc:BaseQuantity', $node);
			$baseQtyUnit = $this->getUblValue($xpath, 'cac:Price/cbc:BaseQuantity/@unitCode', $node);
			$grossPriceRaw = $this->getUblValue($xpath, 'cac:Price/cac:AllowanceCharge[cbc:ChargeIndicator="false"]/cbc:BaseAmount', $node)
				?? $this->getUblValue($xpath, 'cac:Price/cac:AllowanceCharge/cbc:BaseAmount', $node);

			// Line level allowances and charges (BG-27 / BG-28)
			$lineAllowances = array();
			$acNodes = $xpath->query('cac:AllowanceCharge', $node);
			if ($acNodes) {
				foreach ($acNodes as $acNode) {
					$indRaw = strtolower((string) $this->getUblValue($xpath, 'cbc:ChargeIndicator', $acNode));
					$lineAllowances[] = array(
						'indicator' => ($indRaw === 'true' || $indRaw === '1') ? 'true' : 'false',
						'reasonCode' => $this->getUblValue($xpath, 'cbc:AllowanceChargeReasonCode', $acNode),
						'reason' => $this->getUblValue($xpath, 'cbc:AllowanceChargeReason', $acNode),
						'calculationPercent' => $this->toUblFloat($this->getUblValue($xpath, 'cbc:MultiplierFactorNumeric', $acNode)),
						'basisAmount' => $this->toUblFloat($this->getUblValue($xpath, 'cbc:BaseAmount', $acNode)),
						'actualAmount' => $this->toUblFloat($this->getUblValue($xpath, 'cbc:Amount', $acNode)),
					);
				}
			}

			// Gross price discounts (BT-147)
			$lineGrossPriceAllowances = array();
			$priceAcNodes = $xpath->query('cac:Price/cac:AllowanceCharge', $node);
			if ($priceAcNodes) {
				foreach ($priceAcNodes as $priceAcNode) {
					$indRaw = strtolower((string) $this->getUblValue($xpath, 'cbc:ChargeIndicator', $priceAcNode));
					$lineGrossPriceAllowances[] = array(
						'indicator' => ($indRaw === 'true' || $indRaw === '1') ? 'true' : 'false',
						'reasonCode' => $this->getUblValue($xpath, 'cbc:AllowanceChargeReasonCode', $priceAcNode),
						'reason' => $this->getUblValue($xpath, 'cbc:AllowanceChargeReason', $priceAcNode),
						'calculationPercent' => $this->toUblFloat($this->getUblValue($xpath, 'cbc:MultiplierFactorNumeric', $priceAcNode)),
						'basisAmount' => $this->toUblFloat($this->getUblValue($xpath, 'cbc:BaseAmount', $priceAcNode)),
						'actualAmount' => $this->toUblFloat($this->getUblValue($xpath, 'cbc:Amount', $priceAcNode)),
					);
				}
			}

			// Line document references (BT-128 / EXT-FR-FE-BG-06)
			$additionalRefDocs = array();
			$docRefNodes = $xpath->query('cac:DocumentReference | cac:BillingReference/cac:InvoiceDocumentReference', $node);
			if ($docRefNodes) {
				foreach ($docRefNodes as $docRefNode) {
					$additionalRefDocs[] = array(
						'IssuerAssignedID' => $this->getUblValue($xpath, 'cbc:ID', $docRefNode),
						'typeCode' => $this->getUblValue($xpath, 'cbc:DocumentTypeCode', $docRefNode),
						'name' => $this->getUblValue($xpath, 'cbc:DocumentDescription', $docRefNode)
							?? $this->getUblValue($xpath, 'cbc:DocumentType', $docRefNode),
						'referenceTypeCode' => $this->getUblValue($xpath, 'cbc:ID/@schemeID', $docRefNode),
						'uriid' => $this->getUblValue($xpath, 'cac:Attachment/cac:ExternalReference/cbc:URI', $docRefNode),
						'issueDate' => $this->normUblDate($this->getUblValue($xpath, 'cbc:IssueDate', $docRefNode)),
					);
				}
			}

			// Line VAT (BG-30)
			$categoryCode = $this->getUblValue($xpath, 'cac:Item/cac:ClassifiedTaxCategory/cbc:ID', $node)
				?? $this->getUblValue($xpath, 'cac:TaxTotal/cac:TaxSubtotal/cac:TaxCategory/cbc:ID', $node);
			$rawTypeCode = strtoupper((string) ($this->getUblValue($xpath, 'cac:Item/cac:ClassifiedTaxCategory/cac:TaxScheme/cbc:ID', $node) ?? 'VAT'));
			$typeCode = ($rawTypeCode === 'VA' || $rawTypeCode === '') ? 'VAT' : $rawTypeCode;
			$ratePercentRaw = $this->getUblValue($xpath, 'cac:Item/cac:ClassifiedTaxCategory/cbc:Percent', $node)
				?? $this->getUblValue($xpath, 'cac:TaxTotal/cac:TaxSubtotal/cac:TaxCategory/cbc:Percent', $node);
			$calculatedAmountRaw = $this->getUblValue($xpath, 'cac:TaxTotal/cbc:TaxAmount', $node);

			$prodName = $this->getUblValue($xpath, 'cac:Item/cbc:Name', $node)
				?? $this->getUblValue($xpath, 'cac:Item/cbc:Description', $node);
			$prodDesc = $this->getUblValue($xpath, 'cac:Item/cbc:Description', $node);
			$prodSellerId = $this->getUblValue($xpath, 'cac:Item/cac:SellersItemIdentification/cbc:ID', $node);
			if ($prodSellerId !== null) {
				$prodSellerId = dol_substr(trim($prodSellerId), 0, 128);
			}

			$lines[] = array(
				'lineid' => $this->getUblValue($xpath, 'cbc:ID', $node),
				'linestatuscode' => $this->getUblValue($xpath, 'cbc:LineStatusCode', $node),
				'linestatusreasoncode' => null,
				'lineNote' => $this->getUblValue($xpath, 'cbc:Note', $node),

				'prodname' => $prodName,
				'proddesc' => $prodDesc,
				'prodsellerid' => $prodSellerId,
				'prodbuyerid' => $this->getUblValue($xpath, 'cac:Item/cac:BuyersItemIdentification/cbc:ID', $node),
				'prodglobalidtype' => $this->getUblValue($xpath, 'cac:Item/cac:StandardItemIdentification/cbc:ID/@schemeID', $node),
				'prodglobalid' => $this->getUblValue($xpath, 'cac:Item/cac:StandardItemIdentification/cbc:ID', $node),
				'prodmultilangs' => array(),
				'prodClassificationCode' => $this->getUblValue($xpath, 'cac:Item/cac:CommodityClassification/cbc:ItemClassificationCode', $node),
				'prodClassificationScheme' => $this->getUblValue($xpath, 'cac:Item/cac:CommodityClassification/cbc:ItemClassificationCode/@listID', $node)
					?? $this->getUblValue($xpath, 'cac:Item/cac:CommodityClassification/cbc:ItemClassificationCode/@listVersionID', $node),
				'prodOriginCountry' => $this->getUblValue($xpath, 'cac:Item/cac:OriginCountry/cbc:IdentificationCode', $node),

				'grosspriceamount' => $this->toUblFloat($grossPriceRaw),
				'grosspricebasisquantity' => $this->toUblFloat($baseQtyRaw),
				'grosspricebasisquantityunitcode' => $baseQtyUnit,

				'netpriceamount' => $this->toUblFloat($netPriceRaw),
				'netpricebasisquantity' => $this->toUblFloat($baseQtyRaw),
				'netpricebasisquantityunitcode' => $baseQtyUnit,

				'billedquantity' => $this->toUblFloat($billedQtyRaw),
				'billedquantityunitcode' => $billedQtyUnit,
				'chargeFreeQuantity' => null,
				'chargeFreeQuantityunitcode' => null,
				'packageQuantity' => null,
				'packageQuantityunitcode' => null,

				'lineTotalAmount' => $this->toUblFloat($this->getUblValue($xpath, 'cbc:LineExtensionAmount', $node)),
				'totalAllowanceChargeAmount' => null,

				'categoryCode' => $categoryCode,
				'typeCode' => $typeCode,
				'rateApplicablePercent' => $this->toUblFloat($ratePercentRaw),
				'calculatedAmount' => $this->toUblFloat($calculatedAmountRaw),

				'ExemptionReason' => $this->getUblValue($xpath, 'cac:Item/cac:ClassifiedTaxCategory/cbc:TaxExemptionReason', $node),
				'ExemptionReasonCode' => $this->getUblValue($xpath, 'cac:Item/cac:ClassifiedTaxCategory/cbc:TaxExemptionReasonCode', $node),

				'lineAllowances' => $lineAllowances,
				'lineGrossPriceAllowances' => $lineGrossPriceAllowances,
				'lineremisepercent' => null,

				'linePeriodStart' => $this->normUblDate($this->getUblValue($xpath, 'cac:InvoicePeriod/cbc:StartDate', $node)),
				'linePeriodEnd' => $this->normUblDate($this->getUblValue($xpath, 'cac:InvoicePeriod/cbc:EndDate', $node)),

				'additionalRefDocs' => $additionalRefDocs,

				'isDepositLine' => false,
				'depositInvoiceRef' => null,
				'depositInvoiceDate' => null,

				'parentDocumentNo' => $parentDocNo,
				'is_deposit' => 0,
				'fk_remise' => null,
			);
		}

		return $lines;
	}

	/**
	 * Removes embedded binary attachments from an UBL (or CII) XML document to reduce stored XML footprint.
	 *
	 * @param  string $xmlData XML data to process
	 * @param  string $note    Replacement placeholder note
	 * @return string          Cleaned XML
	 */
	public static function removeAttachmentFromXml(string $xmlData, string $note = self::ATTACHMENT_REMOVED_NOTE): string
	{
		$xmlData = self::stripStrayXmlDeclarations($xmlData);
		$xmlDoc = new \DOMDocument();
		if (!$xmlDoc->loadXML($xmlData)) {
			throw new \Exception(__METHOD__ . ' : failed to load XML data');
		}

		$xpath = new \DOMXPath($xmlDoc);
		$attachedDocumentNodes = $xpath->query(
			'//*[local-name()="AdditionalDocumentReference"]/*[local-name()="Attachment"]/*[local-name()="EmbeddedDocumentBinaryObject"]'
			. ' | //*[local-name()="AdditionalReferencedDocument"]/*[local-name()="AttachmentBinaryObject"]'
		);

		if ($attachedDocumentNodes && $attachedDocumentNodes->length >= 1) {
			foreach ($attachedDocumentNodes as $attachedDocumentNode) {
				$attachedDocumentNode->nodeValue = $note;
			}
			return (string) $xmlDoc->saveXML();
		}

		return $xmlData;
	}

	/**
	 * Extracts base64 embedded attachments from UBL XML (BT-125: EmbeddedDocumentBinaryObject),
	 * such as readable PDF embedded under cac:AdditionalDocumentReference by vendors (e.g. Leroy Merlin).
	 *
	 * @param  string $xmlData Received UBL XML
	 * @return array<int,array{filename:string,mimecode:string,extension:string,content:string}>
	 */
	public static function extractEmbeddedAttachments(string $xmlData): array
	{
		$attachments = array();

		if ($xmlData === '') {
			return $attachments;
		}

		$xmlDoc = new \DOMDocument();
		$xmlData = self::stripStrayXmlDeclarations($xmlData);
		if (!@$xmlDoc->loadXML($xmlData)) {
			dol_syslog(__METHOD__ . ' failed to load XML data, no attachment extracted', LOG_WARNING, 0, '_einvoicing');
			return $attachments;
		}

		$xpath = new \DOMXPath($xmlDoc);
		$nodes = $xpath->query(
			'//*[local-name()="AdditionalDocumentReference"]/*[local-name()="Attachment"]/*[local-name()="EmbeddedDocumentBinaryObject"]'
			. ' | //*[local-name()="AdditionalReferencedDocument"]/*[local-name()="AttachmentBinaryObject"]'
		);
		if ($nodes === false) {
			return $attachments;
		}

		$rank = 0;
		foreach ($nodes as $node) {
			$rank++;
			if (!($node instanceof \DOMElement)) {
				continue;
			}

			$mimecode = strtolower(trim($node->getAttribute('mimeCode')));
			if (!isset(self::ATTACHMENT_MIME_EXTENSIONS[$mimecode])) {
				dol_syslog(__METHOD__ . ' attachment #' . $rank . ' ignored, mime code ' . ($mimecode === '' ? '(none)' : $mimecode) . ' is not one EN 16931 allows', LOG_WARNING, 0, '_einvoicing');
				continue;
			}

			$base64 = preg_replace('/\s+/', '', (string) $node->nodeValue);
			$content = ($base64 === '' || $base64 === null) ? false : base64_decode($base64, true);
			if ($content === false || $content === '') {
				dol_syslog(__METHOD__ . ' attachment #' . $rank . ' ignored, its content is not readable base64', LOG_WARNING, 0, '_einvoicing');
				continue;
			}

			$extension = self::ATTACHMENT_MIME_EXTENSIONS[$mimecode];

			$filename = trim($node->getAttribute('filename'));
			if ($filename === '') {
				$filename = trim((string) $xpath->evaluate('string(../../*[local-name()="ID"])', $node));
				if ($filename === '') {
					$filename = trim((string) $xpath->evaluate('string(../*[local-name()="IssuerAssignedID"])', $node));
				}
			}
			$filename = preg_replace('/\.[A-Za-z0-9]{1,8}$/', '', basename($filename));
			$filename = dol_sanitizeFileName((string) $filename);
			if ($filename === '' || $filename === '-') {
				$filename = 'attachment' . $rank;
			}

			$attachments[] = array(
				'filename' => $filename,
				'mimecode' => $mimecode,
				'extension' => $extension,
				'content' => $content,
			);
		}

		return $attachments;
	}
}