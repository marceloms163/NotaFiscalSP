<?php

namespace NotaFiscalSP\Builders;

use NotaFiscalSP\Constants\Requests\ComplexFieldsEnum;
use NotaFiscalSP\Constants\Requests\DetailEnum;
use NotaFiscalSP\Constants\Requests\HeaderEnum;
use NotaFiscalSP\Constants\Requests\RpsEnum;
use NotaFiscalSP\Constants\Requests\SimpleFieldsEnum;
use NotaFiscalSP\Constants\Requests\V2\RpsEnumV2;
use NotaFiscalSP\Contracts\InputTransformer;
use NotaFiscalSP\Entities\BaseInformation;
use NotaFiscalSP\Helpers\Certificate;
use NotaFiscalSP\Helpers\General;
use NotaFiscalSP\Constants\FieldData\BooleanFields;
use NotaFiscalSP\Constants\FieldData\RPSType;

abstract class NfAbstractV2 implements InputTransformer
{
    public function getDocument($information)
    {
        if (!empty($information->getCnpj())) {
            return [SimpleFieldsEnum::CNPJ => $information->getCnpj()];
        }
        return [SimpleFieldsEnum::CPF => $information->getCpf()];
    }

    public function makeHeader(BaseInformation $information, $extraInformations)
    {
        $header = [
            '_attributes' => [
                HeaderEnum::VERSION => 2
            ],
        ];

        // Para PedidoEnvioRPS (V2), o Cabeçalho deve conter APENAS CPFCNPJRemetente
        if (isset($extraInformations[HeaderEnum::CPFCNPJ_SENDER]) || isset($extraInformations[HeaderEnum::CPFCNPJ])) {
            $header[HeaderEnum::CPFCNPJ_SENDER] = $this->getDocument($information);
        }

        // Se houver campos de lote (transacao, datas), eles só entram se vierem explicitamente
        // (Isso é usado pelo PedidoEnvioLoteRPSV2)
        foreach ([HeaderEnum::TRANSACTION, HeaderEnum::START_DATE, HeaderEnum::END_DATE, HeaderEnum::RPS_COUNT, HeaderEnum::SERVICES_TOTAL, HeaderEnum::DEDUCTION_TOTAL] as $field) {
            if (isset($extraInformations[$field])) {
                $header[$field] = $extraInformations[$field];
            }
        }

        return [
            HeaderEnum::HEADER => $header
        ];
    }

    public function makeTaxPayerInformation($document)
    {
        $documentType = strlen(General::onlyNumbers($document) > 11) ? SimpleFieldsEnum::CNPJ : SimpleFieldsEnum::CPF;
        return [
            ComplexFieldsEnum::CNPJ_TAX_PAYER => [
                $documentType => $document
            ]
        ];
    }

    public function makeDetail(BaseInformation $information, $documents)
    {
        $detais = [];

        foreach ($documents as $document) {
            $detail = [];
            // Assinatura usada em detalhes de cancelamento

            if (isset($document[SimpleFieldsEnum::RPS_NUMBER]))
                $detail = array_merge($detail, $this->makeRpsKey($document));


            if (isset($document[SimpleFieldsEnum::NFE_NUMBER]))
                $detail = array_merge($detail, $this->makeNfeKey($document));

            foreach (DetailEnum::signedTypes() as $field) {
                if (isset($document[$field]))
                    $detail[$field] = Certificate::signItem($information, $document[$field]);
            }

            $details[] = $detail;
        }

        return [
            DetailEnum::DETAIL => $details,
        ];
    }

    private function makeRpsKey($extraInformations)
    {
        return [
            ComplexFieldsEnum::RPS_KEY => [
                SimpleFieldsEnum::IM_PROVIDER => General::getPath($extraInformations, SimpleFieldsEnum::IM_PROVIDER),
                SimpleFieldsEnum::RPS_SERIES => General::getPath($extraInformations, SimpleFieldsEnum::RPS_SERIES),
                SimpleFieldsEnum::RPS_NUMBER => General::getPath($extraInformations, SimpleFieldsEnum::RPS_NUMBER),
            ]
        ];
    }

    private function makeNfeKey($extraInformations)
    {

        $params = [
            SimpleFieldsEnum::IM_PROVIDER => General::getPath($extraInformations, SimpleFieldsEnum::IM_PROVIDER),
            SimpleFieldsEnum::NFE_NUMBER => General::getPath($extraInformations, SimpleFieldsEnum::NFE_NUMBER),
        ];


        $verificationCode = General::getPath($extraInformations, SimpleFieldsEnum::VERIFICATION_CODE);

        if ($verificationCode) {
            $params[SimpleFieldsEnum::VERIFICATION_CODE] = $verificationCode;
        }

        return [
            ComplexFieldsEnum::NFE_KEY => $params
        ];
    }

    public function makeRPS(BaseInformation $information, $rpsList)
    {
        $rpsItens = [];
        foreach ($rpsList as $extraInformations) {
            $rps = [];

            // 1. Assinatura (1894)
            $rps[DetailEnum::SIGN] = Certificate::signItem($information, General::getPath($extraInformations, DetailEnum::SIGN));

            // 2. ChaveRPS (1899)
            $rps[ComplexFieldsEnum::RPS_KEY] = [
                SimpleFieldsEnum::IM_PROVIDER => General::onlyNumbers($information->getIm()),
                SimpleFieldsEnum::RPS_SERIES => General::getPath($extraInformations, SimpleFieldsEnum::RPS_SERIES),
                SimpleFieldsEnum::RPS_NUMBER => General::getPath($extraInformations, SimpleFieldsEnum::RPS_NUMBER),
            ];

            // Sequence from TiposNFe_v02.xsd tpRPS
            // 3. TipoRPS (1903)
            if (isset($extraInformations[RpsEnum::RPS_TYPE]))
                $rps[RpsEnum::RPS_TYPE] = $extraInformations[RpsEnum::RPS_TYPE];

            // 4. DataEmissao (1908)
            if (isset($extraInformations[RpsEnum::EMISSION_DATE]))
                $rps[RpsEnum::EMISSION_DATE] = $extraInformations[RpsEnum::EMISSION_DATE];

            // 5. StatusRPS (1913)
            if (isset($extraInformations[RpsEnum::RPS_STATUS]))
                $rps[RpsEnum::RPS_STATUS] = $extraInformations[RpsEnum::RPS_STATUS];

            // 6. TributacaoRPS (1918)
            if (isset($extraInformations[RpsEnum::RPS_TAX]))
                $rps[RpsEnum::RPS_TAX] = $extraInformations[RpsEnum::RPS_TAX];
 
            // 7. ValorDeducoes (1923)
            $rps[RpsEnum::DEDUCTION_VALUE] = $extraInformations[RpsEnum::DEDUCTION_VALUE] ?? '0.00';

            // 9-13. Taxes (1928-1948) - Mandatory sequence
            foreach ([RpsEnum::PIS_VALUE, RpsEnum::COFINS_VALUE, RpsEnum::INSS_VALUE, RpsEnum::IR_VALUE, RpsEnum::CSLL_VALUE] as $taxField) {
                $rps[$taxField] = $extraInformations[$taxField] ?? '0.00';
            }

            // 14. CodigoServico (1953)
            if (isset($extraInformations[RpsEnum::SERVICE_CODE]))
                $rps[RpsEnum::SERVICE_CODE] = $extraInformations[RpsEnum::SERVICE_CODE];

            // 15. AliquotaServicos (1958)
            if (isset($extraInformations[RpsEnum::SERVICE_TAX]))
                $rps[RpsEnum::SERVICE_TAX] = $extraInformations[RpsEnum::SERVICE_TAX];

            // 16. ISSRetido (1963)
            $issRetido = $extraInformations[RpsEnum::ISS_RETENTION] ?? false;
            $isRetidoBool = ($issRetido === 'true' || $issRetido === true || $issRetido === 'S' || $issRetido == 1);
            
            $rps[RpsEnum::ISS_RETENTION] = $isRetidoBool ? BooleanFields::LOWER_TRUE : BooleanFields::LOWER_FALSE;

            // 17. CPFCNPJTomador (1968)
            $rps[RpsEnum::CPFCNPJ_TAKER] = $this->makeCPFCNPJTaker($extraInformations);

            // 18. InscricaoMunicipalTomador (1973)
            if (isset($extraInformations[RpsEnum::IM_TAKER]))
                $rps[RpsEnum::IM_TAKER] = $extraInformations[RpsEnum::IM_TAKER];

            // 19. InscricaoEstadualTomador (1978)
            if (isset($extraInformations[RpsEnum::IE_TAKER]))
                $rps[RpsEnum::IE_TAKER] = $extraInformations[RpsEnum::IE_TAKER];

            // 20. RazaoSocialTomador (1983)
            if (isset($extraInformations[RpsEnum::CORPORATE_NAME_TAKER]))
                $rps[RpsEnum::CORPORATE_NAME_TAKER] = $extraInformations[RpsEnum::CORPORATE_NAME_TAKER];

            // 21. EnderecoTomador (1988)
            $rps[ComplexFieldsEnum::ADDRESS_TAKER] = $this->makeAddress($extraInformations);

            // 22. EmailTomador (1993)
            if (isset($extraInformations[RpsEnum::EMAIL_TAKER]))
                $rps[RpsEnum::EMAIL_TAKER] = $extraInformations[RpsEnum::EMAIL_TAKER];

            // 23-26. Intermediary Info (1998-2013)
            if (isset($extraInformations[RpsEnum::CPFCNPJ_INTERMEDIARY])) {
                $rps[RpsEnum::CPFCNPJ_INTERMEDIARY] = $this->makeCPFCNPJIntermediary($extraInformations);
                if (isset($extraInformations[RpsEnum::IM_INTERMEDIARY]))
                    $rps[RpsEnum::IM_INTERMEDIARY] = $extraInformations[RpsEnum::IM_INTERMEDIARY];
                if (isset($extraInformations[RpsEnum::INTERMEDIARY_ISS_RETENTION]))
                    $rps[RpsEnum::INTERMEDIARY_ISS_RETENTION] = $extraInformations[RpsEnum::INTERMEDIARY_ISS_RETENTION];
                if (isset($extraInformations[RpsEnum::INTERMEDIARY_EMAIL]))
                    $rps[RpsEnum::INTERMEDIARY_EMAIL] = $extraInformations[RpsEnum::INTERMEDIARY_EMAIL];
            }

            // 27. Discriminacao (2018)
            if (isset($extraInformations[RpsEnum::DISCRIMINATION]))
                $rps[RpsEnum::DISCRIMINATION] = $extraInformations[RpsEnum::DISCRIMINATION];

            // 28-30. Tax Burden (2023-2033)
            if (isset($extraInformations[RpsEnum::TAX_VALUE_INTERMEDIARY]))
                $rps[RpsEnum::TAX_VALUE_INTERMEDIARY] = $extraInformations[RpsEnum::TAX_VALUE_INTERMEDIARY];
            if (isset($extraInformations[RpsEnum::TAX_PERCENT_INTERMEDIARY]))
                $rps[RpsEnum::TAX_PERCENT_INTERMEDIARY] = $extraInformations[RpsEnum::TAX_PERCENT_INTERMEDIARY];
            if (isset($extraInformations[RpsEnum::TAX_ORIGIN]))
                $rps[RpsEnum::TAX_ORIGIN] = $extraInformations[RpsEnum::TAX_ORIGIN];

            // 31-32. Construction (2038-2043)
            if (isset($extraInformations[RpsEnum::CEI_CODE]))
                $rps[RpsEnum::CEI_CODE] = $extraInformations[RpsEnum::CEI_CODE];
            if (isset($extraInformations[RpsEnum::WORK_REGISTRATION]))
                $rps[RpsEnum::WORK_REGISTRATION] = $extraInformations[RpsEnum::WORK_REGISTRATION];

            // 33. MunicipioPrestacao (2048)
            if (isset($extraInformations[RpsEnum::CITY_INSTALLMENT]))
                $rps[RpsEnum::CITY_INSTALLMENT] = $extraInformations[RpsEnum::CITY_INSTALLMENT];

            // 34. NumeroEncapsulamento (2053)
            if (isset($extraInformations[RpsEnum::ENCAPSULATION_NUMBER]))
                $rps[RpsEnum::ENCAPSULATION_NUMBER] = $extraInformations[RpsEnum::ENCAPSULATION_NUMBER];

            // 35. ValorTotalRecebido (2058)
            if (isset($extraInformations[RpsEnum::TOTAL_VALUE]))
                $rps[RpsEnum::TOTAL_VALUE] = $extraInformations[RpsEnum::TOTAL_VALUE];

            // --- FIM DA SEQUÊNCIA V1 ---
            // --- INÍCIO DA SEQUÊNCIA EXCLUSIVA V2 ---

            // 36. Choice: ValorInicialCobrado / ValorFinalCobrado (2063)
            if (isset($extraInformations[RpsEnumV2::VALUE_INITIAL_CHARGED])) {
                $rps[RpsEnumV2::VALUE_INITIAL_CHARGED] = $extraInformations[RpsEnumV2::VALUE_INITIAL_CHARGED];
            } else {
                $rps[RpsEnumV2::VALUE_FINAL_CHARGED] = $extraInformations[RpsEnumV2::VALUE_FINAL_CHARGED] ?? $extraInformations[RpsEnum::SERVICE_VALUE];
            }

            // 37-41. V2 Financial Details (2076-2096)
            if (isset($extraInformations[RpsEnumV2::VALUE_FINE]))
                $rps[RpsEnumV2::VALUE_FINE] = $extraInformations[RpsEnumV2::VALUE_FINE];
            if (isset($extraInformations[RpsEnumV2::VALUE_INTEREST]))
                $rps[RpsEnumV2::VALUE_INTEREST] = $extraInformations[RpsEnumV2::VALUE_INTEREST];
            
            $rps[RpsEnumV2::VALUE_IPI] = $extraInformations[RpsEnumV2::VALUE_IPI] ?? '0.00';
            $rps[RpsEnumV2::EXIGIBILITY_SUSPENDED] = (int) ($extraInformations[RpsEnumV2::EXIGIBILITY_SUSPENDED] ?? 0);
            $rps[RpsEnumV2::ADVANCE_INSTALLMENT_PAYMENT] = (int) ($extraInformations[RpsEnumV2::ADVANCE_INSTALLMENT_PAYMENT] ?? 0);

            // 43-45. NCM/NBS/Activity (2108-2118)
            if (isset($extraInformations[RpsEnumV2::NCM]))
                $rps[RpsEnumV2::NCM] = $extraInformations[RpsEnumV2::NCM];
            if (isset($extraInformations[RpsEnumV2::NBS]))
                $rps[RpsEnumV2::NBS] = $extraInformations[RpsEnumV2::NBS];
            if (isset($extraInformations[RpsEnumV2::EVENT_ACTIVITY]))
                $rps[RpsEnumV2::EVENT_ACTIVITY] = $extraInformations[RpsEnumV2::EVENT_ACTIVITY];

            // 46. gpPrestacao (2123) Choice: cLocPrestacao / cPaisPrestacao
            if (isset($extraInformations[RpsEnumV2::SERVICE_LOCATION])) {
                $rps[RpsEnumV2::SERVICE_LOCATION] = $extraInformations[RpsEnumV2::SERVICE_LOCATION];
            } elseif (isset($extraInformations[RpsEnumV2::SERVICE_COUNTRY])) {
                $rps[RpsEnumV2::SERVICE_COUNTRY] = $extraInformations[RpsEnumV2::SERVICE_COUNTRY];
            } else {
                $rps[RpsEnumV2::SERVICE_LOCATION] = '3550308'; // Default SP
            }

            // 47. IBSCBS Group (2122)
            if (isset($extraInformations[RpsEnumV2::IBSCBS]))
                $rps[RpsEnumV2::IBSCBS] = $extraInformations[RpsEnumV2::IBSCBS];

            $rpsItens[] = $rps;
        }
        return [
            RpsEnum::RPS => $rpsItens,
        ];
    }

    private function makeCPFCNPJTaker($extraInformations)
    {
        if (isset($extraInformations[SimpleFieldsEnum::CPF]))
            return [SimpleFieldsEnum::CPF => $extraInformations[SimpleFieldsEnum::CPF]];

        if (isset($extraInformations[SimpleFieldsEnum::CNPJ]))
            return [SimpleFieldsEnum::CNPJ => $extraInformations[SimpleFieldsEnum::CNPJ]];

        return [SimpleFieldsEnum::CNPJ => null];
    }

    private function makeCPFCNPJIntermediary($extraInformations)
    {
        $document = $extraInformations[RpsEnum::CPFCNPJ_INTERMEDIARY];
        $documentType = strlen(General::onlyNumbers($document)) > 11 ? SimpleFieldsEnum::CNPJ : SimpleFieldsEnum::CPF;

        return [
            $documentType => $document
        ];
    }

    private function makeAddress($extraInformations)
    {
        $address = [];
        foreach (SimpleFieldsEnum::addressFields() as $field) {
            if (isset($extraInformations[$field]))
                $address[$field] = $extraInformations[$field];
        }
        return $address;
    }
}

